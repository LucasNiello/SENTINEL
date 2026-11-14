<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** T2: uma linha estornada (cobranca_itens.id ou venda_itens.id em item_id), com a quantidade e o valor da época. */
#[Fillable(['estorno_id', 'item_id', 'quantidade', 'valor_centavos', 'devolveu_estoque'])]
class EstornoItem extends Model
{
    protected $table = 'estorno_itens';

    protected function casts(): array
    {
        return [
            'estorno_id' => 'integer',
            'item_id' => 'integer',
            'quantidade' => 'integer',
            'valor_centavos' => 'integer',
            'devolveu_estoque' => 'boolean',
        ];
    }

    public function estorno(): BelongsTo
    {
        return $this->belongsTo(Estorno::class);
    }
}
