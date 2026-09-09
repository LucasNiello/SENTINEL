<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'tool', 'acao', 'entidade_tipo', 'entidade_id', 'parametros', 'resultado', 'mensagem', 'tenant_id'])]
class AuditLog extends Model
{
    protected function casts(): array
    {
        return [
            'parametros' => 'array',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
