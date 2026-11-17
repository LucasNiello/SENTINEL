<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Clínica vet (H, T2 de 14/11): estorno de uma cobrança paga, por itens. O card mostra o modo, o motivo e o editor de
 * linhas (quantidade por linha); a cobrança (id) vem do localizar e fica na proposta. Nenhum campo de valor: o servidor
 * calcula pelo preço copiado em cada linha. Quem autoriza (PIN do supervisor do setor ou do dono) é digitado no card,
 * no /agente/confirmar, nunca é campo deste formulário.
 */
class EstornarCobrancaRequest extends FormRequest
{
    public const MODOS = ['tudo', 'tudo_menos_prontuario', 'itens'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|integer|min:1',
            'modo' => 'required|in:'.implode(',', static::MODOS),
            'motivo' => 'required|string|max:500',
            // "linhas", não "itens": o localizar preenche os campos com os atributos do registro, e itens() é a relação da cobrança.
            // Sem teto baixo: o Revisar congela "tudo" em uma linha por item, e uma internação longa passa de 50 (2ª passada).
            'linhas' => 'nullable|array|max:1000',
            'linhas.*.item_id' => 'required|integer|min:1',
            'linhas.*.quantidade' => 'required|integer|min:1|max:100000',
            'tenant_id' => 'required|integer',
        ];
    }
}
