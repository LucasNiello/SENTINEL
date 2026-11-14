<?php

namespace Tests\Feature\Clinica;

use App\Models\User;
use App\Services\SupervisaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Item I (clínica vet), tela: o dono marca supervisores e zera o PIN; o supervisor define o próprio PIN. A tela só
 * monta a página e chama as rotas JSON /supervisao/* (provadas em SupervisaoEndpointsTest); aqui se prova quem abre
 * a tela, o que cada um vê e que o menu segue a mesma regra.
 */
class TelaDeSupervisaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_dono_ve_a_lista_da_equipe_e_nao_o_formulario_do_pin(): void
    {
        $this->logarComo('dono', 3);

        $this->get('/supervisao')->assertOk()
            ->assertSee('Supervisores de setor')
            ->assertSee("enviar('/supervisao/supervisores', 'GET')", false)
            ->assertDontSee('id="form-meu-pin"', false);
        $this->get('/agente')->assertOk()->assertSee('href="/supervisao"', false);
    }

    public function test_supervisor_ve_so_o_formulario_do_proprio_pin_com_campos_de_senha(): void
    {
        $dono = User::factory()->papel('dono')->doTenant(3)->create();
        $veterinario = $this->logarComo('veterinario', 3);
        app(SupervisaoService::class)->marcarSupervisor($veterinario->id, 'clinica', $dono->id, 3);
        $this->actingAs($veterinario->fresh()); // num pedido real o usuário é relido do banco

        $this->get('/supervisao')->assertOk()
            ->assertSee('id="form-meu-pin"', false)
            ->assertSee('Você supervisiona o setor clínica.')
            ->assertSee('<input type="password" id="pin" autocomplete="one-time-code"', false)
            ->assertSee('<input type="password" id="pin-confirmacao" autocomplete="one-time-code"', false)
            ->assertSee('<input type="password" id="senha-atual" autocomplete="current-password"', false)
            ->assertDontSee('Supervisores de setor');
        $this->get('/agente')->assertOk()->assertSee('href="/supervisao"', false);
    }

    public function test_quem_nao_e_dono_nem_supervisor_nao_abre_a_tela_nem_ve_o_menu(): void
    {
        foreach (['recepcao', 'veterinario', 'atendente', 'vendedor'] as $papel) {
            $this->logarComo($papel, 3);
            $this->get('/supervisao')->assertForbidden();
            $this->get('/agente')->assertOk()->assertDontSee('href="/supervisao"', false);
        }
    }

    public function test_fora_da_clinica_a_tela_nao_existe(): void
    {
        $this->logarComo('admin', 1);
        $this->get('/supervisao')->assertNotFound();
        $this->get('/agente')->assertOk()->assertDontSee('href="/supervisao"', false);
    }

    public function test_sem_login_vai_para_o_login(): void
    {
        $this->get('/supervisao')->assertRedirect('/login');
    }

    public function test_nada_do_segredo_vai_para_a_pagina(): void
    {
        $dono = User::factory()->papel('dono')->doTenant(3)->create();
        $veterinario = User::factory()->papel('veterinario')->doTenant(3)->create();
        $supervisao = app(SupervisaoService::class);
        $supervisao->marcarSupervisor($veterinario->id, 'clinica', $dono->id, 3);
        $supervisao->definirProprioPin($veterinario->id, '482915', 3);
        $this->actingAs($dono);

        // A página não traz a lista (vem do JSON, que só diz tem_pin) e nunca o hash do PIN.
        $pagina = $this->get('/supervisao')->assertOk();
        $pagina->assertDontSee((string) $veterinario->fresh()->pin_autorizacao, false)->assertDontSee('482915');

        // O que a tela de fato carrega é o JSON da lista: só tem_pin, nunca o hash nem o e-mail (2ª passada).
        $json = $this->getJson('/supervisao/supervisores')->assertOk()->getContent();
        $this->assertStringContainsString('"tem_pin":true', $json);
        $this->assertStringNotContainsString((string) $veterinario->fresh()->pin_autorizacao, $json);
        $this->assertStringNotContainsString((string) $veterinario->email, $json);
    }

    public function test_supervisor_cujo_papel_saiu_da_hierarquia_nao_abre_a_tela_nem_define_pin(): void
    {
        $dono = User::factory()->papel('dono')->doTenant(3)->create();
        $veterinario = User::factory()->papel('veterinario')->doTenant(3)->create(['password' => \Illuminate\Support\Facades\Hash::make('senha-de-login-1')]);
        app(SupervisaoService::class)->marcarSupervisor($veterinario->id, 'clinica', $dono->id, 3);
        $this->actingAs($veterinario->fresh());
        config(['sentinel.papeis' => array_values(array_diff((array) config('sentinel.papeis'), ['veterinario']))]);

        $this->get('/supervisao')->assertForbidden();
        $this->postJson('/supervisao/meu-pin', ['pin' => '771133', 'pin_confirmacao' => '771133', 'senha_atual' => 'senha-de-login-1'])->assertStatus(422);
        $this->assertNull($veterinario->fresh()->pin_autorizacao);
    }
}
