<?php

namespace Tests\Feature\Clinica;

use App\Models\AuditLog;
use App\Models\Cobranca;
use App\Services\CobrancaService;
use App\Services\ProntuarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\ProvaDeTravas;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

class EditarCobrancaTest extends TestCase
{
    use CenarioClinica, ProvaDeTravas, RefreshDatabase, SimulaAgente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAgente();
        $this->logarComo('dono', self::T);
    }

    private function cobranca(): Cobranca
    {
        return app(CobrancaService::class)->abrir($this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item())->id, self::T);
    }

    private function card(Cobranca $c): array
    {
        $card = $this->postJson('/agente/formulario', ['tool' => 'editar_cobranca', 'id' => $c->id])
            ->assertOk()->assertJsonPath('passo_unico', true)->assertJsonPath('segurar', false);
        $editor = $card->json('formulario.edicao_cobranca');

        return ['proposta' => $card->json('proposta'), 'valores' => ['versao' => $editor['versao'], 'linhas' => [], 'novos' => []]];
    }

    public function test_item_clinico_excluido_preserva_consumo_e_nao_baixa_novamente(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $pet = $this->pet();
        $agenda = $this->agendamentoDireto($pet, $vet, $this->item());
        $produto = $this->produto(['setor' => 'clinica', 'categoria' => 'farmacia', 'exige_receita' => true]);
        $clinico = app(ProntuarioService::class);
        $prontuario = $clinico->abrir(['pet_id' => $pet->id, 'tipo' => 'consulta', 'agendamento_id' => $agenda->id, 'diagnostico' => 'Teste isolado'], $vet->id, self::T);
        $clinico->adicionarItem($prontuario->id, $produto->id, 2, $vet->id, self::T);
        $clinico->assinar($prontuario->id, $vet->id, self::T);
        $antes = [$prontuario->fresh()->toArray(), $prontuario->itens()->get()->toArray()];
        $service = app(CobrancaService::class);
        $c = $service->cobrarAtendimento($agenda->id, self::T);
        $linha = $c->itens()->whereNotNull('prontuario_item_id')->sole();
        $invalida = $this->card($c);
        $invalida['valores']['linhas'] = [['item_id' => $linha->id, 'quantidade' => 1, 'remover' => false]];
        $this->postJson('/agente/confirmar', $invalida)->assertStatus(422);
        $this->assertSame(2, $linha->fresh()->quantidade);
        $body = $this->card($c);
        $body['valores']['linhas'] = [['item_id' => $linha->id, 'quantidade' => 2, 'remover' => true, 'motivo' => 'Isenção financeira']];
        $this->postJson('/agente/confirmar', $body)->assertOk()->assertJsonPath('tipo', 'resultado_escrita');
        $this->assertSame(15000, $c->fresh()->total_centavos);
        $this->assertTrue($linha->fresh()->excluido_financeiramente);
        $this->assertSame(2, $linha->fresh()->quantidade);
        $this->assertTrue($linha->fresh()->baixou_estoque);
        $this->assertSame(8, $produto->fresh()->estoque);
        $this->assertSame($antes, [$prontuario->fresh()->toArray(), $prontuario->itens()->get()->toArray()]);
        $log = AuditLog::where('tool', 'editar_cobranca')->where('resultado', 'sucesso')->sole();
        $this->assertSame(57000, $log->valores_anteriores['total_centavos']);
        $this->assertSame(15000, $log->parametros['valores_novos']['total_centavos']);
        $this->assertSame('Isenção financeira', $log->parametros['linhas'][0]['motivo']);
        $this->assertSame(auth()->id(), $log->user_id);
        $this->assertNotNull($log->created_at);
        $service->cancelar($c->id, self::T);
        $nova = $service->cobrarAtendimento($agenda->id, self::T);
        $this->assertSame(57000, $nova->total_centavos);
        $this->assertSame(8, $produto->fresh()->estoque);
    }

    public function test_quantidades_inclusao_remocao_e_estoque_so_uma_vez(): void
    {
        $c = $this->cobranca();
        $produto = $this->produto(['setor' => 'clinica', 'categoria' => 'farmacia']);
        $service = app(CobrancaService::class);
        $service->adicionarItem($c->id, $produto->id, 2, self::T);
        $linha = $c->itens()->where('catalogo_item_id', $produto->id)->sole();
        $body = $this->card($c);
        $body['valores']['linhas'] = [['item_id' => $linha->id, 'quantidade' => 3, 'remover' => false]];
        $this->postJson('/agente/confirmar', $body)->assertOk();
        $this->assertSame(78000, $c->fresh()->total_centavos);
        $this->assertSame(7, $produto->fresh()->estoque);
        $service->fechar($c->id, self::T);
        $body = $this->card($c);
        $body['valores']['linhas'] = [['item_id' => $linha->id, 'quantidade' => 3, 'remover' => true, 'motivo' => 'Produto retirado']];
        $novo = $this->item(['preco_centavos' => 1000]);
        $body['valores']['novos'] = [['catalogo_item_id' => $novo->id, 'quantidade' => 2]];
        $this->postJson('/agente/confirmar', $body)->assertOk();
        $this->assertSame(17000, $c->fresh()->total_centavos);
        $this->assertSame(10, $produto->fresh()->estoque);
        $this->assertContains($this->postJson('/agente/confirmar', $body)->status(), [409, 422]);
        $service->cancelar($c->id, self::T);
        $this->assertSame(10, $produto->fresh()->estoque);
    }

    public function test_motivo_vazio_e_cobranca_vazia_nao_gravam(): void
    {
        $c = $this->cobranca();
        foreach (['', str_repeat('x', 201), 'Retirar tudo'] as $motivo) {
            $body = $this->card($c);
            $body['valores']['linhas'] = [['item_id' => $c->itens()->sole()->id, 'quantidade' => 1, 'remover' => true, 'motivo' => $motivo]];
            $this->postJson('/agente/confirmar', $body)->assertStatus(422);
            $this->assertFalse($c->itens()->sole()->excluido_financeiramente);
            $this->assertSame(15000, $c->fresh()->total_centavos);
        }
    }

    public function test_estado_papel_tenant_e_card_antigo_sao_revalidados(): void
    {
        $c = $this->cobranca();
        $body = $this->card($c);
        app(CobrancaService::class)->adicionarItem($c->id, $this->item()->id, 1, self::T);
        $this->postJson('/agente/confirmar', $body)->assertStatus(422);
        $body = $this->card($c);
        auth()->user()->forceFill(['papel' => 'vendedor'])->save();
        $this->postJson('/agente/confirmar', $body)->assertForbidden();
        $this->logarComo('dono', self::OUTRO_TENANT);
        config(['sentinel.tenants_clinica' => [self::T, self::OUTRO_TENANT]]);
        $this->postJson('/agente/formulario', ['tool' => 'editar_cobranca', 'id' => $c->id])->assertStatus(422);
        $this->logarComo('dono', self::T);
        foreach (['paga', 'cancelada'] as $status) {
            $body = $this->card($c);
            $c->update(['status' => $status]);
            $this->postJson('/agente/confirmar', $body)->assertStatus(422);
            $c->update(['status' => 'aberta']);
        }
    }

    public function test_editor_trava_pai_e_linhas(): void
    {
        $c = $this->cobranca();
        $body = $this->card($c);
        $this->mostrarTravas();
        $sqls = $this->leiturasTravadas(fn () => app(CobrancaService::class)->editarItens($c->id, $body['valores'], self::T));
        $this->assertTravouLeitura($sqls, 'cobrancas');
        $this->assertTravouLeitura($sqls, 'cobranca_itens');
    }

    public function test_estorno_ignora_linhas_excluidas_e_edicao_nao_muda_preco_copiado(): void
    {
        $c = $this->cobranca();
        $service = app(CobrancaService::class);
        $produto = $this->produto(['setor' => 'clinica', 'categoria' => 'farmacia']);
        $service->adicionarItem($c->id, $produto->id, 1, self::T);
        $linha = $c->itens()->where('catalogo_item_id', $produto->id)->sole();
        $body = $this->card($c);
        $body['valores']['linhas'] = [['item_id' => $linha->id, 'quantidade' => 1, 'remover' => true, 'motivo' => 'Retirado']];
        $this->postJson('/agente/confirmar', $body)->assertOk();
        $servico = $c->itens()->whereNull('prontuario_item_id')->where('excluido_financeiramente', false)->sole();
        \App\Models\CatalogoItem::find($servico->catalogo_item_id)->update(['preco_centavos' => 1]);
        $body = $this->card($c);
        $body['valores']['linhas'] = [['item_id' => $servico->id, 'quantidade' => 2, 'remover' => false]];
        $this->postJson('/agente/confirmar', $body)->assertOk();
        $this->assertSame(30000, $c->fresh()->total_centavos);
        $service->fechar($c->id, self::T);
        $service->registrarPagamento($c->id, 'pix', self::T);
        $plano = $service->planoDoEstorno($c->id, 'tudo', [], self::T);
        $this->assertSame(30000, $plano['valor_centavos']);
        $this->assertCount(1, $service->linhasParaEstornar($c->id, self::T));
        $this->assertSame(10, $produto->fresh()->estoque);
        $data = \Carbon\CarbonImmutable::now();
        $resumo = app(\App\Services\ResultadosClinica::class)->resumo(self::T, $data->startOfDay(), $data->endOfDay());
        $this->assertCount(1, $resumo['top_itens']);
        $this->assertSame(30000, $resumo['top_itens'][0]['total']);
    }

    public function test_erro_de_tenant_na_inclusao_desfaz_remocao_e_estoque(): void
    {
        $c = $this->cobranca();
        $produto = $this->produto(['setor' => 'clinica', 'categoria' => 'farmacia']);
        app(CobrancaService::class)->adicionarItem($c->id, $produto->id, 1, self::T);
        $linha = $c->itens()->where('catalogo_item_id', $produto->id)->sole();
        $body = $this->card($c);
        $body['valores']['linhas'] = [['item_id' => $linha->id, 'quantidade' => 1, 'remover' => true, 'motivo' => 'Retirado']];
        $body['valores']['novos'] = [['catalogo_item_id' => $this->item(tenant: self::OUTRO_TENANT)->id, 'quantidade' => 1]];
        $this->postJson('/agente/confirmar', $body)->assertStatus(422);
        $this->assertFalse($linha->fresh()->excluido_financeiramente);
        $this->assertSame(9, $produto->fresh()->estoque);
    }

    public function test_tela_e_chat_expoem_editor_so_para_cobranca_elegivel(): void
    {
        $c = $this->cobranca();
        $this->comando('editar_cobranca', ['id' => $c->id])->assertOk()->assertJsonPath('passo_unico', true)
            ->assertJsonStructure(['formulario' => ['edicao_cobranca' => ['linhas', 'catalogo', 'versao']]]);
        config(['sentinel.front' => 'react']);
        $this->get('/cobrancas')->assertOk()->assertInertia(fn ($p) => $p->component('Financeiro', false)
            ->where('acoes.aberta.0.tool', 'editar_cobranca')->where('acoes.a receber.0.tool', 'editar_cobranca'));
    }

    public function test_falha_na_auditoria_desfaz_edicao_e_reposicao(): void
    {
        $c = $this->cobranca();
        $produto = $this->produto(['setor' => 'clinica', 'categoria' => 'farmacia']);
        app(CobrancaService::class)->adicionarItem($c->id, $produto->id, 1, self::T);
        $linha = $c->itens()->where('catalogo_item_id', $produto->id)->sole();
        $body = $this->card($c);
        $body['valores']['linhas'] = [['item_id' => $linha->id, 'quantidade' => 1, 'remover' => true, 'motivo' => 'Retirado']];
        AuditLog::creating(function (AuditLog $log): void {
            if ($log->tool === 'editar_cobranca' && $log->resultado === 'sucesso') {
                throw new \RuntimeException('Falha simulada de auditoria');
            }
        });
        $this->postJson('/agente/confirmar', $body)->assertJsonPath('tipo', 'erro');
        $this->assertFalse($linha->fresh()->excluido_financeiramente);
        $this->assertSame(9, $produto->fresh()->estoque);
        $this->assertSame(36000, $c->fresh()->total_centavos);
    }
}
