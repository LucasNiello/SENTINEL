<?php

namespace App\Http\Requests;

use App\Models\Pet;
use Illuminate\Foundation\Http\FormRequest;

/** Clínica vet: pet. O tutor precisa ser do tenant (o exists: é escopado pelo AiAgentService::validar). */
class PetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tutor_id' => 'required|integer|exists:tutores,id',
            'nome' => 'required|string|max:255',
            'especie' => 'required|in:'.implode(',', Pet::ESPECIES),
            'raca' => 'nullable|string|max:255',
            'sexo' => 'nullable|in:'.implode(',', Pet::SEXOS),
            'data_nascimento' => 'nullable|date_format:Y-m-d',
            'peso_gramas' => 'nullable|integer|min:0|max:500000',
            'microchip' => 'nullable|string|max:32',
            'alertas' => 'nullable|string|max:500',
            'tenant_id' => 'required|integer',
        ];
    }
}
