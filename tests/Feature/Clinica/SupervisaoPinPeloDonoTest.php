<?php

namespace Tests\Feature\Clinica;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\ReautenticacaoAdmin;
use App\Services\SupervisaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\ProvaDeTravas;
use Tests\TestCase;

/**
 * Bloco B (15/11, decisão do Lucas): o dono também gera e revoga o próprio PIN e o dos supervisores, além do
 * Administrador. Continua: 6 dígitos de random_int, mostrado uma vez, só o hash, código novo invalida o anterior,
 * auditoria sem o código, teto de tentativas da senha de login contado antes de conferir. O dono autoriza o estorno só
 * com o PIN (a senha não serve; sem PIN não autoriza) e o Administrador nunca autoriza.
 */
class SupervisaoPinPeloDonoTest extends TestCase
{
    use CenarioClinica, ProvaDeTravas, RefreshDatabase;

    private const SENHA_DONO = 'senha-do-dono-2026';

    private function dono(int $tenant = self::T): User
    {
        $dono = User::factory()->papel('dono')->doTenant($tenant)->create(['password' => Hash::make(self::SENHA_DONO)]);
        $this->actingAs($dono);

        return $dono;
    }

    private function supervisor(User $dono, string $papel = 'veterinario', string $setor = 'clinica'): User
    {
        $usuario = $this->usuarioClinica($papel, (int) $dono->tenant_id);
        app(SupervisaoService::class)->marcarSupervisor($usuario->id, $setor, $dono->id, (int) $dono->tenant_id);

        return $usuario->fresh();
    }

    private function gerar(User $alvo, string $senha = self::SENHA_DONO)
    {
        return $this->postJson("/supervisao/pins/{$alvo->id}/gerar", ['senha_atual' => $senha]);
    }

    public function test_dono_gera_o_proprio_pin_mostrado_uma_vez_so_o_hash_e_auditoria_sem_o_codigo(): void
    {
        $dono = $this->dono();
        Log::spy();

        $resposta = $this->gerar($dono)->assertOk()->assertJsonPath('tipo', 'sucesso');
        $pin = (string) $resposta->json('pin');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);
        $this->assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
        $this->assertTrue(Hash::check($pin, $dono->fresh()->pin_autorizacao));
        $this->assertNotSame($pin, $dono->fresh()->pin_autorizacao);

