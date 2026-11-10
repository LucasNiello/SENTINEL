<?php

namespace App\Services;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Agendamento;
use App\Models\CatalogoItem;
use App\Models\Cobranca;
use App\Models\Pet;
use App\Models\User;
use App\Models\Prontuario;
use App\Services\Concerns\ConfereTenant;
use App\Services\Concerns\FiltroPorNome;
use App\Services\Concerns\InterpretaMomento;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Clínica vet: agenda da clínica e do banho e tosa.
 * - O servidor recusa conflito de horário do mesmo profissional (cancelado e faltou liberam o horário).
 * - encaixe_emergencia registra um atendimento que JÁ aconteceu: não confere conflito, mas só aceita
 *   início até agora (encaixe no futuro é recusado) e só na clínica.
 * - Profissional: clínica = veterinário ou dono; petshop = atendente.
 * Datas no fuso da aplicação (America/Sao_Paulo).
 */
class AgendaService
{
    use ConfereTenant, FiltroPorNome, InterpretaMomento;

    private const PROFISSIONAIS = ['clinica' => ['veterinario', 'dono'], 'petshop' => ['atendente']];

    private const TRANSICOES = [
        'agendado' => ['confirmado', 'realizado', 'cancelado', 'faltou'],
        'confirmado' => ['realizado', 'cancelado', 'faltou'],
    ];

    /** @param  array{pet_id: mixed, profissional_id: mixed, catalogo_item_id: mixed, inicio: mixed, fim: mixed, origem?: string}  $dados */
    public function agendar(array $dados, int $tenantId): Agendamento
    {
        return DB::transaction(function () use ($dados, $tenantId) {
            // Serializa os agendamentos do mesmo profissional até o commit: dois pedidos simultâneos
            // não passam os dois pela conferência de conflito. (O SQLite dos testes ignora FOR UPDATE.)
            User::query()->whereKey($this->idValido($dados['profissional_id'] ?? null) ?? 0)->lockForUpdate()->first();

            ['servico' => $servico, 'inicio' => $inicio, 'fim' => $fim, 'origem' => $origem] = $this->conferir($dados, $tenantId);

            return Agendamento::create([
                'pet_id' => (int) $dados['pet_id'],
                'profissional_id' => (int) $dados['profissional_id'],
                'setor' => $servico->setor,
                'catalogo_item_id' => $servico->id,
                'inicio' => $inicio,
                'fim' => $fim,
                'status' => 'agendado',
                'origem' => $origem,
                'tenant_id' => $tenantId,
            ]);
        });
    }

    /** Só leitura (para o Revisar, antes do segurar): a frase do motivo de recusa, ou null. */
    public function motivoParaNaoAgendar(array $dados, int $tenantId): ?string
    {
        try {
            $this->conferir($dados, $tenantId);

            return null;
        } catch (RegraDeNegocioException $e) {
            return $e->getMessage();
        }
    }

