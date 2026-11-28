<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EditarCobrancaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|integer|min:1', 'tenant_id' => 'required|integer',
            'desconto_tipo' => 'nullable|required_with:desconto_valor|in:reais,percentual',
            'desconto_valor' => 'nullable|required_with:desconto_tipo|string|max:30',
            'desconto_motivo' => 'nullable|string|max:200',
            'versao' => 'required|string|size:64',
            'linhas' => 'present|array|max:200',
            'linhas.*' => 'array:item_id,quantidade,remover,motivo',
            'linhas.*.item_id' => 'required|integer|min:1|distinct',
            'linhas.*.quantidade' => 'required|integer|min:1|max:100000',
            'linhas.*.remover' => 'required|boolean',
            'linhas.*.motivo' => 'nullable|string|max:200',
            'novos' => 'present|array|max:20',
            'novos.*' => 'array:catalogo_item_id,quantidade',
            'novos.*.catalogo_item_id' => 'required|integer|min:1|distinct',
            'novos.*.quantidade' => 'required|integer|min:1|max:100000',
        ];
    }
}
