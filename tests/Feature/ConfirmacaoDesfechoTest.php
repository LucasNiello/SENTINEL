<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\Lancamento;
use App\Services\AuditoriaService;
use App\Services\ClienteService;
use App\Services\ConfirmacoesPendentes;
use App\Services\ContextoUsuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Bloco 1 (Fase 4, Tarefa 2): toda proposta de escrita tem um desfecho só —
 * confirmada, cancelada ou expirada. O desfecho fica em audit_logs com o que a
 * IA propôs (mascarado) e a proposta sai da sessão (R3). Anthropic sempre simulada.
 */
class ConfirmacaoDesfechoTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    private const LANCAMENTO = ['descricao' => 'Café', 'valor' => 12.5, 'data' => '2026-09-21'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararAgente();
        $this->logarComo('operador', 1);
    }

    private function cancelar(string $token, array $corpoExtra = [])
    {
        return $this->postJson('/agente/cancelar', ['token' => $token] + $corpoExtra);
    }

    /** @return string[] */
    private function resultadosAuditados(): array
    {
        return AuditLog::query()->orderBy('id')->pluck('resultado')->all();
    }

    // ---- cancelar ----

    public function test_cancelar_audita_cancelado_com_o_proposto_e_tira_a_proposta_da_sessao(): void
    {
        $token = $this->propor(self::LANCAMENTO);
        $this->travel(3)->seconds();

        $this->cancelar($token)
            ->assertOk()
            ->assertJsonPath('tipo', 'cancelado')
            ->assertJsonPath('mensagem', 'Tudo bem, cancelei o pedido. Não alterei nada.');

        $log = AuditLog::query()->sole();
        $this->assertSame('criar_lancamento', $log->tool);
        $this->assertSame('criar', $log->acao);
        $this->assertSame('Lancamento', $log->entidade_tipo);
        $this->assertNull($log->entidade_id);
        $this->assertSame('cancelado', $log->resultado);
        $this->assertSame('operador', $log->papel);
        $this->assertTrue($log->permitido);
        $this->assertSame(1, $log->tenant_id);
        $this->assertSame([], $log->parametros, 'nada foi confirmado');
        $this->assertEquals(self::LANCAMENTO, $log->parametros_propostos, 'o que a IA propôs fica registrado');

        $this->assertEmpty(session('confirmacoes', []), 'R3: a proposta saiu da sessão');
        $this->assertSame(0, Lancamento::count());

        // Uso único: o mesmo token não cancela de novo nem confirma.
        $this->cancelar($token)->assertStatus(422);
        $this->postJson('/agente/confirmar', ['token' => $token])->assertStatus(422);
        $this->assertSame(['cancelado'], $this->resultadosAuditados());
        $this->assertSame(0, Lancamento::count());
    }

    public function test_cancelar_e_sempre_cancelado_quem_decide_e_o_servidor_nao_o_corpo(): void
    {
        // O corpo dizendo "expirado" não muda nada: foi o usuário.
        $token = $this->propor(self::LANCAMENTO);
        $this->travel(3)->seconds();
        $this->cancelar($token, ['tipo' => 'expirado', 'resultado' => 'expirado', 'emitido_em' => 0])
            ->assertOk()
            ->assertJsonPath('tipo', 'cancelado');

        // 25/11 (Lucas): a proposta não vence. Muito depois dos antigos 5 min, encerrar continua sendo cancelamento.
        $token = $this->propor(self::LANCAMENTO);
        $this->travel(ConfirmacoesPendentes::PRAZO_RASCUNHO_S + 1)->seconds();
        $this->cancelar($token, ['tipo' => 'expirado', 'resultado' => 'expirado'])
            ->assertOk()
            ->assertJsonPath('tipo', 'cancelado');

        $this->assertSame(['cancelado', 'cancelado'], $this->resultadosAuditados());
    }

    public function test_proposta_nao_vence_cancelar_e_confirmar_valem_horas_depois(): void
    {
        // 25/11 (Lucas): quem sai da mesa enquanto o agente pensa não precisa pedir de novo. Sem prazo para cancelar
        // nem para confirmar; o token continua de uso único.
        $token = $this->propor(self::LANCAMENTO);
        $this->travel(240)->seconds();
        $this->cancelar($token)->assertOk()->assertJsonPath('tipo', 'cancelado');

        $outro = $this->propor(self::LANCAMENTO);
        $this->travel(240)->seconds();
        $this->postJson('/agente/confirmar', ['token' => $outro])->assertOk();

        $tarde = $this->propor(self::LANCAMENTO);
        $this->travel(2)->hours();
        $this->cancelar($tarde)->assertOk()->assertJsonPath('tipo', 'cancelado');

        $muitoTarde = $this->propor(self::LANCAMENTO);
        $this->travel(2)->hours();
        $this->postJson('/agente/confirmar', ['token' => $muitoTarde])->assertOk();

        $this->assertSame(['cancelado', 'sucesso', 'cancelado', 'sucesso'], $this->resultadosAuditados());
        $this->assertSame(2, Lancamento::count());
    }

    public function test_cancelar_depois_de_confirmar_nao_tem_efeito_nem_nova_auditoria(): void
    {
        $token = $this->propor(self::LANCAMENTO);
        $this->confirmar($token)->assertOk();

        $this->cancelar($token)
            ->assertStatus(422)
            ->assertJsonPath('tipo', 'erro')
            ->assertJsonPath('categoria', 'validation_error')
            ->assertJsonPath('codigo', 'AI_CONFIRMATION_INVALID')
            ->assertJsonPath('http_status', 422);

        $this->assertSame(['sucesso'], $this->resultadosAuditados());
        $this->assertSame(1, Lancamento::count());
    }

    public function test_m1_confirmar_e_cancelar_simultaneos_so_o_primeiro_desfecho_vale(): void
    {
        // Cancelar chega com uma cópia da sessão lida antes de a confirmação consumir o token.
        $token = $this->propor(self::LANCAMENTO);
        $this->travel(2)->seconds();
        $entrada = session("confirmacoes.{$token}");
        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();

        $this->withSession(['confirmacoes' => [$token => $entrada]])
            ->postJson('/agente/cancelar', ['token' => $token])
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'AI_CONFIRMATION_USED')
            ->assertJsonPath('http_status', 409);

        // O inverso: a confirmação chega depois de um cancelamento.
        $outro = $this->propor(self::LANCAMENTO);
        $this->travel(3)->seconds();
        $entrada = session("confirmacoes.{$outro}");
        $this->cancelar($outro)->assertOk();

        $this->withSession(['confirmacoes' => [$outro => $entrada]])
            ->postJson('/agente/confirmar', ['token' => $outro])
            ->assertStatus(409);

        $this->assertSame(['sucesso', 'cancelado'], $this->resultadosAuditados());
        $this->assertSame(1, Lancamento::count(), 'o cancelado não gravou');
    }

    public function test_cancelar_recusa_token_ausente_malformado_inexistente_ou_de_outra_sessao(): void
    {
        foreach ([[], ['token' => 'abc'], ['token' => ['x']], ['token' => 'a.b'], ['token' => (string) Str::uuid()]] as $corpo) {
            $this->postJson('/agente/cancelar', $corpo)
                ->assertStatus(422)
                ->assertJsonPath('tipo', 'erro')
                ->assertJsonPath('codigo', 'AI_CONFIRMATION_INVALID');
        }

        // Token emitido para outra sessão (outro usuário) não existe nesta.
        $token = $this->propor(self::LANCAMENTO);
        $this->flushSession();
        $this->logarComo('operador', 1);
        $this->travel(3)->seconds();
        $this->cancelar($token)->assertStatus(422);

        $this->assertSame(0, AuditLog::count());
    }

    public function test_cancelar_exige_login(): void
    {
        $token = $this->propor(self::LANCAMENTO);
        Auth::logout();

        $this->cancelar($token)->assertUnauthorized();
        $this->assertSame(0, AuditLog::count());
        $this->assertNotNull(session("confirmacoes.{$token}"), 'barrado antes de chegar à ação: nada foi retirado da sessão');
    }

    public function test_cancelar_tem_limite_de_taxa_no_contrato_de_erro(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->cancelar((string) Str::uuid())->assertStatus(422);
        }

        $this->cancelar((string) Str::uuid())
            ->assertStatus(429)
            ->assertJsonPath('tipo', 'erro')
            ->assertJsonPath('categoria', 'rate_limit')
            ->assertJsonPath('http_status', 429);
    }

    // ---- confirmar muito depois (25/11: sem prazo) ----

    public function test_confirmar_muito_depois_da_proposta_grava_uma_vez(): void
    {
        $token = $this->propor(self::LANCAMENTO);
        $this->travel(ConfirmacoesPendentes::PRAZO_RASCUNHO_S + 1)->seconds();

        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('sucesso', $log->resultado);
        $this->assertSame('criar_lancamento', $log->tool);
        $this->assertEmpty(session('confirmacoes', []), 'R3: saiu da sessão');
        $this->assertSame(1, Lancamento::count());
        $this->postJson('/agente/confirmar', ['token' => $token])->assertStatus(422);
        $this->assertSame(1, Lancamento::count());
    }

    public function test_confirmar_sem_espera_grava_uma_vez_e_queima_o_token(): void
    {
        // Decisão B (13/11): sem espera mínima. Confirmar no mesmo instante do Revisar grava (um clique).
        $token = $this->propor(self::LANCAMENTO);

        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('sucesso', $log->resultado);

        // O token continua de uso único: a segunda confirmação não grava nem audita de novo.
        $this->postJson('/agente/confirmar', ['token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Confirmação inválida ou já utilizada.');
        $this->assertSame(1, Lancamento::count());
        $this->assertSame(1, AuditLog::count());
    }

    // ---- varredura das vencidas ----

    public function test_proposta_esquecida_nao_vence_na_requisicao_seguinte(): void
    {
        // 25/11 (Lucas): a varredura continua rodando, mas nenhuma proposta passa do prazo.
        $esquecida = $this->propor(self::LANCAMENTO);
        $this->travel(ConfirmacoesPendentes::PRAZO_RASCUNHO_S + 1)->seconds();

        $this->postJson('/agente/consultar', ['tool' => 'consultar_lancamentos'])->assertOk();

        $this->assertSame(0, AuditLog::query()->where('resultado', 'expirado')->count());
        $this->assertNotNull(session("confirmacoes.{$esquecida}"), 'continua valendo');
        $this->postJson('/agente/confirmar', ['token' => $esquecida])->assertOk();
        $this->assertSame(1, Lancamento::count());
    }

    public function test_varredura_nao_mexe_na_proposta_ainda_valida(): void
    {
        $token = $this->propor(self::LANCAMENTO);
        $this->travel(240)->seconds(); // muito além dos antigos 12 s (25/11: e sem prazo nenhum)

        // Consulta manual (/agente/consultar): passa pela varredura, mas não é um novo pedido ao agente. Um pedido novo
        // (/agente/comando) descartaria a proposta (23/11), coberto em CicloDaPendenciaTest.
        $this->postJson('/agente/consultar', ['tool' => 'consultar_lancamentos'])->assertOk();

        $this->assertNotNull(session("confirmacoes.{$token}"));
        $this->assertSame(0, AuditLog::query()->where('resultado', 'expirado')->count());

        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();
        $this->assertSame(1, Lancamento::count());
    }

    public function test_abrir_a_tela_do_agente_nao_vence_a_proposta(): void
    {
        $token = $this->propor(self::LANCAMENTO);
        $this->travel(ConfirmacoesPendentes::PRAZO_RASCUNHO_S + 1)->seconds();

        $this->get('/agente')->assertOk();

        $this->assertSame([], $this->resultadosAuditados());
        $this->assertNotNull(session("confirmacoes.{$token}"));
    }

    public function test_varredura_nao_audita_de_novo_proposta_ja_confirmada_que_voltou_para_a_sessao(): void
    {
        $token = $this->propor(self::LANCAMENTO);
        $this->travel(2)->seconds();
        $entrada = session("confirmacoes.{$token}");
        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();

        // Uma requisição concorrente regravou a sessão com a proposta já confirmada.
        $this->withSession(['confirmacoes' => [$token => $entrada]]);
        $this->travel(3)->hours(); // além do TTL antigo da marca (sessão, 120 min): prova os 30 dias de DIAS_DA_MARCA_DE_DESFECHO

        $this->get('/agente')->assertOk();

        $this->assertSame(['sucesso'], $this->resultadosAuditados(), 'confirmada não vira expirada');
        // 25/11: sem varredura por prazo, a cópia ressuscitada pode ficar na sessão, mas a marca de desfecho único barra
        // uma segunda gravação.
        $this->postJson('/agente/confirmar', ['token' => $token])->assertStatus(409);
        $this->assertSame(['sucesso'], $this->resultadosAuditados());
        $this->assertSame(1, Lancamento::count());
    }

    public function test_sair_com_card_aberto_audita_o_desfecho_em_nome_de_quem_saiu(): void
    {
        $this->propor(self::LANCAMENTO);
        $usuarioId = Auth::id();
        $this->travel(240)->seconds(); // sair é cancelamento (25/11: em qualquer tempo)

        $this->post('/logout')->assertRedirect('/login');

        $log = AuditLog::query()->sole();
        $this->assertSame('cancelado', $log->resultado);
        $this->assertSame('Encerrada ao sair do sistema. Nada foi gravado.', $log->mensagem);
        $this->assertSame($usuarioId, $log->user_id, 'auditada em nome de quem saiu');
        $this->assertEquals(self::LANCAMENTO, $log->parametros_propostos);
        $this->assertGuest();
    }

    public function test_sair_muito_depois_da_proposta_audita_cancelado(): void
    {
        $this->propor(self::LANCAMENTO);
        $this->travel(5)->seconds();
        $this->get('/agente')->assertOk(); // recarregou a tela: a proposta ainda vale e o card sumiu
        $this->travel(ConfirmacoesPendentes::PRAZO_RASCUNHO_S)->seconds(); // 25/11: sem prazo, continua valendo

        $this->post('/logout')->assertRedirect('/login');

        $this->assertSame(['cancelado'], $this->resultadosAuditados());
        $this->assertGuest();
    }

    public function test_sair_nunca_falha_mesmo_sem_poder_auditar(): void
    {
        $usuario = $this->logarComo('operador', 1);
        $this->propor(self::LANCAMENTO);
        $usuario->forceFill(['papel' => null])->save(); // papel apagado no banco no meio da sessão

        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame(0, AuditLog::count());
    }

    public function test_falha_ao_auditar_o_desfecho_nao_derruba_a_resposta_nem_vaza_dados(): void
    {
        $this->logarComo('admin', 1);
        // Banco recusando o insert: a mensagem da exceção traz o SQL com os valores.
        $this->app->instance(AuditoriaService::class, new class(app(ContextoUsuario::class)) extends AuditoriaService
        {
            public function registrar(string $tool, string $acao, ?string $entidadeTipo, ?int $entidadeId, array $parametros, string $resultado, ?string $mensagem, string $papel, bool $permitido): AuditLog
            {
                throw new RuntimeException('insert into audit_logs values (111.222.333-44, 4567.89)');
            }
        });
        Log::spy();
        $funcionario = ['nome' => 'Ana Souza', 'cpf' => '111.222.333-44', 'salario' => 4567.89, 'data_admissao' => '2026-01-10'];

        $token = $this->propor($funcionario, 'criar_funcionario');
        $this->travel(3)->seconds();
        $cancelou = $this->cancelar($token)->assertOk()->assertJsonPath('tipo', 'cancelado');

        // 25/11: sem prazo, o desfecho "tarde" também é cancelamento.
        $token = $this->propor($funcionario, 'criar_funcionario');
        $this->travel(ConfirmacoesPendentes::PRAZO_RASCUNHO_S + 1)->seconds();
        $atrasou = $this->cancelar($token)->assertOk()->assertJsonPath('tipo', 'cancelado');

        foreach ([$cancelou, $atrasou] as $resposta) {
            $this->assertStringNotContainsString('insert into', $resposta->getContent());
            $this->assertStringNotContainsString('111.222', $resposta->getContent());
        }
        $this->assertEmpty(session('confirmacoes', []), 'R3 vale mesmo sem auditoria');
        Log::shouldHaveReceived('warning')->times(2)->withArgs(function ($mensagem, $contexto) {
            $json = json_encode($contexto);

            return $mensagem === 'AiAgentService: falha ao auditar desfecho'
                && $contexto['tool'] === 'criar_funcionario'
                && $contexto['excecao'] === RuntimeException::class
                && isset($contexto['correlation_id'])
                && ! isset($contexto['argumentos'])
                && ! str_contains($json, '111.222')
                && ! str_contains($json, '4567')
                && ! str_contains($json, 'insert into');
        });
    }

    // ---- LGPD no proposto ----

    public function test_proposto_de_funcionario_cancelado_fica_mascarado_na_auditoria(): void
    {
        $this->logarComo('admin', 1);
        $token = $this->propor([
            'nome' => 'Ana Souza',
            'cpf' => '111.222.333-44',
            'salario' => 4567.89,
            'data_admissao' => '2026-01-10',
        ], 'criar_funcionario');
        $this->travel(3)->seconds();

        $this->cancelar($token)->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('111.***.**3-44', $log->parametros_propostos['cpf']);
        $this->assertSame('[redigido]', $log->parametros_propostos['salario']);

        $bruto = (string) DB::table('audit_logs')->value('parametros_propostos');
        $this->assertStringNotContainsString('111.222', $bruto);
        $this->assertStringNotContainsString('4567', $bruto);
    }

    // ---- schema, R6 e tela ----

    public function test_migration_da_auditoria_sobe_e_desce_sem_tocar_no_resto(): void
    {
        $migracao = require database_path('migrations/2026_10_19_120000_add_proposto_autorizacao_tempo_e_origem_to_audit_logs_table.php');
        $novas = ['autorizado_por', 'origem', 'parametros_propostos', 'tempo_ia_ms'];

        $this->assertTrue(Schema::hasColumns('audit_logs', $novas));

        $migracao->down();
        foreach ($novas as $coluna) {
            $this->assertFalse(Schema::hasColumn('audit_logs', $coluna), $coluna);
        }
        $this->assertTrue(Schema::hasColumns('audit_logs', ['parametros', 'resultado', 'papel', 'permitido', 'tenant_id']));

        $migracao->up();
        $this->assertTrue(Schema::hasColumns('audit_logs', $novas));
    }

    public function test_r6_restaurar_sem_senha_de_admin_explica_em_linguagem_simples(): void
    {
        $cliente = Cliente::create(['nome' => 'Na lixeira', 'tenant_id' => 1]);
        $cliente->delete();

        $resultado = app(ClienteService::class)->restaurarDaLixeira($cliente->id, 1, false);

        $this->assertFalse($resultado['restaurado']);
        $this->assertSame('Para restaurar, é preciso a senha de um administrador.', $resultado['motivo']);
    }

    public function test_tela_do_agente_chama_o_cancelamento_e_avisa_que_nada_foi_gravado(): void
    {
        // Só confere o que a tela entrega; o comportamento no navegador fica para o teste manual.
        $this->get('/agente')
            ->assertOk()
            ->assertSee("fetch('/agente/cancelar'", false)
            ->assertSee('Você soltou antes da hora, então não fiz nada. Se quiser, é só segurar de novo.')
            // 23/11: o aviso virou toast; o botão de fechar é o "×" com aria-label (antes, o botão "Fechar" do modal de erro).
            ->assertSee("setAttribute('aria-label', 'Fechar aviso')", false)
            ->assertDontSee('transition: opacity', false);
    }
}
