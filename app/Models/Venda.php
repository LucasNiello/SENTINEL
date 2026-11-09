<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Venda de balcão. total_centavos é sempre calculado pelo VendaService. */
#[Fillable(['setor', 'vendedor_id', 'tutor_id', 'status', 'total_centavos', 'forma_pagamento', 'pago_em', 'tenant_id'])]
class Venda extends Model
{
    public const STATUS = ['aberta', 'paga', 'cancelada'];

    protected function casts(): array
    {
        return [
            'vendedor_id' => 'integer',
            'tutor_id' => 'integer',
            'tenant_id' => 'integer',
            'total_centavos' => 'integer',
            'pago_em' => 'datetime',
        ];
    }

    public function itens(): HasMany
    {
        return $this->hasMany(VendaItem::class);
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }

    public function tutor(): BelongsTo
    {
        return $this->belongsTo(Tutor::class);
    }
}
