<?php

namespace App\Models;

use App\Models\Concerns\ComArquivamento;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['nome', 'tipo', 'tenant_id'])]
class CategoriaLancamento extends Model
{
    use ComArquivamento, SoftDeletes;

    protected $table = 'categorias_lancamento';

    protected function casts(): array
    {
        return [
            'tipo' => 'string',
            'arquivado_em' => 'datetime',
        ];
    }

    public function lancamentos(): HasMany
    {
        return $this->hasMany(Lancamento::class);
    }
}
