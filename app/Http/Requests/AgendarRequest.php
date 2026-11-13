<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Clínica vet (P-C4): agendar um atendimento ou registrar um encaixe de emergência. As regras desenham o card e
 * validam a execução; a regra de negócio (pet, serviço e profissional do tenant, setor, passado, conflito, duração)
 * fica no AgendaService. Início e fim vão como texto (AAAA-MM-DD HH:MM): com date_format o card viraria um campo só
 * de data e perderia a hora. A conferência estrita do formato é do AgendaService::momento().
 */
class AgendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pet_id' => 'required|integer|exists:pets,id',
            'catalogo_item_id' => 'required|integer|exists:catalogo_itens,id',
            'profissional_id' => 'required|integer|exists:users,id',
            'inicio' => 'required|string|max:19',
            'fim' => 'required|string|max:19',
            'tenant_id' => 'required|integer',
        ];
    }
}
