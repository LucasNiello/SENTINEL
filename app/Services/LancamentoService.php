<?php

namespace App\Services;

use App\Models\Lancamento;
use Illuminate\Support\Collection;

class LancamentoService
{
    /**
     * Contrato: buscar_lancamentos.
     */
    public function buscarPorFiltro(?string $status = null, ?string $dataInicio = null, ?string $dataFim = null): Collection
    {
        return Lancamento::query()
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
     * @param  int[]  $ids
     * @return array{atualizados: int[], ignorados: int[]}
     */
    public function marcarConciliado(array $ids): array
    {
        return $this->alterarStatus($ids, de: 'pendente', para: 'conciliado');
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
    public function desmarcarConciliado(array $ids): array
    {
        return $this->alterarStatus($ids, de: 'conciliado', para: 'pendente');
    }

    /**
     * @param  int[]  $ids
     * @return array{atualizados: int[], ignorados: int[]}
     */
    private function alterarStatus(array $ids, string $de, string $para): array
    {
        $lancamentos = Lancamento::query()->whereIn('id', $ids)->get()->keyBy('id');

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
}
