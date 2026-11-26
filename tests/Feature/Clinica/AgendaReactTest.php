<?php

namespace Tests\Feature\Clinica;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

/** 26/11: Agenda em React. Mesmos dados do Blade, só os campos exibidos, com tenant e RBAC do servidor. */
class AgendaReactTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    public function test_react_recebe_dias_e_linhas_so_com_campos_da_tela_e_o_blade_continua(): void
    {
        $vet = $this->logarComo('veterinario', self::T);
        $tutor = $this->tutor(self::T, 'Maria Souza');
        $pet = $this->pet($tutor, self::T, ['nome' => 'Bolt']);
        $this->agendamentoDireto($pet, $vet, $this->item(), ['inicio' => now()->setTime(10, 0), 'fim' => now()->setTime(10, 30), 'status' => 'agendado']);

        config(['sentinel.front' => 'blade']);
        $this->get('/agenda')->assertOk()->assertSee('Bolt');

        config(['sentinel.front' => 'react']);
        $this->get('/agenda')->assertOk()->assertInertia(fn ($p) => $p->component('Agenda', false)
            ->where('visao', 'dia')->where('total', 1)->where('podeMudarStatus', true)
            ->has('dias.0.linhas.0', fn ($l) => $l->where('pet', 'Bolt')->where('tutor', 'Maria Souza')->where('status', 'agendado')
                ->has('id')->has('hora')->has('servico')->has('profissional')->has('setor')->etc())
            ->missing('dias.0.linhas.0.tenant_id')->missing('dias.0.linhas.0.horario'));
    }

    public function test_outro_tenant_nao_aparece_na_agenda_react(): void
    {
        $vet = $this->logarComo('veterinario', self::T);
        $alheio = $this->pet($this->tutor(self::OUTRO_TENANT), self::OUTRO_TENANT, ['nome' => 'Fantasma']);
        $this->agendamentoDireto($alheio, $vet, $this->item([], self::OUTRO_TENANT), ['inicio' => now()->setTime(10, 0), 'fim' => now()->setTime(10, 30), 'status' => 'agendado']);

        config(['sentinel.front' => 'react']);
        $r = $this->get('/agenda')->assertOk();
        $r->assertInertia(fn ($p) => $p->where('total', 0));
        $this->assertStringNotContainsString('Fantasma', $r->getContent());
    }
}
