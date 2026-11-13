<?php

namespace Tests\Feature\Clinica;

use App\Models\AuditLog;
use App\Models\Cobranca;
use App\Models\User;
use App\Services\CobrancaService;
use App\Services\ReautenticacaoAdmin;
use App\Services\SupervisaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * H: estorno de cobrança paga pelo card. Quem pede é o usuário logado; quem autoriza digita no card o PIN (supervisor
 * do setor) ou a senha (dono), no mesmo JSON da reautenticação (email_admin/senha_admin). O PIN nunca vai para a IA,
 * a sessão, o log ou a auditoria; a regra (setor, autoaprovação, papel válido) é reconferida no CobrancaService.
 */
class EstornoNoCardTest extends TestCase
{
    use CenarioClinica, RefreshDatabase, SimulaAgente;

    private const PIN = '482915';

    private User $recepcao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->prepararAgente();
        $this->recepcao = $this->logarComo('recepcao', self::T);
    }

    private function paga(string $setor = 'clinica'): Cobranca
    {
        $cobrancas = app(CobrancaService::class);
        $servico = $setor === 'clinica' ? $this->item() : $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho', 'preco_centavos' => 6000]);
        $profissional = $this->usuarioClinica($setor === 'clinica' ? 'veterinario' : 'atendente');
        $cobranca = $cobrancas->abrir($this->agendamentoDireto($this->pet(), $profissional, $servico)->id, self::T);
        $cobrancas->fechar($cobranca->id, self::T);

        return $cobrancas->registrarPagamento($cobranca->id, 'pix', self::T);
    }

    private function supervisor(string $setor = 'clinica'): User
    {
        $usuario = $this->usuarioClinica($setor === 'clinica' ? 'veterinario' : 'atendente');
        $supervisao = app(SupervisaoService::class);
        $supervisao->marcarSupervisor($usuario->id, $setor, $this->usuarioClinica('dono')->id, self::T);
        $supervisao->definirProprioPin($usuario->id, self::PIN, self::T);

        return $usuario->fresh();
    }

    /** Propõe e revisa o estorno pelo chat (como a recepção logada) e confirma com o que foi digitado no card. */
    private function estornarComCard(Cobranca $cobranca, array $card)
    {
        $token = $this->propor(['id' => $cobranca->id, 'motivo' => 'Cobrado em duplicidade.'], 'estornar_cobranca');
        $this->travel(2)->seconds();

        return $this->postJson('/agente/confirmar', ['token' => $token, ...$card]);
    }

    public function test_supervisor_do_setor_autoriza_com_o_pin_e_o_estorno_grava_quem_pediu_e_quem_autorizou(): void
    {
        $cobranca = $this->paga();
        $supervisor = $this->supervisor();

        $this->estornarComCard($cobranca, ['email_admin' => $supervisor->email, 'senha_admin' => self::PIN])->assertOk();

        $depois = $cobranca->fresh();
        $this->assertSame(['cancelada', $this->recepcao->id, $supervisor->id, 'Cobrado em duplicidade.'],
            [$depois->status, $depois->estorno_solicitado_por, $depois->estorno_autorizado_por, $depois->estorno_motivo]);
        $log = AuditLog::query()->where('tool', 'estornar_cobranca')->latest('id')->first();
        $this->assertSame(['sucesso', $this->recepcao->id, $supervisor->id], [$log->resultado, $log->user_id, $log->autorizado_por]);
        $this->assertStringNotContainsString(self::PIN, (string) json_encode(AuditLog::all()->toArray()));
        $this->assertStringNotContainsString(self::PIN, (string) json_encode(session()->all()));
    }

    public function test_dono_autoriza_com_a_senha_de_login(): void
    {
        $cobranca = $this->paga();
        $dono = $this->usuarioClinica('dono');

        $this->estornarComCard($cobranca, ['email_admin' => $dono->email, 'senha_admin' => 'password'])->assertOk();

        $this->assertSame([$dono->id, 'cancelada'], [$cobranca->fresh()->estorno_autorizado_por, $cobranca->fresh()->status]);
    }

    public function test_atendente_pede_estorno_do_petshop_com_o_pin_do_supervisor_do_petshop(): void
    {
        $cobranca = $this->paga('petshop');
        $supervisor = $this->supervisor('petshop');
        $atendente = $this->logarComo('atendente', self::T);

        $this->estornarComCard($cobranca, ['email_admin' => $supervisor->email, 'senha_admin' => self::PIN])->assertOk();

        $depois = $cobranca->fresh();
        $this->assertSame(['cancelada', $atendente->id, $supervisor->id],
            [$depois->status, $depois->estorno_solicitado_por, $depois->estorno_autorizado_por]);
    }

    public function test_dono_logado_pede_e_se_autoaprova_com_a_propria_senha(): void
    {
        $cobranca = $this->paga();
        $dono = $this->logarComo('dono', self::T);

        $this->estornarComCard($cobranca, ['email_admin' => $dono->email, 'senha_admin' => 'password'])->assertOk();

        $depois = $cobranca->fresh();
        $this->assertSame(['cancelada', $dono->id, $dono->id], [$depois->status, $depois->estorno_solicitado_por, $depois->estorno_autorizado_por]);
    }

    public function test_pin_com_espacos_nas_pontas_vale_como_na_definicao(): void
    {
        $cobranca = $this->paga();
        $supervisor = $this->supervisor();

        $this->estornarComCard($cobranca, ['email_admin' => $supervisor->email, 'senha_admin' => ' '.self::PIN.' '])->assertOk();

        $this->assertSame('cancelada', $cobranca->fresh()->status);
    }

    public function test_dono_ou_supervisor_de_outro_tenant_com_o_segredo_certo_nao_autorizam(): void
    {
        $donoDeFora = $this->usuarioClinica('dono', self::OUTRO_TENANT);
        $supervisorDeFora = $this->usuarioClinica('veterinario', self::OUTRO_TENANT);
        $supervisao = app(SupervisaoService::class);
        $supervisao->marcarSupervisor($supervisorDeFora->id, 'clinica', $donoDeFora->id, self::OUTRO_TENANT);
        $supervisao->definirProprioPin($supervisorDeFora->id, self::PIN, self::OUTRO_TENANT);

        foreach ([[$donoDeFora->email, 'password'], [$supervisorDeFora->email, self::PIN]] as [$email, $segredo]) {
            $cobranca = $this->paga();
            $this->estornarComCard($cobranca, ['email_admin' => $email, 'senha_admin' => $segredo])
                ->assertStatus(403)->assertJsonPath('codigo', 'AI_REAUTH_FAILED');
            $this->assertSame('paga', $cobranca->fresh()->status);
        }
    }

    public function test_senha_de_login_do_supervisor_pin_errado_ou_nada_nao_autorizam(): void
    {
        $supervisor = $this->supervisor();

        foreach ([
            [['email_admin' => $supervisor->email, 'senha_admin' => 'password'], 'AI_REAUTH_FAILED'],
            [['email_admin' => $supervisor->email, 'senha_admin' => '000000'], 'AI_REAUTH_FAILED'],
            [['email_admin' => $this->usuarioClinica('veterinario')->email, 'senha_admin' => 'password'], 'AI_REAUTH_FAILED'],
            [[], 'AI_REAUTH_REQUIRED'],
        ] as [$card, $codigo]) {
            $cobranca = $this->paga();
            $this->estornarComCard($cobranca, $card)->assertStatus(403)->assertJsonPath('codigo', $codigo);
            $this->assertSame('paga', $cobranca->fresh()->status);
        }

        $this->assertSame(['negado'], AuditLog::query()->where('tool', 'estornar_cobranca')->pluck('resultado')->unique()->values()->all());
    }

    public function test_supervisor_de_outro_setor_com_o_pin_certo_e_recusado_pelo_service(): void
    {
        $cobranca = $this->paga();
        $doPetshop = $this->supervisor('petshop');

        $this->estornarComCard($cobranca, ['email_admin' => $doPetshop->email, 'senha_admin' => self::PIN])
            ->assertStatus(422)->assertJsonPath('mensagem', fn (string $m) => str_contains($m, 'supervisor do setor clinica'));

        $this->assertSame('paga', $cobranca->fresh()->status);
    }

    public function test_pin_errado_demais_bloqueia_mesmo_com_o_certo(): void
    {
        $supervisor = $this->supervisor();

        for ($i = 0; $i < ReautenticacaoAdmin::LIMITE_POR_ADMIN; $i++) {
            $this->estornarComCard($this->paga(), ['email_admin' => $supervisor->email, 'senha_admin' => '000000'])->assertStatus(403);
        }

        $cobranca = $this->paga();
        $this->estornarComCard($cobranca, ['email_admin' => $supervisor->email, 'senha_admin' => self::PIN])
            ->assertStatus(429)->assertJsonPath('codigo', 'AI_REAUTH_RATE_LIMIT');
        $this->assertSame('paga', $cobranca->fresh()->status);
    }

    public function test_so_quem_pode_pedir_propoe_e_o_card_pede_a_autorizacao_sem_campo_de_pin(): void
    {
        $cobranca = $this->paga();

        $resposta = $this->comando('estornar_cobranca', ['id' => $cobranca->id, 'motivo' => 'Erro.'])->assertOk()
            ->assertJsonPath('tipo', 'confirmacao_pendente')->assertJsonPath('formulario.reautenticar', true);
        $this->assertSame(['motivo'], array_column($resposta->json('formulario.campos'), 'nome'));

        $this->logarComo('vendedor', self::T);
        $this->comando('estornar_cobranca', ['id' => $cobranca->id, 'motivo' => 'Erro.'])->assertStatus(403)->assertJsonPath('tipo', 'negado');

        // Cobrança que não está paga não é achada para estorno.
        $this->logarComo('recepcao', self::T);
        $aberta = app(CobrancaService::class)->abrir($this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item())->id, self::T);
        $this->comando('estornar_cobranca', ['id' => $aberta->id, 'motivo' => 'Erro.'])->assertStatus(422)
            ->assertJsonPath('mensagem', fn (string $m) => str_contains($m, 'Não encontrei a cobrança paga'));
    }
}
