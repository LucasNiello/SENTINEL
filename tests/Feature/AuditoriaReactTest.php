<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 26/11: Auditoria em React: só admin (como no Blade); props só com o que os filtros precisam. */
class AuditoriaReactTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_abre_a_auditoria_react_e_o_operador_e_barrado(): void
    {
        config(['sentinel.front' => 'react']);

        $this->logarComo('admin');
        $this->get('/auditoria')->assertOk()->assertInertia(fn ($p) => $p->component('Auditoria', false)
            ->has('usuarios.0', fn ($u) => $u->has('id')->has('nome')->etc())->has('resultados')->has('tools'));

        $this->logarComo('operador');
        $this->get('/auditoria')->assertForbidden();
    }
}
