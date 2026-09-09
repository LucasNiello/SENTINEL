<?php

namespace App\Services;

use App\Models\CategoriaLancamento;
use App\Services\Concerns\ExclusaoSegura;
use Illuminate\Support\Collection;

class CategoriaLancamentoService
{
    use ExclusaoSegura;

    public function criar(array $dados): CategoriaLancamento
    {
        return CategoriaLancamento::create([
            'nome' => $dados['nome'],
            'tipo' => $dados['tipo'],
            'tenant_id' => $dados['tenant_id'],
        ]);
    }

    public function buscarPorFiltro(?string $tipo = null): Collection
    {
        return CategoriaLancamento::query()
            ->when($tipo, fn ($query) => $query->where('tipo', $tipo))
            ->get();
    }

    /**
     * @return array{bloqueado: bool, motivo?: string}
     */
    public function excluir(int $id): array
    {
        $categoria = CategoriaLancamento::findOrFail($id);

        return $this->excluirComBloqueio($categoria, ['lancamentos']);
    }

    /**
     * @return array{restaurado: bool, motivo?: string}
     */
    public function restaurarDaLixeira(int $id, bool $reautenticadoComoAdmin): array
    {
        $categoria = CategoriaLancamento::onlyTrashed()->findOrFail($id);

        return $this->restaurar($categoria, $reautenticadoComoAdmin);
    }
}
