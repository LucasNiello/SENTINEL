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
            'salario' => 'required|numeric|min:0',
            'data_admissao' => 'required|date',
            'data_demissao' => 'nullable|date|after_or_equal:data_admissao',
            'tenant_id' => 'required|integer',
        ];
    }
}
