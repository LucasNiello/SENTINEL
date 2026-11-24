<?php

namespace Tests\Feature;

use App\Models\Cliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

class ChatReactConfirmacaoTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sentinel.front' => 'react']);
        $this->prepararAgente();
        $this->logarComo('operador');
    }

    public function test_revisao_sem_edicao_preserva_dados_e_exige_confirmacao_separada(): void
    {
        $proposta = $this->comando('criar_cliente', ['nome' => 'Cliente QA', 'documento' => '123.456.789-01'])
            ->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente')->json('proposta');
        $this->postJson('/agente/confirmar', ['token' => $proposta])->assertStatus(422)
            ->assertJsonPath('codigo', 'AI_CONFIRMATION_NOT_REVIEWED');
        $revisao = $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => (object) []])
            ->assertOk()->assertJsonPath('tipo', 'revisada');
        $this->assertNotEmpty($revisao->json('token'));
        $this->assertDatabaseCount('clientes', 0);
        $token = $revisao->json('token');
        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk()->assertJsonPath('tipo', 'resultado_escrita');
        $this->assertSame('123.456.789-01', Cliente::sole()->documento);
        $this->postJson('/agente/confirmar', ['token' => $token])->assertStatus(422);
        $this->assertDatabaseCount('clientes', 1);
        $this->assertNotEmpty(session('agente_conversa.mensagens'));
    }

    public function test_cancelamento_por_proposta_antes_e_depois_da_revisao(): void
    {
        foreach ([false, true] as $revisar) {
            $proposta = $this->comando('criar_cliente', ['nome' => 'Cancelar QA'])->assertOk()->json('proposta');
            if ($revisar) {
                $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => (object) []])->assertOk();
            }
            $this->postJson('/agente/cancelar', ['proposta' => $proposta])->assertOk()->assertJsonPath('tipo', 'cancelado');
            $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => (object) []])->assertStatus(422);
        }
        $this->assertDatabaseCount('clientes', 0);
    }

    public function test_revisao_invalida_nao_emite_token_e_permite_cancelar(): void
    {
        $proposta = $this->comando('criar_cliente', [])->assertOk()->json('proposta');
        $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => (object) []])
            ->assertStatus(422)->assertJsonMissingPath('token');
        $this->postJson('/agente/cancelar', ['proposta' => $proposta])->assertOk()->assertJsonPath('tipo', 'cancelado');
        $this->assertDatabaseCount('clientes', 0);
    }
}
