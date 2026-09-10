<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class NotaFiscalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'numero' => 'required|string|max:50',
            'tipo' => 'required|in:entrada,saida',
            'valor' => 'required|numeric|min:0',
            'data_emissao' => 'required|date',
            'cliente_id' => 'required_if:tipo,saida|prohibited_if:tipo,entrada|nullable|exists:clientes,id',
            'fornecedor_id' => 'required_if:tipo,entrada|prohibited_if:tipo,saida|nullable|exists:fornecedores,id',
            'lancamento_id' => 'nullable|exists:lancamentos,id',
            'tenant_id' => 'required|integer',
        ];
    }
}
