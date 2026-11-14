<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** T2: um estorno (parcial ou o que esgota) de uma cobrança paga ou de uma venda paga. valor_centavos = soma dos itens. */
#[Fillable(['cobranca_id', 'venda_id', 'solicitado_por', 'autorizado_por', 'motivo', 'valor_centavos', 'tenant_id'])]
class Estorno extends Model
{
    protected function casts(): array
    {
        return [
            'cobranca_id' => 'integer',
            'venda_id' => 'integer',
            'solicitado_por' => 'integer',
            'autorizado_por' => 'integer',
            'valor_centavos' => 'integer',
            'tenant_id' => 'integer',
        ];
    }

    public function itens(): HasMany
    {
        return $this->hasMany(EstornoItem::class);
    }
}
