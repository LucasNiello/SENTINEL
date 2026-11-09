<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Caixa de entrada SIMULADA (só seed nesta fase). */
#[Fillable(['tutor_id', 'remetente', 'texto', 'status', 'tenant_id'])]
class Mensagem extends Model
{
    public const STATUS = ['nova', 'tratada'];

    protected $table = 'mensagens';

    public function tutor(): BelongsTo
    {
        return $this->belongsTo(Tutor::class);
    }
}
