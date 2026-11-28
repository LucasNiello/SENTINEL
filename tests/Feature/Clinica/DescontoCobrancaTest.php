<?php

namespace Tests\Feature\Clinica;

use App\Exceptions\RegraDeNegocioException;
use App\Models\AuditLog;
use App\Models\Cobranca;
use App\Models\Estorno;
use App\Models\EstornoItem;
use App\Services\CobrancaService;
use App\Services\ProntuarioService;
use App\Services\ResultadosClinica;
use App\Support\RateioCobranca;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\ProvaDeTravas;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

class DescontoCobrancaTest extends TestCase
{
    use CenarioClinica, ProvaDeTravas, RefreshDatabase, SimulaAgente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAgente();
        $this->logarComo('dono', self::T);
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }

    private function cobranca(int $preco = 15000): Cobranca
    {
        return app(CobrancaService::class)->abrir($this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item(['preco_centavos' => $preco]))->id, self::T);
    }

    private function card(Cobranca $c, string $tipo = 'reais', string $valor = '10,00', string $motivo = 'Acordo comercial'): array
    {
        $r = $this->postJson('/agente/formulario', ['tool' => 'editar_cobranca', 'id' => $c->id])->assertOk()->assertJsonPath('passo_unico', true);

        return ['proposta' => $r->json('proposta'), 'valores' => ['versao' => $r->json('formulario.edicao_cobranca.versao'), 'linhas' => [], 'novos' => [],
            'desconto_tipo' => $tipo, 'desconto_valor' => $valor, 'desconto_motivo' => $motivo]];
    }

    public function test_reais_percentual_previa_sem_gravacao_e_auditoria(): void
    {
        $c = $this->cobranca();
        $body = $this->card($c);
        $logs = AuditLog::count();
        $this->postJson('/agente/revisar?previa=1', $body)->assertOk()->assertJsonPath('formulario.edicao_cobranca.financeiro.total_centavos', 14000);
        $this->assertSame(15000, $c->fresh()->total_centavos);
        $this->assertSame($logs, AuditLog::count());
        $this->postJson('/agente/confirmar', $body)->assertOk();
        $this->assertSame(1000, $c->fresh()->desconto_centavos);
        $this->assertNull($c->fresh()->desconto_autorizado_por);
        $log = AuditLog::where('tool', 'editar_cobranca')->where('resultado', 'sucesso')->sole();
        $this->assertSame(0, $log->valores_anteriores['desconto_centavos']);
        $this->assertSame(1000, $log->parametros['valores_novos']['desconto_centavos']);
        $this->assertSame('Acordo comercial', $log->parametros['valores_novos']['desconto_motivo']);
        $this->assertSame(auth()->id(), $log->user_id);
        $this->assertContains($this->postJson('/agente/confirmar', $body)->status(), [409, 422]);
        app(CobrancaService::class)->fechar($c->id, self::T);
        $this->postJson('/agente/confirmar', $this->card($c, 'percentual', '10'))->assertOk();
        $this->assertSame(13500, $c->fresh()->total_centavos);
    }

    public function test_invalidos_limite_e_motivo_nao_alteram_cobranca(): void
    {
        $c = $this->cobranca();
        foreach ([['reais', '-1', 'Motivo'], ['reais', '15,01', 'Motivo'], ['reais', '151', 'Motivo'], ['percentual', '10,01', 'Motivo'],
            ['percentual', '101', 'Motivo'], ['percentual', 'abc', 'Motivo'], ['reais', '0.001', 'Motivo'], ['reais', '1', '   '], ['reais', '1', str_repeat('a', 201)], ['outro', '1', 'Motivo']] as [$tipo,$valor,$motivo]) {
            $this->postJson('/agente/confirmar', $this->card($c, $tipo, $valor, $motivo))->assertStatus(422);
            $this->assertSame(15000, $c->fresh()->total_centavos);
        }
        $r = $this->postJson('/agente/confirmar', $this->card($c, 'percentual', '11'));
        $this->assertStringContainsString('supervisor', $r->getContent());
        $this->postJson('/agente/confirmar', $this->card($c, 'reais', '0', ''))->assertOk();
        $this->assertNull($c->fresh()->desconto_motivo);
    }

    public function test_recalculo_apos_quantidade_e_recusa_de_desconto_fixo_acima_do_novo_teto(): void
    {
        $c = $this->cobranca();
        $b = $this->card($c, 'percentual', '10');
        $b['valores']['linhas'] = [['item_id' => $c->itens()->sole()->id, 'quantidade' => 2, 'remover' => false]];
        $this->postJson('/agente/confirmar', $b)->assertOk();
        $this->assertSame(27000, $c->fresh()->total_centavos);
        $b = $this->card($c, 'reais', '30');
        $b['valores']['linhas'] = [['item_id' => $c->itens()->sole()->id, 'quantidade' => 1, 'remover' => false]];
        $this->postJson('/agente/confirmar', $b)->assertStatus(422);
        $this->assertSame(2, $c->itens()->sole()->quantidade);
        $this->assertSame(27000, $c->fresh()->total_centavos);
        $b = $this->card($c, 'percentual', '10');
        $b['valores']['linhas'] = [['item_id' => $c->itens()->sole()->id, 'quantidade' => 1, 'remover' => false]];
        $this->postJson('/agente/confirmar', $b)->assertOk();
        $this->assertSame(13500, $c->fresh()->total_centavos);
    }

    public function test_estornos_proporcionais_parciais_sucessivos_fecham_exatamente_no_liquido(): void
    {
        $service = app(CobrancaService::class);
        $c = $this->cobranca(101);
        $produto = $this->produto(['setor' => 'clinica', 'categoria' => 'farmacia', 'preco_centavos' => 103]);
        $service->adicionarItem($c->id, $produto->id, 3, self::T);
        $this->postJson('/agente/confirmar', $this->card($c, 'percentual', '10'))->assertOk();
        $this->assertSame(369, $c->fresh()->total_centavos);
        $service->fechar($c->id, self::T);
        $service->registrarPagamento($c->id, 'pix', self::T);
        $dono = $this->comPin($this->usuarioClinica('dono'));
        $linha = $c->itens()->where('catalogo_item_id', $produto->id)->sole();
        $this->mostrarTravas();
        foreach ([92, 93, 93] as $valor) {
            $escolha = [['item_id' => $linha->id, 'quantidade' => 1]];
            $this->assertSame($valor, $service->planoDoEstorno($c->id, 'itens', $escolha, self::T)['valor_centavos']);
            $locks = $this->leiturasTravadas(fn () => $service->estornar($c->id, 'Devolução', $dono->id, $dono->id, self::T, true, 'itens', $escolha));
            $this->assertTravouLeitura($locks, 'cobrancas');
            $this->assertTravouLeitura($locks, 'cobranca_itens');
            $this->assertTravouLeitura($locks, 'estorno_itens');
            $this->assertLessThanOrEqual(369, Estorno::sum('valor_centavos'));
        }
        $this->assertSame(10, $produto->fresh()->estoque);
        $this->assertSame(91, $service->planoDoEstorno($c->id, 'tudo', [], self::T)['valor_centavos']);
        $service->estornar($c->id, 'Restante', $dono->id, $dono->id, self::T, true);
        $this->assertSame(369, (int) Estorno::sum('valor_centavos'));
        $this->assertSame(369, (int) EstornoItem::sum('valor_centavos'));
        $this->assertSame('cancelada', $c->fresh()->status);
        $this->expectException(RegraDeNegocioException::class);
        $service->estornar($c->id, 'Repetido', $dono->id, $dono->id, self::T, true);
    }

    public function test_centavos_pequenos_e_rateio_independente_da_ordem_e_sem_overflow(): void
    {
        $this->assertSame(922337203685477580, RateioCobranca::proporcao(PHP_INT_MAX, 1, 10));
        $this->assertSame(PHP_INT_MAX, RateioCobranca::proporcao(PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX));
        $this->assertSame([1 => 91, 2 => 278], RateioCobranca::liquidos([2 => 309, 1 => 101], 41));
        for ($total = 1; $total <= 101; $total++) {
            for ($q = 1; $q <= 7; $q++) {
                $soma = 0;
                for ($k = 0; $k < $q; $k++) {
                    $parcial = RateioCobranca::proporcao($total, $k + 1, $q) - RateioCobranca::proporcao($total, $k, $q);
                    $this->assertSame(intdiv($total * ($k + 1), $q), RateioCobranca::proporcao($total, $k + 1, $q));
                    $this->assertGreaterThanOrEqual(0, $parcial);
                    $soma += $parcial;
                }
                $this->assertSame($total, $soma);
            }
        }
        $c = $this->cobranca(9);
        $this->postJson('/agente/confirmar', $this->card($c, 'percentual', '10'))->assertOk();
        $this->assertSame(9, $c->fresh()->total_centavos);
    }

    public function test_cobrancas_e_resultados_mostram_total_liquido_com_alternancia(): void
    {
        $c = $this->cobranca();
        $this->postJson('/agente/confirmar', $this->card($c))->assertOk();
        $service = app(CobrancaService::class);
        $service->fechar($c->id, self::T);
        $service->registrarPagamento($c->id, 'pix', self::T);
        $r = app(ResultadosClinica::class)->resumo(self::T, CarbonImmutable::now()->startOfDay(), CarbonImmutable::now()->endOfDay());
        $this->assertSame(14000, $r['faturamento']);
        $this->assertSame(14000, $r['liquido']);
        $this->assertSame(14000, $r['top_itens'][0]['total']);
        config(['sentinel.front' => 'react']);
        $this->get('/cobrancas')->assertOk()->assertInertia(fn ($p) => $p->where('linhas.0.total', 'R$ 140,00')->where('linhas.0.desconto', 'R$ 10,00')->where('linhas.0.bruto', 'R$ 150,00'));
        $this->get('/resultados')->assertOk();
        config(['sentinel.front' => 'blade']);
        $this->get('/cobrancas')->assertOk()->assertSee('R$ 140,00');
        $this->get('/resultados')->assertOk()->assertSee('R$ 140,00');
    }

    public function test_card_antigo_permissoes_tenant_e_status_protegidos(): void
    {
        $c = $this->cobranca();
        $velho = $this->card($c);
        $this->postJson('/agente/confirmar', $this->card($c, 'reais', '5'))->assertOk();
        $this->postJson('/agente/confirmar', $velho)->assertStatus(422);
        $this->assertSame(500, $c->fresh()->desconto_centavos);
        $this->logarComo('dono', self::OUTRO_TENANT);
        $this->postJson('/agente/formulario', ['tool' => 'editar_cobranca', 'id' => $c->id])->assertStatus(422);
        $this->logarComo('veterinario', self::T);
        $this->postJson('/agente/formulario', ['tool' => 'editar_cobranca', 'id' => $c->id])->assertForbidden();
        $this->logarComo('dono', self::T);
        foreach (['paga', 'cancelada'] as $status) {
            $c->update(['status' => $status]);
            $this->postJson('/agente/formulario', ['tool' => 'editar_cobranca', 'id' => $c->id])->assertStatus(422);
        }
    }

    public function test_ia_nao_define_desconto_e_valores_monetarios_externos_sao_ignorados(): void
    {
        $c = $this->cobranca();
        $this->comando('editar_cobranca', ['id' => $c->id, 'desconto_tipo' => 'reais', 'desconto_valor' => '10', 'desconto_motivo' => 'IA'])->assertStatus(502);
        $r = $this->comando('editar_cobranca', ['id' => $c->id])->assertOk();
        $editor = $r->json('formulario.edicao_cobranca');
        $this->postJson('/agente/confirmar', ['proposta' => $r->json('proposta'), 'valores' => ['versao' => $editor['versao'], 'linhas' => [], 'novos' => [],
            'total_centavos' => 1, 'desconto_centavos' => 1000, 'desconto_autorizado_por' => auth()->id()]])->assertOk();
        $this->assertSame(15000, $c->fresh()->total_centavos);
        $this->assertSame(0, $c->fresh()->desconto_centavos);
        $this->assertNull($c->fresh()->desconto_autorizado_por);
    }

    public function test_historico_incompativel_recusa_estorno_sem_gravar(): void
    {
        $c = $this->cobranca();
        $service = app(CobrancaService::class);
        $this->postJson('/agente/confirmar', $this->card($c))->assertOk();
        $service->fechar($c->id, self::T);
        $service->registrarPagamento($c->id, 'pix', self::T);
        $dono = $this->comPin($this->usuarioClinica('dono'));
        $e = Estorno::create(['cobranca_id' => $c->id, 'solicitado_por' => $dono->id, 'autorizado_por' => $dono->id, 'motivo' => 'Histórico inconsistente de teste', 'valor_centavos' => 1, 'tenant_id' => self::T]);
        EstornoItem::create(['estorno_id' => $e->id, 'item_id' => $c->itens()->sole()->id, 'quantidade' => 0, 'valor_centavos' => 1, 'devolveu_estoque' => false]);
        try {
            $service->estornar($c->id, 'Recusar', $dono->id, $dono->id, self::T, true);
            $this->fail('Deveria recusar');
        } catch (RegraDeNegocioException $e) {
            $this->assertStringContainsString('Histórico', $e->getMessage());
        }
        $this->assertSame(1, Estorno::count());
        $this->assertSame('paga', $c->fresh()->status);
    }

    public function test_desconto_e_estorno_clinico_preservam_prontuario_e_estoque(): void
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
        $this->postJson('/agente/confirmar', $this->card($c, 'percentual', '10'))->assertOk();
        $this->assertSame(51300, $c->fresh()->total_centavos);
        $service->registrarPagamento($c->id, 'pix', self::T);
        $dono = $this->comPin($this->usuarioClinica('dono'));
        $linha = $c->itens()->whereNotNull('prontuario_item_id')->sole();
        $escolha = [['item_id' => $linha->id, 'quantidade' => 1]];
        $this->assertSame(18900, $service->planoDoEstorno($c->id, 'itens', $escolha, self::T)['valor_centavos']);
        $service->estornar($c->id, 'Parcial clínico', $dono->id, $dono->id, self::T, true, 'itens', $escolha);
        $this->assertSame(8, $produto->fresh()->estoque);
        $this->assertSame($antes, [$prontuario->fresh()->toArray(), $prontuario->itens()->get()->toArray()]);
        $this->assertSame($prontuario->itens()->sole()->id, $linha->fresh()->prontuario_item_id);
    }

    public function test_falha_auditoria_desfaz_desconto(): void
    {
        $c = $this->cobranca();
        $b = $this->card($c);
        AuditLog::creating(function (AuditLog $log) {
            if ($log->tool === 'editar_cobranca' && $log->resultado === 'sucesso') {
                throw new \RuntimeException('Falha simulada');
            }
        });
        $this->postJson('/agente/confirmar', $b)->assertJsonPath('tipo', 'erro');
        $this->assertSame(0, $c->fresh()->desconto_centavos);
        $this->assertSame(15000, $c->fresh()->total_centavos);
    }

    public function test_migration_aditiva_preserva_registros_existentes(): void
    {
        $c = $this->cobranca();
        $itens = $c->itens()->get()->toArray();
        $migration = require database_path('migrations/2026_11_28_200000_add_desconto_to_cobrancas.php');
        // Apenas SQLite :memory: (conferido no setUp), nunca o MySQL local.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        $migration->down();
        $antes = $c->fresh()->toArray();
        $migration->up();
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $depois = $c->fresh()->toArray();
        $this->assertSame($antes,array_diff_key($depois,array_flip(['desconto_centavos', 'desconto_motivo', 'desconto_autorizado_por'])));
        $this->assertSame(0,$depois['desconto_centavos']);
        $this->assertNull($depois['desconto_motivo']);
        $this->assertNull($depois['desconto_autorizado_por']);
        $this->assertSame($itens,$c->itens()->get()->toArray());
    }
}
