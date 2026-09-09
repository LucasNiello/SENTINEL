<?php

namespace App\Http\Controllers;

use App\Services\AiAgentService;
use Illuminate\Http\Request;

class AgenteController extends Controller
{
    public function __construct(private AiAgentService $aiAgentService)
    {
    }

    /**
     * Recebe o comando em linguagem natural do usuário e delega ao agente.
     */
    public function processar(Request $request)
    {
        $mensagem = (string) $request->input('mensagem', '');

        return response()->json(
            $this->aiAgentService->processar($mensagem)
        );
    }

    /**
     * Executa uma tool após confirmação humana no front.
     *
     * Contrato mínimo: { "tool": "criar_lancamento", "argumentos": {...} }.
     */
    public function confirmar(Request $request)
    {
        $tool = (string) $request->input('tool', '');
        $argumentos = (array) $request->input('argumentos', []);

        $resultado = $this->aiAgentService->executarTool($tool, $argumentos);

        $status = ($resultado['tipo'] ?? null) === 'erro' ? 422 : 200;

        return response()->json($resultado, $status);
    }
}