    /** Registro de outro tenant: ModelNotFoundException (inexistente). */
    public function alterarStatus(int $id, string $novoStatus, int $tenantId): Agendamento
    {
        return DB::transaction(function () use ($id, $novoStatus, $tenantId) {
            $agendamento = Agendamento::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);
            $this->conferirStatus($agendamento, $novoStatus);

            $agendamento->update(['status' => $novoStatus]);

            return $agendamento;
        });
    }

    /** Só leitura (para o Revisar, antes do segurar): a frase do motivo de recusa da mudança de status, ou null. */
    public function motivoParaNaoAlterarStatus(int $id, string $novoStatus, int $tenantId): ?string
    {
        $agendamento = Agendamento::where('tenant_id', $tenantId)->find($id);

        if ($agendamento === null) {
            return 'Não encontrei o agendamento #'.$id.'.';
        }

        try {
            $this->conferirStatus($agendamento, $novoStatus);

            return null;
        } catch (RegraDeNegocioException $e) {
            return $e->getMessage();
        }
    }

    /**
     * A agenda do tenant num período (padrão: de hoje até 6 dias depois), com o pet, o tutor, o profissional e o
     * serviço de cada horário. Datas AAAA-MM-DD no fuso da aplicação; até 200, do mais cedo para o mais tarde.
     */
    public function buscarPorFiltro(?string $dataInicio, ?string $dataFim, ?string $setor, ?int $profissionalId, ?int $petId, ?string $status, int $tenantId): Collection
    {
        $fuso = (string) config('app.timezone');
        foreach (['de início' => $dataInicio, 'de fim' => $dataFim] as $qual => $informada) {
            if ($informada !== null && $informada !== '' && $this->dia($informada) === null) {
                throw new RegraDeNegocioException("A data {$qual} da agenda deve estar no formato AAAA-MM-DD (ex.: 2026-12-15).");
            }
        }
        $inicio = $this->dia($dataInicio) ?? CarbonImmutable::now($fuso)->startOfDay();
        $fim = ($this->dia($dataFim) ?? $inicio->addDays(6))->endOfDay();

        return Agendamento::query()
            ->join('pets', 'pets.id', '=', 'agendamentos.pet_id')
            ->join('tutores', 'tutores.id', '=', 'pets.tutor_id')
            ->join('users', 'users.id', '=', 'agendamentos.profissional_id')
            ->join('catalogo_itens', 'catalogo_itens.id', '=', 'agendamentos.catalogo_item_id')
            ->where('agendamentos.tenant_id', $tenantId)
            ->whereBetween('agendamentos.inicio', [$inicio, $fim])
            ->when($setor, fn ($query) => $query->where('agendamentos.setor', $setor))
            ->when($profissionalId, fn ($query) => $query->where('agendamentos.profissional_id', $profissionalId))
            ->when($petId, fn ($query) => $query->where('agendamentos.pet_id', $petId))
            ->when($status, fn ($query) => $query->where('agendamentos.status', $status))
            ->orderBy('agendamentos.inicio')
            ->limit(200)
            ->get(['agendamentos.*', 'pets.nome as pet', 'tutores.nome as tutor', 'users.name as profissional', 'catalogo_itens.nome_comercial as servico']);
    }

    /** Para mudar o status: agendamento ainda em aberto (agendado ou confirmado), por id ou pelo nome do pet. Até 6. */
    public function localizarEmAberto(?int $id, ?string $busca, int $tenantId): Collection
    {
        return Agendamento::query()
            ->join('pets', 'pets.id', '=', 'agendamentos.pet_id')
            ->where('agendamentos.tenant_id', $tenantId)
            ->whereIn('agendamentos.status', array_keys(self::TRANSICOES))
            ->when($id, fn ($query) => $query->where('agendamentos.id', $id), fn ($query) => $this->nomeContem($query, (string) $busca, 'pets.nome'))
            ->orderBy('agendamentos.inicio')
            ->limit(6)
            ->get(['agendamentos.*', 'pets.nome as pet']);
    }

    /** As regras da mudança de status (transição, cobrança viva, prontuário), com leitura travada. */
    private function conferirStatus(Agendamento $agendamento, string $novoStatus): void
    {
        if (! in_array($novoStatus, self::TRANSICOES[$agendamento->status] ?? [], true)) {
            throw new RegraDeNegocioException("Um agendamento {$agendamento->status} não pode passar para {$novoStatus}.");
        }
        if (in_array($novoStatus, Agendamento::STATUS_LIVRES, true)) {
            // Defesa: com cobrança viva (não cancelada), o agendamento não é desfeito. Paga sai por estorno.
            if ($this->existeTravado(Cobranca::where('agendamento_id', $agendamento->id)->where('status', '!=', 'cancelada'))) {
                throw new RegraDeNegocioException("Este agendamento tem cobrança: cancele ou estorne a cobrança antes de marcá-lo como {$novoStatus}.");
            }
            // O atendimento já foi registrado: cancelado ou faltou seria um estado final que impede a cobrança para sempre.
            if ($this->existeTravado(Prontuario::where('agendamento_id', $agendamento->id))) {
                throw new RegraDeNegocioException("Este agendamento já tem prontuário: o atendimento aconteceu e não pode ser marcado como {$novoStatus}.");
            }
        }
    }

    /** AAAA-MM-DD estrito, no fuso da aplicação, ou null. */
    private function dia(?string $valor): ?CarbonImmutable
    {
        if ($valor === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) !== 1) {
            return null;
        }
        $dia = CarbonImmutable::createFromFormat('!Y-m-d', $valor, (string) config('app.timezone'));

        return $dia !== false && $dia->format('Y-m-d') === $valor ? $dia : null;
    }

    /** @return array{servico: CatalogoItem, inicio: CarbonImmutable, fim: CarbonImmutable, origem: string} */
    private function conferir(array $dados, int $tenantId): array
    {
        $origem = $dados['origem'] ?? 'normal';
        if (! in_array($origem, Agendamento::ORIGENS, true)) {
            throw new RegraDeNegocioException('Origem do agendamento inválida.');
        }

        $this->doTenant(Pet::class, $dados['pet_id'] ?? null, $tenantId, 'o pet');
        $servico = $this->doTenant(CatalogoItem::class, $dados['catalogo_item_id'] ?? null, $tenantId, 'o serviço');

        if ($servico->tipo !== 'servico') {
            throw new RegraDeNegocioException("{$servico->nome_comercial} é um produto: agende um serviço.");
        }

        $this->usuarioDoTenant($dados['profissional_id'] ?? null, $tenantId, self::PROFISSIONAIS[$servico->setor], 'o profissional',
            $servico->setor === 'clinica'
                ? 'Na clínica, o profissional precisa ser um veterinário.'
                : 'No banho e tosa, o profissional precisa ser um atendente.');

        $inicio = $this->momento($dados['inicio'] ?? null);
        $fim = $this->momento($dados['fim'] ?? null);

        if ($inicio === null || $fim === null) {
            throw new RegraDeNegocioException('Informe o início e o fim com data e hora.');
        }
        if ($fim->lte($inicio)) {
            throw new RegraDeNegocioException('O fim precisa ser depois do início.');
        }

        // Limite de duração vem da configuração (sentinel.agenda_duracao_maxima_minutos); sem valor válido, recusa (falha fechada).
        $maximo = max(0, (int) config('sentinel.agenda_duracao_maxima_minutos'));
        if ($inicio->diffInMinutes($fim) > $maximo) {
            throw new RegraDeNegocioException('Um agendamento dura no máximo '.$this->duracaoPorExtenso($maximo).': ajuste o fim.');
        }

        if ($origem === 'encaixe_emergencia') {
            if ($servico->setor !== 'clinica') {
                throw new RegraDeNegocioException('Encaixe de emergência é só da clínica.');
            }
            if ($inicio->gt(now())) {
                throw new RegraDeNegocioException('Encaixe de emergência registra um atendimento que já começou: o início não pode estar no futuro.');
            }
        } elseif ($inicio->lt(CarbonImmutable::now($inicio->getTimezone())->startOfMinute())) {
            // Atendimento que já aconteceu só entra como encaixe de emergência; agendar é para daqui em diante.
            throw new RegraDeNegocioException('Não dá para agendar no passado: o início precisa ser agora ou depois.');
        } elseif ($this->temConflito((int) $dados['profissional_id'], $inicio, $fim, $tenantId)) {
            throw new RegraDeNegocioException('O profissional já tem um agendamento nesse horário.');
        }

        return ['servico' => $servico, 'inicio' => $inicio, 'fim' => $fim, 'origem' => $origem];
    }

    /** Sobreposição de intervalos [início, fim): encostar (fim de um = início do outro) não é conflito. */
    private function temConflito(int $profissionalId, CarbonInterface $inicio, CarbonInterface $fim, int $tenantId): bool
    {
        // Leitura TRAVADA: dentro da transação do executor, uma leitura comum veria a foto de antes
        // (InnoDB, REPEATABLE READ) e não enxergaria o agendamento que acabou de ser gravado por outro pedido.
        return $this->existeTravado(Agendamento::query()
            ->where('tenant_id', $tenantId)
            ->where('profissional_id', $profissionalId)
            ->whereNotIn('status', Agendamento::STATUS_LIVRES)
            ->where('inicio', '<', $fim)
            ->where('fim', '>', $inicio));
    }

    /** "8 h" ou "45 min": a duração máxima como aparece na frase de recusa. */
    private function duracaoPorExtenso(int $minutos): string
    {
        return $minutos % 60 === 0 ? ($minutos / 60).' h' : $minutos.' min';
    }
}
