<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Clínica vet: registrar o pagamento de uma cobrança fechada (a receber). */
class ReceberCobrancaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|integer|min:1',
            'forma_pagamento' => 'required|in:dinheiro,pix,cartao',
            'tenant_id' => 'required|integer',
        ];
    }
}
