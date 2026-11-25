<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Caixa de entrada SIMULADA: o seed e, desde 24/11, os e-mails para o contato da clínica lidos no Mailpit (CaixaDeEntrada). */
#[Fillable(['tutor_id', 'remetente', 'origem_id', 'texto', 'status', 'tenant_id'])]
class Mensagem extends Model
{
    public const STATUS = ['nova', 'tratada'];

    protected $table = 'mensagens';

    public function tutor(): BelongsTo
    {
        return $this->belongsTo(Tutor::class);
    }
}
