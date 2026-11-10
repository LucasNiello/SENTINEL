<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Clínica vet: mudar o status de um agendamento em aberto (agendado ou confirmado). */
class AlterarStatusAgendamentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|integer|min:1',
            'novo_status' => 'required|in:confirmado,realizado,cancelado,faltou',
            'tenant_id' => 'required|integer',
        ];
    }
}
