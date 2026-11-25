<?php

namespace App\Http\Controllers;

use App\Services\AgendaService;
use App\Services\AiAgentService;
use App\Services\ConversaAgente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Cancelar agendamentos em lote (25/11, decisão do Lucas): nada às cegas. A tool `cancelar_agendamentos_em_lote` só MOSTRA a
 * lista com checkbox; este endpoint grava SÓ os ids que o usuário marcou, e só se estavam naquela lista (a última tabela mostrada a
 * ele, guardada no servidor, ainda dentro do prazo). Tudo ou nada; papel, setor e tenant são conferidos no mesmo executor do
 * cancelamento de um agendamento (AiAgentService::cancelarAgendamentosEmLote).
 */
class AgendaLoteController extends Controller
{
    public function __construct(private AiAgentService $agente, private ConversaAgente $conversa)
    {
    }

    public function cancelar(Request $request): JsonResponse
    {
        $validador = Validator::make($request->all(), [
            'ids' => 'required|array|min:1|max:'.AgendaService::LIMITE_CANCELAR_EM_LOTE,
            'ids.*' => 'required|integer|min:1|distinct',
        ]);

        if ($validador->fails()) {
            return response()->json(['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_VALIDATION_ERROR', 'http_status' => 422,
                'mensagem' => 'Marque ao menos um agendamento (até '.AgendaService::LIMITE_CANCELAR_EM_LOTE.') para cancelar.'], 422);
        }

        $ids = array_map('intval', $validador->validated()['ids']);
        $lista = $this->conversa->lerLista($request->session());

        // Só vale a lista de cancelar em lote que ESTE usuário (neste tenant) acabou de ver; nunca ids soltos do navegador.
        if ($lista === null || ($lista['selecionavel'] ?? null) !== 'cancelar_agendamentos_em_lote') {
            return response()->json(['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_BATCH_LIST_EXPIRED', 'http_status' => 422,
                'mensagem' => 'Essa lista não vale mais. Peça de novo e marque os agendamentos.'], 422);
        }
        if (array_diff($ids, array_map('intval', array_filter($lista['linhas'] ?? [], 'is_int'))) !== []) {
            return response()->json(['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_BATCH_IDS_NOT_LISTED', 'http_status' => 422,
                'mensagem' => 'Só dá para cancelar agendamentos que estavam na lista. Peça de novo.'], 422);
        }

        try {
            // As versões são as de quando a lista foi mostrada (guardadas no servidor): o que mudou desde então é recusado.
            $resposta = $this->agente->cancelarAgendamentosEmLote($ids, array_map('strval', (array) ($lista['versoes'] ?? [])));
        } catch (\Illuminate\Auth\AuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $resposta = $this->agente->erroTecnicoTool($e);
        }

        if (($resposta['tipo'] ?? null) === 'resultado_escrita') {
            // Uma lista, um envio: depois de gravar ela some (reenviar o mesmo pedido não cancela de novo), e o chat fica sabendo.
            $this->conversa->esquecerLista($request->session());
            $this->conversa->registrarResultado($request->session(), $resposta['mensagem'], null);
        }

        return response()->json($resposta, $resposta['http_status'] ?? match ($resposta['tipo'] ?? null) {
            'negado' => 403,
            'erro' => 422,
            default => 200,
        });
    }
}
