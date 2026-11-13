<?php

namespace Tests\Feature\Clinica;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\SupervisaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

/**
 * Item I: endpoints JSON do supervisor e do PIN. O dono lista, marca/desmarca e zera; o supervisor define o próprio
 * PIN com a senha de login. Papel, tenant e "quem" vêm da sessão; o PIN nunca sai na resposta nem na auditoria.
 */
class SupervisaoEndpointsTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    private const PIN = '482915';

    private function supervisorComPin(): User
    {
        $vet = $this->usuarioClinica('veterinario');
        $supervisao = app(SupervisaoService::class);
        $supervisao->marcarSupervisor($vet->id, 'clinica', $this->usuarioClinica('dono')->id, self::T);
        $supervisao->definirProprioPin($vet->id, self::PIN, self::T);

        return $vet->fresh();
    }

    public function test_dono_marca_lista_desmarca_e_zera_pin_com_auditoria(): void
    {
        $vet = $this->supervisorComPin();
        $dono = $this->logarComo('dono', self::T);

        $lista = $this->getJson('/supervisao/supervisores')->assertOk()->json('usuarios');
        $linha = collect($lista)->firstWhere('id', $vet->id);
        $this->assertSame(['id', 'nome', 'papel', 'supervisor_setor', 'tem_pin'], array_keys($linha), 'sem e-mail nem hash');
        $this->assertSame(['clinica', true], [$linha['supervisor_setor'], $linha['tem_pin']]);
        $this->assertStringNotContainsString((string) $vet->pin_autorizacao, json_encode($lista));

        $this->postJson("/supervisao/supervisores/{$vet->id}/zerar-pin")->assertOk();
        $this->assertNull($vet->fresh()->pin_autorizacao);

        $outro = $this->usuarioClinica('atendente');
        $this->postJson("/supervisao/supervisores/{$outro->id}", ['setor' => 'petshop'])->assertOk();
        $this->assertSame('petshop', $outro->fresh()->supervisor_setor);

        app(SupervisaoService::class)->definirProprioPin($outro->id, self::PIN, self::T);
        $this->postJson("/supervisao/supervisores/{$outro->id}", ['setor' => null])->assertOk();
        $this->assertSame([null, null], [$outro->fresh()->supervisor_setor, $outro->fresh()->pin_autorizacao]);

        $logs = AuditLog::query()->whereIn('tool', ['zerar_pin', 'marcar_supervisor'])->get();
        $this->assertSame(['sucesso'], $logs->pluck('resultado')->unique()->values()->all());
        $this->assertSame([$dono->id], $logs->pluck('user_id')->unique()->values()->all());
        $this->assertSame(['manual'], $logs->pluck('origem')->unique()->values()->all());
    }

    public function test_quem_nao_e_dono_e_negado_e_nada_muda(): void
    {
        $vet = $this->supervisorComPin();
        $hash = $vet->pin_autorizacao;

        foreach (['veterinario', 'recepcao', 'atendente', 'vendedor'] as $papel) {
            $this->logarComo($papel, self::T);
            $this->getJson('/supervisao/supervisores')->assertStatus(403)->assertJsonPath('codigo', 'AI_FORBIDDEN');
            $this->postJson("/supervisao/supervisores/{$vet->id}", ['setor' => null])->assertStatus(403);
            $this->postJson("/supervisao/supervisores/{$vet->id}/zerar-pin")->assertStatus(403);
        }

        $this->assertSame(['clinica', $hash], [$vet->fresh()->supervisor_setor, $vet->fresh()->pin_autorizacao]);
        $this->assertSame(['negado'], AuditLog::query()->pluck('resultado')->unique()->values()->all());
    }

    public function test_dono_nao_alcanca_usuario_de_outro_tenant_e_a_lista_e_do_tenant(): void
    {
        $deFora = $this->usuarioClinica('veterinario', self::OUTRO_TENANT);
        $this->logarComo('dono', self::T);

        $this->postJson("/supervisao/supervisores/{$deFora->id}", ['setor' => 'clinica'])->assertStatus(422)
            ->assertJsonPath('mensagem', fn (string $m) => str_contains($m, 'Não encontrei'));
        $this->postJson("/supervisao/supervisores/{$deFora->id}/zerar-pin")->assertStatus(422);
        $this->assertNull($deFora->fresh()->supervisor_setor);
        $recusas = AuditLog::query()->where('resultado', 'recusado')->get();
        $this->assertSame([[$deFora->id], ['marcar_supervisor', 'zerar_pin']],
            [$recusas->pluck('entidade_id')->unique()->values()->all(), $recusas->pluck('tool')->sort()->values()->all()]);

        $ids = collect($this->getJson('/supervisao/supervisores')->json('usuarios'))->pluck('id');
        $this->assertNotContains($deFora->id, $ids);
    }

    /** B3 (tarde 2): o dono não se marca supervisor (nem outro dono); desmarcar um dono já marcado continua valendo. */
    public function test_dono_nao_pode_ser_marcado_supervisor_mas_pode_ser_desmarcado(): void
    {
        $dono = $this->logarComo('dono', self::T);
        $outroDono = $this->usuarioClinica('dono');

        foreach ([$dono, $outroDono] as $alvo) {
            foreach (['clinica', 'petshop'] as $setor) {
                $this->postJson("/supervisao/supervisores/{$alvo->id}", ['setor' => $setor])->assertStatus(422)
                    ->assertJsonPath('codigo', 'AI_BUSINESS_RULE')->assertJsonPath('categoria', 'business_rule')
                    ->assertJsonPath('tipo', 'erro')->assertJsonPath('mensagem', fn (string $m) => str_contains($m, 'dono'));
            }
            $this->assertNull($alvo->fresh()->supervisor_setor);
        }
        $recusas = AuditLog::query()->where('tool', 'marcar_supervisor')->get();
        $this->assertSame([4, ['recusado'], [$dono->id, $outroDono->id]],
            [$recusas->count(), $recusas->pluck('resultado')->unique()->values()->all(), $recusas->pluck('entidade_id')->unique()->sort()->values()->all()]);

        // Um dono que já esteja marcado (dado antigo) pode ser desmarcado.
        $outroDono->forceFill(['supervisor_setor' => 'clinica', 'pin_autorizacao' => Hash::make(self::PIN)])->save();
        $this->postJson("/supervisao/supervisores/{$outroDono->id}", ['setor' => null])->assertOk();
        $this->assertSame([null, null], [$outroDono->fresh()->supervisor_setor, $outroDono->fresh()->pin_autorizacao]);
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
        $this->logarComo('admin', 1);

        $this->getJson('/supervisao/supervisores')->assertStatus(404);
        $this->postJson('/supervisao/meu-pin', ['pin' => self::PIN, 'pin_confirmacao' => self::PIN, 'senha_atual' => 'password'])->assertStatus(404);
    }

    public function test_supervisor_define_o_proprio_pin_com_a_senha_de_login(): void
    {
        $vet = $this->supervisorComPin();
        $this->actingAs($vet);
        $outroSupervisor = $this->supervisorComPin();

        $resposta = $this->postJson('/supervisao/meu-pin', ['pin' => ' 771133 ', 'pin_confirmacao' => ' 771133 ', 'senha_atual' => 'password',
            'usuario_id' => $outroSupervisor->id])->assertOk();

        $this->assertTrue(Hash::check('771133', $vet->fresh()->pin_autorizacao));
        $this->assertTrue(Hash::check(self::PIN, $outroSupervisor->fresh()->pin_autorizacao), 'o alvo é sempre o usuário logado');
        $this->assertStringNotContainsString('771133', $resposta->getContent());
        $this->assertStringNotContainsString('771133', (string) json_encode(AuditLog::all()->toArray()));
        $this->assertStringNotContainsString('password', (string) json_encode(AuditLog::all()->toArray()));
        $this->assertSame('sucesso', AuditLog::query()->where('tool', 'definir_pin')->value('resultado'));
    }

    public function test_senha_errada_confirmacao_diferente_ou_quem_nao_e_supervisor_nao_definem_pin(): void
    {
        $vet = $this->supervisorComPin();
        $hash = $vet->pin_autorizacao;
        $this->actingAs($vet);

        $this->postJson('/supervisao/meu-pin', ['pin' => '771133', 'pin_confirmacao' => '771133', 'senha_atual' => 'errada'])
            ->assertStatus(403)->assertJsonPath('codigo', 'AI_REAUTH_FAILED');
        $negado = AuditLog::query()->where('tool', 'definir_pin')->sole();
        $this->assertSame(['negado', false, $vet->id], [$negado->resultado, $negado->permitido, $negado->entidade_id]);
        $this->assertStringNotContainsString('errada', (string) json_encode($negado->toArray()));
        $this->postJson('/supervisao/meu-pin', ['pin' => '771133', 'pin_confirmacao' => '771134', 'senha_atual' => 'password'])
            ->assertStatus(422);
        $this->postJson('/supervisao/meu-pin', ['pin' => 'password', 'pin_confirmacao' => 'password', 'senha_atual' => 'password'])
            ->assertStatus(422)->assertJsonPath('mensagem', fn (string $m) => str_contains($m, 'igual à sua senha'));
        $this->assertSame($hash, $vet->fresh()->pin_autorizacao);

        $this->logarComo('recepcao', self::T);
        $this->postJson('/supervisao/meu-pin', ['pin' => '771133', 'pin_confirmacao' => '771133', 'senha_atual' => 'password'])
            ->assertStatus(422)->assertJsonPath('mensagem', fn (string $m) => str_contains($m, 'Só um supervisor'));
    }

    public function test_tentativas_do_pin_tem_teto(): void
    {
        $vet = $this->supervisorComPin();
        $this->actingAs($vet);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/supervisao/meu-pin', ['pin' => '771133', 'pin_confirmacao' => '771133', 'senha_atual' => 'errada'])->assertStatus(403);
        }

        $this->postJson('/supervisao/meu-pin', ['pin' => '771133', 'pin_confirmacao' => '771133', 'senha_atual' => 'password'])->assertStatus(429);
        $this->assertSame(5, \Illuminate\Support\Facades\RateLimiter::attempts('pin:'.$vet->id), 'o pedido barrado devolve a tentativa');
        $this->travel(9)->minutes();
        $this->postJson('/supervisao/meu-pin', ['pin' => '771133', 'pin_confirmacao' => '771133', 'senha_atual' => 'password'])->assertStatus(429);
        $this->assertTrue(Hash::check(self::PIN, $vet->fresh()->pin_autorizacao));

        // O teto é por usuário: outro supervisor não é barrado pelas tentativas do primeiro.
        $this->actingAs($this->supervisorComPin());
        $this->postJson('/supervisao/meu-pin', ['pin' => '771133', 'pin_confirmacao' => '771133', 'senha_atual' => 'password'])->assertOk();
    }

    /** B2 (tarde 2): a tentativa já está contada quando a senha é conferida (rajadas em paralelo não passam do teto). */
    public function test_tentativa_do_pin_e_contada_antes_de_conferir_a_senha(): void
    {
        $vet = $this->supervisorComPin();
        $this->actingAs($vet);
        $visto = null;

        Hash::shouldReceive('check')->once()->andReturnUsing(function () use (&$visto, $vet) {
            $visto = \Illuminate\Support\Facades\RateLimiter::attempts('pin:'.$vet->id);

            return false;
        });

        $this->postJson('/supervisao/meu-pin', ['pin' => '771133', 'pin_confirmacao' => '771133', 'senha_atual' => 'errada'])->assertStatus(403);

        $this->assertSame(1, $visto, 'a tentativa precisa estar contada ANTES do Hash::check');
        $this->assertSame(1, \Illuminate\Support\Facades\RateLimiter::attempts('pin:'.$vet->id), 'erro continua contando');
    }

    public function test_acerto_devolve_a_tentativa_do_pin(): void
    {
        $vet = $this->supervisorComPin();
        $this->actingAs($vet);

        $this->postJson('/supervisao/meu-pin', ['pin' => '771133', 'pin_confirmacao' => '771133', 'senha_atual' => 'password'])->assertOk();

        $this->assertSame(0, \Illuminate\Support\Facades\RateLimiter::attempts('pin:'.$vet->id));
    }

    public function test_senha_de_login_com_espacos_nas_pontas_nao_e_aparada(): void
    {
        $vet = $this->supervisorComPin();
        $vet->forceFill(['password' => Hash::make(' abc123 ')])->save();
        $this->actingAs($vet->fresh());

        $this->postJson('/supervisao/meu-pin', ['pin' => '771133', 'pin_confirmacao' => '771133', 'senha_atual' => ' abc123 '])->assertOk();
        $this->assertTrue(Hash::check('771133', $vet->fresh()->pin_autorizacao));
    }
}
