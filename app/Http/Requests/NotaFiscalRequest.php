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
            // O teto é o de decimal(10,2): acima disso o MySQL recusaria com erro genérico.
            'valor' => 'required|numeric|min:0|max:99999999.99',
            // date_format em vez de date: "10/09" seria lido como 9 de outubro.
            'data_emissao' => 'required|date_format:Y-m-d',
            'cliente_id' => 'required_if:tipo,saida|prohibited_if:tipo,entrada|nullable|exists:clientes,id',
            'fornecedor_id' => 'required_if:tipo,entrada|prohibited_if:tipo,saida|nullable|exists:fornecedores,id',
            'lancamento_id' => 'nullable|exists:lancamentos,id',
            'tenant_id' => 'required|integer',
        ];
    }
}
