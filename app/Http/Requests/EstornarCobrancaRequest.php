<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Clínica vet (H): estorno de uma cobrança paga. O card mostra só o motivo; a cobrança (id) vem do localizar e
 * fica na proposta. Quem autoriza (PIN do supervisor ou senha do dono) é digitado no card, no /agente/confirmar,
 * nunca é campo deste formulário.
 */
class EstornarCobrancaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|integer|min:1',
            'motivo' => 'required|string|max:500',
            'tenant_id' => 'required|integer',
        ];
    }
}
