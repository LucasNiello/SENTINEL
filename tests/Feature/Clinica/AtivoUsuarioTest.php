<?php

namespace Tests\Feature\Clinica;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\ReautenticacaoAdmin;
use App\Services\SupervisaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

/**
 * 26/11 (decisão do Lucas): conta ativa ou inativa. O dono dá a ordem; o Administrador/RH liga ou desliga (toggle na
 * /supervisao). Inativa não entra, perde a sessão na próxima requisição, some das listas de profissional e não autoriza
 * nada; o histórico dela continua ligado aos registros. Todo desfecho é auditado.
 */
class AtivoUsuarioTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    private const SENHA = 'senha-do-funcionario-2026';

    private function admin(int $tenant = self::T): User
    {
        $admin = User::factory()->papel('admin')->doTenant($tenant)->create(['password' => Hash::make(self::SENHA)]);
        $this->actingAs($admin);

        return $admin;
    }

    private function definir(User $alvo, bool $ativo)
    {
        return $this->postJson("/supervisao/usuarios/{$alvo->id}/ativo", ['ativo' => $ativo]);
    }

    public function test_administrador_desativa_e_reativa_e_cada_mudanca_e_auditada(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $dono = $this->usuarioClinica('dono');
        app(SupervisaoService::class)->marcarSupervisor($vet->id, 'clinica', $dono->id, self::T);
        $vet->forceFill(['pin_autorizacao' => Hash::make('123456'), 'remember_token' => 'lembrar'])->save();
        $admin = $this->admin();

        $this->definir($vet, false)->assertOk()->assertJsonPath('tipo', 'sucesso');

        $vet = $vet->fresh();
        $this->assertFalse($vet->ativo);
        $this->assertNull($vet->supervisor_setor, 'desativar zera a marcação de supervisor');
        $this->assertNull($vet->pin_autorizacao, 'desativar zera o PIN');
        $this->assertNull($vet->remember_token);
        $log = AuditLog::query()->where('tool', 'alterar_ativo_usuario')->sole();
        $this->assertSame(['sucesso', $admin->id, $vet->id], [$log->resultado, $log->user_id, $log->entidade_id]);

        $this->definir($vet, true)->assertOk();
        $this->assertTrue($vet->fresh()->ativo);
        $this->assertSame(2, AuditLog::query()->where('tool', 'alterar_ativo_usuario')->where('resultado', 'sucesso')->count());
        $this->assertNull($vet->fresh()->pin_autorizacao, 'reativar não devolve o PIN: o Administrador gera outro');
    }

    public function test_desativar_derruba_as_sessoes_abertas_da_pessoa(): void
    {
        $recepcao = $this->usuarioClinica('recepcao');
        DB::table('sessions')->insert(['id' => 'sessao-da-recepcao', 'user_id' => $recepcao->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'teste', 'payload' => '', 'last_activity' => time()]);
        $this->admin();

        $this->definir($recepcao, false)->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $recepcao->id)->count());
    }

    public function test_so_o_administrador_liga_e_desliga_e_a_negacao_e_auditada(): void
    {
        $alvo = $this->usuarioClinica('recepcao');
        foreach (['dono', 'veterinario', 'recepcao', 'vendedor'] as $papel) {
            $this->actingAs($this->usuarioClinica($papel));
            $this->definir($alvo, false)->assertStatus(403);
        }

        $this->assertTrue($alvo->fresh()->ativo);
        $this->assertSame(4, AuditLog::query()->where('tool', 'alterar_ativo_usuario')->where('resultado', 'negado')->count());
    }

    public function test_ninguem_se_tranca_para_fora_nem_desliga_o_dono(): void
    {
        $dono = $this->usuarioClinica('dono');
        $admin = $this->admin();

        $this->definir($admin, false)->assertStatus(422);
        $this->definir($dono, false)->assertStatus(422);

        $this->assertTrue($admin->fresh()->ativo);
        $this->assertTrue($dono->fresh()->ativo);
    }

    public function test_usuario_de_outro_tenant_e_tratado_como_inexistente(): void
    {
        $deFora = User::factory()->papel('recepcao')->doTenant(self::OUTRO_TENANT)->create();
        $this->admin();

        $this->definir($deFora, false)->assertStatus(422);

        $this->assertTrue($deFora->fresh()->ativo);
    }

    public function test_valor_invalido_e_recusado(): void
    {
        $alvo = $this->usuarioClinica('recepcao');
        $this->admin();

        $this->postJson("/supervisao/usuarios/{$alvo->id}/ativo", [])->assertStatus(422);
        $this->postJson("/supervisao/usuarios/{$alvo->id}/ativo", ['ativo' => 'talvez'])->assertStatus(422);
        $this->assertTrue($alvo->fresh()->ativo);
    }

    public function test_a_lista_do_administrador_traz_a_equipe_toda_com_ativo_e_quem_pode_mudar(): void
    {
        $dono = $this->usuarioClinica('dono');
        $recepcao = $this->usuarioClinica('recepcao');
        $recepcao->forceFill(['ativo' => false])->save();
        $admin = $this->admin();

        $usuarios = collect($this->getJson('/supervisao/supervisores')->assertOk()->json('usuarios'))->keyBy('id');

        $this->assertFalse($usuarios[$recepcao->id]['ativo']);
        $this->assertTrue($usuarios[$recepcao->id]['pode_ativar']);
        $this->assertFalse($usuarios[$recepcao->id]['pode_pin']);
        $this->assertFalse($usuarios[$dono->id]['pode_ativar'], 'o dono não é ligado nem desligado aqui');
        $this->assertTrue($usuarios[$dono->id]['pode_pin']);
        $this->assertFalse($usuarios[$admin->id]['pode_ativar'], 'ninguém desliga a própria conta');
    }

    public function test_conta_inativa_nao_entra_pelo_login_e_a_mensagem_so_vem_com_a_senha_certa(): void
    {
        $usuario = User::factory()->papel('recepcao')->doTenant(self::T)->create(['password' => Hash::make(self::SENHA), 'ativo' => false]);

        $this->post('/login', ['email' => $usuario->email, 'password' => self::SENHA])
            ->assertSessionHasErrors(['email' => 'Esta conta foi desativada. Fale com o administrador.']);
        $this->assertGuest();

        // Senha errada: mensagem genérica (não revela que a conta existe nem que está inativa).
        $this->post('/login', ['email' => $usuario->email, 'password' => 'errada-errada-1'])
            ->assertSessionHasErrors('email');
        $this->assertStringNotContainsString('desativada', (string) collect(session('errors')->get('email'))->first());
    }

    public function test_conta_desativada_com_sessao_aberta_e_derrubada_na_requisicao_seguinte(): void
    {
        $usuario = $this->usuarioClinica('recepcao');
        $this->actingAs($usuario);
        $this->get('/cadastros')->assertOk();

        $usuario->forceFill(['ativo' => false])->save();

        $this->get('/cadastros')->assertRedirect('/login');
        $this->assertGuest();

        $this->actingAs($usuario->fresh());
        $this->getJson('/agente/painel')->assertStatus(401)->assertJsonPath('codigo', 'AI_ACCOUNT_DISABLED');
    }

    public function test_conta_desligada_pela_rota_perde_o_acesso_na_requisicao_seguinte(): void
    {
        // Caminho real: o Administrador desliga pela rota; a sessão da pessoa é apagada e ela não volta a agir.
        $recepcao = $this->usuarioClinica('recepcao');
        $this->actingAs($recepcao);
        $this->get('/cadastros')->assertOk();
        $this->admin();
        $this->definir($recepcao, false)->assertOk();

        $this->actingAs($recepcao->fresh());
        $this->get('/cadastros')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_auditoria_guarda_o_estado_de_antes_e_diz_sem_mudanca_na_repeticao(): void
    {
        $dono = $this->usuarioClinica('dono');
        $vet = $this->usuarioClinica('veterinario');
        app(SupervisaoService::class)->marcarSupervisor($vet->id, 'clinica', $dono->id, self::T);
        $vet->forceFill(['pin_autorizacao' => Hash::make('123456')])->save();
        $this->admin();

        $this->definir($vet, false)->assertOk();
        $primeiro = AuditLog::query()->where('tool', 'alterar_ativo_usuario')->orderBy('id')->first();
        $this->assertTrue($primeiro->parametros_propostos['ativo_anterior'] ?? $primeiro->parametros['ativo_anterior']);
        $this->assertSame('clinica', $primeiro->parametros['setor_anterior'] ?? $primeiro->parametros_propostos['setor_anterior']);

        $this->definir($vet, false)->assertOk()->assertJsonPath('mensagem', fn ($m) => str_starts_with($m, 'Sem mudança'));

        $linhas = AuditLog::query()->where('tool', 'alterar_ativo_usuario')->orderBy('id')->get();
        $this->assertCount(2, $linhas);
        $params = fn (AuditLog $l) => $l->parametros + ($l->parametros_propostos ?? []);
        $this->assertTrue($params($linhas[0])['tinha_pin'], 'a auditoria diz que a pessoa tinha PIN');
        $this->assertFalse($params($linhas[1])['ativo_anterior'], 'a repetição já encontrou a conta inativa');
        $this->assertStringNotContainsString('$2y$', (string) json_encode(AuditLog::all()->toArray()), 'nenhum hash na auditoria');
    }

    public function test_conta_inativa_nao_vira_supervisora_nem_recebe_pin(): void
    {
        $dono = $this->usuarioClinica('dono');
        $vet = $this->usuarioClinica('veterinario');
        app(SupervisaoService::class)->marcarSupervisor($vet->id, 'clinica', $dono->id, self::T);
        $vet->forceFill(['ativo' => false, 'supervisor_setor' => 'clinica'])->save();
        $this->admin();

        $this->postJson("/supervisao/pins/{$vet->id}/gerar", ['senha_atual' => self::SENHA])->assertStatus(422)
            ->assertJsonPath('mensagem', fn ($m) => str_contains($m, 'desativada'));
        $this->actingAs($dono);
        $this->postJson("/supervisao/supervisores/{$vet->id}", ['setor' => 'petshop'])->assertStatus(422)
            ->assertJsonPath('mensagem', fn ($m) => str_contains($m, 'reative a conta'));
        $this->assertSame('clinica', $vet->fresh()->supervisor_setor, 'nada mudou');
        $this->assertNull($vet->fresh()->pin_autorizacao);

        // Desmarcar continua permitido numa conta inativa.
        $this->postJson("/supervisao/supervisores/{$vet->id}", ['setor' => null])->assertOk();
        $this->assertNull($vet->fresh()->supervisor_setor);
    }

    public function test_profissional_inativo_some_da_lista_de_horarios(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->usuarioClinica('dono');
        $vet = $this->usuarioClinica('veterinario');
        $servico = $this->item();
        $this->logarComo('recepcao', self::T);
        $antes = collect($this->getJson('/agenda/disponibilidade?servico='.$servico->id.'&mes=2026-11')->assertOk()->json('profissionais'))->pluck('id');
        $this->assertTrue($antes->contains($vet->id));

        $vet->forceFill(['ativo' => false])->save();

        $depois = collect($this->getJson('/agenda/disponibilidade?servico='.$servico->id.'&mes=2026-11')->assertOk()->json('profissionais'))->pluck('id');
        $this->assertFalse($depois->contains($vet->id));
    }

    public function test_quem_autoriza_com_senha_e_desativado_deixa_de_autorizar(): void
    {
        // Na clínica quem autoriza a lixeira com a senha é o dono (o Administrador só gerencia PIN e contas).
        $dono = User::factory()->papel('dono')->doTenant(self::T)->create(['password' => Hash::make(self::SENHA)]);
        $this->actingAs($dono);
        $this->assertTrue(app(ReautenticacaoAdmin::class)->confirmar($dono->email, self::SENHA));

        DB::table('users')->where('id', $dono->id)->update(['ativo' => false]);

        $this->assertFalse(app(ReautenticacaoAdmin::class)->confirmar($dono->email, self::SENHA));
    }

    public function test_supervisor_desativado_nao_autoriza_estorno_pelo_pin(): void
    {
        $dono = $this->usuarioClinica('dono');
        $vet = $this->usuarioClinica('veterinario');
        app(SupervisaoService::class)->marcarSupervisor($vet->id, 'clinica', $dono->id, self::T);
        $vet->forceFill(['pin_autorizacao' => Hash::make('482913')])->save();
        $this->actingAs($this->usuarioClinica('recepcao'));
        $this->assertSame(['admin' => $vet->id], app(ReautenticacaoAdmin::class)->autorizarEstorno($vet->email, '482913'));

        DB::table('users')->where('id', $vet->id)->update(['ativo' => false]);

        $this->assertSame(['falha' => 'invalido'], app(ReautenticacaoAdmin::class)->autorizarEstorno($vet->email, '482913'));
    }
}
