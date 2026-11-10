<?php

namespace App\Models;

use App\Models\Concerns\ComArquivamento;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tutor_id', 'nome', 'especie', 'raca', 'sexo', 'data_nascimento', 'peso_gramas', 'microchip', 'alertas', 'tenant_id'])]
class Pet extends Model
{
    use ComArquivamento, SoftDeletes;

    public const ESPECIES = ['cao', 'gato', 'ave', 'roedor', 'reptil', 'outro'];

    public const SEXOS = ['macho', 'femea'];

    protected function casts(): array
    {
        return [
            'tutor_id' => 'integer',
            'tenant_id' => 'integer',
            'data_nascimento' => 'date',
            'peso_gramas' => 'integer',
            'arquivado_em' => 'datetime',
        ];
    }

    public function tutor(): BelongsTo
    {
        return $this->belongsTo(Tutor::class);
    }

    public function agendamentos(): HasMany
    {
        return $this->hasMany(Agendamento::class);
    }

    public function prontuarios(): HasMany
    {
        return $this->hasMany(Prontuario::class);
    }

    /** Vendas avulsas ligadas ao pet (vendas.pet_id): impedem a exclusão, como no tutor. */
    public function vendas(): HasMany
    {
        return $this->hasMany(Venda::class);
    }
}
