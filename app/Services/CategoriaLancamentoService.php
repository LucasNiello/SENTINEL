<?php

namespace App\Services;

use App\Models\CategoriaLancamento;
use App\Services\Concerns\ExclusaoSegura;
use App\Services\Concerns\FiltroPorNome;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class CategoriaLancamentoService
{
    use ExclusaoSegura, FiltroPorNome;

    public function criar(array $dados): CategoriaLancamento
    {
        return CategoriaLancamento::create([
            'nome' => $dados['nome'],
            'tipo' => $dados['tipo'],
            'tenant_id' => $dados['tenant_id'],
        ]);
    }

    /**
     * Atualizar pelo agente: acha o registro por id ou por parte do nome, só no
     * tenant e fora da lixeira (SoftDeletes). Até 6, para o agente saber se ficou ambíguo.
     */
    public function localizar(?int $id, ?string $busca, int $tenantId): Collection
    {
        return CategoriaLancamento::query()
            ->where('tenant_id', $tenantId)
            ->when($id, fn ($query) => $query->whereKey($id), fn ($query) => $this->nomeContem($query, (string) $busca))
            ->limit(6)
            ->get();
    }

    /**
     * Contrato: atualizar_*. Recebe só dados já validados pelo Form Request; registro de
     * outro tenant ou na lixeira é tratado como inexistente (ModelNotFoundException).
     */
    public function atualizar(int $id, array $dados, int $tenantId): CategoriaLancamento
    {
        $categoria = CategoriaLancamento::where('tenant_id', $tenantId)->findOrFail($id);
        $categoria->update(Arr::only($dados, ['nome', 'tipo']));

        return $categoria;
    }

    /**
     * $tenantId restringe a consulta ao tenant informado (obrigatório).
     */
    public function buscarPorFiltro(?string $tipo, int $tenantId): Collection
    {
        return CategoriaLancamento::query()
            ->where('tenant_id', $tenantId)
            ->when($tipo, fn ($query) => $query->where('tipo', $tipo))
            ->get();
    }

    /**
     * @return array{bloqueado: bool, motivo?: string}
     */
    public function excluir(int $id, int $tenantId): array
    {
        $categoria = CategoriaLancamento::where('tenant_id', $tenantId)->findOrFail($id);

        return $this->excluirComBloqueio($categoria, ['lancamentos']);
    }

    /**
     * @return array{restaurado: bool, motivo?: string}
     */
    public function restaurarDaLixeira(int $id, int $tenantId, bool $reautenticadoComoAdmin): array
    {
        $categoria = CategoriaLancamento::onlyTrashed()->where('tenant_id', $tenantId)->findOrFail($id);

        return $this->restaurar($categoria, $reautenticadoComoAdmin);
    }
}
