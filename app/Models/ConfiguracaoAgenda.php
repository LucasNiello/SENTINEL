<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Painel do dono (23/11): a agenda do tenant. Sem linha, vale config('sentinel.agenda'); ver ConfiguracaoDaAgenda. */
#[Fillable(['tenant_id', 'expediente_inicio', 'expediente_fim', 'intervalo_inicio', 'intervalo_fim', 'vagas_por_bloco'])]
class ConfiguracaoAgenda extends Model
{
    protected $table = 'configuracoes_agenda';

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'vagas_por_bloco' => 'integer'];
    }
}
