<?php

namespace Tests\Feature;

use Tests\TestCase;

class SessaoExpiradaFormularioTest extends TestCase
{
    public function test_formulario_html_sem_sessao_exibe_419_sem_afetar_contrato_json_ou_get(): void
    {
        $this->post('/logout')->assertStatus(419)->assertSee('Sessão expirada');
        $this->get('/agente')->assertRedirect('/login');
        $this->postJson('/agente/comando', ['mensagem' => 'oi'])->assertUnauthorized();
    }
}
