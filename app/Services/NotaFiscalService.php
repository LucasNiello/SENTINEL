<?php

namespace App\Services;

use App\Models\NotaFiscal;
use App\Services\Concerns\ExclusaoSegura;
use Illuminate\Support\Arr;
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
     * Atualizar pelo agente: acha o registro por id ou por número exato da nota, só no
     * tenant e fora da lixeira (SoftDeletes). Até 6, para o agente saber se ficou ambíguo.
     */
    public function localizar(?int $id, ?string $busca, int $tenantId): Collection
    {
        return NotaFiscal::query()
            ->where('tenant_id', $tenantId)
            ->when($id, fn ($query) => $query->whereKey($id), fn ($query) => $query->where('numero', (string) $busca))
            ->limit(6)
            ->get();
    }

    /**
     * Contrato: atualizar_*. Recebe só dados já validados pelo Form Request; registro de
     * outro tenant ou na lixeira é tratado como inexistente (ModelNotFoundException).
     */
    public function atualizar(int $id, array $dados, int $tenantId): NotaFiscal
    {
        $notaFiscal = NotaFiscal::where('tenant_id', $tenantId)->findOrFail($id);
        $notaFiscal->update(Arr::only($dados, ['numero', 'tipo', 'valor', 'data_emissao', 'cliente_id', 'fornecedor_id', 'lancamento_id']));

        return $notaFiscal;
    }

    /**
     * $tenantId restringe a consulta ao tenant informado (obrigatório).
     */
    public function buscarPorFiltro(?string $tipo, int $tenantId): Collection
    {
        return NotaFiscal::query()
            ->where('tenant_id', $tenantId)
            ->when($tipo, fn ($query) => $query->where('tipo', $tipo))
            ->get();
    }

    /**
     * Notas fiscais não têm dependentes próprios — o bloqueio de exclusão
     * roda pela integridade referencial (restrictOnDelete) das FKs.
     *
     * @return array{bloqueado: bool, motivo?: string}
     */
    public function excluir(int $id, int $tenantId): array
    {
        $notaFiscal = NotaFiscal::where('tenant_id', $tenantId)->findOrFail($id);

        return $this->excluirComBloqueio($notaFiscal, []);
    }

    /**
     * @return array{restaurado: bool, motivo?: string}
     */
    public function restaurarDaLixeira(int $id, int $tenantId, bool $reautenticadoComoAdmin): array
    {
        $notaFiscal = NotaFiscal::onlyTrashed()->where('tenant_id', $tenantId)->findOrFail($id);

        return $this->restaurar($notaFiscal, $reautenticadoComoAdmin);
    }
}
