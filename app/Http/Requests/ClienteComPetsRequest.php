<?php

namespace App\Http\Requests;

use App\Models\Pet;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Clínica vet (27/11): cadastro unificado do cliente. O tutor e, de uma vez, os pets dele (0 a 10), gravados tudo ou nada.
 * Os campos "pets.*.x" não viram campos soltos no card: o card os mostra como uma lista de pets (ver AiAgentService::formulario).
 */
class ClienteComPetsRequest extends FormRequest
{
    public const MAX_PETS = 10;

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
            'endereco' => 'nullable|string|max:255',
            'pets' => 'nullable|list|max:'.self::MAX_PETS,
            'pets.*' => 'array:nome,especie,raca,sexo,data_nascimento,peso_gramas,microchip,alertas,observacoes',
            'pets.*.nome' => 'required|string|max:255',
            'pets.*.especie' => 'required|in:'.implode(',', Pet::ESPECIES),
            'pets.*.raca' => 'nullable|string|max:255',
            'pets.*.sexo' => 'nullable|in:'.implode(',', Pet::SEXOS),
            'pets.*.data_nascimento' => 'nullable|date_format:Y-m-d',
            'pets.*.peso_gramas' => 'nullable|integer|min:0|max:500000',
            'pets.*.microchip' => 'nullable|string|max:32',
            'pets.*.alertas' => 'nullable|string|max:500',
            'pets.*.observacoes' => 'nullable|string|max:500',
            'tenant_id' => 'required|integer',
        ];
    }
}
