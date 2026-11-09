<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Linha da cobrança: descrição e preço são CÓPIAS do catálogo no momento do lançamento. */
#[Fillable(['cobranca_id', 'catalogo_item_id', 'prontuario_item_id', 'descricao', 'preco_unitario_centavos', 'quantidade', 'subtotal_centavos', 'baixou_estoque', 'tenant_id'])]
class CobrancaItem extends Model
{
    protected $table = 'cobranca_itens';

    protected function casts(): array
    {
        return [
            'preco_unitario_centavos' => 'integer',
            'quantidade' => 'integer',
            'subtotal_centavos' => 'integer',
            'baixou_estoque' => 'boolean',
        ];
    }

    public function cobranca(): BelongsTo
    {
        return $this->belongsTo(Cobranca::class);
    }
}
