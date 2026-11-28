<?php

namespace Tests\Feature\Clinica;

use App\Exceptions\RegraDeNegocioException;
use App\Models\AuditLog;
use App\Services\AiAgentService;
use App\Services\CatalogoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\ProvaDeTravas;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

class EntradaEstoqueTest extends TestCase
{
    use CenarioClinica, ProvaDeTravas, RefreshDatabase, SimulaAgente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAgente();
        $this->logarComo('dono', self::T);
    }

    public function test_entrada_por_nome_confirma_uma_vez_audita_e_venda_usa_o_saldo_novo(): void
    {
        $item = $this->produto(['estoque' => -5]);
        $card = $this->comando('dar_entrada_estoque', ['busca' => 'Ração', 'quantidade' => 20, 'motivo' => 'Compra'])
            ->assertOk()->assertJsonPath('passo_unico', true)->assertJsonPath('segurar', false);
        $this->assertSame(-5, $item->fresh()->estoque);
        $body = ['proposta' => $card->json('proposta')];
        $this->postJson('/agente/confirmar', $body)->assertOk()->assertJsonPath('tipo', 'resultado_escrita');
        $this->assertSame(15, $item->fresh()->estoque);
        $this->assertContains($this->postJson('/agente/confirmar', $body)->status(), [409, 422]);
        $this->assertSame(15, $item->fresh()->estoque);
        $log = AuditLog::where('tool', 'dar_entrada_estoque')->where('resultado', 'sucesso')->sole();
        $this->assertSame(-5, $log->valores_anteriores['estoque']);
        $this->assertSame(15, $log->parametros['estoque']);
        $this->assertSame('Compra', $log->parametros['motivo']);
        $this->assertSame(self::T, $log->tenant_id);
        $venda = $this->comando('registrar_venda', ['itens' => [['catalogo_item_id' => $item->id, 'quantidade' => 3]]])->assertOk();
        $this->postJson('/agente/confirmar', ['proposta' => $venda->json('proposta')])->assertOk();
        $this->assertSame(12, $item->fresh()->estoque);
    }

    public function test_quantidade_e_motivo_invalidos_retornam_422_sem_movimentar(): void
    {
        $item = $this->produto();
        $card = $this->postJson('/agente/formulario', ['tool' => 'dar_entrada_estoque', 'id' => $item->id]);
        $this->assertSame(200, $card->status(), $card->getContent());
        $proposta = $card->json('proposta');
        foreach ([[], ['quantidade' => 0], ['quantidade' => -1], ['quantidade' => 100001], ['quantidade' => '1.5'], ['quantidade' => 1, 'motivo' => str_repeat('x', 201)]] as $valores) {
            $this->postJson('/agente/confirmar', compact('proposta', 'valores'))->assertStatus(422)->assertJsonStructure(['formulario']);
            $this->assertSame(10, $item->fresh()->estoque);
        }
        $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'valores' => ['quantidade' => 1, 'motivo' => '']])->assertOk();
        $this->assertSame(11, $item->fresh()->estoque);
    }

    public function test_servicos_e_produtos_sem_controle_sao_recusados(): void
    {
        foreach ([$this->item(['controla_estoque' => true]), $this->produto(['controla_estoque' => false])] as $item) {
            $card = $this->comando('dar_entrada_estoque', ['id' => $item->id, 'quantidade' => 2])->assertOk();
            $this->postJson('/agente/confirmar', ['proposta' => $card->json('proposta')])->assertStatus(422);
            $this->assertSame($item->estoque, $item->fresh()->estoque);
            try {
                app(CatalogoService::class)->entradaDeEstoque($item->id, 2, self::T);
                $this->fail('O service também precisa recusar o item inelegível.');
            } catch (RegraDeNegocioException $e) {
                $this->assertStringContainsString('produto', $e->getMessage());
            }
        }
    }

    public function test_tenant_e_permissoes_sao_conferidos_no_servidor(): void
    {
        $outro = $this->produto(tenant: self::OUTRO_TENANT);
        $this->postJson('/agente/formulario', ['tool' => 'dar_entrada_estoque', 'id' => $outro->id])->assertStatus(422);
        $this->assertSame(10, $outro->fresh()->estoque);
        $item = $this->produto();
        $proposta = $this->comando('dar_entrada_estoque', ['id' => $item->id, 'quantidade' => 1])->assertOk()->json('proposta');
        auth()->user()->forceFill(['papel' => 'vendedor'])->save();
        $this->postJson('/agente/confirmar', ['proposta' => $proposta])->assertForbidden();
        $this->assertSame(10, $item->fresh()->estoque);
        $this->assertTrue(AuditLog::where('tool', 'dar_entrada_estoque')->where('resultado', 'negado')->exists());
        foreach (['dono', 'veterinario', 'recepcao', 'vendedor', 'atendente', 'admin'] as $papel) {
            $this->logarComo($papel, self::T);
            $agente = app(AiAgentService::class);
            $this->assertSame($agente->podeExecutar('atualizar_item_catalogo'), $agente->podeExecutar('dar_entrada_estoque'));
        }
    }

    public function test_limite_e_saldo_auditado_usam_o_estado_no_confirmar(): void
    {
        $item = $this->produto(['estoque' => 0]);
        $proposta = $this->comando('dar_entrada_estoque', ['id' => $item->id, 'quantidade' => 2])->assertOk()->json('proposta');
        // Outra entrada aconteceu entre a abertura do card e o clique.
        app(CatalogoService::class)->entradaDeEstoque($item->id, 5, self::T);
        $this->postJson('/agente/confirmar', ['proposta' => $proposta])->assertOk();
        $log = AuditLog::where('tool', 'dar_entrada_estoque')->where('resultado', 'sucesso')->sole();
        $this->assertSame(5, $log->valores_anteriores['estoque']);
        $this->assertSame(7, $log->parametros['estoque']);
        app(CatalogoService::class)->entradaDeEstoque($item->id, 99993, self::T);
        $this->assertSame(100000, $item->fresh()->estoque);
        try {
            app(CatalogoService::class)->entradaDeEstoque($item->id, 1, self::T);
            $this->fail('Não pode ultrapassar o teto do saldo.');
        } catch (RegraDeNegocioException $e) {
            $this->assertSame(100000, $item->fresh()->estoque);
        }
    }

    public function test_leitura_do_saldo_e_travada_dentro_da_transacao(): void
    {
        $item = $this->produto();
        $this->mostrarTravas();
        $sql = $this->leiturasTravadas(fn () => app(CatalogoService::class)->entradaDeEstoque($item->id, 1, self::T));
        $this->assertTravouLeitura($sql, 'catalogo_itens', 'tenant_id');
        $this->assertSame(11, $item->fresh()->estoque);
    }

    public function test_nome_ambiguo_pede_escolha_sem_movimentar(): void
    {
        $primeiro = $this->produto(['nome_comercial' => 'Ração pequena']);
        $segundo = $this->produto(['nome_comercial' => 'Ração grande']);
        $lista = $this->comando('dar_entrada_estoque', ['busca' => 'Ração', 'quantidade' => 20])
            ->assertOk()->assertJsonPath('tipo', 'resultado_leitura')->assertJsonCount(2, 'resultado');
        $this->assertSame('dar_entrada_estoque', $lista->json('resultado.0.acao.tool'));
        $this->assertSame(10, $primeiro->fresh()->estoque);
        $this->assertSame(10, $segundo->fresh()->estoque);
        $this->postJson('/agente/formulario', ['tool' => 'dar_entrada_estoque', 'id' => $segundo->id])
            ->assertOk()->assertJsonPath('passo_unico', true);
    }

    public function test_falha_na_auditoria_desfaz_a_entrada(): void
    {
        $item = $this->produto();
        $proposta = $this->comando('dar_entrada_estoque', ['id' => $item->id, 'quantidade' => 20])->assertOk()->json('proposta');
        AuditLog::creating(function (AuditLog $log): void {
            if ($log->tool === 'dar_entrada_estoque' && $log->resultado === 'sucesso') {
                throw new \RuntimeException('Falha simulada de auditoria');
            }
        });
        $this->postJson('/agente/confirmar', ['proposta' => $proposta])->assertJsonPath('tipo', 'erro');
        $this->assertSame(10, $item->fresh()->estoque);
        $this->assertFalse(AuditLog::where('tool', 'dar_entrada_estoque')->where('resultado', 'sucesso')->exists());
    }

    public function test_catalogo_preserva_blade_e_react_e_expoe_permissao(): void
    {
        config(['sentinel.front' => 'blade']);
        $this->get('/cadastros')->assertOk();
        config(['sentinel.front' => 'react']);
        $this->get('/cadastros')->assertOk()->assertInertia(fn ($p) => $p->component('Consulta', false)->where('podeDarEntradaEstoque', true));
        $this->logarComo('vendedor', self::T);
        $this->get('/cadastros')->assertOk()->assertInertia(fn ($p) => $p->where('podeDarEntradaEstoque', false));
    }
}
