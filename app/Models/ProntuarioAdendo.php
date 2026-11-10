<?php

namespace App\Models;

use App\Exceptions\RegraDeNegocioException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Correção de um prontuário assinado. Nasce assinado e nunca muda nem é excluído. */
#[Fillable(['prontuario_id', 'autor_id', 'autor_nome', 'autor_crmv', 'texto', 'assinado_em', 'tenant_id'])]
class ProntuarioAdendo extends Model
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new RegraDeNegocioException('Adendo assinado não pode ser alterado. Registre um novo adendo.'));
        static::deleting(fn () => throw new RegraDeNegocioException('Adendo não pode ser excluído: a guarda mínima é de 5 anos.'));
    }

    protected function casts(): array
    {
        return [
            'assinado_em' => 'datetime',
        ];
    }

    public function prontuario(): BelongsTo
    {
        return $this->belongsTo(Prontuario::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_id');
    }
}
