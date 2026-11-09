<?php

namespace App\Services;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Agendamento;
use App\Models\CatalogoItem;
use App\Models\Pet;
use App\Models\Prontuario;
use App\Models\ProntuarioAdendo;
use App\Models\ProntuarioItem;
use App\Models\User;
use App\Services\Concerns\ConfereTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Clínica vet: prontuário. Regras:
 * - quem cria é um veterinário (ou o dono) do tenant; só o autor edita e assina os próprios;
 * - assinar exige CRMV; internação exige entrada e alta;
 * - depois de assinado, nada muda (guarda no model): correção só por adendo, que qualquer veterinário
 *   ou dono com CRMV pode registrar;
 * - a emergência sem agendamento é vinculada depois a um encaixe_emergencia do mesmo pet e do mesmo
 *   veterinário, uma única vez, guardando quem vinculou e quando; quem vincula (recepção) só recebe
 *   os metadados de volta.
 * Este service nunca toca em dinheiro: a cobrança puxa os itens (CobrancaService).
 */
class ProntuarioService
{
    use ConfereTenant;

    private const PAPEIS_CLINICOS = ['veterinario', 'dono'];

    /** Quem pode vincular a emergência ao agendamento (regra do dado; o RBAC da tool vem na Fase B). */
    private const PAPEIS_VINCULO = ['recepcao', 'veterinario', 'dono'];

    private const CAMPOS_EDITAVEIS = ['tipo', 'anamnese', 'exame_fisico', 'diagnostico', 'conduta', 'entrada_em', 'alta_em'];

    /** @param  array{pet_id: mixed, tipo: string, agendamento_id?: mixed}  $dados  e, opcionais, os CAMPOS_EDITAVEIS */
    public function abrir(array $dados, int $veterinarioId, int $tenantId): Prontuario
    {
        return DB::transaction(function () use ($dados, $veterinarioId, $tenantId) {
            $this->veterinario($veterinarioId, $tenantId);
            $pet = $this->doTenant(Pet::class, $dados['pet_id'] ?? null, $tenantId, 'o pet');
            $campos = $this->camposClinicos($dados, null);

            $agendamentoId = null;
            if (($dados['agendamento_id'] ?? null) !== null) {
                $agendamentoId = $this->agendamentoParaVincular($dados['agendamento_id'], $pet->id, $veterinarioId, $tenantId, null)->id;
            }

            return Prontuario::create([
                ...$campos,
                'pet_id' => $pet->id,
                'veterinario_id' => $veterinarioId,
                'agendamento_id' => $agendamentoId,
                'tenant_id' => $tenantId,
            ]);
        });
    }

    /** Rascunho (não assinado), só pelo autor. Registro de outro tenant: ModelNotFoundException. */
    public function atualizar(int $id, array $dados, int $veterinarioId, int $tenantId): Prontuario
    {
        return DB::transaction(function () use ($id, $dados, $veterinarioId, $tenantId) {
            $prontuario = $this->rascunhoDoAutor($id, $veterinarioId, $tenantId);
            $prontuario->update($this->camposClinicos($dados, $prontuario));

            return $prontuario;
        });
    }

    /** O que foi realizado (sem preço). Só item da clínica, só no rascunho, só pelo autor. */
    public function adicionarItem(int $prontuarioId, int $catalogoItemId, int $quantidade, int $veterinarioId, int $tenantId): ProntuarioItem
    {
        return DB::transaction(function () use ($prontuarioId, $catalogoItemId, $quantidade, $veterinarioId, $tenantId) {
            $prontuario = $this->rascunhoDoAutor($prontuarioId, $veterinarioId, $tenantId);
            $item = $this->doTenant(CatalogoItem::class, $catalogoItemId, $tenantId, 'o item do catálogo');

            if ($item->setor !== 'clinica') {
                throw new RegraDeNegocioException("{$item->nome_comercial} é do petshop: no prontuário entram só itens da clínica.");
            }

            return ProntuarioItem::create([
                'prontuario_id' => $prontuario->id,
                'catalogo_item_id' => $item->id,
                'quantidade' => $this->quantidadeValida($quantidade),
                'tenant_id' => $tenantId,
            ]);
        });
    }

