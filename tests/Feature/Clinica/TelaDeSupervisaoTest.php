<?php

namespace Tests\Feature\Clinica;

use App\Models\User;
use App\Services\SupervisaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Item I (clínica vet), tela, T1 (14/11): o dono marca os supervisores de setor; o Administrador gera e revoga os PINs.
 * A tela só monta a página e chama as rotas JSON /supervisao/* (provadas em SupervisaoEndpointsTest); aqui se prova
 * quem abre a tela, o que cada um vê e que o menu segue a mesma regra.
 */
class TelaDeSupervisaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_dono_ve_a_marcacao_de_setor_e_nao_os_botoes_do_pin(): void
    {
        $this->logarComo('dono', 3);

        $this->get('/supervisao')->assertOk()
            ->assertSee('Supervisores de setor')
            ->assertSee("enviar('/supervisao/supervisores', 'GET')", false)
            ->assertSee('const EH_ADMIN = false;', false)
            ->assertDontSee('id="form-gerar-pin"', false)
            ->assertDontSee('id="form-meu-pin"', false);
        $this->get('/agente')->assertOk()->assertSee('href="/supervisao"', false);
    }

    public function test_admin_ve_gerar_e_revogar_com_a_senha_e_o_codigo_uma_vez(): void
    {
        $this->logarComo('admin', 3);

        $this->get('/supervisao')->assertOk()
            ->assertSee('PIN de autorização')
            ->assertSee('id="form-gerar-pin"', false)
            ->assertSee('<input type="password" id="senha-admin" autocomplete="current-password"', false)
            ->assertSee('este código não aparece de novo')
            ->assertSee('const EH_ADMIN = true;', false)
            ->assertSee("'/supervisao/pins/' + alvo.id + '/gerar'", false)
            ->assertSee("'/revogar'", false)
            ->assertDontSee('id="form-meu-pin"', false)
            // o menu do Administrador só tem a Supervisão
            ->assertSee('<span>Supervisão</span>', false)
            ->assertDontSee('<span>Agente</span>', false)
            ->assertDontSee('<span>Cadastros</span>', false);
    }

    public function test_admin_da_clinica_nao_usa_o_agente_nem_os_cadastros(): void
    {
        $this->logarComo('admin', 3);

        $this->get('/agente')->assertRedirect('/supervisao');
        $this->get('/cadastros')->assertRedirect('/supervisao');
        $this->get('/auditoria')->assertForbidden();
        $this->postJson('/agente/comando', ['mensagem' => 'liste os tutores'])->assertStatus(403)->assertJsonPath('codigo', 'AI_FORBIDDEN');
    }

    /** 1ª passada do reviewer (bloqueador): as tools da lixeira que o domínio antigo dá ao 'admin' não valem na clínica. */
    public function test_admin_da_clinica_nao_tem_tool_nenhuma_nem_a_lixeira(): void
    {
        $tutor = \App\Models\Tutor::create(['nome' => 'Ana Souza', 'tenant_id' => 3]);
        $this->logarComo('admin', 3);

        $this->get('/lixeira')->assertForbidden();
        $this->get('/supervisao')->assertOk()->assertDontSee('<span>Lixeira</span>', false)->assertDontSee('<span>Auditoria</span>', false);
        $this->postJson('/agente/consultar', ['tool' => 'consultar_lixeira'])->assertStatus(403);
        // A recusa do Admin passa pelo negar() de sempre: vira linha "negado" na auditoria (2ª passada).
        $this->assertDatabaseHas('audit_logs', ['tool' => 'consultar_lixeira', 'resultado' => 'negado', 'permitido' => false]);
        $this->postJson('/agente/consultar', ['tool' => 'consultar_tutores'])->assertStatus(403);
        $this->postJson('/agente/formulario', ['tool' => 'excluir_tutor', 'id' => $tutor->id])->assertStatus(403);
        $this->postJson('/agente/formulario', ['tool' => 'criar_tutor'])->assertStatus(403);

        $agente = app(\App\Services\AiAgentService::class);
        foreach (['consultar_lixeira', 'excluir_tutor', 'excluir_definitivo_tutor', 'restaurar_tutor', 'estornar_cobranca', 'registrar_venda', 'consultar_cobrancas'] as $tool) {
            $this->assertFalse($agente->podeExecutar($tool), $tool);
        }
        $this->assertNull($tutor->fresh()->deleted_at);

        // O admin do domínio antigo continua com a lixeira.
        $this->logarComo('admin', 1);
        $this->assertTrue(app(\App\Services\AiAgentService::class)->podeExecutar('consultar_lixeira'));
    }

    public function test_supervisor_e_os_outros_papeis_nao_abrem_a_tela_nem_veem_o_menu(): void
    {
        $dono = User::factory()->papel('dono')->doTenant(3)->create();
        $veterinario = User::factory()->papel('veterinario')->doTenant(3)->create();
        app(SupervisaoService::class)->marcarSupervisor($veterinario->id, 'clinica', $dono->id, 3);
        $this->actingAs($veterinario->fresh());
        $this->get('/supervisao')->assertForbidden();
        $this->get('/agente')->assertOk()->assertDontSee('href="/supervisao"', false);

        foreach (['recepcao', 'veterinario', 'atendente', 'vendedor'] as $papel) {
            $this->logarComo($papel, 3);
            $this->get('/supervisao')->assertForbidden();
            $this->get('/agente')->assertOk()->assertDontSee('href="/supervisao"', false);
        }
    }

    public function test_fora_da_clinica_a_tela_nao_existe_e_o_admin_antigo_continua_com_o_agente(): void
    {
        $this->logarComo('admin', 1);
        $this->get('/supervisao')->assertNotFound();
        $this->get('/agente')->assertOk()->assertDontSee('href="/supervisao"', false)->assertSee('href="/auditoria"', false);
    }

    public function test_sem_login_vai_para_o_login(): void
    {
        $this->get('/supervisao')->assertRedirect('/login');
    }

    public function test_nada_do_segredo_vai_para_a_pagina(): void
    {
        $dono = User::factory()->papel('dono')->doTenant(3)->create();
        $veterinario = User::factory()->papel('veterinario')->doTenant(3)->create();
        app(SupervisaoService::class)->marcarSupervisor($veterinario->id, 'clinica', $dono->id, 3);
        $veterinario->forceFill(['pin_autorizacao' => \Illuminate\Support\Facades\Hash::make('482915')])->save();

        foreach ([$dono, User::factory()->papel('admin')->doTenant(3)->create()] as $quem) {
            $this->actingAs($quem);
            $pagina = $this->get('/supervisao')->assertOk();
            $pagina->assertDontSee((string) $veterinario->fresh()->pin_autorizacao, false)->assertDontSee('482915');

            $json = $this->getJson('/supervisao/supervisores')->assertOk()->getContent();
            $this->assertStringContainsString('"tem_pin":true', $json);
            $this->assertStringNotContainsString((string) $veterinario->fresh()->pin_autorizacao, $json);
            $this->assertStringNotContainsString((string) $veterinario->email, $json);
        }
    }
}