        $log = AuditLog::query()->where('tool', 'gerar_pin')->sole();
        $this->assertSame(['sucesso', $dono->id, $dono->id, 'manual', self::T, 'dono', true],
            [$log->resultado, $log->user_id, $log->entidade_id, $log->origem, (int) $log->tenant_id, $log->papel, (bool) $log->permitido]);
        $tudo = (string) json_encode(AuditLog::all()->toArray());
        $this->assertStringNotContainsString($pin, $tudo);
        $this->assertStringNotContainsString(self::SENHA_DONO, $tudo);
        $this->assertStringNotContainsString($pin, (string) json_encode(session()->all()));
        $contem = fn ($v) => str_contains((string) json_encode($v), $pin);
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $nivel) {
            Log::shouldNotHaveReceived($nivel, [\Mockery::on($contem)]);
            Log::shouldNotHaveReceived($nivel, [\Mockery::any(), \Mockery::on($contem)]);
        }
        $this->assertStringNotContainsString($pin, $this->getJson('/supervisao/supervisores')->assertOk()->getContent());
    }

    public function test_dono_gera_e_revoga_o_pin_do_supervisor_e_o_codigo_novo_invalida_o_anterior(): void
    {
        $dono = $this->dono();
        $vet = $this->supervisor($dono);

        $primeiro = (string) $this->gerar($vet)->assertOk()->json('pin');
        $segundo = (string) $this->gerar($vet)->assertOk()->json('pin');
        $hash = $vet->fresh()->pin_autorizacao;
        $this->assertTrue(Hash::check($segundo, $hash));
        if ($primeiro !== $segundo) {
            $this->assertFalse(Hash::check($primeiro, $hash), 'o código anterior deixa de valer');
        }

        $this->postJson("/supervisao/pins/{$vet->id}/revogar")->assertOk()
            ->assertJsonPath('mensagem', 'PIN revogado para '.$vet->name.' por '.$dono->name.'.')->assertJsonMissingPath('pin');
        $this->assertNull($vet->fresh()->pin_autorizacao);
        $this->assertSame(['gerar_pin', 'gerar_pin', 'revogar_pin'], AuditLog::query()->orderBy('id')->pluck('tool')->all());
    }

    public function test_dono_nao_da_pin_a_quem_nao_pode_ter_nem_ao_administrador(): void
    {
        $this->dono();
        $recepcao = $this->usuarioClinica('recepcao');
        $admin = $this->usuarioClinica('admin');

        foreach ([$recepcao, $admin] as $alvo) {
            $this->gerar($alvo)->assertStatus(422)->assertJsonPath('codigo', 'AI_BUSINESS_RULE')->assertJsonMissingPath('pin');
            $this->assertNull($alvo->fresh()->pin_autorizacao);
        }
    }

    public function test_dono_nao_alcanca_usuario_de_outro_tenant_e_dono_de_outro_tenant_nao_alcanca_este(): void
    {
        $donoDeFora = User::factory()->papel('dono')->doTenant(self::OUTRO_TENANT)->create();
        $deFora = $this->comPin($this->supervisor($donoDeFora));
        $hashDeFora = $deFora->pin_autorizacao;

        $this->dono();
        $this->gerar($deFora)->assertStatus(422)->assertJsonMissingPath('pin');
        $this->postJson("/supervisao/pins/{$deFora->id}/revogar")->assertStatus(422);
        $this->assertSame($hashDeFora, $deFora->fresh()->pin_autorizacao);

        // O service também recusa, sem depender do controller.
        $this->expectException(\App\Exceptions\RegraDeNegocioException::class);
        $this->expectExceptionMessage('Não encontrei o dono ou o Administrador');
        app(SupervisaoService::class)->revogarPin($deFora->id, $this->usuarioClinica('dono')->id, self::OUTRO_TENANT);
    }

    public function test_quem_nao_e_dono_nem_admin_continua_sem_gerar_e_a_negacao_e_auditada(): void
    {
        $dono = User::factory()->papel('dono')->doTenant(self::T)->create();
        $vet = $this->comPin($this->supervisor($dono));
        $hash = $vet->pin_autorizacao;

        foreach (['veterinario', 'recepcao', 'atendente', 'vendedor'] as $papel) {
            $this->actingAs(User::factory()->papel($papel)->doTenant(self::T)->create(['password' => Hash::make(self::SENHA_DONO)]));
            $this->gerar($vet)->assertStatus(403)->assertJsonPath('mensagem', 'Só o dono e o Administrador geram e revogam PIN.');
            $this->postJson("/supervisao/pins/{$vet->id}/revogar")->assertStatus(403);
        }
        // O supervisor, nem o dele.
        $this->actingAs($vet);
        $this->gerar($vet)->assertStatus(403);

        $this->assertSame($hash, $vet->fresh()->pin_autorizacao);
        $this->assertSame(['negado'], AuditLog::query()->pluck('resultado')->unique()->values()->all());
    }

    public function test_senha_errada_do_dono_nao_gera_e_o_teto_conta_antes_de_conferir(): void
    {
        $dono = $this->dono();
        $chave = 'pin-admin:'.$dono->id;

        $this->gerar($dono, 'errada-123')->assertStatus(403)->assertJsonPath('codigo', 'AI_REAUTH_FAILED')->assertJsonMissingPath('pin');
        $negado = AuditLog::query()->where('tool', 'gerar_pin')->sole();
        $this->assertSame(['negado', false, $dono->id, 'dono'], [$negado->resultado, (bool) $negado->permitido, $negado->entidade_id, $negado->papel]);
        $this->assertStringNotContainsString('errada-123', (string) json_encode(AuditLog::all()->toArray()));
        for ($i = 0; $i < 4; $i++) {
            $this->gerar($dono, 'errada')->assertStatus(403);
        }
        $this->gerar($dono)->assertStatus(429)->assertJsonMissingPath('pin');
        $this->assertNull($dono->fresh()->pin_autorizacao);

        RateLimiter::clear($chave);
        $visto = null;
        Hash::shouldReceive('check')->once()->andReturnUsing(function () use (&$visto, $chave) {
            $visto = RateLimiter::attempts($chave);

            return false;
        });
        $this->gerar($dono, 'errada')->assertStatus(403);
        $this->assertSame(1, $visto, 'contada antes do Hash::check');
    }

    public function test_dono_autoriza_estorno_so_com_o_pin_que_gerou_e_o_admin_nunca(): void
    {
        $dono = $this->dono();
        $reautenticacao = app(ReautenticacaoAdmin::class);

        $this->assertSame(['falha' => 'invalido'], $reautenticacao->autorizarEstorno($dono->email, self::SENHA_DONO), 'sem PIN não autoriza');

        $pin = (string) $this->gerar($dono)->assertOk()->json('pin');
        $this->assertSame(['falha' => 'invalido'], $reautenticacao->autorizarEstorno($dono->email, self::SENHA_DONO), 'a senha de login não autoriza');
        $this->assertSame(['admin' => $dono->id], $reautenticacao->autorizarEstorno($dono->email, $pin));

        // Mesmo com setor marcado e um hash de PIN por dado antigo, o Administrador não autoriza nem ganha PIN novo.
        $admin = $this->comPin($this->usuarioClinica('admin'));
        $admin->forceFill(['supervisor_setor' => 'clinica'])->save();
        $this->assertSame(['falha' => 'invalido'], $reautenticacao->autorizarEstorno($admin->email, '482915'), 'o Administrador nunca autoriza');
        $this->gerar($admin)->assertStatus(422)->assertJsonMissingPath('pin');
    }

    public function test_dono_fora_da_clinica_nao_gera_nem_revoga_pin_no_service(): void
    {
        // OUTRO_TENANT não é tenant da clínica (sentinel.tenants_clinica): lá não existe quem gere PIN.
        $dono = User::factory()->papel('dono')->doTenant(self::OUTRO_TENANT)->create();
        $alvo = $this->comPin($this->supervisor($dono));
        $hash = $alvo->pin_autorizacao;

        foreach (['gerarPin', 'revogarPin'] as $metodo) {
            try {
                app(SupervisaoService::class)->{$metodo}($alvo->id, $dono->id, self::OUTRO_TENANT);
                $this->fail("{$metodo} passou fora da clínica");
            } catch (\App\Exceptions\RegraDeNegocioException $e) {
                $this->assertSame('Só o dono e o Administrador geram e revogam PIN.', $e->getMessage());
            }
        }
        $this->assertSame($hash, $alvo->fresh()->pin_autorizacao);
    }

    /** 2ª passada do Bloco B: o dono gera e revoga o próprio PIN e o dos supervisores, não o de outro dono (o Admin sim). */
    public function test_dono_nao_gera_nem_revoga_o_pin_de_outro_dono_e_o_admin_gera(): void
    {
        $outroDono = $this->comPin($this->usuarioClinica('dono'));
        $hash = $outroDono->pin_autorizacao;
        $this->dono();

        $this->gerar($outroDono)->assertStatus(422)->assertJsonPath('mensagem', fn (string $m) => str_contains($m, 'só o próprio PIN'))->assertJsonMissingPath('pin');
        $this->postJson("/supervisao/pins/{$outroDono->id}/revogar")->assertStatus(422);
        $this->assertSame($hash, $outroDono->fresh()->pin_autorizacao);

        $admin = $this->usuarioClinica('admin');
        $this->assertMatchesRegularExpression('/^\d{6}$/', app(SupervisaoService::class)->gerarPin($outroDono->id, $admin->id, self::T));
    }

    /** 2ª passada do Bloco B: trocar o setor lê o alvo travado (como o gerarPin), para ver o PIN de um gerar simultâneo. */
    public function test_marcar_supervisor_le_o_alvo_com_trava(): void
    {
        $dono = $this->dono();
        $vet = $this->comPin($this->supervisor($dono));
        $this->mostrarTravas();

        $sqls = $this->leiturasTravadas(fn () => app(SupervisaoService::class)->marcarSupervisor($vet->id, 'petshop', $dono->id, self::T));

        $this->assertTravouLeitura($sqls, 'users');
        $this->assertSame(['petshop', null], [$vet->fresh()->supervisor_setor, $vet->fresh()->pin_autorizacao], 'trocar o setor zera o PIN');
    }
}
