<?php

namespace Tests\Feature\Clinica;

use App\Models\AuditLog;
use App\Models\Cobranca;
use App\Models\Venda;
use App\Services\AiAgentService;
use App\Services\CobrancaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

class CobrancaUmPassoTest extends TestCase
{
    use CenarioClinica, RefreshDatabase, SimulaAgente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAgente();
        $this->logarComo('dono', self::T);
    }

    private function fechada(int $tenant = self::T): Cobranca
    {
        $agenda = $this->agendamentoDireto($this->pet(tenant: $tenant), $this->usuarioClinica('veterinario', $tenant), $this->item(tenant: $tenant));

        return app(CobrancaService::class)->cobrarAtendimento($agenda->id, $tenant);
    }

    public function test_receber_exige_pagamento_e_confirma_sem_revisar_nos_dois_fronts(): void
    {
        foreach (['blade', 'react'] as $front) {
            config(['sentinel.front' => $front]);
            $cobranca = $this->fechada();
            $card = $this->comando('receber_cobranca', ['id' => $cobranca->id])->assertOk()
                ->assertJsonPath('passo_unico', true)->assertJsonPath('segurar', false);
            $proposta = $card->json('proposta');
            $this->assertNotEmpty($card->json('formulario.resumo'));
            $this->assertSame('fechada', $cobranca->fresh()->status);
            foreach (['', 'invalida'] as $forma) {
                $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'valores' => ['forma_pagamento' => $forma]])
                    ->assertStatus(422)->assertJsonStructure(['formulario']);
                $this->assertSame('fechada', $cobranca->fresh()->status);
            }
            $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'valores' => ['forma_pagamento' => 'pix', 'total_centavos' => 1]])
                ->assertOk()->assertJsonPath('tipo', 'resultado_escrita');
            $this->assertSame('paga', $cobranca->fresh()->status);
            $this->assertSame(15000, $cobranca->fresh()->total_centavos);
            $this->assertSame('pix', $cobranca->fresh()->forma_pagamento);
            $this->assertContains($this->postJson('/agente/confirmar', ['proposta' => $proposta])->status(), [409, 422]);
        }
        $this->assertSame(2, AuditLog::where('tool', 'receber_cobranca')->where('resultado', 'sucesso')->count());
    }

    public function test_cobrar_atendimento_mostra_previa_e_so_grava_apos_clique(): void
    {
        $agenda = $this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item());
        $card = $this->comando('cobrar_atendimento', ['id' => $agenda->id])->assertOk()->assertJsonPath('passo_unico', true);
        $this->assertNotEmpty($card->json('formulario.resumo'));
        $this->assertSame(0, Cobranca::count());
        $body = ['proposta' => $card->json('proposta')];
        $this->postJson('/agente/confirmar', $body)->assertOk()->assertJsonPath('tipo', 'resultado_escrita');
        $this->assertSame('fechada', Cobranca::sole()->status);
        $this->assertSame(15000, Cobranca::sole()->total_centavos);
        $this->assertContains($this->postJson('/agente/confirmar', $body)->status(), [409, 422]);
        $this->assertSame(1, Cobranca::count());
        $this->assertSame(1, AuditLog::where('tool', 'cobrar_atendimento')->where('resultado', 'sucesso')->count());
    }

    public function test_venda_em_um_passo_preserva_pagamento_opcional_preco_e_estoque(): void
    {
        $produto = $this->produto();
        foreach ([null, 'pix'] as $forma) {
            $card = $this->comando('registrar_venda', ['itens' => [['catalogo_item_id' => $produto->id, 'quantidade' => 2]]])
                ->assertOk()->assertJsonPath('passo_unico', true)->assertJsonPath('segurar', false);
            $antes = $produto->fresh()->estoque;
            $body = ['proposta' => $card->json('proposta'), 'valores' => ['forma_pagamento' => $forma, 'total_centavos' => 1]];
            $this->postJson('/agente/confirmar', $body)->assertOk();
            $venda = Venda::latest('id')->first();
            $this->assertSame($forma ? 'paga' : 'aberta', $venda->status);
            $this->assertSame(42000, $venda->total_centavos);
            $this->assertSame($antes - 2, $produto->fresh()->estoque);
            $this->assertContains($this->postJson('/agente/confirmar', $body)->status(), [409, 422]);
            $this->assertSame($antes - 2, $produto->fresh()->estoque);
        }
        $this->assertSame(2, Venda::count());
        $this->assertSame(2, AuditLog::where('tool', 'registrar_venda')->where('resultado', 'sucesso')->count());
    }

    public function test_recebimento_revalida_papel_e_nao_permite_trocar_o_alvo_por_outro_tenant(): void
    {
        $cobranca = $this->fechada();
        $outra = $this->fechada(self::OUTRO_TENANT);
        $proposta = $this->comando('receber_cobranca', ['id' => $cobranca->id])->assertOk()->json('proposta');
        $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'valores' => ['id' => $outra->id, 'forma_pagamento' => 'pix']])
            ->assertOk(); // id não é um campo editável: a proposta mantém a cobrança original.
        $this->assertSame('paga', $cobranca->fresh()->status);
        $this->assertSame('fechada', $outra->fresh()->status);
        $cobranca = $this->fechada();
        $proposta = $this->comando('receber_cobranca', ['id' => $cobranca->id])->assertOk()->json('proposta');
        auth()->user()->forceFill(['papel' => 'vendedor'])->save();
        $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'valores' => ['id' => $cobranca->id, 'forma_pagamento' => 'pix']])
            ->assertForbidden();
        $this->assertSame('fechada', $cobranca->fresh()->status);
        $this->assertSame(1, AuditLog::where('tool', 'receber_cobranca')->where('resultado', 'sucesso')->count());
    }

    public function test_cancelamento_e_estornos_continuam_com_revisao_e_protecao(): void
    {
        $cobranca = $this->fechada();
        $card = $this->comando('cancelar_cobranca', ['id' => $cobranca->id])->assertOk()->assertJsonPath('segurar', true);
        $this->assertFalse($card->json('passo_unico') === true);
        $proposta = $card->json('proposta');
        $this->postJson('/agente/confirmar', ['token' => $proposta])->assertStatus(422);
        $this->assertSame('fechada', $cobranca->fresh()->status);
        $revisada = $this->postJson('/agente/revisar', ['proposta' => $proposta])->assertOk()->assertJsonPath('segurar', true);
        $this->postJson('/agente/confirmar', ['token' => $revisada->json('token')])->assertOk();
        $this->assertSame('cancelada', $cobranca->fresh()->status);
        $agente = app(AiAgentService::class);
        foreach (['estornar_cobranca', 'estornar_venda'] as $tool) {
            $this->assertFalse($agente->confirmaEmUmPasso($tool));
            $this->assertTrue($agente->exigeSegurar($tool, []));
            $this->assertTrue($agente->exigeReautenticacao($tool));
        }
    }
}
