<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LancamentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'descricao' => 'required|string|max:255',
            // gt:0 — lançamento de valor zero ou negativo não faz sentido; o teto é o de decimal(10,2).
            'valor' => 'required|numeric|gt:0|max:99999999.99',
            // date_format em vez de date: "10/09" seria lido como 9 de outubro.
            'data' => 'required|date_format:Y-m-d',
            'tenant_id' => 'required|integer',
        ];
    }
}
