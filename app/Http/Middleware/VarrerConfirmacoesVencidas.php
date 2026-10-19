<?php

namespace App\Http\Middleware;

use App\Services\AiAgentService;
use App\Services\ConfirmacoesPendentes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Em toda requisição ao agente, as propostas vencidas na sessão são auditadas
 * como "expirado" e removidas (R3). Roda DEPOIS da ação: o /confirmar e o
 * /cancelar dão o desfecho do próprio token primeiro (expirado ou não, com a
 * mensagem certa), e a varredura cuida só das que ficaram para trás. Uma falha
 * ao auditar não derruba a resposta (ver AiAgentService::auditarDesfecho()).
 */
class VarrerConfirmacoesVencidas
{
    public function __construct(private ConfirmacoesPendentes $pendentes, private AiAgentService $aiAgentService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $resposta = $next($request);

        foreach ($this->pendentes->retirarVencidas($request->session()) as $pendente) {
            $this->aiAgentService->auditarDesfecho($pendente['tool'], ConfirmacoesPendentes::propostos($pendente), 'expirado',
                'A confirmação expirou sem resposta. Nada foi gravado.');
        }

        return $resposta;
    }
}
