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

    public function test_telas_portadas_seguem_o_front_escolhido(): void
    {
        $this->logarComo('operador');

        // 26/11: as telas foram portadas; com blade continuam idênticas (plano B), com react viram página Inertia.
        config(['sentinel.front' => 'blade']);
        $this->get('/cadastros')->assertOk()->assertSee('workspace-sidebar', false)->assertDontSee('data-page', false);

        config(['sentinel.front' => 'react']);
        $this->get('/cadastros')->assertOk()->assertSee('data-page', false)->assertInertia(fn (Assert $p) => $p->component('Consulta', false));
    }

    public function test_agente_blade_preserva_a_tela_atual(): void
    {
        config(['sentinel.front' => 'blade']);
        $this->logarComo('operador');

        $this->get('/agente')->assertOk()->assertViewIs('agente')
            ->assertSee('form-comando', false)->assertDontSee('data-page', false);
    }

    public function test_agente_react_recebe_historico_ordenado_da_sessao(): void
    {
        config(['sentinel.front' => 'react']);
        $usuario = $this->logarComo('operador');
        $mensagens = [
            ['role' => 'user', 'content' => 'Olá'],
            ['role' => 'assistant', 'content' => 'Como posso ajudar?'],
        ];

        $this->withSession(['agente_conversa' => [
            'identidade' => $usuario->id.':1', 'mensagens' => $mensagens,
        ]])->get('/agente')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Agente')->where('historico', $mensagens)
            ->where('usuario.papel', 'operador')->has('menu')->etc());
    }

    public function test_agente_react_nao_recebe_historico_de_outra_identidade(): void
    {
        config(['sentinel.front' => 'react']);
        $this->logarComo('operador');

        $this->withSession(['agente_conversa' => [
            'identidade' => '999:999',
            'mensagens' => [['role' => 'user', 'content' => 'Privado']],
        ]])->get('/agente')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Agente')->has('historico', 0)->etc());
    }

    public function test_agente_preserva_login_e_redirecionamento_do_admin_da_clinica(): void
    {
        config(['sentinel.front' => 'react', 'sentinel.tenants_clinica' => [3]]);
        $this->get('/agente')->assertRedirect('/login');
        $this->logarComo('admin', 3);
        $this->get('/agente')->assertRedirect('/supervisao');
        config(['sentinel.front' => 'blade']);
        $this->get('/agente')->assertRedirect('/supervisao');
    }

    public function test_dois_comandos_textuais_mantem_o_historico_no_react(): void
    {
        config(['sentinel.front' => 'react']);
        $this->logarComo('operador');
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Olá, posso ajudar.']]],
        ])]);

        foreach (['Olá', 'Obrigado'] as $mensagem) {
            $this->postJson('/agente/comando', ['mensagem' => $mensagem])->assertOk()
                ->assertJsonPath('tipo', 'texto')->assertJsonPath('mensagem', 'Olá, posso ajudar.');
        }
        $this->get('/agente')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Agente')->has('historico', 4)
            ->where('historico.0.content', 'Olá')->where('historico.2.content', 'Obrigado')->etc());
    }
}
