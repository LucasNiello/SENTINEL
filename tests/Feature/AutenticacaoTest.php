<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AiAgentService;
use App\Services\ContextoUsuario;
use App\Services\ReautenticacaoAdmin;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * RF10: login/logout com o Auth nativo, rotas protegidas, papel e tenant
 * vindos do usuário logado (falha fechada) e reautenticação de admin.
 */
class AutenticacaoTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    private const SENHA = 'senha-de-teste';

    private function usuario(?string $papel = 'admin', ?int $tenantId = 1, string $email = 'alguem@sentinel.local'): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => self::SENHA,
            'papel' => $papel,
            'tenant_id' => $tenantId,
        ]);
    }

    // ---- Rotas protegidas ----

    public function test_sem_login_agente_redireciona_para_o_login(): void
    {
        $this->get('/agente')->assertRedirect('/login');
    }

    public function test_sem_login_as_rotas_json_do_agente_respondem_401(): void
    {
        $this->postJson('/agente/comando', ['mensagem' => 'oi'])->assertStatus(401);
        $this->postJson('/agente/confirmar', ['token' => 'x'])->assertStatus(401);
    }

    // ---- Login ----

    public function test_login_valido_regenera_a_sessao_e_vai_para_o_agente(): void
    {
        $usuario = $this->usuario();
        $this->get('/login')->assertOk();
        $sessaoAntes = session()->getId();

        $this->post('/login', ['email' => $usuario->email, 'password' => self::SENHA])
            ->assertRedirect('/agente');

        $this->assertAuthenticatedAs($usuario);
        $this->assertNotSame($sessaoAntes, session()->getId(), 'id de sessão novo após o login (fixação de sessão)');
    }

    public function test_senha_errada_e_email_inexistente_dao_a_mesma_mensagem(): void
    {
        $usuario = $this->usuario();

        $senhaErrada = $this->from('/login')->post('/login', ['email' => $usuario->email, 'password' => 'errada']);
        $emailInexistente = $this->from('/login')->post('/login', ['email' => 'ninguem@sentinel.local', 'password' => 'errada']);

        $senhaErrada->assertRedirect('/login')->assertSessionHasErrors(['email' => 'E-mail ou senha inválidos.']);
        $emailInexistente->assertRedirect('/login')->assertSessionHasErrors(['email' => 'E-mail ou senha inválidos.']);
        $this->assertGuest();
    }

    public function test_login_bloqueia_a_sexta_tentativa_no_mesmo_minuto(): void
    {
        $usuario = $this->usuario();

        for ($i = 1; $i <= 5; $i++) {
            $this->post('/login', ['email' => $usuario->email, 'password' => 'errada'])->assertRedirect();
        }

        $this->post('/login', ['email' => $usuario->email, 'password' => self::SENHA])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_usuario_sem_papel_valido_ou_sem_tenant_nao_entra_mesmo_com_a_senha_certa(): void
    {
        $casos = [
            'papel fora da hierarquia' => $this->usuario('root', 1, 'root@sentinel.local'),
            'sem papel' => $this->usuario(null, 1, 'sempapel@sentinel.local'),
            'sem tenant' => $this->usuario('admin', null, 'semtenant@sentinel.local'),
        ];

        foreach ($casos as $caso => $usuario) {
            $this->from('/login')->post('/login', ['email' => $usuario->email, 'password' => self::SENHA])
                ->assertRedirect('/login')
                ->assertSessionHasErrors(['email' => 'E-mail ou senha inválidos.']);

            $this->assertFalse($this->isAuthenticated(), $caso);
        }
    }

    public function test_quem_ja_esta_logado_e_abre_o_login_vai_para_o_agente(): void
    {
        $this->actingAs($this->usuario());

        $this->get('/login')->assertRedirect('/agente');
    }

    // ---- Logout ----

    public function test_logout_volta_para_o_login_e_o_agente_exige_login_de_novo(): void
    {
        $this->actingAs($this->usuario());
        $this->get('/agente')->assertOk();

        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->get('/agente')->assertRedirect('/login');
    }

    // ---- Falha fechada fora de HTTP ----

    public function test_contexto_sem_usuario_logado_lanca_excecao_em_vez_de_usar_padrao(): void
    {
        $contexto = app(ContextoUsuario::class);

        foreach (['usuario', 'papel', 'tenantId'] as $metodo) {
            try {
                $contexto->{$metodo}();
                $this->fail("{$metodo}() deveria lançar AuthenticationException sem usuário logado");
            } catch (AuthenticationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_contexto_com_usuario_sem_papel_ou_sem_tenant_lanca_excecao(): void
    {
        $this->actingAs($this->usuario(null, 1, 'sempapel@sentinel.local'));
        $this->expectException(AuthenticationException::class);
        app(ContextoUsuario::class)->papel();
    }

    public function test_contexto_com_usuario_sem_tenant_lanca_excecao(): void
    {
        $this->actingAs($this->usuario('admin', null, 'semtenant@sentinel.local'));
        $this->expectException(AuthenticationException::class);
        app(ContextoUsuario::class)->tenantId();
    }

    public function test_agente_chamado_sem_usuario_falha_e_nao_grava_nada(): void
    {
        try {
            app(AiAgentService::class)->executarTool('consultar_lancamentos', []);
            $this->fail('executarTool sem usuário logado deveria lançar AuthenticationException');
        } catch (AuthenticationException) {
            $this->assertSame(0, AuditLog::count());
        }
    }

    public function test_comando_de_arquivamento_roda_sem_usuario_logado(): void
    {
        $this->assertGuest();

        $this->artisan('sentinel:arquivar-expirados')->assertSuccessful();
    }

    // ---- Auditoria com o usuário real ----

    public function test_auditoria_grava_o_usuario_o_papel_e_o_tenant_de_quem_agiu(): void
    {
        $this->prepararAgente();
        $usuario = $this->usuario('operador', 3);
        $this->actingAs($usuario);

        $this->comando('consultar_lancamentos')->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame($usuario->id, $log->user_id);
        $this->assertSame('operador', $log->papel);
        $this->assertSame(3, (int) $log->tenant_id);
    }

    // ---- Reautenticação de admin (mecanismo; a tela de restauração é Fase 4) ----

    public function test_reautenticacao_so_aceita_admin_do_mesmo_tenant_com_a_senha_certa(): void
    {
        $this->usuario('admin', 1, 'admin@sentinel.local');
        $this->usuario('operador', 1, 'operador@sentinel.local');
        $this->usuario('admin', 2, 'admin.t2@sentinel.local');

        // Quem está logado é um operador do tenant 1 pedindo autorização ao admin.
        $this->actingAs(User::query()->where('email', 'operador@sentinel.local')->sole());
        $reautenticacao = app(ReautenticacaoAdmin::class);

        $this->assertTrue($reautenticacao->confirmar('admin@sentinel.local', self::SENHA), 'admin do mesmo tenant, senha certa');
        $this->assertFalse($reautenticacao->confirmar('admin@sentinel.local', 'errada'), 'senha errada');
        $this->assertFalse($reautenticacao->confirmar('operador@sentinel.local', self::SENHA), 'não é admin');
        $this->assertFalse($reautenticacao->confirmar('admin.t2@sentinel.local', self::SENHA), 'admin de outro tenant');
        $this->assertFalse($reautenticacao->confirmar('ninguem@sentinel.local', self::SENHA), 'e-mail inexistente');
    }

    public function test_reautenticacao_sem_usuario_logado_falha_fechada(): void
    {
        $this->usuario('admin', 1, 'admin@sentinel.local');

        $this->expectException(AuthenticationException::class);
        app(ReautenticacaoAdmin::class)->confirmar('admin@sentinel.local', self::SENHA);
    }
}
