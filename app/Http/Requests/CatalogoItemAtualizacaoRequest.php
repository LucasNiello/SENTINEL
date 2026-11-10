<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Clínica vet: o que se corrige num item do catálogo já cadastrado — nome, categoria e preço (em centavos).
 * Tipo, setor, controle de estoque e receita não mudam por aqui (mudam o que o histórico significa), e o
 * estoque só muda por entrada, venda e cobrança. O preço novo vale só daqui para frente.
 */
class CatalogoItemAtualizacaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'categoria' => 'required|string|max:30',
            'nome_comercial' => 'required|string|max:255',
            'preco_centavos' => 'required|integer|min:0|max:100000000',
            'tenant_id' => 'required|integer',
        ];
    }
}
