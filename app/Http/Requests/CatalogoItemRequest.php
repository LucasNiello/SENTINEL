<?php

namespace App\Http\Requests;

use App\Models\CatalogoItem;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Clínica vet: item do catálogo (serviço ou produto, dos dois setores). Preço em CENTAVOS inteiros.
 * Os campos sim/não são "sim" ou "nao" (no formulário do card e na IA): a tool converte para verdadeiro/falso.
 *
 * Decisão do Lucas (10/11): o que muda regra de negócio e não pode ser alterado depois do cadastro é OBRIGATÓRIO,
 * sem valor padrão — ausente é recusado com frase amigável: tipo, setor, categoria, nome, preço, cobrança por
 * unidade/diária, controle de estoque e exigência de receita. Só o estoque inicial tem padrão (0); depois ele muda
 * por entrada, venda e cobrança e pode ficar negativo (decisão de 10/11), com aviso no card antes do segurar.
 */
class CatalogoItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tipo' => 'required|in:'.implode(',', CatalogoItem::TIPOS),
            'setor' => 'required|in:'.implode(',', CatalogoItem::SETORES),
            'categoria' => 'required|string|max:30',
            'nome_comercial' => 'required|string|max:255',
            'preco_centavos' => 'required|integer|min:0|max:100000000',
            'cobranca_por' => 'required|in:'.implode(',', CatalogoItem::COBRANCA_POR),
            // Só "sim" ou "nao" exatos: "required" recusa ausente, vazio e espaço; "in" recusa true, 1, "Sim ", "SIM"...
            // (e desenha as duas opções do formulário). Não há "nao" por padrão.
            'controla_estoque' => 'required|in:sim,nao',
            'estoque' => 'nullable|integer|min:0|max:100000',
            'exige_receita' => 'required|in:sim,nao',
            'tenant_id' => 'required|integer',
        ];
    }
}