    public function removerItem(int $prontuarioItemId, int $veterinarioId, int $tenantId): void
    {
        DB::transaction(function () use ($prontuarioItemId, $veterinarioId, $tenantId) {
            $item = ProntuarioItem::where('tenant_id', $tenantId)->findOrFail($prontuarioItemId);
            $this->rascunhoDoAutor($item->prontuario_id, $veterinarioId, $tenantId);
            $item->delete();
        });
    }

    public function assinar(int $id, int $veterinarioId, int $tenantId): Prontuario
    {
        return DB::transaction(function () use ($id, $veterinarioId, $tenantId) {
            $prontuario = $this->rascunhoDoAutor($id, $veterinarioId, $tenantId);
            $this->exigirCrmv($this->veterinario($veterinarioId, $tenantId), 'assinar o prontuário');

            if ($prontuario->tipo === 'internacao' && ($prontuario->entrada_em === null || $prontuario->alta_em === null)) {
                throw new RegraDeNegocioException('Internação só é assinada com a entrada e a alta informadas.');
            }

            $prontuario->update(['assinado_em' => now()]);

            return $prontuario;
        });
    }

    /** Correção de prontuário ASSINADO. Nasce assinado e não muda. */
    public function adendar(int $id, string $texto, int $autorId, int $tenantId): ProntuarioAdendo
    {
        return DB::transaction(function () use ($id, $texto, $autorId, $tenantId) {
            $prontuario = Prontuario::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);
            $this->exigirCrmv($this->veterinario($autorId, $tenantId), 'registrar um adendo');

            if (! $prontuario->estaAssinado()) {
                throw new RegraDeNegocioException('O prontuário ainda não foi assinado: corrija o próprio prontuário em vez de registrar um adendo.');
            }
            if (trim($texto) === '') {
                throw new RegraDeNegocioException('Escreva o texto do adendo.');
            }

            return ProntuarioAdendo::create([
                'prontuario_id' => $prontuario->id,
                'autor_id' => $autorId,
                'texto' => $texto,
                'assinado_em' => now(),
                'tenant_id' => $tenantId,
            ]);
        });
    }

    /**
     * Vínculo da emergência: agendamento_id de NULL para um encaixe_emergencia do mesmo pet e do mesmo
     * veterinário, uma única vez, com quem vinculou e quando. Vale também para prontuário assinado (é a
     * única mudança aceita depois da assinatura). Devolve só os metadados: quem vincula é, em geral, a
     * recepção, que não vê o conteúdo clínico.
     */
    public function vincular(int $id, int $agendamentoId, int $usuarioId, int $tenantId): Prontuario
    {
        return DB::transaction(function () use ($id, $agendamentoId, $usuarioId, $tenantId) {
            $prontuario = Prontuario::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);
            $this->usuarioDoTenant($usuarioId, $tenantId, self::PAPEIS_VINCULO, 'o usuário',
                'Só a recepção ou um veterinário pode vincular o atendimento ao agendamento.');

            if ($prontuario->agendamento_id !== null) {
                throw new RegraDeNegocioException('Este prontuário já está vinculado a um agendamento: o vínculo é feito uma única vez.');
            }

            $agendamento = $this->agendamentoParaVincular($agendamentoId, $prontuario->pet_id, $prontuario->veterinario_id, $tenantId, 'encaixe_emergencia');

            $prontuario->update(['agendamento_id' => $agendamento->id, 'vinculado_por' => $usuarioId, 'vinculado_em' => now()]);

            return Prontuario::query()->select([...Prontuario::CAMPOS_METADADOS, 'vinculado_por', 'vinculado_em'])->find($prontuario->id);
        });
    }

    /** O que a recepção vê: pet, veterinário, data e tipo. Nenhum campo clínico sai daqui. */
    public function metadados(int $tenantId, ?int $petId = null): Collection
    {
        return Prontuario::query()
            ->select(Prontuario::CAMPOS_METADADOS)
            ->where('tenant_id', $tenantId)
            ->when($petId, fn ($query) => $query->where('pet_id', $petId))
            ->orderByDesc('created_at')
            ->get();
    }

    private function rascunhoDoAutor(int $id, int $veterinarioId, int $tenantId): Prontuario
    {
        $prontuario = Prontuario::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);

        if ($prontuario->veterinario_id !== $veterinarioId) {
            throw new RegraDeNegocioException('Só o veterinário responsável pode alterar ou assinar este prontuário.');
        }
        if ($prontuario->estaAssinado()) {
            throw new RegraDeNegocioException('Prontuário assinado não pode ser alterado. Para corrigir, registre um adendo.');
        }

        return $prontuario;
    }

    private function veterinario(int $id, int $tenantId): User
    {
        return $this->usuarioDoTenant($id, $tenantId, self::PAPEIS_CLINICOS, 'o veterinário', 'Só um veterinário pode registrar o prontuário.');
    }

    private function exigirCrmv(User $usuario, string $acao): void
    {
        if (trim((string) $usuario->crmv) === '') {
            throw new RegraDeNegocioException("Para {$acao}, o veterinário precisa ter o CRMV cadastrado.");
        }
    }

    /** Agendamento da clínica, do tenant, do mesmo pet e do mesmo veterinário, ativo e ainda sem prontuário. */
    private function agendamentoParaVincular(mixed $id, int $petId, int $veterinarioId, int $tenantId, ?string $origemExigida): Agendamento
    {
        $agendamento = $this->doTenant(Agendamento::class, $id, $tenantId, 'o agendamento');

        if ($origemExigida !== null && $agendamento->origem !== $origemExigida) {
            throw new RegraDeNegocioException('Atendimento de emergência só se vincula a um agendamento de encaixe de emergência.');
        }
        if ($agendamento->setor !== 'clinica') {
            throw new RegraDeNegocioException('O agendamento precisa ser da clínica.');
        }
        if ($agendamento->pet_id !== $petId) {
            throw new RegraDeNegocioException('O agendamento é de outro pet.');
        }
        if ($agendamento->profissional_id !== $veterinarioId) {
            throw new RegraDeNegocioException('O agendamento é de outro veterinário.');
        }
        if (in_array($agendamento->status, Agendamento::STATUS_LIVRES, true)) {
            throw new RegraDeNegocioException("O agendamento está {$agendamento->status}.");
        }
        if (Prontuario::where('agendamento_id', $agendamento->id)->exists()) {
            throw new RegraDeNegocioException('Este agendamento já tem um prontuário.');
        }

        return $agendamento;
    }

    /** @return array<string, mixed> */
    private function camposClinicos(array $dados, ?Prontuario $atual): array
    {
        $campos = Arr::only($dados, self::CAMPOS_EDITAVEIS);
        $tipo = $campos['tipo'] ?? $atual?->tipo;

        if (! in_array($tipo, Prontuario::TIPOS, true)) {
            throw new RegraDeNegocioException('Informe o tipo do atendimento: '.implode(', ', Prontuario::TIPOS).'.');
        }

        foreach (['entrada_em', 'alta_em'] as $campo) {
            if (array_key_exists($campo, $campos) && $campos[$campo] !== null) {
                $campos[$campo] = $this->momento($campos[$campo])
                    ?? throw new RegraDeNegocioException('Informe a entrada e a alta com data e hora.');
            }
        }

        $entrada = array_key_exists('entrada_em', $campos) ? $campos['entrada_em'] : $atual?->entrada_em;
        $alta = array_key_exists('alta_em', $campos) ? $campos['alta_em'] : $atual?->alta_em;
        if ($entrada !== null && $alta !== null && $alta->lt($entrada)) {
            throw new RegraDeNegocioException('A alta não pode ser antes da entrada.');
        }

        return $campos;
    }

    private function momento(mixed $valor): ?CarbonImmutable
    {
        if ($valor instanceof CarbonInterface) {
            return CarbonImmutable::instance($valor)->setTimezone((string) config('app.timezone'));
        }

        try {
            return is_string($valor) && trim($valor) !== '' ? CarbonImmutable::parse($valor, (string) config('app.timezone')) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
