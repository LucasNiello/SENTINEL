<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FuncionarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nome' => 'required|string|max:255',
            'cpf' => 'required|string|max:14',
            'cargo' => 'nullable|string|max:255',
            // O teto é o de decimal(10,2): acima disso o MySQL recusaria com erro genérico.
            'salario' => 'required|numeric|min:0|max:99999999.99',
            // date_format em vez de date: "10/09" seria lido como 9 de outubro.
            'data_admissao' => 'required|date_format:Y-m-d',
            'data_demissao' => 'nullable|date_format:Y-m-d|after_or_equal:data_admissao',
            'tenant_id' => 'required|integer',
        ];
    }
}
