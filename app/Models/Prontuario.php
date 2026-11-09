<?php

namespace App\Models;

use App\Exceptions\RegraDeNegocioException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;

/**
 * Prontuário clínico. IMUTÁVEL depois de assinado: a guarda abaixo recusa qualquer update pelo
 * Eloquent, com UMA exceção — o vínculo da emergência (agendamento_id de NULL para um agendamento,
 * uma única vez, com quem e quando). Correção do conteúdo só por adendo. Nunca é excluído (guarda
 * mínima de 5 anos) e não tem campo de valor: o que foi feito está em prontuario_itens, sem preço.
 *
 * Texto clínico (CAMPOS_CLINICOS) nunca vai para a IA; quem não é veterinário vê só CAMPOS_METADADOS.
 * Limite conhecido: update/delete em massa (Prontuario::where()->update(), DB::table) passa por fora
 * dos eventos do model; nenhum código do projeto faz isso.
 */
#[Fillable(['pet_id', 'veterinario_id', 'agendamento_id', 'vinculado_por', 'vinculado_em', 'tipo', 'anamnese', 'exame_fisico', 'diagnostico', 'conduta', 'entrada_em', 'alta_em', 'assinado_em', 'tenant_id'])]
class Prontuario extends Model
{
    public const TIPOS = ['consulta', 'retorno', 'vacinacao', 'exame', 'cirurgia', 'internacao', 'emergencia'];

    public const CAMPOS_CLINICOS = ['anamnese', 'exame_fisico', 'diagnostico', 'conduta'];

    /** O que a recepção vê: pet, veterinário, data e tipo (e se já foi assinado/vinculado). */
    public const CAMPOS_METADADOS = ['id', 'pet_id', 'veterinario_id', 'agendamento_id', 'tipo', 'created_at', 'assinado_em'];

    private const CAMPOS_DO_VINCULO = ['agendamento_id', 'vinculado_por', 'vinculado_em'];

    protected static function booted(): void
    {
        static::updating(function (Prontuario $prontuario) {
            if ($prontuario->getOriginal('assinado_em') === null) {
                return;
            }

            $mudou = array_keys(Arr::except($prontuario->getDirty(), ['updated_at']));
            $soVinculo = $prontuario->getOriginal('agendamento_id') === null
                && in_array('agendamento_id', $mudou, true)
                && array_diff($mudou, self::CAMPOS_DO_VINCULO) === [];

            if (! $soVinculo) {
                throw new RegraDeNegocioException('Prontuário assinado não pode ser alterado. Para corrigir, registre um adendo.');
            }
        });

        static::deleting(fn () => throw new RegraDeNegocioException('Prontuário não pode ser excluído: a guarda mínima é de 5 anos.'));
    }

    protected function casts(): array
    {
        return [
            'pet_id' => 'integer',
            'veterinario_id' => 'integer',
            'agendamento_id' => 'integer',
            'vinculado_por' => 'integer',
            'tenant_id' => 'integer',
            'vinculado_em' => 'datetime',
            'entrada_em' => 'datetime',
            'alta_em' => 'datetime',
            'assinado_em' => 'datetime',
        ];
    }

    public function estaAssinado(): bool
    {
        return $this->assinado_em !== null;
    }

    /**
     * Diárias de internação: dias de calendário (America/Sao_Paulo) entre a entrada e a alta,
     * no mínimo 1. Sem entrada ou sem alta: null (não dá para contar).
     */
    public function diarias(): ?int
    {
        if ($this->entrada_em === null || $this->alta_em === null) {
            return null;
        }

        $dias = (int) $this->entrada_em->copy()->startOfDay()->diffInDays($this->alta_em->copy()->startOfDay());

        return max(1, $dias);
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function veterinario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'veterinario_id');
    }

    public function agendamento(): BelongsTo
    {
        return $this->belongsTo(Agendamento::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ProntuarioItem::class);
    }

    public function adendos(): HasMany
    {
        return $this->hasMany(ProntuarioAdendo::class);
    }
}
