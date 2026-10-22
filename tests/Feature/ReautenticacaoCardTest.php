<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\User;
use App\Services\ConversaAgente;
use App\Services\ReautenticacaoAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Bloco 4 — a senha do admin no card (RF10): ela só vai do card para o /agente/confirmar
 * e nunca aparece na resposta, no log, na auditoria, na sessão, no histórico do agente,
 * no cache nem no pedido à IA; nenhum caminho vira 500 (APP_DEBUG=true no laboratório);
 * e as tentativas erradas têm dois limites: 5 por usuário + e-mail e 10 por usuário, a cada 10 min.
 */
class ReautenticacaoCardTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    private const SENHA = 'Senha-Do-Supervisor#7731';

    private const SENHA_ERRADA = 'Tentativa-Errada#4402';

    private const EMAIL = 'supervisor@sentinel.local';

    /** @var string[] tudo que foi para o log nesta requisição de teste (mensagem + contexto) */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararAgente();
        $this->logarComo('operador', 1);
        $this->supervisor();
        Log::listen(function (MessageLogged $evento) {
            $this->logs[] = $evento->message.' '.json_encode($evento->context);
        });
    }

    private function supervisor(string $email = self::EMAIL, string $senha = self::SENHA, int $tenant = 1): User
    {
        return User::factory()->papel('admin')->doTenant($tenant)->create(['email' => $email, 'password' => Hash::make($senha)]);
    }

    private function naLixeira(string $nome = 'Mercado Central'): Cliente
    {
        $cliente = Cliente::create(['nome' => $nome, 'tenant_id' => 1]);
        $cliente->delete();

        return $cliente;
    }

    /** Abre o card de restaurar sem IA (o /agente/comando tem o limite de 10 por minuto) e revisa: devolve o token de segurar. */
    private function cardDeRestaurar(Cliente $cliente): string
    {
        $this->freezeTime();
        $proposta = $this->postJson('/agente/formulario', ['tool' => 'restaurar_cliente', 'id' => $cliente->id])->assertOk()->json('proposta');

        return $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->assertOk()->json('token');
    }

    private function confirmarCom(string $token, array $corpo)
    {
        $this->travel(2)->seconds();

        return $this->postJson('/agente/confirmar', ['token' => $token, ...$corpo]);
    }

    // ---- A senha não aparece em lugar nenhum ----

    public function test_a_senha_nao_aparece_na_resposta_log_auditoria_sessao_historico_cache_nem_na_ia(): void
    {
        $respostas = [];
        $cliente = $this->naLixeira();

        // Revisar com a senha enfiada nos valores e no corpo: é descartada (não é campo do formulário).
        $proposta = $this->postJson('/agente/formulario', ['tool' => 'restaurar_cliente', 'id' => $cliente->id])->json('proposta');
        $respostas[] = $this->postJson('/agente/revisar', ['proposta' => $proposta, 'senha_admin' => self::SENHA,
            'valores' => ['senha_admin' => self::SENHA, 'email_admin' => self::EMAIL]])->assertOk()->getContent();
        $this->postJson('/agente/cancelar', ['proposta' => $proposta]);

        // Senha errada; senha digitada no campo do e-mail; e por fim a certa.
        $respostas[] = $this->confirmarCom($this->cardDeRestaurar($cliente), ['email_admin' => self::EMAIL, 'senha_admin' => self::SENHA_ERRADA])->assertStatus(403)->getContent();
        $respostas[] = $this->confirmarCom($this->cardDeRestaurar($cliente), ['email_admin' => self::SENHA, 'senha_admin' => self::SENHA])->assertStatus(403)->getContent();
        $respostas[] = $this->confirmarCom($this->cardDeRestaurar($cliente), ['email_admin' => self::EMAIL, 'senha_admin' => self::SENHA])->assertOk()->getContent();
        $this->assertNotNull(Cliente::find($cliente->id));

        // Um pedido seguinte ao agente leva o histórico à IA.
        $this->comando('consultar_clientes')->assertOk();

        $lugares = [
            'respostas' => implode("\n", $respostas),
            'log' => implode("\n", $this->logs),
            'auditoria' => AuditLog::all()->toJson(),
            'sessão' => json_encode(session()->all()),
            'histórico do agente' => json_encode(app(ConversaAgente::class)->ler(session()->driver())),
            'cache' => json_encode((fn () => $this->storage)->call(Cache::store()->getStore())),
            'pedidos à IA' => collect(Http::recorded())->map(fn ($par) => $par[0]->body())->implode("\n"),
        ];

        foreach ($lugares as $lugar => $conteudo) {
            foreach ([self::SENHA, self::SENHA_ERRADA] as $senha) {
                $this->assertStringNotContainsStringIgnoringCase($senha, $conteudo, "a senha apareceu em: {$lugar}");
            }
        }
        $this->assertStringNotContainsString(self::EMAIL, $lugares['auditoria'], 'o e-mail digitado não vai para a auditoria');
        $this->assertNotEmpty(Http::recorded(), 'houve pedido à IA para conferir');
    }

    public function test_tool_sem_senha_ignora_e_mail_e_senha_no_corpo(): void
    {
        $token = $this->propor(['nome' => 'Cliente novo'], 'criar_cliente');

        $this->confirmarCom($token, ['email_admin' => self::EMAIL, 'senha_admin' => self::SENHA])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertNull($log->autorizado_por);
        $this->assertStringNotContainsString(self::SENHA, AuditLog::all()->toJson().json_encode(session()->all()));
    }

    // ---- Sem permissão, a senha nem é conferida (o card não é verificador de senha) ----

    public function test_usuario_rebaixado_recebe_o_mesmo_negado_com_senha_certa_ou_errada_sem_gastar_tentativa(): void
    {
        $operador = auth()->user();
        $cliente = $this->naLixeira();
        $tokenSenhaErrada = $this->cardDeRestaurar($cliente);
        $tokenSenhaCerta = $this->cardDeRestaurar($cliente);
        $operador->update(['papel' => 'leitura']); // rebaixado entre o Revisar e a confirmação
        $tentativas = fn () => [RateLimiter::attempts("reautenticacao:usuario:{$operador->id}"),
            RateLimiter::attempts("reautenticacao:admin:{$operador->id}:".hash_hmac('sha256', self::EMAIL, (string) config('app.key')))];
        $reautenticacao = $this->spy(ReautenticacaoAdmin::class);

        $comErrada = $this->confirmarCom($tokenSenhaErrada, ['email_admin' => self::EMAIL, 'senha_admin' => self::SENHA_ERRADA])->assertStatus(403);
        $comCerta = $this->confirmarCom($tokenSenhaCerta, ['email_admin' => self::EMAIL, 'senha_admin' => self::SENHA])->assertStatus(403);

        $comCerta->assertJsonPath('tipo', 'negado')->assertJsonPath('codigo', 'AI_FORBIDDEN');
        $this->assertSame($comErrada->json(), $comCerta->json(), 'a resposta não revela se a senha estava certa');
        $reautenticacao->shouldNotHaveReceived('autorizar');
        $this->assertSame([0, 0], $tentativas(), 'nenhuma tentativa foi contada');
        $this->assertNull(Cliente::find($cliente->id));
        $this->assertSame([['negado', false, null], ['negado', false, null]],
            AuditLog::query()->get()->map(fn (AuditLog $log) => [$log->resultado, $log->permitido, $log->autorizado_por])->all());
    }

    // ---- Nenhum caminho vira 500 ----

    public static function corposEstranhos(): array
    {
        return [
            'e-mail em lista' => [['email_admin' => [self::EMAIL], 'senha_admin' => self::SENHA]],
            'senha em lista' => [['email_admin' => self::EMAIL, 'senha_admin' => [self::SENHA]]],
            'senha número' => [['email_admin' => self::EMAIL, 'senha_admin' => 12345]],
            'e-mail nulo' => [['email_admin' => null, 'senha_admin' => self::SENHA]],
            'objeto no lugar da senha' => [['email_admin' => self::EMAIL, 'senha_admin' => ['x' => ['y' => self::SENHA]]]],
            'textos enormes' => [['email_admin' => str_repeat('a', 300).'@x.test', 'senha_admin' => str_repeat('s', 5000)]],
            'e-mail inválido' => [['email_admin' => "a'; drop table users; --", 'senha_admin' => self::SENHA]],
        ];
    }

    #[DataProvider('corposEstranhos')]
    public function test_corpo_estranho_nunca_vira_500_e_nao_restaura(array $corpo): void
    {
        config(['app.debug' => true]); // como no laboratório
        $cliente = $this->naLixeira();

        $resposta = $this->confirmarCom($this->cardDeRestaurar($cliente), $corpo);

        $this->assertContains($resposta->status(), [403, 429]);
        $resposta->assertJsonPath('tipo', 'erro')->assertJsonMissingPath('exception')->assertJsonMissingPath('trace');
        $this->assertNull(Cliente::find($cliente->id));
        $this->assertSame([false], AuditLog::query()->where('resultado', 'negado')->pluck('permitido')->all());
    }

    public function test_excecao_na_conferencia_vira_recusa_generica_sem_vazar_a_senha(): void
    {
        config(['app.debug' => true]);
        $cliente = $this->naLixeira();
        $token = $this->cardDeRestaurar($cliente);
        // A falha mais traiçoeira: uma exceção cuja mensagem traz o que foi digitado.
        $this->mock(ReautenticacaoAdmin::class)->shouldReceive('autorizar')->andThrow(new RuntimeException('falhou com '.self::SENHA));

        $resposta = $this->confirmarCom($token, ['email_admin' => self::EMAIL, 'senha_admin' => self::SENHA])
            ->assertStatus(403)
            ->assertJsonPath('codigo', 'AI_REAUTH_FAILED');

        $this->assertStringNotContainsString(self::SENHA, $resposta->getContent().implode("\n", $this->logs).AuditLog::all()->toJson());
        $this->assertNotEmpty($this->logs, 'a falha fica no log, só com metadados');
        $this->assertNull(Cliente::find($cliente->id));
    }

    public function test_falha_do_hash_dentro_da_reautenticacao_tambem_nao_vira_500(): void
    {
        config(['app.debug' => true]);
        $cliente = $this->naLixeira();
        $token = $this->cardDeRestaurar($cliente);
        Hash::shouldReceive('check')->andThrow(new RuntimeException('hash quebrou com '.self::SENHA));

        $this->confirmarCom($token, ['email_admin' => self::EMAIL, 'senha_admin' => self::SENHA])->assertStatus(403);

        $this->assertStringNotContainsString(self::SENHA, implode("\n", $this->logs));
        $this->assertNull(Cliente::find($cliente->id));
    }

    // ---- Limites de tentativas ----

    public function test_limite_de_5_erros_por_usuario_e_e_mail_do_admin(): void
    {
        $this->supervisor('outro.admin@sentinel.local');
        $cliente = $this->naLixeira();

        for ($i = 0; $i < ReautenticacaoAdmin::LIMITE_POR_ADMIN; $i++) {
            $this->confirmarCom($this->cardDeRestaurar($cliente), ['email_admin' => self::EMAIL, 'senha_admin' => self::SENHA_ERRADA])->assertStatus(403);
        }

        // A 6ª, mesmo com a senha certa, é barrada — e a proposta termina sem restaurar.
        $this->confirmarCom($this->cardDeRestaurar($cliente), ['email_admin' => self::EMAIL, 'senha_admin' => self::SENHA])
            ->assertStatus(429)
            ->assertJsonPath('codigo', 'AI_REAUTH_RATE_LIMIT')
            ->assertJsonPath('mensagem', 'Muitas tentativas com a senha errada. Aguarde alguns minutos. Nada foi gravado.');
        $this->assertNull(Cliente::find($cliente->id));
        $this->assertSame('Limite de tentativas de senha de administrador atingido.', AuditLog::query()->latest('id')->first()->mensagem);
        // A barrada não conferiu senha: não conta como tentativa errada.
        $operadorId = auth()->id();
        $this->assertSame(ReautenticacaoAdmin::LIMITE_POR_ADMIN, RateLimiter::attempts("reautenticacao:usuario:{$operadorId}"));

        // Outro admin ainda pode autorizar (o limite por usuário é 10).
        $this->confirmarCom($this->cardDeRestaurar($cliente), ['email_admin' => 'outro.admin@sentinel.local', 'senha_admin' => self::SENHA])->assertOk();
        $this->assertNotNull(Cliente::find($cliente->id));

        // Passados os 10 minutos, o primeiro e-mail volta a valer.
        $cliente->delete();
        $this->travel(ReautenticacaoAdmin::JANELA_S + 1)->seconds();
        $this->confirmarCom($this->cardDeRestaurar($cliente), ['email_admin' => self::EMAIL, 'senha_admin' => self::SENHA])->assertOk();
    }

    public function test_limite_de_10_erros_por_usuario_com_qualquer_e_mail(): void
    {
        $cliente = $this->naLixeira();

        for ($i = 0; $i < ReautenticacaoAdmin::LIMITE_POR_USUARIO; $i++) {
            // Dois erros por e-mail: nenhum e-mail chega ao limite de 5, mas o usuário chega ao de 10.
            $email = 'chute'.intdiv($i, 2).'@sentinel.local';
            $this->confirmarCom($this->cardDeRestaurar($cliente), ['email_admin' => $email, 'senha_admin' => self::SENHA_ERRADA])->assertStatus(403);
        }

        $this->confirmarCom($this->cardDeRestaurar($cliente), ['email_admin' => self::EMAIL, 'senha_admin' => self::SENHA])
            ->assertStatus(429)
            ->assertJsonPath('codigo', 'AI_REAUTH_RATE_LIMIT');
        $this->assertNull(Cliente::find($cliente->id));

        // O limite é de quem errou: outro usuário logado segue normal.
        $this->logarComo('operador', 1);
        $this->confirmarCom($this->cardDeRestaurar($cliente), ['email_admin' => self::EMAIL, 'senha_admin' => self::SENHA])->assertOk();
    }

    public function test_a_tentativa_conta_antes_de_conferir_a_senha(): void
    {
        // Pedidos em paralelo: se o erro só contasse depois da conferência, todos passariam por ela antes
        // de o primeiro ser contado. Aqui, durante a conferência, a tentativa já está no contador.
        $operador = auth()->user();
        $cliente = $this->naLixeira();
        $token = $this->cardDeRestaurar($cliente);
        $durante = null;
        Hash::shouldReceive('check')->once()->andReturnUsing(function () use ($operador, &$durante) {
            $durante = RateLimiter::attempts("reautenticacao:usuario:{$operador->id}");

            return false;
        });

        $this->confirmarCom($token, ['email_admin' => self::EMAIL, 'senha_admin' => self::SENHA_ERRADA])->assertStatus(403);

        $this->assertSame(1, $durante);
    }

    public function test_acerto_nao_conta_para_o_limite(): void
    {
        for ($i = 0; $i < ReautenticacaoAdmin::LIMITE_POR_ADMIN + 1; $i++) {
            $cliente = $this->naLixeira("Cliente {$i}");
            $this->confirmarCom($this->cardDeRestaurar($cliente), ['email_admin' => self::EMAIL, 'senha_admin' => self::SENHA])->assertOk();
        }

        $this->assertSame(ReautenticacaoAdmin::LIMITE_POR_ADMIN + 1, Cliente::query()->count());
    }

    public function test_campo_de_senha_do_card_nao_e_preenchido_pelo_navegador(): void
    {
        // "off" é ignorado em senha: o navegador preencheria a senha salva de quem está logado.
        $this->get('/agente')->assertOk()->assertSee('class="admin-senha" autocomplete="new-password"', false);
    }

    public function test_senha_com_espaco_no_fim_nao_e_aparada(): void
    {
        $this->supervisor('espaco@sentinel.local', 'senha com espaço ');
        $cliente = $this->naLixeira();

        $this->confirmarCom($this->cardDeRestaurar($cliente), ['email_admin' => 'espaco@sentinel.local', 'senha_admin' => 'senha com espaço '])->assertOk();

        $this->assertNotNull(Cliente::find($cliente->id));
    }
}
