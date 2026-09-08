<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['descricao', 'valor', 'status', 'data', 'tenant_id'])]
class Lancamento extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'string',
            'valor' => 'decimal:2',
            'data' => 'date',
        ];
    }

    // Sem relação com Tenant: a tabela de tenants ainda não existe no
    // projeto. tenant_id fica como coluna simples até essa estrutura ser criada.
}
