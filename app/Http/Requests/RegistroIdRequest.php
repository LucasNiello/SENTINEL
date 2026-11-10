<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Clínica vet: ação sobre um registro que já existe (cobrar o atendimento, cancelar a cobrança, marcar a mensagem): só o id, achado pelo servidor no tenant. */
class RegistroIdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|integer|min:1',
            'tenant_id' => 'required|integer',
        ];
    }
}
