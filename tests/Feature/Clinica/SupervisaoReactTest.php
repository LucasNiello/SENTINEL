<?php

namespace Tests\Feature\Clinica;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

/** 26/11: Supervisão em React. Mesmas portas do Blade; a página não carrega PIN, hash nem senha nas props. */
class SupervisaoReactTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    public function test_dono_abre_a_pagina_react_sem_nenhum_segredo_nas_props(): void
    {
        $dono = $this->logarComo('dono', self::T);
        $this->comPin($dono, '482915');
        config(['sentinel.front' => 'react']);

        $r = $this->get('/supervisao')->assertOk()->assertInertia(fn ($p) => $p->component('Supervisao', false)->where('ehDono', true)->where('ehAdmin', false)->has('rotulosPapeis'));
        $this->assertStringNotContainsString('482915', $r->getContent());
        $this->assertStringNotContainsString('pin_autorizacao', $r->getContent());
        $this->assertStringNotContainsString('password', strtolower(substr($r->getContent(), strpos($r->getContent(), 'data-page'), 4000)));
    }

    public function test_outros_papeis_continuam_barrados(): void
    {
        config(['sentinel.front' => 'react']);
        $this->logarComo('recepcao', self::T);
        $this->get('/supervisao')->assertForbidden();
    }
}
