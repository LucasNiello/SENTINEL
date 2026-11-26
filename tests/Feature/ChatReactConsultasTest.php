<?php

namespace Tests\Feature;

use App\Models\Cliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatReactConsultasTest extends TestCase
{
    use RefreshDatabase;

    public function test_contrato_de_consulta_com_linhas_vazio_e_erro_sem_ia(): void
    {
        config(['sentinel.front' => 'react']);
        Http::preventStrayRequests();
        $this->logarComo('operador');
        foreach (['Cliente A', 'Cliente B'] as $nome) {
            Cliente::create(['nome' => $nome, 'tenant_id' => 1]);
        }
        $this->postJson('/agente/consultar', ['tool' => 'consultar_clientes'])
            ->assertOk()->assertJsonPath('tipo', 'resultado_leitura')
            ->assertJsonPath('colunas', ['id', 'nome', 'documento', 'email', 'telefone'])
            ->assertJsonCount(2, 'resultado')->assertJsonPath('editar', 'atualizar_cliente');
        $this->postJson('/agente/consultar', ['tool' => 'consultar_clientes', 'filtros' => ['nome' => 'Inexistente']])
            ->assertOk()->assertJsonCount(0, 'resultado');
        $this->postJson('/agente/consultar', [])->assertStatus(422)->assertJsonPath('tipo', 'erro');
        Http::assertNothingSent();
    }
}
