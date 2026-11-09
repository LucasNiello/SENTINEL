<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Linha da venda: descrição e preço são CÓPIAS do catálogo no momento da venda. */
#[Fillable(['venda_id', 'catalogo_item_id', 'descricao', 'preco_unitario_centavos', 'quantidade', 'subtotal_centavos', 'baixou_estoque', 'tenant_id'])]
class VendaItem extends Model
{
    protected $table = 'venda_itens';

    protected function casts(): array
    {
        return [
            'preco_unitario_centavos' => 'integer',
            'quantidade' => 'integer',
            'subtotal_centavos' => 'integer',
            'baixou_estoque' => 'boolean',
        ];
    }

    public function venda(): BelongsTo
    {
        return $this->belongsTo(Venda::class);
    }
}
