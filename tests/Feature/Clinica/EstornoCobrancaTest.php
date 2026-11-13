<?php

namespace Tests\Feature\Clinica;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Cobranca;
use App\Models\User;
use App\Services\CobrancaService;
use App\Services\SupervisaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

/** Estorno de cobrança paga (duas pessoas) e a supervisão que o autoriza (marcação pelo dono, PIN). */
class EstornoCobrancaTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    private const PIN = '482915';

    private CobrancaService $cobrancas;

    private SupervisaoService $supervisao;

    private User $dono;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->cobrancas = app(CobrancaService::class);
        $this->supervisao = app(SupervisaoService::class);
        $this->dono = $this->usuarioClinica('dono');
    }

    private function recusa(callable $acao, string $frase): void
    {
        try {
            $acao();
            $this->fail("Esperava a recusa: {$frase}");
        } catch (RegraDeNegocioException $e) {
            $this->assertStringContainsString($frase, $e->getMessage());
        }
    }

    /** Cobrança PAGA de um atendimento do setor dado (clínica: consulta + 2 vermífugos que baixaram estoque). */
    private function paga(string $setor = 'clinica'): Cobranca
    {
        $servico = $setor === 'clinica' ? $this->item() : $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho', 'preco_centavos' => 6000]);
        $profissional = $this->usuarioClinica($setor === 'clinica' ? 'veterinario' : 'atendente');
        $cobranca = $this->cobrancas->abrir($this->agendamentoDireto($this->pet(), $profissional, $servico)->id, self::T);

        if ($setor === 'clinica') {
            $vermifugo = $this->produto(['setor' => 'clinica', 'categoria' => 'farmacia', 'nome_comercial' => 'Vermífugo', 'preco_centavos' => 3500, 'estoque' => 5]);
            $this->cobrancas->adicionarItem($cobranca->id, $vermifugo->id, 2, self::T);
        }

        $this->cobrancas->fechar($cobranca->id, self::T);

        return $this->cobrancas->registrarPagamento($cobranca->id, 'pix', self::T);
    }

    private function supervisor(string $papel, ?string $setor, bool $comPin = true, int $tenant = self::T): User
    {
        $usuario = $this->usuarioClinica($papel, $tenant);
        $dono = $tenant === self::T ? $this->dono : $this->usuarioClinica('dono', $tenant);
        $this->supervisao->marcarSupervisor($usuario->id, $setor, $dono->id, $tenant);
        if ($comPin) {
            $this->supervisao->definirProprioPin($usuario->id, self::PIN, $tenant);
        }

        return $usuario->fresh();
    }

    private function estornar(Cobranca $cobranca, User $solicitante, User $autorizador, string $motivo = 'Cobrado em duplicidade.', bool $conferida = true): Cobranca
    {
        return $this->cobrancas->estornar($cobranca->id, $motivo, $solicitante->id, $autorizador->id, self::T, $conferida);
    }

    public function test_sem_a_conferencia_do_autorizador_nada_muda_nem_para_o_dono(): void
    {
        $cobranca = $this->paga();

        $this->recusa(fn () => $this->estornar($cobranca, $this->usuarioClinica('recepcao'), $this->supervisor('veterinario', 'clinica'), conferida: false), 'precisa da confirmação de quem autoriza');
        $this->recusa(fn () => $this->estornar($cobranca, $this->dono, $this->dono, conferida: false), 'precisa da confirmação de quem autoriza');
        $this->assertSame('paga', $cobranca->fresh()->status);
    }

    public function test_desmarcar_ou_trocar_o_setor_zera_o_pin(): void
    {
        $supervisor = $this->supervisor('recepcao', 'clinica');
        $this->assertNotNull($supervisor->pin_autorizacao);

        $this->supervisao->marcarSupervisor($supervisor->id, 'clinica', $this->dono->id, self::T);
        $this->assertNotNull($supervisor->fresh()->pin_autorizacao, 'remarcar no mesmo setor não zera');

        $this->supervisao->marcarSupervisor($supervisor->id, 'petshop', $this->dono->id, self::T);
        $this->assertNull($supervisor->fresh()->pin_autorizacao);

        $this->supervisao->definirProprioPin($supervisor->id, self::PIN, self::T);
        $this->supervisao->marcarSupervisor($supervisor->id, null, $this->dono->id, self::T);
        $this->supervisao->marcarSupervisor($supervisor->id, 'petshop', $this->dono->id, self::T);
        $this->assertNull($supervisor->fresh()->pin_autorizacao, 'remarcado depois de desmarcado: PIN antigo não volta');
    }

    public function test_recepcao_pede_e_supervisor_da_clinica_com_pin_autoriza_tudo_gravado_e_estoque_devolvido(): void
    {
        $cobranca = $this->paga();
        $recepcao = $this->usuarioClinica('recepcao');
        $supervisor = $this->supervisor('veterinario', 'clinica');
        $this->assertSame(3, \App\Models\CatalogoItem::where('nome_comercial', 'Vermífugo')->value('estoque'));

        $estornada = $this->estornar($cobranca, $recepcao, $supervisor, '  Cobrado em duplicidade.  ');

        $this->assertSame(
            ['cancelada', $recepcao->id, $supervisor->id, 'Cobrado em duplicidade.', 22000, '2026-11-10 10:00:00'],
            [$estornada->status, $estornada->estorno_solicitado_por, $estornada->estorno_autorizado_por, $estornada->estorno_motivo,
                $estornada->estorno_valor_centavos, $estornada->estornado_em->format('Y-m-d H:i:s')],
        );
        $this->assertSame(['pix', 22000], [$estornada->forma_pagamento, $estornada->total_centavos], 'o registro do pagamento fica');
        $this->assertSame(5, \App\Models\CatalogoItem::where('nome_comercial', 'Vermífugo')->value('estoque'));
        $this->recusa(fn () => $this->estornar($estornada->fresh(), $recepcao, $supervisor), 'Só uma cobrança paga é estornada');
    }

    public function test_quem_pede_depende_do_setor_da_cobranca(): void
    {
        $clinica = $this->paga();
        $petshop = $this->paga('petshop');
        $autorizaClinica = $this->supervisor('veterinario', 'clinica');
        $autorizaPetshop = $this->supervisor('vendedor', 'petshop');

        foreach (['atendente', 'vendedor', 'veterinario'] as $papel) {
            $this->recusa(fn () => $this->estornar($clinica, $this->usuarioClinica($papel), $autorizaClinica), 'é pedido pela recepção ou pelo dono');
        }
        foreach (['recepcao', 'vendedor', 'veterinario'] as $papel) {
            $this->recusa(fn () => $this->estornar($petshop, $this->usuarioClinica($papel), $autorizaPetshop), 'é pedido pela equipe de atendimento do petshop ou pelo dono');
        }
        $this->assertSame(['paga', 'paga'], [$clinica->fresh()->status, $petshop->fresh()->status]);

        $this->assertSame('cancelada', $this->estornar($petshop, $this->usuarioClinica('atendente'), $autorizaPetshop)->status);
        $this->assertSame('cancelada', $this->estornar($clinica, $this->usuarioClinica('recepcao'), $this->dono)->status, 'o dono autoriza em qualquer setor');
    }

    public function test_dono_estorna_direto(): void
    {
        $estornada = $this->estornar($this->paga('petshop'), $this->dono, $this->dono);

        $this->assertSame(['cancelada', $this->dono->id, $this->dono->id], [$estornada->status, $estornada->estorno_solicitado_por, $estornada->estorno_autorizado_por]);
    }

    public function test_autorizador_de_outro_setor_de_outro_tenant_sem_marcacao_ou_sem_pin_e_recusado(): void
    {
        $cobranca = $this->paga();
        $recepcao = $this->usuarioClinica('recepcao');

        $this->recusa(fn () => $this->estornar($cobranca, $recepcao, $this->supervisor('atendente', 'petshop')), 'Só o dono ou um supervisor do setor clínica');
        $this->recusa(fn () => $this->estornar($cobranca, $recepcao, $this->usuarioClinica('veterinario')), 'Só o dono ou um supervisor do setor clínica');
        $this->recusa(fn () => $this->estornar($cobranca, $recepcao, $this->supervisor('veterinario', 'clinica', comPin: false)), 'ainda não definiu o PIN');

        $deFora = $this->supervisor('veterinario', 'clinica', tenant: self::OUTRO_TENANT);
        $this->recusa(fn () => $this->estornar($cobranca, $recepcao, $deFora), "Não encontrei quem autorizou o estorno #{$deFora->id}.");
        $this->recusa(fn () => $this->estornar($cobranca, $recepcao, $this->usuarioClinica('dono', self::OUTRO_TENANT)), 'Não encontrei quem autorizou o estorno');

        $this->assertSame('paga', $cobranca->fresh()->status);
        $this->assertNull($cobranca->fresh()->estorno_autorizado_por);
    }

    public function test_quem_pede_nao_autoriza_o_proprio_pedido(): void
    {
        $cobranca = $this->paga();
        $recepcaoSupervisora = $this->supervisor('recepcao', 'clinica');

        $this->recusa(fn () => $this->estornar($cobranca, $recepcaoSupervisora, $recepcaoSupervisora), 'não pode autorizá-lo');
        $this->assertSame('paga', $cobranca->fresh()->status);
    }

    public function test_motivo_obrigatorio_so_cobranca_paga_e_outro_tenant_inexistente(): void
    {
        $cobranca = $this->paga();
        $recepcao = $this->usuarioClinica('recepcao');

        $this->recusa(fn () => $this->estornar($cobranca, $recepcao, $this->dono, '   '), 'Informe o motivo do estorno.');
        $this->recusa(fn () => $this->estornar($cobranca, $recepcao, $this->dono, str_repeat('x', 501)), 'passa de 500 caracteres');

        $fechada = $this->cobrancas->abrir($this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item())->id, self::T);
        $this->cobrancas->fechar($fechada->id, self::T);
        $this->recusa(fn () => $this->estornar($fechada, $recepcao, $this->dono), 'Só uma cobrança paga é estornada (esta está fechada)');

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->cobrancas->estornar($cobranca->id, 'Motivo.', $recepcao->id, $this->dono->id, self::OUTRO_TENANT, true);
    }

    public function test_so_o_dono_marca_supervisor_e_zera_pin(): void
    {
        $alvo = $this->usuarioClinica('recepcao');

        foreach (['veterinario', 'recepcao', 'atendente', 'vendedor'] as $papel) {
            $outro = $this->usuarioClinica($papel);
            $this->recusa(fn () => $this->supervisao->marcarSupervisor($alvo->id, 'clinica', $outro->id, self::T), 'Só o dono');
            $this->recusa(fn () => $this->supervisao->resetarPin($alvo->id, $outro->id, self::T), 'Só o dono');
        }
        $this->recusa(fn () => $this->supervisao->marcarSupervisor($alvo->id, 'clinica', $this->usuarioClinica('dono', self::OUTRO_TENANT)->id, self::T), 'Não encontrei o dono');
        $this->recusa(fn () => $this->supervisao->marcarSupervisor($this->usuarioClinica('recepcao', self::OUTRO_TENANT)->id, 'clinica', $this->dono->id, self::T), 'Não encontrei o usuário');
        $this->recusa(fn () => $this->supervisao->marcarSupervisor($alvo->id, 'farmacia', $this->dono->id, self::T), '"clinica" ou "petshop"');
        $this->assertNull($alvo->fresh()->supervisor_setor);

        $this->assertSame('clinica', $this->supervisao->marcarSupervisor($alvo->id, 'clinica', $this->dono->id, self::T)->supervisor_setor);
        $this->assertNull($this->supervisao->marcarSupervisor($alvo->id, null, $this->dono->id, self::T)->supervisor_setor, 'o dono desmarca');
    }

    public function test_pin_definido_so_pelo_supervisor_minimo_de_6_guardado_so_como_hash_e_o_dono_so_zera(): void
    {
        $naoSupervisor = $this->usuarioClinica('recepcao');
        $this->recusa(fn () => $this->supervisao->definirProprioPin($naoSupervisor->id, self::PIN, self::T), 'Só um supervisor de setor');

        $supervisor = $this->supervisor('recepcao', 'clinica', comPin: false);
        $this->recusa(fn () => $this->supervisao->definirProprioPin($supervisor->id, '12345', self::T), 'pelo menos 6 caracteres');
        $this->recusa(fn () => $this->supervisao->definirProprioPin($supervisor->id, '       ', self::T), 'espaços não contam');
        $supervisor->update(['password' => 'Senha-De-Login-2026']);
        $this->recusa(fn () => $this->supervisao->definirProprioPin($supervisor->id, 'Senha-De-Login-2026', self::T), 'não pode ser igual à sua senha de login');
        $this->assertNull($supervisor->fresh()->pin_autorizacao);
        $this->supervisao->definirProprioPin($supervisor->id, self::PIN, self::T);

        $guardado = $supervisor->fresh()->getRawOriginal('pin_autorizacao');
        $this->assertNotSame(self::PIN, $guardado);
        $this->assertTrue(Hash::check(self::PIN, $guardado));
        $this->assertFalse(Hash::check(self::PIN, $supervisor->fresh()->password), 'o PIN não é a senha de login');
        $this->assertArrayNotHasKey('pin_autorizacao', $supervisor->fresh()->toArray());

        // Nem PIN nem marcação entram por atribuição em massa.
        $supervisor->fresh()->update(['pin_autorizacao' => 'x', 'supervisor_setor' => 'petshop']);
        $this->assertSame([$guardado, 'clinica'], [$supervisor->fresh()->getRawOriginal('pin_autorizacao'), $supervisor->fresh()->supervisor_setor]);

        $this->supervisao->resetarPin($supervisor->id, $this->dono->id, self::T);
        $this->assertNull($supervisor->fresh()->pin_autorizacao);
        $this->recusa(fn () => $this->estornar($this->paga(), $this->usuarioClinica('recepcao'), $supervisor->fresh()), 'ainda não definiu o PIN');
    }
}
