<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Clínica vet (15/11): remarcar um agendamento que já existe. Os campos da ficha são o novo início (texto
 * AAAA-MM-DD HH:MM, como no AgendarRequest: com date_format o card perderia a hora) e o profissional (do mesmo setor;
 * a ficha abre com o atual), escolhidos no calendário. Pet, tutor e serviço só aparecem para conferir (cabeçalho da
 * ficha); o fim o servidor recalcula. A regra de negócio (em aberto, passado, vaga e conflito) fica no AgendaService.
 */
class RemarcarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|integer|min:1',
            'profissional_id' => 'required|integer|exists:users,id',
            'inicio' => 'required|string|max:19',
            'tenant_id' => 'required|integer',
        ];
    }
}
