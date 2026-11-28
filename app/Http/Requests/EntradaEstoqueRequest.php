<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EntradaEstoqueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|integer|min:1',
            'quantidade' => 'required|integer|min:1|max:100000',
            'motivo' => 'nullable|string|max:200',
            'tenant_id' => 'required|integer',
        ];
    }
}
