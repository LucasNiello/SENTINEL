<?php

namespace Tests\Feature\Clinica;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\SupervisaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

/**
 * Item I, T1 (14/11): endpoints JSON da supervisão. O dono lista e marca/desmarca o setor; o Administrador (papel 'admin'
 * do tenant da clínica) lista o dono e os supervisores, gera o PIN (com a senha de login dele) e revoga. Papel, tenant e
 * "quem" vêm da sessão; o código aparece UMA vez, na resposta do gerar, e nunca na auditoria, no log, na sessão ou
 * numa resposta seguinte. Saíram o "meu PIN" do supervisor e o "zerar PIN" do dono.
 */
class SupervisaoEndpointsTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    private const SENHA_ADMIN = 'senha-do-admin-2026';

    private function admin(int $tenant = self::T): User
    {
        $admin = User::factory()->papel('admin')->doTenant($tenant)->create(['password' => Hash::make(self::SENHA_ADMIN)]);
        $this->actingAs($admin);

        return $admin;
    }

    private function supervisor(string $papel = 'veterinario', string $setor = 'clinica'): User
    {
        $usuario = $this->usuarioClinica($papel);
        app(SupervisaoService::class)->marcarSupervisor($usuario->id, $setor, $this->usuarioClinica('dono')->id, self::T);

        return $usuario->fresh();
    }

    private function gerar(User $alvo, string $senha = self::SENHA_ADMIN)
    {
        return $this->postJson("/supervisao/pins/{$alvo->id}/gerar", ['senha_atual' => $senha]);
    }

    public function test_admin_gera_pin_de_6_digitos_mostrado_uma_vez_e_so_o_hash_fica(): void
    {
        $vet = $this->supervisor();
        $admin = $this->admin();
        Log::spy();

        $resposta = $this->gerar($vet)->assertOk()->assertJsonPath('tipo', 'sucesso');
        $pin = $resposta->json('pin');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);
        $this->assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
        $this->assertTrue(Hash::check($pin, $vet->fresh()->pin_autorizacao));
        $this->assertNotSame($pin, $vet->fresh()->pin_autorizacao, 'só o hash é guardado');

        // Auditoria "PIN gerado para X por Y", sem o código.
        $log = AuditLog::query()->where('tool', 'gerar_pin')->sole();
        $this->assertSame(['sucesso', $admin->id, $vet->id, 'manual'], [$log->resultado, $log->user_id, $log->entidade_id, $log->origem]);
        $this->assertSame('PIN gerado para '.$vet->name.' por '.$admin->name.'.', $log->mensagem);
        $this->assertStringNotContainsString($pin, (string) json_encode(AuditLog::all()->toArray()));
        $this->assertStringNotContainsString(self::SENHA_ADMIN, (string) json_encode(AuditLog::all()->toArray()));

        // Nem no log, nem na sessão, nem na resposta seguinte (a lista só diz tem_pin).
        $contem = fn ($v) => str_contains((string) json_encode($v), $pin);
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $nivel) {
            Log::shouldNotHaveReceived($nivel, [\Mockery::on($contem)]);
            Log::shouldNotHaveReceived($nivel, [\Mockery::any(), \Mockery::on($contem)]);
            Log::shouldNotHaveReceived($nivel, [\Mockery::on($contem), \Mockery::any()]);
        }
        $this->assertStringNotContainsString($pin, (string) json_encode(session()->all()));
        $lista = $this->getJson('/supervisao/supervisores')->assertOk()->getContent();
        $this->assertStringNotContainsString($pin, $lista);
        $this->assertStringContainsString('"tem_pin":true', $lista);
    }

    public function test_codigo_novo_invalida_o_anterior_e_revogar_zera(): void
    {
        $vet = $this->supervisor();
        $admin = $this->admin();

        $primeiro = $this->gerar($vet)->assertOk()->json('pin');
        $segundo = $this->gerar($vet)->assertOk()->json('pin');

        $hash = $vet->fresh()->pin_autorizacao;
        $this->assertTrue(Hash::check($segundo, $hash));
        if ($primeiro !== $segundo) {
            $this->assertFalse(Hash::check($primeiro, $hash), 'o código anterior deixa de valer');
        }

        $this->postJson("/supervisao/pins/{$vet->id}/revogar")->assertOk()
            ->assertJsonPath('mensagem', 'PIN revogado para '.$vet->name.' por '.$admin->name.'.')
            ->assertJsonMissingPath('pin');
        $this->assertNull($vet->fresh()->pin_autorizacao);
        $this->assertSame(['gerar_pin', 'gerar_pin', 'revogar_pin'], AuditLog::query()->orderBy('id')->pluck('tool')->all());
    }

    public function test_revogar_quem_nao_tem_pin_e_recusado_sem_auditar_sucesso(): void
    {
        $vet = $this->supervisor();
        $this->admin();

        $this->postJson("/supervisao/pins/{$vet->id}/revogar")->assertStatus(422)->assertJsonPath('codigo', 'AI_BUSINESS_RULE');
        $this->assertSame(['recusado'], AuditLog::query()->where('tool', 'revogar_pin')->pluck('resultado')->all());
    }

    public function test_os_codigos_sao_aleatorios(): void
    {
        $vet = $this->supervisor();
        $this->admin();

        $codigos = [];
        for ($i = 0; $i < 4; $i++) {
            $codigos[] = $this->gerar($vet)->assertOk()->json('pin');
            RateLimiter::clear('pin-admin:'.auth()->id());
        }

        $this->assertGreaterThan(1, count(array_unique($codigos)), 'quatro códigos iguais seguidos: não é aleatório');
    }

    public function test_admin_gera_pin_para_o_dono_sem_setor(): void
    {
        $dono = $this->usuarioClinica('dono');
        $this->admin();

        $pin = $this->gerar($dono)->assertOk()->json('pin');
        $this->assertTrue(Hash::check($pin, $dono->fresh()->pin_autorizacao));
        $this->assertNull($dono->fresh()->supervisor_setor);
    }

    /** Bloco B (15/11): o dono saiu desta lista (agora gera e revoga: SupervisaoPinPeloDonoTest). */
    public function test_quem_nao_e_admin_nem_dono_nao_gera_nem_revoga_e_nada_muda(): void
    {
        $vet = $this->comPin($this->supervisor());
        $hash = $vet->pin_autorizacao;

        foreach (['veterinario', 'recepcao', 'atendente', 'vendedor'] as $papel) {
            $logado = User::factory()->papel($papel)->doTenant(self::T)->create(['password' => Hash::make(self::SENHA_ADMIN)]);
            $this->actingAs($logado);
            $this->gerar($vet)->assertStatus(403)->assertJsonPath('codigo', 'AI_FORBIDDEN')->assertJsonMissingPath('pin');
            $this->postJson("/supervisao/pins/{$vet->id}/revogar")->assertStatus(403);
        }

        $this->assertSame($hash, $vet->fresh()->pin_autorizacao);
        $this->assertSame(['negado'], AuditLog::query()->pluck('resultado')->unique()->values()->all());

        // O próprio supervisor também não gera o PIN dele.
        $this->actingAs($vet);
        $this->gerar($vet)->assertStatus(403);
    }

    public function test_senha_errada_nao_gera_e_e_auditada_sem_a_senha(): void
    {
        $vet = $this->comPin($this->supervisor());
        $hash = $vet->pin_autorizacao;
        $this->admin();

        $this->gerar($vet, 'errada-123')->assertStatus(403)->assertJsonPath('codigo', 'AI_REAUTH_FAILED')->assertJsonMissingPath('pin');
        $this->postJson("/supervisao/pins/{$vet->id}/gerar", [])->assertStatus(422);
        $this->assertSame($hash, $vet->fresh()->pin_autorizacao);

        $negado = AuditLog::query()->where('tool', 'gerar_pin')->sole();
        $this->assertSame(['negado', false, $vet->id], [$negado->resultado, $negado->permitido, $negado->entidade_id]);
        $this->assertStringNotContainsString('errada-123', (string) json_encode($negado->toArray()));
    }

    public function test_tentativas_da_senha_do_admin_tem_teto_contado_antes_de_conferir(): void
    {
        $vet = $this->supervisor();
        $admin = $this->admin();
        $chave = 'pin-admin:'.$admin->id;

        for ($i = 0; $i < 5; $i++) {
            $this->gerar($vet, 'errada')->assertStatus(403);
        }
        $this->gerar($vet)->assertStatus(429)->assertJsonMissingPath('pin');
        $this->assertSame(5, RateLimiter::attempts($chave), 'o pedido barrado devolve a tentativa');
        $this->assertNull($vet->fresh()->pin_autorizacao);

        // Contada ANTES do Hash::check (rajadas em paralelo não passam do teto).
        RateLimiter::clear($chave);
        $visto = null;
        Hash::shouldReceive('check')->once()->andReturnUsing(function () use (&$visto, $chave) {
            $visto = RateLimiter::attempts($chave);

            return false;
        });
        $this->gerar($vet, 'errada')->assertStatus(403);
        $this->assertSame(1, $visto);
    }

    public function test_acerto_devolve_a_tentativa(): void
    {
        $vet = $this->supervisor();
        $admin = $this->admin();

        $this->gerar($vet)->assertOk();
        $this->assertSame(0, RateLimiter::attempts('pin-admin:'.$admin->id));
    }

    public function test_quem_nao_pode_ter_pin_nao_ganha(): void
    {
        $this->admin();
        $semSetor = $this->usuarioClinica('recepcao');
        $outroAdmin = $this->usuarioClinica('admin');

        foreach ([$semSetor, $outroAdmin, auth()->user()] as $alvo) {
            $this->gerar($alvo)->assertStatus(422)->assertJsonPath('codigo', 'AI_BUSINESS_RULE')->assertJsonMissingPath('pin');
            $this->assertNull($alvo->fresh()->pin_autorizacao);
        }
    }

    public function test_admin_nao_alcanca_usuario_de_outro_tenant_e_a_lista_e_so_de_quem_pode_ter_pin(): void
    {
        config(['sentinel.tenants_clinica' => [self::T, self::OUTRO_TENANT]]);
        $deFora = $this->comPin($this->usuarioClinica('dono', self::OUTRO_TENANT));
        $hash = $deFora->pin_autorizacao;
        $vet = $this->supervisor();
        $semSetor = $this->usuarioClinica('vendedor');
        $this->admin();

        $this->gerar($deFora)->assertStatus(422)->assertJsonPath('mensagem', fn (string $m) => str_contains($m, 'Não encontrei'));
        $this->postJson("/supervisao/pins/{$deFora->id}/revogar")->assertStatus(422);
        $this->assertSame($hash, $deFora->fresh()->pin_autorizacao);

        $ids = collect($this->getJson('/supervisao/supervisores')->assertOk()->json('usuarios'))->pluck('id');
        $this->assertContains($vet->id, $ids);
        $this->assertNotContains($deFora->id, $ids);
        $this->assertNotContains($semSetor->id, $ids, 'o Administrador só vê o dono e os supervisores');
        $this->assertNotContains(auth()->id(), $ids);
    }

    public function test_dono_marca_lista_e_desmarca_com_auditoria_e_desmarcar_zera_o_pin(): void
    {
        $dono = $this->logarComo('dono', self::T);
        $outro = $this->usuarioClinica('atendente');

        $lista = $this->getJson('/supervisao/supervisores')->assertOk()->json('usuarios');
        $this->assertSame(['id', 'nome', 'papel', 'supervisor_setor', 'tem_pin'], array_keys($lista[0]), 'sem e-mail nem hash');

        $this->postJson("/supervisao/supervisores/{$outro->id}", ['setor' => 'petshop'])->assertOk();
        $this->assertSame('petshop', $outro->fresh()->supervisor_setor);
        $this->assertNull($outro->fresh()->pin_autorizacao, 'marcar não dá PIN: quem gera é o Administrador');

        $this->comPin($outro->fresh());
        $this->postJson("/supervisao/supervisores/{$outro->id}", ['setor' => null])->assertOk();
        $this->assertSame([null, null], [$outro->fresh()->supervisor_setor, $outro->fresh()->pin_autorizacao]);

        $logs = AuditLog::query()->where('tool', 'marcar_supervisor')->get();
        $this->assertSame(['sucesso'], $logs->pluck('resultado')->unique()->values()->all());
        $this->assertSame([$dono->id], $logs->pluck('user_id')->unique()->values()->all());
    }

    public function test_o_admin_nao_marca_supervisor(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $this->admin();

        $this->postJson("/supervisao/supervisores/{$vet->id}", ['setor' => 'clinica'])->assertStatus(403)->assertJsonPath('codigo', 'AI_FORBIDDEN');
        $this->assertNull($vet->fresh()->supervisor_setor);
    }

    public function test_quem_nao_e_dono_nem_admin_e_negado(): void
    {
        $vet = $this->supervisor();

        foreach (['veterinario', 'recepcao', 'atendente', 'vendedor'] as $papel) {
            $this->logarComo($papel, self::T);
            $this->getJson('/supervisao/supervisores')->assertStatus(403)->assertJsonPath('codigo', 'AI_FORBIDDEN');
            $this->postJson("/supervisao/supervisores/{$vet->id}", ['setor' => null])->assertStatus(403);
        }

        $this->assertSame('clinica', $vet->fresh()->supervisor_setor);
        $this->assertSame(['negado'], AuditLog::query()->pluck('resultado')->unique()->values()->all());
    }

    public function test_rotas_antigas_sairam(): void
    {
        $vet = $this->comPin($this->supervisor());
        $this->actingAs($vet);
        $this->postJson('/supervisao/meu-pin', ['pin' => '771133', 'pin_confirmacao' => '771133', 'senha_atual' => 'password'])->assertNotFound();

        $this->logarComo('dono', self::T);
        $this->postJson("/supervisao/supervisores/{$vet->id}/zerar-pin")->assertNotFound();
        $this->assertNotNull($vet->fresh()->pin_autorizacao);
    }

    public function test_dono_e_admin_nao_podem_ser_marcados_supervisor_mas_podem_ser_desmarcados(): void
    {
        $dono = $this->logarComo('dono', self::T);
        $outroDono = $this->usuarioClinica('dono');
        $admin = $this->usuarioClinica('admin');

        foreach ([[$dono, 'dono'], [$outroDono, 'dono'], [$admin, 'Administrador']] as [$alvo, $palavra]) {
            foreach (['clinica', 'petshop'] as $setor) {
                $this->postJson("/supervisao/supervisores/{$alvo->id}", ['setor' => $setor])->assertStatus(422)
                    ->assertJsonPath('codigo', 'AI_BUSINESS_RULE')->assertJsonPath('mensagem', fn (string $m) => str_contains($m, $palavra));
            }
            $this->assertNull($alvo->fresh()->supervisor_setor);
        }

        // Um dono ou admin que já esteja marcado (dado antigo) pode ser desmarcado.
        $admin->forceFill(['supervisor_setor' => 'clinica'])->save();
        $this->postJson("/supervisao/supervisores/{$admin->id}", ['setor' => null])->assertOk();
        $this->assertNull($admin->fresh()->supervisor_setor);
    }

    public function test_setor_invalido_e_recusado(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $this->logarComo('dono', self::T);

        foreach ([['setor' => 'farmacia'], [], ['setor' => ['clinica']]] as $corpo) {
            $this->postJson("/supervisao/supervisores/{$vet->id}", $corpo)->assertStatus(422)->assertJsonPath('codigo', 'AI_VALIDATION_ERROR');
        }
        $this->assertNull($vet->fresh()->supervisor_setor);
    }

    public function test_fora_da_clinica_as_rotas_nao_existem(): void
    {
        $alvo = User::factory()->papel('operador')->doTenant(1)->create();
        $this->admin(1);

        $this->getJson('/supervisao/supervisores')->assertStatus(404);
        $this->gerar($alvo)->assertStatus(404);
        $this->postJson("/supervisao/pins/{$alvo->id}/revogar")->assertStatus(404);
        $this->assertNull($alvo->fresh()->pin_autorizacao);
    }

    public function test_auditoria_diz_o_setor_anterior_e_remarcar_no_mesmo_setor_diz_que_nada_mudou(): void
    {
        $vet = $this->comPin($this->supervisor());
        $this->logarComo('dono', self::T);

        $this->postJson("/supervisao/supervisores/{$vet->id}", ['setor' => 'clinica'])->assertOk()
            ->assertJsonPath('mensagem', 'Sem mudança: o usuário já estava nesse setor.');
        $this->assertNotNull($vet->fresh()->pin_autorizacao);
        $this->postJson("/supervisao/supervisores/{$vet->id}", ['setor' => null])->assertOk()->assertJsonPath('mensagem', 'Supervisor atualizado.');

        $logs = AuditLog::query()->where('tool', 'marcar_supervisor')->orderBy('id')->get();
        $this->assertSame([['setor' => 'clinica', 'setor_anterior' => 'clinica'], ['setor' => null, 'setor_anterior' => 'clinica']],
            $logs->pluck('parametros')->all());
    }

    public function test_setor_anterior_de_outro_tenant_nao_vaza_na_auditoria(): void
    {
        $deFora = $this->usuarioClinica('veterinario', self::OUTRO_TENANT);
        $deFora->forceFill(['supervisor_setor' => 'petshop'])->save();
        $this->logarComo('dono', self::T);

        $this->postJson("/supervisao/supervisores/{$deFora->id}", ['setor' => null])->assertStatus(422);
        $this->assertSame(['setor' => null, 'setor_anterior' => null], AuditLog::query()->latest('id')->first()->parametros);
        $this->assertSame('petshop', $deFora->fresh()->supervisor_setor);
    }

    public function test_dono_e_admin_so_valem_enquanto_forem_papeis_da_hierarquia(): void
    {
        $vet = $this->supervisor();
        $dono = $this->logarComo('dono', self::T);
        config(['sentinel.papeis' => array_values(array_diff((array) config('sentinel.papeis'), ['dono']))]);
        $this->getJson('/supervisao/supervisores')->assertForbidden();
        $this->postJson("/supervisao/supervisores/{$vet->id}", ['setor' => null])->assertForbidden();
        $this->get('/supervisao')->assertForbidden();

        config(['sentinel.papeis' => array_values(array_diff((array) config('sentinel.papeis'), ['admin']))]);
        $admin = $this->admin();
        $this->gerar($vet)->assertForbidden();
        $this->assertSame(['clinica', null], [$vet->fresh()->supervisor_setor, $vet->fresh()->pin_autorizacao]);

        // O service também recusa, sem depender do controller.
        try {
            app(SupervisaoService::class)->gerarPin($vet->id, $admin->id, self::T);
            $this->fail('admin fora da hierarquia gerou PIN');
        } catch (\App\Exceptions\RegraDeNegocioException) {
        }
        $this->expectException(\App\Exceptions\RegraDeNegocioException::class);
        app(SupervisaoService::class)->marcarSupervisor($vet->id, null, $dono->id, self::T);
    }

    public function test_senha_do_admin_com_espacos_nas_pontas_nao_e_aparada(): void
    {
        $vet = $this->supervisor();
        $admin = $this->admin();
        $admin->forceFill(['password' => Hash::make(' abc123 ')])->save();
        $this->actingAs($admin->fresh());

        $this->gerar($vet, ' abc123 ')->assertOk();
    }
}
