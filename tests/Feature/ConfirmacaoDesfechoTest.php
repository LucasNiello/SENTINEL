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
 * IA propôs (mascarado) e a proposta sai da sessão (R3). Foundry sempre simulado.
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
            ->assertJsonPath('mensagem', 'Cancelado. Nada foi gravado.');

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

    public function test_cancelado_ou_expirado_quem_decide_e_o_servidor_pelo_horario_da_proposta(): void
    {
        // Dentro da janela da interface: foi o usuário, mesmo que o corpo diga "expirado".
        $token = $this->propor(self::LANCAMENTO);
        $this->travel(3)->seconds();
        $this->cancelar($token, ['tipo' => 'expirado', 'resultado' => 'expirado', 'emitido_em' => 0])
            ->assertOk()
            ->assertJsonPath('tipo', 'cancelado');

        // Depois da validade: foi o prazo, mesmo que o corpo diga "cancelado".
        $token = $this->propor(self::LANCAMENTO);
        $this->travel(ConfirmacoesPendentes::VALIDADE_MAXIMA_S + 1)->seconds();
        $this->cancelar($token, ['tipo' => 'cancelado', 'resultado' => 'cancelado'])
            ->assertOk()
            ->assertJsonPath('tipo', 'expirado')
            ->assertJsonPath('mensagem', 'Confirmação expirada. Nada foi gravado. Peça de novo.');

        $this->assertSame(['cancelado', 'expirado'], $this->resultadosAuditados());
    }

    public function test_cancelar_e_confirmar_usam_o_mesmo_limite_de_validade(): void
    {
        // Aos 11 s a proposta ainda é confirmável, então encerrá-la é cancelamento, não expiração.
        $token = $this->propor(self::LANCAMENTO);
        $this->travel(11)->seconds();
        $this->cancelar($token)->assertOk()->assertJsonPath('tipo', 'cancelado');

        // A prova de que, com o mesmo tempo decorrido, ela ainda era confirmável.
        $outro = $this->propor(self::LANCAMENTO);
        $this->travel(11)->seconds();
        $this->postJson('/agente/confirmar', ['token' => $outro])->assertOk();

        // Exatamente no limite ainda é cancelamento (o /confirmar também aceita aos 12 s).
        $limite = $this->propor(self::LANCAMENTO);
        $this->travel(ConfirmacoesPendentes::VALIDADE_MAXIMA_S)->seconds();
        $this->cancelar($limite)->assertJsonPath('tipo', 'cancelado');

        $this->assertSame(['cancelado', 'sucesso', 'cancelado'], $this->resultadosAuditados());
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

    // ---- confirmar fora do prazo ----

    public function test_confirmar_fora_do_prazo_audita_expirado_e_nao_grava(): void
    {
        $token = $this->propor(self::LANCAMENTO);
        $this->travel(ConfirmacoesPendentes::VALIDADE_MAXIMA_S + 1)->seconds();

        $this->postJson('/agente/confirmar', ['token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Confirmação fora do prazo. Refaça o comando.');

        $log = AuditLog::query()->sole();
        $this->assertSame('expirado', $log->resultado);
        $this->assertSame('criar_lancamento', $log->tool);
        $this->assertSame([], $log->parametros);
        $this->assertEquals(self::LANCAMENTO, $log->parametros_propostos);
        $this->assertEmpty(session('confirmacoes', []));
        $this->assertSame(0, Lancamento::count());
    }

    public function test_confirmar_antes_da_espera_minima_audita_recusado_e_queima_o_token(): void
    {
        $token = $this->propor(self::LANCAMENTO);
        $this->travel(1)->seconds();

        $this->postJson('/agente/confirmar', ['token' => $token])->assertStatus(422);

        $log = AuditLog::query()->sole();
        $this->assertSame('recusado', $log->resultado);
        $this->assertSame('Confirmação antes da espera mínima. Nada foi gravado.', $log->mensagem);

        $this->travel(2)->seconds();
        $this->postJson('/agente/confirmar', ['token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Confirmação inválida ou já utilizada.');
        $this->assertSame(0, Lancamento::count());
        $this->assertSame(1, AuditLog::count());
    }

    // ---- varredura das vencidas ----

    public function test_proposta_esquecida_e_auditada_como_expirada_na_requisicao_seguinte(): void
    {
        $esquecida = $this->propor(self::LANCAMENTO);
        $this->travel(ConfirmacoesPendentes::VALIDADE_MAXIMA_S + 1)->seconds();

        $this->comando('consultar_lancamentos')->assertOk();

        $log = AuditLog::query()->where('resultado', 'expirado')->sole();
        $this->assertSame('criar_lancamento', $log->tool);
        $this->assertSame('A confirmação expirou sem resposta. Nada foi gravado.', $log->mensagem);
        $this->assertEquals(self::LANCAMENTO, $log->parametros_propostos);
        $this->assertNull(session("confirmacoes.{$esquecida}"), 'R3: saiu da sessão');
        $this->assertSame(2, AuditLog::count(), 'a consulta + a expirada');
    }

    public function test_varredura_nao_mexe_na_proposta_ainda_valida(): void
    {
        $token = $this->propor(self::LANCAMENTO);
        $this->travel(5)->seconds();

        $this->comando('consultar_lancamentos')->assertOk();

        $this->assertNotNull(session("confirmacoes.{$token}"));
        $this->assertSame(0, AuditLog::query()->where('resultado', 'expirado')->count());

        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();
        $this->assertSame(1, Lancamento::count());
    }

    public function test_abrir_a_tela_do_agente_tambem_varre_as_vencidas(): void
    {
        $this->propor(self::LANCAMENTO);
        $this->travel(ConfirmacoesPendentes::VALIDADE_MAXIMA_S + 1)->seconds();

        $this->get('/agente')->assertOk();

        $this->assertSame(['expirado'], $this->resultadosAuditados());
        $this->assertEmpty(session('confirmacoes', []));
    }

    public function test_varredura_nao_audita_de_novo_proposta_ja_confirmada_que_voltou_para_a_sessao(): void
    {
        $token = $this->propor(self::LANCAMENTO);
        $this->travel(2)->seconds();
        $entrada = session("confirmacoes.{$token}");
        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();

        // Uma requisição concorrente regravou a sessão com a proposta já confirmada.
        $this->withSession(['confirmacoes' => [$token => $entrada]]);
        $this->travel(5)->minutes();

        $this->get('/agente')->assertOk();

        $this->assertSame(['sucesso'], $this->resultadosAuditados(), 'confirmada não vira expirada');
        $this->assertEmpty(session('confirmacoes', []));
    }

    public function test_limite_de_validade_conta_pelo_tempo_real_e_nao_pelo_segundo_cheio(): void
    {
        // 12,3 s reais, mas só 12 "segundos cheios" (de :00 a :12): já passou da validade.
        $this->travelTo(Carbon::parse('2026-10-19 10:00:00.100'));
        $token = $this->propor(self::LANCAMENTO);
        $this->travelTo(Carbon::parse('2026-10-19 10:00:12.400'));
        $this->cancelar($token)->assertOk()->assertJsonPath('tipo', 'expirado');

        $this->assertSame(['expirado'], $this->resultadosAuditados());
    }

    public function test_sair_com_card_aberto_audita_o_desfecho_em_nome_de_quem_saiu(): void
    {
        $this->propor(self::LANCAMENTO);
        $usuarioId = Auth::id();
        $this->travel(3)->seconds();

        $this->post('/logout')->assertRedirect('/login');

        $log = AuditLog::query()->sole();
        $this->assertSame('cancelado', $log->resultado);
        $this->assertSame('Encerrada ao sair do sistema. Nada foi gravado.', $log->mensagem);
        $this->assertSame($usuarioId, $log->user_id, 'auditada em nome de quem saiu');
        $this->assertEquals(self::LANCAMENTO, $log->parametros_propostos);
        $this->assertGuest();
    }

    public function test_sair_com_proposta_ja_fora_da_janela_audita_expirado(): void
    {
        $this->propor(self::LANCAMENTO);
        $this->travel(5)->seconds();
        $this->get('/agente')->assertOk(); // recarregou a tela: a proposta ainda vale e o card sumiu
        $this->travel(1)->minutes();

        $this->post('/logout')->assertRedirect('/login');

        $this->assertSame(['expirado'], $this->resultadosAuditados());
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

        $token = $this->propor($funcionario, 'criar_funcionario');
        $this->travel(ConfirmacoesPendentes::VALIDADE_MAXIMA_S + 1)->seconds();
        $atrasou = $this->postJson('/agente/confirmar', ['token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Confirmação fora do prazo. Refaça o comando.');

        $this->propor($funcionario, 'criar_funcionario');
        $this->travel(ConfirmacoesPendentes::VALIDADE_MAXIMA_S + 1)->seconds();
        $this->get('/agente')->assertOk();

        foreach ([$cancelou, $atrasou] as $resposta) {
            $this->assertStringNotContainsString('insert into', $resposta->getContent());
            $this->assertStringNotContainsString('111.222', $resposta->getContent());
        }
        $this->assertEmpty(session('confirmacoes', []), 'R3 vale mesmo sem auditoria');
        Log::shouldHaveReceived('warning')->times(3)->withArgs(function ($mensagem, $contexto) {
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
        $this->assertSame('***.***.333-44', $log->parametros_propostos['cpf']);
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
            ->assertSee('Você soltou antes do fim. Nada foi gravado.')
            ->assertSee("fechar.textContent = 'Fechar'", false)
            ->assertDontSee('transition: opacity', false);
    }
}
