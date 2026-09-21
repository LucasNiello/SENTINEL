<?php

namespace App\Services;

use App\Models\NotaFiscal;
use App\Services\Concerns\ExclusaoSegura;
use Illuminate\Support\Collection;

class NotaFiscalService
{
    use ExclusaoSegura;

    public function criar(array $dados): NotaFiscal
    {
        return NotaFiscal::create([
            'numero' => $dados['numero'],
            'tipo' => $dados['tipo'],
            'valor' => $dados['valor'],
            'data_emissao' => $dados['data_emissao'],
            'cliente_id' => $dados['cliente_id'] ?? null,
            'fornecedor_id' => $dados['fornecedor_id'] ?? null,
            'lancamento_id' => $dados['lancamento_id'] ?? null,
            'tenant_id' => $dados['tenant_id'],
        ]);
    }

    /**
     * $tenantId restringe a consulta ao tenant informado (null = sem filtro).
     */
    public function buscarPorFiltro(?string $tipo = null, ?int $tenantId = null): Collection
    {
        return NotaFiscal::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($tipo, fn ($query) => $query->where('tipo', $tipo))
            ->get();
    }

    /**
     * Notas fiscais não têm dependentes próprios — o bloqueio de exclusão
     * roda pela integridade referencial (restrictOnDelete) das FKs.
     *
     * @return array{bloqueado: bool, motivo?: string}
     */
    public function excluir(int $id): array
    {
        $notaFiscal = NotaFiscal::findOrFail($id);

        return $this->excluirComBloqueio($notaFiscal, []);
    }

    /**
     * @return array{restaurado: bool, motivo?: string}
     */
    public function restaurarDaLixeira(int $id, bool $reautenticadoComoAdmin): array
    {
        $notaFiscal = NotaFiscal::onlyTrashed()->findOrFail($id);

        return $this->restaurar($notaFiscal, $reautenticadoComoAdmin);
    }
}
