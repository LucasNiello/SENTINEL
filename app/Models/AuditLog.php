<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'autorizado_por', 'tool', 'origem', 'acao', 'entidade_tipo', 'entidade_id', 'parametros', 'parametros_propostos', 'resultado', 'mensagem', 'tempo_ia_ms', 'papel', 'permitido', 'tenant_id'])]
class AuditLog extends Model
{
    protected function casts(): array
    {
        return [
            'parametros' => 'array',
            'parametros_propostos' => 'array',
            'tempo_ia_ms' => 'integer',
            'permitido' => 'boolean',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
