<?php

namespace App\Services;

use App\Models\Lancamento;
use App\Services\Concerns\ExclusaoSegura;
use Illuminate\Support\Collection;

class LancamentoService
{
    use ExclusaoSegura;

    /**
     * Contrato: criar_lancamento.
     *
     * Lançamento sempre nasce como "pendente" — conciliação é uma ação
     * separada (marcarConciliado).
     */
    public function criar(array $dados): Lancamento
    {
        return Lancamento::create([
            'descricao' => $dados['descricao'],
            'valor' => $dados['valor'],
            'data' => $dados['data'],
            'tenant_id' => $dados['tenant_id'],
            'status' => 'pendente',
        ]);
    }

    /**
     * Contrato: buscar_lancamentos.
     *
     * $tenantId restringe a consulta ao tenant informado (null = sem filtro).
     */
    public function buscarPorFiltro(?string $status = null, ?string $dataInicio = null, ?string $dataFim = null, ?int $tenantId = null): Collection
    {
        return Lancamento::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($dataInicio, fn ($query) => $query->whereDate('data', '>=', $dataInicio))
            ->when($dataFim, fn ($query) => $query->whereDate('data', '<=', $dataFim))
            ->get();
    }

    /**
     * Contrato: marcar_conciliado.
     *
     * Pré-requisito: só altera lançamentos cujo status atual é "pendente".
     * IDs inexistentes ou que não atendem ao pré-requisito são reportados
     * em "ignorados" (erro parcial), nunca alterados silenciosamente.
     *
     * $tenantId restringe a alteração ao tenant informado (null = sem filtro);
     * lançamento de outro tenant cai em "ignorados", como se não existisse.
     *
     * @param  int[]  $ids
     * @return array{atualizados: int[], ignorados: int[]}
     */
    public function marcarConciliado(array $ids, ?int $tenantId = null): array
    {
        return $this->alterarStatus($ids, de: 'pendente', para: 'conciliado', tenantId: $tenantId);
    }

    /**
     * Contrato: desmarcar_conciliado.
     *
     * Pré-requisito: só altera lançamentos cujo status atual é "conciliado".
     * Mesma política de erro parcial de marcarConciliado().
     *
     * @param  int[]  $ids
     * @return array{atualizados: int[], ignorados: int[]}
     */
    public function desmarcarConciliado(array $ids, ?int $tenantId = null): array
    {
        return $this->alterarStatus($ids, de: 'conciliado', para: 'pendente', tenantId: $tenantId);
    }

    /**
     * Contrato: atualizar_status_lancamento.
     *
     * Reaproveita marcarConciliado/desmarcarConciliado (mesmo pré-requisito de
     * status atual e mesma política de "ignorados") e, quando não atualiza,
     * devolve o motivo em linguagem simples — sem termo técnico.
     *
     * @return array{atualizado: bool, lancamento?: Lancamento, motivo?: string}
     */
    public function atualizarStatus(int $id, string $novoStatus, ?int $tenantId = null): array
    {
        $resultado = match ($novoStatus) {
            'conciliado' => $this->marcarConciliado([$id], $tenantId),
            'pendente' => $this->desmarcarConciliado([$id], $tenantId),
            default => null,
        };

        if ($resultado === null) {
            return ['atualizado' => false, 'motivo' => 'Status inválido: use "pendente" ou "conciliado".'];
        }

        $lancamento = Lancamento::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->find($id);

        if ($resultado['atualizados'] !== []) {
            return ['atualizado' => true, 'lancamento' => $lancamento];
        }

        // Ignorado: ou não existe (para este tenant), ou já está no status pedido.
        return [
            'atualizado' => false,
            'motivo' => $lancamento === null
                ? "Não encontrei o lançamento #{$id}."
                : "O lançamento #{$id} já está {$novoStatus}.",
        ];
    }

    /**
     * @param  int[]  $ids
     * @return array{atualizados: int[], ignorados: int[]}
     */
    private function alterarStatus(array $ids, string $de, string $para, ?int $tenantId = null): array
    {
        $lancamentos = Lancamento::query()
            ->whereIn('id', $ids)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->get()
            ->keyBy('id');

        $atualizados = [];
        $ignorados = [];

        foreach ($ids as $id) {
            $lancamento = $lancamentos->get($id);

            if (! $lancamento || $lancamento->status !== $de) {
                $ignorados[] = $id;

                continue;
            }

            $lancamento->update(['status' => $para]);
            $atualizados[] = $id;
        }

        return [
            'atualizados' => $atualizados,
            'ignorados' => $ignorados,
        ];
    }

    /**
     * @return array{bloqueado: bool, motivo?: string}
     */
    public function excluir(int $id): array
    {
        $lancamento = Lancamento::findOrFail($id);

        return $this->excluirComBloqueio($lancamento, ['notasFiscais']);
    }

    /**
     * @return array{restaurado: bool, motivo?: string}
     */
    public function restaurarDaLixeira(int $id, bool $reautenticadoComoAdmin): array
    {
        $lancamento = Lancamento::onlyTrashed()->findOrFail($id);

        return $this->restaurar($lancamento, $reautenticadoComoAdmin);
    }
}
