<?php

namespace App\Http\Controllers;

use App\Services\ConsultaAuditoria;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Consulta da auditoria (Etapa A1), em JSON para a tela. Quem decide papel e tenant é o
 * ConsultaAuditoria; aqui só a validação dos filtros e o contrato de erro.
 */
class AuditoriaController extends Controller
{
    public function __construct(private ConsultaAuditoria $consulta)
    {
    }

    /** GET /auditoria/registros?de=&ate=&usuario=&tool=&resultado=&grupo=&pagina= */
    public function registros(Request $request)
    {
        $validador = Validator::make($request->query(), [
            'de' => 'nullable|string|date_format:Y-m-d',
            // Só com "de" em texto: sem ele, o after_or_equal compararia com o texto "de"; com "de" em
            // array, a comparação de datas lançaria TypeError (500). O "de" inválido já cai na regra dele.
            'ate' => ['nullable', 'string', 'date_format:Y-m-d', ...(is_string($request->query('de')) && $request->query('de') !== '' ? ['after_or_equal:de'] : [])],
            'usuario' => 'nullable|integer|min:1',
            'tool' => 'nullable|string|max:64|alpha_dash:ascii',
            'resultado' => ['nullable', 'string', Rule::in(ConsultaAuditoria::resultados())],
            'grupo' => ['nullable', 'string', Rule::in(ConsultaAuditoria::GRUPOS)],
            'pagina' => 'nullable|integer|min:1|max:100000',
        ], [
            'de.date_format' => 'Informe a data inicial no formato AAAA-MM-DD.',
            'ate.date_format' => 'Informe a data final no formato AAAA-MM-DD.',
            'ate.after_or_equal' => 'A data final precisa ser igual ou posterior à inicial.',
            '*.in' => 'Escolha uma das opções da lista.',
        ]);

        if ($validador->fails()) {
            return response()->json(['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_VALIDATION_ERROR', 'http_status' => 422,
                'mensagem' => $validador->errors()->first() ?: 'Confira os filtros e tente de novo.'], 422);
        }

        try {
            return response()->json($this->consulta->listar(array_filter($validador->validated(), fn ($valor) => $valor !== null && $valor !== '')));
        } catch (AuthorizationException) {
            return $this->semPermissao();
        }
    }

    /** GET /auditoria/registros/{id}: proposto × confirmado, mascarados. */
    public function registro(int $id)
    {
        try {
            return response()->json($this->consulta->detalhe($id));
        } catch (AuthorizationException) {
            return $this->semPermissao();
        } catch (ModelNotFoundException) {
            // Outro tenant ou inexistente: a mesma resposta (não revela que o registro existe).
            return response()->json(['tipo' => 'erro', 'categoria' => 'not_found', 'codigo' => 'AI_NOT_FOUND', 'http_status' => 404,
                'mensagem' => 'Registro de auditoria não encontrado.'], 404);
        }
    }

    private function semPermissao()
    {
        return response()->json(['tipo' => 'negado', 'categoria' => 'authorization_error', 'codigo' => 'AI_FORBIDDEN', 'http_status' => 403,
            'mensagem' => 'Você não tem permissão para ver a auditoria.'], 403);
    }
}
