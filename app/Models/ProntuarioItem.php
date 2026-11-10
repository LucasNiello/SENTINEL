<?php

namespace App\Models;

use App\Exceptions\RegraDeNegocioException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O que foi realizado no atendimento (SEM preço). É a ponte para a cobrança. Segue a imutabilidade
 * do prontuário: depois da assinatura, nenhum item entra, muda ou sai.
 */
#[Fillable(['prontuario_id', 'catalogo_item_id', 'nome_item', 'quantidade', 'tenant_id'])]
class ProntuarioItem extends Model
{
    protected $table = 'prontuario_itens';

    protected static function booted(): void
    {
        $recusarSeAssinado = function (ProntuarioItem $item): void {
            $assinado = Prontuario::query()->whereKey($item->prontuario_id)->whereNotNull('assinado_em')->exists();

            if ($assinado) {
                throw new RegraDeNegocioException('Prontuário assinado não pode ser alterado. Para corrigir, registre um adendo.');
            }
        };

        static::creating($recusarSeAssinado);
        static::updating(function (ProntuarioItem $item) use ($recusarSeAssinado): void {
            // Item não troca de prontuário: senão sairia de um assinado (a guarda só veria o destino).
            if ($item->isDirty('prontuario_id')) {
                throw new RegraDeNegocioException('Um item do prontuário não muda de prontuário.');
            }
            $recusarSeAssinado($item);
        });
        static::deleting($recusarSeAssinado);
    }

    protected function casts(): array
    {
        return [
            'prontuario_id' => 'integer',
            'catalogo_item_id' => 'integer',
            'quantidade' => 'integer',
        ];
    }

    public function prontuario(): BelongsTo
    {
        return $this->belongsTo(Prontuario::class);
    }

    /** Com lixeira: item excluído do catálogo depois de usado continua ligado ao prontuário. */
    public function catalogoItem(): BelongsTo
    {
        return $this->belongsTo(CatalogoItem::class)->withTrashed();
    }

    /** O nome que valia no lançamento (cópia); só registro anterior à cópia cai no cadastro atual. */
    public function nomeNoLancamento(): ?string
    {
        return $this->nome_item ?? $this->catalogoItem?->nome_comercial;
    }
}
