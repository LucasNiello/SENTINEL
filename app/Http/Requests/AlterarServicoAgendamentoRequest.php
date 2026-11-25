<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Clínica vet (15/11): trocar o serviço de um agendamento que já existe ("não era banho, era consulta"). Campos da
 * ficha: o serviço novo, o profissional e o início (os dois últimos pelo calendário; no mesmo setor a ficha abre com
 * os atuais). O fim o servidor recalcula. A regra de negócio (em aberto, cobrança válida, setor do profissional, vaga
 * e conflito) fica no AgendaService::trocarServico.
 */
class AlterarServicoAgendamentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|integer|min:1',
            'catalogo_item_id' => 'required|integer|exists:catalogo_itens,id',
            'profissional_id' => 'nullable|integer|exists:users,id',
            'inicio' => 'required|string|max:19',
            'tenant_id' => 'required|integer',
        ];
    }
}
