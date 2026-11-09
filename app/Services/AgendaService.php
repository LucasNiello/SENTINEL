<?php

namespace App\Services;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Agendamento;
use App\Models\CatalogoItem;
use App\Models\Cobranca;
use App\Models\Pet;
use App\Models\User;
use App\Services\Concerns\ConfereTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

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
    use ConfereTenant;

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

            if (! in_array($novoStatus, self::TRANSICOES[$agendamento->status] ?? [], true)) {
                throw new RegraDeNegocioException("Um agendamento {$agendamento->status} não pode passar para {$novoStatus}.");
            }
            // Defesa: com cobrança viva (não cancelada), o agendamento não é desfeito. Paga sai por estorno.
            if (in_array($novoStatus, Agendamento::STATUS_LIVRES, true)
                && Cobranca::where('agendamento_id', $agendamento->id)->where('status', '!=', 'cancelada')->exists()) {
                throw new RegraDeNegocioException("Este agendamento tem cobrança: cancele ou estorne a cobrança antes de marcá-lo como {$novoStatus}.");
            }

            $agendamento->update(['status' => $novoStatus]);

            return $agendamento;
        });
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

        if ($origem === 'encaixe_emergencia') {
            if ($servico->setor !== 'clinica') {
                throw new RegraDeNegocioException('Encaixe de emergência é só da clínica.');
            }
            if ($inicio->gt(now())) {
                throw new RegraDeNegocioException('Encaixe de emergência registra um atendimento que já começou: o início não pode estar no futuro.');
            }
        } elseif ($this->temConflito((int) $dados['profissional_id'], $inicio, $fim, $tenantId)) {
            throw new RegraDeNegocioException('O profissional já tem um agendamento nesse horário.');
        }

        return ['servico' => $servico, 'inicio' => $inicio, 'fim' => $fim, 'origem' => $origem];
    }

    /** Sobreposição de intervalos [início, fim): encostar (fim de um = início do outro) não é conflito. */
    private function temConflito(int $profissionalId, CarbonInterface $inicio, CarbonInterface $fim, int $tenantId): bool
    {
        return Agendamento::query()
            ->where('tenant_id', $tenantId)
            ->where('profissional_id', $profissionalId)
            ->whereNotIn('status', Agendamento::STATUS_LIVRES)
            ->where('inicio', '<', $fim)
            ->where('fim', '>', $inicio)
            ->exists();
    }

    private function momento(mixed $valor): ?CarbonImmutable
    {
        $fuso = (string) config('app.timezone');

        if ($valor instanceof CarbonInterface) {
            return CarbonImmutable::instance($valor)->setTimezone($fuso);
        }

        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($valor, $fuso);
        } catch (Throwable) {
            return null;
        }
    }
}
