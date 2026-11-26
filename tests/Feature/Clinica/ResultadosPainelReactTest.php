<?php

namespace Tests\Feature\Clinica;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

/** 26/11: Resultados e Painel do dono em React: só o dono, mesmos dados do Blade, gravação da aba Clínica intacta. */
class ResultadosPainelReactTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    public function test_dono_ve_resultados_e_painel_em_react(): void
    {
        $this->logarComo('dono', self::T);
        config(['sentinel.front' => 'react']);

        $this->get('/resultados')->assertOk()->assertInertia(fn ($p) => $p->component('Resultados', false)
            ->where('periodo', 'mes')->has('r.faturamento')->has('r.setores.0.rotulo')->has('r.setores.1')->has('rotuloPeriodo')->has('nomes.0'));
        $this->get('/painel')->assertOk()->assertInertia(fn ($p) => $p->component('Painel', false)->where('aba', 'dashboard')->has('indicadores', 4));
        $this->get('/painel?aba=clinica')->assertOk()->assertInertia(fn ($p) => $p->component('Painel', false)->has('valores.abre')->has('maxVagas'));
        $this->get('/painel?aba=clientes')->assertOk()->assertInertia(fn ($p) => $p->has('tutores')->missing('tutores.0.tenant_id'));
    }

    public function test_outros_papeis_continuam_barrados_no_react(): void
    {
        config(['sentinel.front' => 'react']);
        $this->logarComo('recepcao', self::T);
        $this->get('/resultados')->assertForbidden();
        $this->get('/painel')->assertForbidden();
    }

    public function test_salvar_a_aba_clinica_continua_gravando_e_volta_com_o_aviso(): void
    {
        $this->logarComo('dono', self::T);
        config(['sentinel.front' => 'react']);

        $this->from('/painel?aba=clinica')->post('/painel/clinica', ['abre' => '08:00', 'fecha' => '18:00', 'intervalo_inicio' => '', 'intervalo_fim' => '', 'vagas' => '2'])
            ->assertRedirect('/painel?aba=clinica');
        $this->get('/painel?aba=clinica')->assertInertia(fn ($p) => $p->where('valores.abre', '08:00')->where('valores.vagas', 2)->where('flash.ok', fn ($v) => str_contains((string) $v, 'Configuração salva')));
    }
}
