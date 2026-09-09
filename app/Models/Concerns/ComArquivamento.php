<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Ciclo de vida de exclusão segura (RF08-RF12): ativo -> lixeira
 * (soft delete) -> arquivado (prazo de retenção expirado). Espera que o
 * model também use Illuminate\Database\Eloquent\SoftDeletes.
 */
trait ComArquivamento
{
    public function arquivar(): void
    {
        $this->forceFill(['arquivado_em' => now()])->save();
    }

    public function estaArquivado(): bool
    {
        return $this->arquivado_em !== null;
    }

    protected function scopeNaLixeira(Builder $query): Builder
    {
        return $query->onlyTrashed()->whereNull('arquivado_em');
    }

    protected function scopeArquivados(Builder $query): Builder
    {
        return $query->onlyTrashed()->whereNotNull('arquivado_em');
    }
}
