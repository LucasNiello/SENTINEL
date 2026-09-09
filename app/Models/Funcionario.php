<?php

namespace App\Models;

use App\Models\Concerns\ComArquivamento;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['nome', 'cpf', 'cargo', 'salario', 'data_admissao', 'data_demissao', 'tenant_id'])]
class Funcionario extends Model
{
    use ComArquivamento, SoftDeletes;

    protected function casts(): array
    {
        return [
            'salario' => 'decimal:2',
            'data_admissao' => 'date',
            'data_demissao' => 'date',
            'arquivado_em' => 'datetime',
        ];
    }

    public function lancamentos(): HasMany
    {
        return $this->hasMany(Lancamento::class);
    }
}
