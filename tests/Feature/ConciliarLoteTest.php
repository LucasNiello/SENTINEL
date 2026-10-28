<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Lancamento;
use App\Services\AuditoriaService;
use App\Services\ConfirmacoesPendentes;
use App\Services\LancamentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * RF03: conciliar_lancamentos_em_lote. O servidor resolve o filtro uma vez, no tenant,
 * e guarda os ids no rascunho; zero ou mais de 25 pendentes não abrem plano.
 */
class ConciliarLoteTest extends TestCase
{
    use RefreshDatabase;

    private const FILTRO = ['data_inicio' => '2026-09-01', 'data_fim' => '2026-09-30'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.azure_foundry' => ['endpoint' => 'https://foundry.test', 'api_key' => 'k', 'deployment' => 'd']]);
        $this->logarComo('operador', 1);
    }

    private function pedirLote(array $argumentos = self::FILTRO)
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'conciliar_lancamentos_em_lote', 'arguments' => json_encode((object) $argumentos)]],
        ]]]]])]);

        return $this->postJson('/agente/comando', ['mensagem' => 'concilie os pendentes de setembro']);
    }

    private function lancamento(string $data, string $status = 'pendente', int $tenant = 1): Lancamento
    {
        return Lancamento::create(['descricao' => "L {$data}", 'valor' => 10, 'data' => $data, 'status' => $status, 'tenant_id' => $tenant]);
    }

    public function test_rascunho_guarda_so_os_ids_pendentes_do_periodo_no_tenant(): void
    {
        $segundo = $this->lancamento('2026-09-20');
        $primeiro = $this->lancamento('2026-09-05');
        $this->lancamento('2026-09-10', 'conciliado');
        $this->lancamento('2026-10-01');
        $this->lancamento('2026-09-12', 'pendente', 2);

        $proposta = $this->pedirLote()->assertOk()
            ->assertJsonPath('tipo', 'confirmacao_pendente')
            ->assertJsonMissingPath('lote_ids')
            ->json('proposta');

        $this->assertSame([$primeiro->id, $segundo->id], session("confirmacoes.{$proposta}.lote_ids"));
    }

    public function test_nenhum_pendente_responde_texto_sem_rascunho(): void
    {
        $this->lancamento('2026-09-10', 'conciliado');

        $this->pedirLote()->assertOk()->assertJsonPath('tipo', 'texto');

        $this->assertSame([], session('confirmacoes', []));
    }

    public function test_mais_de_25_pendentes_recusa_sem_truncar(): void
    {
        foreach (range(1, 26) as $dia) {
            $this->lancamento(sprintf('2026-09-%02d', min($dia, 30)));
        }

        $this->pedirLote()->assertOk()->assertJsonPath('tipo', 'texto')
            ->assertJsonPath('mensagem', fn (string $m) => str_contains($m, 'Restrinja'));

        $this->assertSame([], session('confirmacoes', []));
    }

    public function test_formulario_sem_ia_nao_abre_lote(): void
    {
        $this->postJson('/agente/formulario', ['tool' => 'conciliar_lancamentos_em_lote'])->assertStatus(422);

        $this->assertSame([], session('confirmacoes', []));
    }

    // ---- Fase C/D: Revisar, segurar e Confirmar ----

    /** Pede, revisa e devolve [proposta, token]. */
    private function revisarLote(array $valores = []): array
    {
        $proposta = $this->pedirLote()->assertOk()->json('proposta');
        $token = $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => $valores])->assertOk()->json('token');

        return [$proposta, $token];
    }

    private function confirmar(string $token, array $extra = [])
    {
        $this->travel(ConfirmacoesPendentes::ESPERA_MINIMA_S + 1)->seconds();

        return $this->postJson('/agente/confirmar', ['token' => $token, ...$extra]);
    }

    private function linhasDoLote()
    {
        return AuditLog::query()->where('tool', 'conciliar_lancamentos_em_lote')->whereNotIn('resultado', [AuditoriaService::REVISAO_COM_ERRO])->orderBy('id')->get();
    }

    public function test_sucesso_concilia_todos_e_audita_uma_linha_por_item_com_o_mesmo_lote_id(): void
    {
        $a = $this->lancamento('2026-09-05');
        $b = $this->lancamento('2026-09-20');
        [, $token] = $this->revisarLote();

        $this->confirmar($token)->assertOk()
            ->assertJsonPath('tipo', 'resultado_escrita')
            ->assertJsonPath('mensagem', '2 lançamentos conciliados.');

        $this->assertSame(['conciliado', 'conciliado'], [$a->fresh()->status, $b->fresh()->status]);
        $linhas = $this->linhasDoLote();
        $this->assertSame([$a->id, $b->id], $linhas->pluck('entidade_id')->all());
        $this->assertSame(['sucesso', 'sucesso'], $linhas->pluck('resultado')->all());
        $this->assertSame(['atualizar'], $linhas->pluck('acao')->unique()->values()->all());
        $this->assertCount(1, $linhas->pluck('lote_id')->unique());
        $this->assertTrue(Str::isUuid($linhas->first()->lote_id));
        $this->assertSame(['status' => 'pendente'], $linhas->first()->valores_anteriores);
        $this->assertSame('conciliado', $linhas->first()->parametros['novo_status']);
        // Fora de lote, lote_id continua nulo.
        $this->assertSame(0, AuditLog::query()->where('tool', '!=', 'conciliar_lancamentos_em_lote')->whereNotNull('lote_id')->count());
    }

    public function test_card_monta_a_lista_no_servidor_sem_campo_editavel(): void
    {
        $this->lancamento('2026-09-05');

        $this->pedirLote()->assertOk()
            ->assertJsonPath('formulario.campos', [])
            ->assertJsonPath('formulario.itens.0.descricao', 'L 2026-09-05')
            ->assertJsonPath('formulario.itens.0.valor', 'R$ 10,00')
            ->assertJsonPath('formulario.itens.0.antes', 'pendente')
            ->assertJsonPath('formulario.itens.0.depois', 'conciliado');
    }

    public function test_valores_e_ids_do_navegador_sao_ignorados_no_revisar_e_no_confirmar(): void
    {
        $doLote = $this->lancamento('2026-09-05');
        $fora = $this->lancamento('2026-08-10');
        [$proposta, $token] = $this->revisarLote(['data_inicio' => '2026-01-01', 'ids' => [$fora->id]]);

        $this->assertSame(['data_inicio' => '2026-09-01', 'data_fim' => '2026-09-30'], session("confirmacoes.{$token}.argumentos"));
        $this->assertSame([$doLote->id], session("confirmacoes.{$token}.lote_ids"));

        $this->confirmar($token, ['ids' => [$fora->id], 'valores' => ['data_inicio' => '2026-01-01']])->assertOk();

        $this->assertSame('conciliado', $doLote->fresh()->status);
        $this->assertSame('pendente', $fora->fresh()->status);
        $this->assertSame([$doLote->id], $this->linhasDoLote()->pluck('entidade_id')->all());
    }

    public function test_item_invalido_no_revisar_volta_o_plano_inteiro_apontando_o_item(): void
    {
        $this->lancamento('2026-09-05');
        $mudou = $this->lancamento('2026-09-20');
        $proposta = $this->pedirLote()->json('proposta');
        $mudou->update(['status' => 'conciliado']);

        $this->postJson('/agente/revisar', ['proposta' => $proposta])->assertStatus(422)
            ->assertJsonMissingPath('token')
            ->assertJsonPath('formulario.itens.1.erro', "O lançamento #{$mudou->id} não está mais pendente.")
            ->assertJsonPath('formulario.itens.0.erro', null);

        // Não-final: o rascunho segue (sem token), e só a revisão com erro foi auditada.
        $this->assertNotNull(session("confirmacoes.{$proposta}"));
        $this->assertSame(1, AuditLog::query()->where('resultado', AuditoriaService::REVISAO_COM_ERRO)->count());
        $this->assertSame(0, Lancamento::query()->where('status', 'conciliado')->where('id', '!=', $mudou->id)->count());
    }

    public function test_item_que_muda_entre_revisar_e_confirmar_desfaz_o_lote_inteiro_com_uma_linha_so(): void
    {
        $a = $this->lancamento('2026-09-05');
        $b = $this->lancamento('2026-09-20');
        [, $token] = $this->revisarLote();
        $b->update(['status' => 'conciliado']); // outra aba

        $this->confirmar($token)->assertStatus(422)
            ->assertJsonPath('mensagem', fn (string $m) => str_contains($m, "#{$b->id}") && str_contains($m, 'Nada foi gravado'));

        $this->assertSame('pendente', $a->fresh()->status);
        $linha = $this->linhasDoLote()->sole();
        $this->assertSame('recusado', $linha->resultado);
        $this->assertNull($linha->lote_id);
        $this->assertNull($linha->entidade_id);
        $this->assertSame([$a->id, $b->id], $linha->parametros['ids']);
        $this->assertSame(["lote_{$b->id}" => "O lançamento #{$b->id} não está mais pendente."], $linha->parametros['motivos']);
    }

    public function test_falha_no_meio_da_gravacao_desfaz_o_lote_inteiro(): void
    {
        $a = $this->lancamento('2026-09-05');
        $b = $this->lancamento('2026-09-20');
        [, $token] = $this->revisarLote();
        $gravados = 0;
        Lancamento::updating(function () use (&$gravados) {
            if (++$gravados === 2) {
                throw new RuntimeException('falha simulada no 2º item');
            }
        });

        $this->confirmar($token)->assertStatus(500)->assertJsonPath('codigo', 'AI_TOOL_ERROR');

        $this->assertSame(2, $gravados);
        $this->assertSame(['pendente', 'pendente'], [$a->fresh()->status, $b->fresh()->status]);
        $linha = $this->linhasDoLote()->sole();
        $this->assertSame('erro', $linha->resultado);
        $this->assertSame([$a->id, $b->id], $linha->parametros['ids']);
    }

    public function test_id_de_outro_tenant_posto_na_sessao_e_recusado_no_service(): void
    {
        $meu = $this->lancamento('2026-09-05');
        $alheio = $this->lancamento('2026-09-06', 'pendente', 2);
        $proposta = $this->pedirLote()->json('proposta');
        // Segunda barreira: mesmo que a lista da sessão viesse adulterada, o Revisar e o Confirmar conferem o tenant.
        session()->put("confirmacoes.{$proposta}.lote_ids", [$meu->id, $alheio->id]);

        $this->postJson('/agente/revisar', ['proposta' => $proposta])->assertStatus(422)
            ->assertJsonPath('formulario.itens.1.erro', "O lançamento #{$alheio->id} não existe mais.");

        $token = $this->app->make(ConfirmacoesPendentes::class)->emitirToken(session()->driver(), $proposta, session("confirmacoes.{$proposta}"), session("confirmacoes.{$proposta}.argumentos"));
        $this->confirmar($token)->assertStatus(422);

        $this->assertSame(['pendente', 'pendente'], [$meu->fresh()->status, $alheio->fresh()->status]);
        $this->assertSame('recusado', $this->linhasDoLote()->sole()->resultado);
    }

    public function test_confirmar_le_os_itens_com_trava(): void
    {
        // O SQLite dos testes ignora FOR UPDATE; o que dá para provar aqui é que o Confirmar pede a leitura travada.
        $a = $this->lancamento('2026-09-05');
        [, $token] = $this->revisarLote();
        $this->partialMock(LancamentoService::class)->shouldReceive('doLote')
            ->once()->with([$a->id], 1, true)->passthru();

        $this->confirmar($token)->assertOk();
    }

    public function test_token_reutilizado_nao_grava_de_novo(): void
    {
        $this->lancamento('2026-09-05');
        [, $token] = $this->revisarLote();

        $this->confirmar($token)->assertOk();
        $this->confirmar($token)->assertStatus(422);

        $this->assertSame(1, $this->linhasDoLote()->count());
    }

    public function test_papel_leitura_e_negado_antes_de_resolver_o_filtro_com_uma_linha(): void
    {
        $this->logarComo('leitura', 1);
        $this->lancamento('2026-09-05');
        DB::enableQueryLog();

        $this->pedirLote()->assertStatus(403)->assertJsonPath('tipo', 'negado');

        $this->assertFalse(collect(DB::getQueryLog())->contains(fn ($q) => str_contains($q['query'], 'from "lancamentos"')));
        $this->assertSame('negado', $this->linhasDoLote()->sole()->resultado);
        $this->assertSame([], session('confirmacoes', []));
    }

    public function test_cancelado_deixa_uma_linha_e_nada_grava(): void
    {
        $a = $this->lancamento('2026-09-05');
        [, $token] = $this->revisarLote();

        $this->postJson('/agente/cancelar', ['token' => $token])->assertOk();

        $this->assertSame('cancelado', $this->linhasDoLote()->sole()->resultado);
        $this->assertSame('pendente', $a->fresh()->status);
    }

    public function test_expirado_deixa_uma_linha_e_nada_grava(): void
    {
        $a = $this->lancamento('2026-09-05');
        [, $token] = $this->revisarLote();
        $this->travel(ConfirmacoesPendentes::VALIDADE_MAXIMA_S + 1)->seconds();

        $this->postJson('/agente/confirmar', ['token' => $token])->assertStatus(422);
        $this->get('/agente')->assertOk(); // varredura de novo: não pode duplicar

        $this->assertSame('expirado', $this->linhasDoLote()->sole()->resultado);
        $this->assertSame('pendente', $a->fresh()->status);
    }
}
