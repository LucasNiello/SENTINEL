<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class VitrineTest extends TestCase
{
    public function test_vitrine_publica_tem_conteudo_e_tres_acessos_ao_login_real(): void
    {
        $response = $this->get('/')->assertOk()->assertViewIs('welcome');

        $response->assertSee('Peça em português.')
            ->assertSee('Confirmação humana')
            ->assertSee('Auditoria')
            ->assertSee('Dados de demonstração')
            ->assertSee('lighthouse-fallback')
            ->assertDontSee('<form', false)
            ->assertDontSee('href="#entrar"', false);

        preg_match_all('/href="([^"]+)" data-access/', $response->getContent(), $links);
        $this->assertSame(array_fill(0, 3, route('login')), $links[1]);
    }

    public function test_usuario_autenticado_pode_abrir_o_agente_pelos_tres_ctas(): void
    {
        // No database writes are needed to exercise session-aware presentation.
        $this->actingAs(User::factory()->make(['id' => 1, 'papel' => 'operador', 'tenant_id' => 1]));

        $response = $this->get('/')->assertOk()->assertSee('Abrir Sentinel');
        preg_match_all('/href="([^"]+)" data-access/', $response->getContent(), $links);
        $this->assertSame(array_fill(0, 3, url('/agente')), $links[1]);
    }

    public function test_destino_da_vitrine_continua_sendo_o_login_do_laravel(): void
    {
        $this->get(route('login'))->assertOk()->assertViewIs('login')
            ->assertSee('name="password"', false);
        $this->get('/agente')->assertRedirect(route('login'));
    }
}
