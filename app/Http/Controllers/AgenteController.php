<?php

namespace App\Http\Controllers;

use App\Services\AiAgentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
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
        // Array ou tipo errado vira 422 com frase amigável, nunca 500 (B4); o teto limita o custo por chamada (RNF10).
        $validador = Validator::make($request->all(), ['mensagem' => 'required|string|max:1000'], [
            'mensagem.required' => 'Escreva o seu pedido.',
            'mensagem.string' => 'O pedido deve ser um texto.',
            'mensagem.max' => 'O pedido aceita no máximo 1000 caracteres.',
        ]);

        if ($validador->fails()) {
            return response()->json(['tipo' => 'erro', 'mensagem' => $validador->errors()->first()], 422);
        }

        $resposta = $this->aiAgentService->processar($validador->validated()['mensagem']);

        if (($resposta['tipo'] ?? null) === 'confirmacao_pendente') {
            $token = (string) Str::uuid();

            $request->session()->put("confirmacoes.{$token}", [
                'tool' => $resposta['tool'],
                'argumentos' => $resposta['argumentos'],
                'emitido_em' => now()->timestamp,
            ]);

            $resposta['token'] = $token;
        }

        return response()->json($resposta, ($resposta['tipo'] ?? null) === 'negado' ? 403 : 200);
    }

    /**
     * Executa uma tool após confirmação humana no front.
     *
     * Contrato: { "token": "<uuid recebido em confirmacao_pendente>" }.
     * Tool e argumentos vêm da sessão, nunca do corpo da requisição.
     */
    public function confirmar(Request $request)
    {
        // Só UUID: um token com ponto navegaria dentro da sessão (N2); array não vira 500 (B4).
        $validador = Validator::make($request->all(), ['token' => 'required|string|uuid']);

        if ($validador->fails()) {
            return response()->json(['tipo' => 'erro', 'mensagem' => 'Confirmação inválida ou já utilizada.'], 422);
        }

        $token = $validador->validated()['token'];
        $pendente = $request->session()->pull("confirmacoes.{$token}"); // uso único

        if (! $pendente) {
            return response()->json(['tipo' => 'erro', 'mensagem' => 'Confirmação inválida ou já utilizada.'], 422);
        }

        // M1: duas requisições simultâneas leem a mesma sessão e as duas "puxam" o token.
        // O add é atômico no store (chave única no driver database), então só a primeira passa.
        // A marca dura pelo menos a validade do token, para não "expirar" antes da janela.
        if (! Cache::add("agente:token_usado:{$token}", true, max(60, self::VALIDADE_MAXIMA_S))) {
            return response()->json(['tipo' => 'erro', 'mensagem' => 'Esta confirmação já foi usada.'], 409);
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

        $status = match ($resultado['tipo'] ?? null) {
            'erro' => 422,
            'negado' => 403,
            default => 200,
        };

        return response()->json($resultado, $status);
    }
}
