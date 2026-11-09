<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Sem lixeira: um agendamento se cancela (status), não se exclui. */
#[Fillable(['pet_id', 'profissional_id', 'setor', 'catalogo_item_id', 'inicio', 'fim', 'status', 'origem', 'tenant_id'])]
class Agendamento extends Model
{
    public const STATUS = ['agendado', 'confirmado', 'realizado', 'cancelado', 'faltou'];

    /** Status que liberam o horário do profissional (não contam como conflito). */
    public const STATUS_LIVRES = ['cancelado', 'faltou'];

    public const ORIGENS = ['normal', 'encaixe_emergencia'];

    protected function casts(): array
    {
        return [
            'pet_id' => 'integer',
            'profissional_id' => 'integer',
            'catalogo_item_id' => 'integer',
            'tenant_id' => 'integer',
            'inicio' => 'datetime',
            'fim' => 'datetime',
        ];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function profissional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'profissional_id');
    }

    public function servico(): BelongsTo
    {
        return $this->belongsTo(CatalogoItem::class, 'catalogo_item_id');
    }

    public function prontuario(): HasOne
    {
        return $this->hasOne(Prontuario::class);
    }

    public function cobranca(): HasOne
    {
        return $this->hasOne(Cobranca::class);
    }
}
