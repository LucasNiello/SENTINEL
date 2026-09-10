<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nome' => 'required|string|max:255',
            'documento' => 'nullable|string|max:32',
            'email' => 'nullable|email|max:255',
            'telefone' => 'nullable|string|max:20',
            'tenant_id' => 'required|integer',
        ];
    }
}
