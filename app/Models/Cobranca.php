<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cobrança de um agendamento. total_centavos é sempre calculado pelo CobrancaService. */
#[Fillable(['agendamento_id', 'setor', 'status', 'total_centavos', 'fechada_em', 'forma_pagamento', 'pago_em',
    'estorno_solicitado_por', 'estorno_autorizado_por', 'estorno_motivo', 'estorno_valor_centavos', 'estornado_em', 'tenant_id'])]
class Cobranca extends Model
{
    public const STATUS = ['aberta', 'fechada', 'paga', 'cancelada'];

    public const FORMAS_PAGAMENTO = ['dinheiro', 'pix', 'cartao'];

    protected function casts(): array
    {
        return [
            'agendamento_id' => 'integer',
            'tenant_id' => 'integer',
            'total_centavos' => 'integer',
            'fechada_em' => 'datetime',
            'pago_em' => 'datetime',
            'estorno_solicitado_por' => 'integer',
            'estorno_autorizado_por' => 'integer',
            'estorno_valor_centavos' => 'integer',
            'estornado_em' => 'datetime',
        ];
    }

    public function agendamento(): BelongsTo
    {
        return $this->belongsTo(Agendamento::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(CobrancaItem::class);
    }
}
