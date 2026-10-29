<?php

namespace Tests\Feature;

use App\Services\ConversaAgente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConversaMarcadorTest extends TestCase
{
    use RefreshDatabase;

    public function test_novo_pedido_preserva_contexto_sem_reenviar_marcador_de_interface_como_resposta_da_ia(): void
    {
        $this->logarComo();
        config(['services.azure_foundry' => ['endpoint' => 'https://foundry.test', 'api_key' => 'k', 'deployment' => 'd']]);
        $conversa = app(ConversaAgente::class);
        $conversa->guardar(session()->driver(), 'Cadastre Mercado QA', ['tipo' => 'confirmacao_pendente']);
        $conversa->registrarResultado(session()->driver(), 'Cliente cadastrado.');
        $conversa->guardar(session()->driver(), 'Concilie janeiro', ['tipo' => 'texto', 'tool' => 'conciliar_lancamentos_em_lote', 'mensagem' => 'Sem pendentes neste filtro.']);
        Http::fake(fn () => Http::response(['choices' => [['message' => ['content' => 'Qual o nome do próximo cliente?']]]]));

        $this->postJson('/agente/comando', ['mensagem' => 'Cadastre outro cliente'])->assertOk();

        Http::assertSent(function ($request) {
            $texto = json_encode($request['messages'], JSON_UNESCAPED_UNICODE);
            return str_contains($texto, 'Cadastre Mercado QA')
                && str_contains($texto, 'Cliente cadastrado.')
                && ! str_contains($texto, 'Sem pendentes neste filtro.')
                && collect($request['messages'])->contains(fn ($m) =>
                    $m['role'] === 'system' && str_contains($m['content'], 'não é o estado atual do banco'))
                && ! collect($request['messages'])->skip(1)->contains(fn ($m) =>
                    ($m['content'] ?? '') === 'Proposta pendente de confirmação humana. Nenhuma escrita foi executada.');
        });
    }
}
