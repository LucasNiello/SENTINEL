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
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => $tool, 'arguments' => json_encode((object) $argumentos)]],
        ]]]]];
    }

    /** Envia um comando cujo "modelo" responde chamando $tool. */
    private function comando(string $tool, array $argumentos = [])
    {
        $this->respostaFoundry = $this->respostaComTool($tool, $argumentos);

        return $this->postJson('/agente/comando', ['mensagem' => 'faça algo']);
    }

    /** Revisões recusadas (dado inválido, sem permissão): proposta => resposta do /agente/revisar. */
    private array $revisoesRecusadas = [];

    /**
     * Passo 1 de toda escrita: o agente propõe a tool (rascunho) e o humano revisa sem
     * editar (Bloco 2b); devolve o token de segurar (RNF02). Se a revisão for recusada,
     * devolve a proposta — e o confirmar() abaixo devolve a resposta da revisão, porque
     * no fluxo do Revisar é ali que o erro de validação aparece (mesma frase, mesma auditoria).
     */
    private function propor(array $argumentos, string $tool = 'criar_lancamento'): string
    {
        $this->freezeTime();

        $proposta = (string) $this->comando($tool, $argumentos)
            ->assertOk()
            ->assertJsonPath('tipo', 'confirmacao_pendente')
            ->json('proposta');

        $revisao = $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []]);

        if ($revisao->status() !== 200) {
            $this->revisoesRecusadas[$proposta] = $revisao;

            return $proposta;
        }

        return (string) $revisao->json('token');
    }

    /** Passo 2: espera o hold (2 s) e confirma com o token. */
    private function confirmar(string $token)
    {
        $this->travel(2)->seconds();

        return $this->revisoesRecusadas[$token] ?? $this->postJson('/agente/confirmar', ['token' => $token]);
    }
}
