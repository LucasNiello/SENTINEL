<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;

/**
 * Helpers para testar o fluxo do agente (/agente) com o Foundry simulado.
 * Chame prepararAgente() no setUp() da classe de teste.
 *
 * Http::fake acumula stubs (o primeiro vence), então faz-se o fake uma vez só
 * e o que muda entre chamadas é $respostaFoundry.
 */
trait SimulaAgente
{
    /** Resposta que o "Foundry" devolve na próxima chamada. */
    private array $respostaFoundry = [];

    protected function prepararAgente(): void
    {
        config(['services.azure_foundry' => ['endpoint' => 'https://foundry.test', 'api_key' => 'k', 'deployment' => 'd']]);
        Http::fake(fn () => Http::response($this->respostaFoundry));
    }

    private function respostaComTool(string $tool, array $argumentos): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => $tool, 'arguments' => json_encode($argumentos)]],
        ]]]]];
    }

    /** Envia um comando cujo "modelo" responde chamando $tool. */
    private function comando(string $tool, array $argumentos = [])
    {
        $this->respostaFoundry = $this->respostaComTool($tool, $argumentos);

        return $this->postJson('/agente/comando', ['mensagem' => 'faça algo']);
    }

    /** Passo 1 de toda escrita: o agente propõe a tool e o servidor devolve o token de confirmação (RNF02). */
    private function propor(array $argumentos, string $tool = 'criar_lancamento'): string
    {
        $this->freezeTime();

        return (string) $this->comando($tool, $argumentos)
            ->assertOk()
            ->assertJsonPath('tipo', 'confirmacao_pendente')
            ->json('token');
    }

    /** Passo 2: espera o hold (2 s) e confirma com o token. */
    private function confirmar(string $token)
    {
        $this->travel(2)->seconds();

        return $this->postJson('/agente/confirmar', ['token' => $token]);
    }
}
