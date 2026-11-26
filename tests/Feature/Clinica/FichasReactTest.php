<?php

namespace Tests\Feature\Clinica;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

/** 26/11: fichas de Pet e Tutor em React: só os campos da tela, mesmo bloqueio de tenant e papel do Blade. */
class FichasReactTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    public function test_ficha_do_pet_e_do_tutor_em_react_sem_campos_de_sistema(): void
    {
        $this->logarComo('dono', self::T);
        $tutor = $this->tutor(self::T, 'Maria Souza');
        $pet = $this->pet($tutor, self::T, ['nome' => 'Bolt', 'alertas' => 'Morde']);

        config(['sentinel.front' => 'react']);
        $this->get('/pets/'.$pet->id)->assertOk()->assertInertia(fn ($p) => $p->component('Pet', false)
            ->where('pet.nome', 'Bolt')->where('pet.alertas', 'Morde')->where('tutor.nome', 'Maria Souza')
            ->missing('pet.tenant_id')->missing('pet.tutor_id')->missing('tutor.tenant_id')->where('podeEditar', true));
        $this->get('/tutores/'.$tutor->id)->assertOk()->assertInertia(fn ($p) => $p->component('Tutor', false)
            ->where('tutor.nome', 'Maria Souza')->missing('tutor.tenant_id')->has('pets.0', fn ($x) => $x->where('nome', 'Bolt')->etc()));
    }

    public function test_ficha_de_outro_tenant_da_404_no_react(): void
    {
        $this->logarComo('dono', self::T);
        $alheio = $this->pet($this->tutor(self::OUTRO_TENANT), self::OUTRO_TENANT, ['nome' => 'Fantasma']);
        config(['sentinel.front' => 'react']);
        $this->get('/pets/'.$alheio->id)->assertNotFound();
        $this->get('/tutores/'.$alheio->tutor_id)->assertNotFound();
    }
}
