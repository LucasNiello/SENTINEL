<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Clínica vet: arquivar o cadastro de um pet (não apaga nada). O motivo é obrigatório e vai para as observações do pet. */
class ArquivarPetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|integer|min:1',
            'observacoes' => 'required|string|max:500',
            'tenant_id' => 'required|integer',
        ];
    }
}
