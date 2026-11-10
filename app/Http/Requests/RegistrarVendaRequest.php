<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Clínica vet: venda avulsa. Itens: o id do item do catálogo e a quantidade inteira; o preço é sempre o do catálogo, copiado pelo servidor. Sem forma de pagamento, a venda fica em aberto. */
class RegistrarVendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'itens' => 'required|array|min:1|max:20',
            'itens.*.catalogo_item_id' => 'required|integer|min:1',
            'itens.*.quantidade' => 'required|integer|min:1|max:100000',
            'forma_pagamento' => 'nullable|in:dinheiro,pix,cartao',
            'tutor_id' => 'nullable|integer|exists:tutores,id',
            'pet_id' => 'nullable|integer|exists:pets,id',
            'agendamento_id' => 'nullable|integer|exists:agendamentos,id',
            'tenant_id' => 'required|integer',
        ];
    }
}
