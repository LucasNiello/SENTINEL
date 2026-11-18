<?php

namespace Tests\Feature;

use App\Support\Front;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Migração para React (18/11): SENTINEL_FRONT alterna Blade (padrão) e Inertia sem mexer nas telas atuais. */
class FrontAlternanciaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // As páginas React ficam em resources/js/react/Pages (o padrão do Inertia é resources/js/pages).
        config(['inertia.pages.paths' => [resource_path('js/react/Pages')], 'inertia.pages.extensions' => ['jsx']]);
    }

    public function test_padrao_e_blade_e_a_pagina_de_teste_nao_existe(): void
    {
        $this->assertFalse(Front::react());
        $this->logarComo('operador');

        $this->get('/react-teste')->assertNotFound();
        $this->get('/cadastros')->assertOk()->assertSee('workspace-sidebar', false)->assertDontSee('data-page', false);
    }

    public function test_valor_desconhecido_vale_blade(): void
    {
        config(['sentinel.front' => 'qualquer']);

        $this->assertFalse(Front::react());
    }

    public function test_com_react_a_pagina_de_teste_traz_usuario_e_menu_do_papel(): void
    {
        config(['sentinel.front' => 'react']);
        $this->logarComo('admin');

        $this->get('/react-teste')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Teste')
                ->where('usuario.papel', 'admin')
                ->has('menu', fn (Assert $m) => $m->each(fn (Assert $i) => $i->hasAll(['caminho', 'rotulo', 'icone', 'ativo']))->etc())
                ->where('menu.0.caminho', 'agente')
                ->where('menu.3.caminho', 'auditoria')
                ->has('flash'));
    }

    public function test_com_react_a_pagina_de_teste_exige_login(): void
    {
        config(['sentinel.front' => 'react']);

        $this->get('/react-teste')->assertRedirect('/login');
    }

    public function test_com_react_as_telas_blade_continuam_iguais(): void
    {
        config(['sentinel.front' => 'react']);
        $this->logarComo('operador');

        $this->get('/cadastros')->assertOk()->assertSee('workspace-sidebar', false)->assertDontSee('data-page', false);
    }
}
