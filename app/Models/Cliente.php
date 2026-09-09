<?php

namespace App\Models;

use App\Models\Concerns\ComArquivamento;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['nome', 'documento', 'email', 'telefone', 'tenant_id'])]
class Cliente extends Model
{
    use ComArquivamento, SoftDeletes;

    protected function casts(): array
    {
        return [
            'arquivado_em' => 'datetime',
        ];
    }

    public function lancamentos(): HasMany
    {
        return $this->hasMany(Lancamento::class);
    }

    public function notasFiscais(): HasMany
    {
        return $this->hasMany(NotaFiscal::class);
    }
}
