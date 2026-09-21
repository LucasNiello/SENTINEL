<?php

namespace App\Http\Controllers;

use App\Services\AiAgentService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AgenteController extends Controller
{
    /** Espera mínima entre propor e confirmar — igual ao hold físico do front (DURACAO_HOLD_MS). */
    private const ESPERA_MINIMA_S = 2;

    /** Validade máxima da confirmação — janela ociosa do front (DURACAO_IDLE_MS, 10 s) + folga. */
    private const VALIDADE_MAXIMA_S = 12;

    public function __construct(private AiAgentService $aiAgentService)
    {
    }

    /**
     * Recebe o comando em linguagem natural do usuário e delega ao agente.
     *
     * Quando o agente propõe uma escrita, a proposta fica guardada na sessão
     * sob um token de uso único (RNF02) — é ele que autoriza o /confirmar.
     */
    public function processar(Request $request)
    {
        $resposta = $this->aiAgentService->processar((string) $request->input('mensagem', ''));

        if (($resposta['tipo'] ?? null) === 'confirmacao_pendente') {
            $token = (string) Str::uuid();

            $request->session()->put("confirmacoes.{$token}", [
                'tool' => $resposta['tool'],
                'argumentos' => $resposta['argumentos'],
                'emitido_em' => now()->timestamp,
            ]);

            $resposta['token'] = $token;
        }

        return response()->json($resposta);
    }

    /**
     * Executa uma tool após confirmação humana no front.
     *
     * Contrato: { "token": "<uuid recebido em confirmacao_pendente>" }.
     * Tool e argumentos vêm da sessão, nunca do corpo da requisição.
     */
    public function confirmar(Request $request)
    {
        $token = (string) $request->input('token', '');
        $pendente = $request->session()->pull("confirmacoes.{$token}"); // uso único

        if (! $pendente) {
            return response()->json(['tipo' => 'erro', 'mensagem' => 'Confirmação inválida ou já utilizada.'], 422);
        }

        $decorrido = now()->timestamp - $pendente['emitido_em'];

        if ($decorrido < self::ESPERA_MINIMA_S || $decorrido > self::VALIDADE_MAXIMA_S) {
            return response()->json(['tipo' => 'erro', 'mensagem' => 'Confirmação fora do prazo. Refaça o comando.'], 422);
        }

        try {
            $resultado = $this->aiAgentService->executarTool($pendente['tool'], $pendente['argumentos']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['tipo' => 'erro', 'mensagem' => $e->getMessage()], 422);
        }

        $status = ($resultado['tipo'] ?? null) === 'erro' ? 422 : 200;

        return response()->json($resultado, $status);
    }
}
