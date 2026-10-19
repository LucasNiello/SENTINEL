<?php

namespace App\Http\Controllers;

use App\Services\AiAgentService;
use App\Services\ConfirmacoesPendentes;
use App\Services\ConversaAgente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class AgenteController extends Controller
{
    public function __construct(
        private AiAgentService $aiAgentService,
        private ConversaAgente $conversa,
        private ConfirmacoesPendentes $pendentes,
    ) {
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
            return response()->json(['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_VALIDATION_ERROR', 'mensagem' => $validador->errors()->first()], 422);
        }

        $mensagem = $validador->validated()['mensagem'];
        try {
            $resposta = $this->aiAgentService->processar($mensagem, $this->conversa->ler($request->session()));
        } catch (\Illuminate\Auth\AuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $resposta = $this->aiAgentService->erroTecnicoTool($e);
        }
        $this->conversa->guardar($request->session(), $mensagem, $resposta);

        if (($resposta['tipo'] ?? null) === 'confirmacao_pendente') {
            $resposta['token'] = $this->pendentes->guardar($request->session(), $resposta['tool'], $resposta['argumentos']);
        }

        return response()->json($resposta, $resposta['http_status'] ?? match ($resposta['tipo'] ?? null) {
            'negado' => 403,
            'erro' => 422,
            default => 200,
        });
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
        $pendente = $this->pendentes->retirar($request->session(), $token); // uso único

        if (! $pendente) {
            return response()->json(['tipo' => 'erro', 'mensagem' => 'Confirmação inválida ou já utilizada.'], 422);
        }

        if (! $this->pendentes->concluir($token)) {
            return response()->json(['tipo' => 'erro', 'mensagem' => 'Esta confirmação já foi usada.'], 409);
        }

        $decorrido = $this->pendentes->decorrido($pendente);

        if ($decorrido < ConfirmacoesPendentes::ESPERA_MINIMA_S || $decorrido > ConfirmacoesPendentes::VALIDADE_MAXIMA_S) {
            [$desfecho, $motivo] = $decorrido > ConfirmacoesPendentes::VALIDADE_MAXIMA_S
                ? ['expirado', 'Confirmação recebida fora do prazo. Nada foi gravado.']
                : ['recusado', 'Confirmação antes da espera mínima. Nada foi gravado.'];
            $this->aiAgentService->auditarDesfecho($pendente['tool'], $pendente['argumentos'], $desfecho, $motivo);

            return response()->json(['tipo' => 'erro', 'mensagem' => 'Confirmação fora do prazo. Refaça o comando.'], 422);
        }

        try {
            $resultado = $this->aiAgentService->executarTool($pendente['tool'], $pendente['argumentos']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['tipo' => 'erro', 'categoria' => 'unsupported_operation', 'codigo' => 'AI_UNSUPPORTED_OPERATION', 'mensagem' => 'Esta operação não é suportada pelo agente.'], 422);
        } catch (\Illuminate\Auth\AuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $resultado = $this->aiAgentService->erroTecnicoTool($e);
        }

        $status = $resultado['http_status'] ?? match ($resultado['tipo'] ?? null) {
            'erro' => 422,
            'negado' => 403,
            default => 200,
        };

        return response()->json($resultado, $status);
    }

    /**
     * Encerra uma proposta sem gravar: Cancelar no card ou fim da janela da interface.
     *
     * Contrato: { "token": "<uuid>" }. Se foi "cancelado" ou "expirado", decide o
     * servidor pelo horário da proposta, nunca pelo que o front manda: depois da
     * janela da interface (10 s) o card já não aceitava ação do usuário, então
     * quem encerrou foi o prazo, não uma escolha. O desfecho vai para a auditoria
     * e a proposta sai da sessão.
     */
    public function cancelar(Request $request)
    {
        $validador = Validator::make($request->all(), ['token' => 'required|string|uuid']);
        $token = $validador->fails() ? null : $validador->validated()['token'];
        $pendente = $token === null ? null : $this->pendentes->retirar($request->session(), $token);

        if (! $pendente) {
            return response()->json(['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_CONFIRMATION_INVALID',
                'http_status' => 422, 'mensagem' => 'Confirmação inválida ou já utilizada.'], 422);
        }

        // Mesma marca do /confirmar: se a proposta já foi confirmada por uma requisição concorrente, não há o que cancelar.
        if (! $this->pendentes->concluir($token)) {
            return response()->json(['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_CONFIRMATION_USED',
                'http_status' => 409, 'mensagem' => 'Esta confirmação já foi usada.'], 409);
        }

        $expirou = $this->pendentes->decorrido($pendente) >= ConfirmacoesPendentes::JANELA_INTERFACE_S;
        [$desfecho, $motivo] = $expirou
            ? ['expirado', 'A confirmação expirou sem resposta. Nada foi gravado.']
            : ['cancelado', 'Cancelado pelo usuário. Nada foi gravado.'];
        $this->aiAgentService->auditarDesfecho($pendente['tool'], $pendente['argumentos'], $desfecho, $motivo);

        return response()->json($expirou
            ? ['tipo' => 'expirado', 'mensagem' => 'Confirmação expirada. Nada foi gravado. Peça de novo.']
            : ['tipo' => 'cancelado', 'mensagem' => 'Cancelado. Nada foi gravado.']);
    }
}
