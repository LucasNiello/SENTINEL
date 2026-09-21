<?php

namespace App\Services;

use App\Models\Fornecedor;
use App\Services\Concerns\ExclusaoSegura;
use Illuminate\Support\Collection;

class FornecedorService
{
    use ExclusaoSegura;

    public function criar(array $dados): Fornecedor
    {
        return Fornecedor::create([
            'nome' => $dados['nome'],
            'documento' => $dados['documento'] ?? null,
            'email' => $dados['email'] ?? null,
            'telefone' => $dados['telefone'] ?? null,
            'tenant_id' => $dados['tenant_id'],
        ]);
    }

    /**
     * $tenantId restringe a consulta ao tenant informado (null = sem filtro).
     */
    public function buscarPorFiltro(?string $nome = null, ?int $tenantId = null): Collection
    {
        return Fornecedor::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($nome, fn ($query) => $query->where('nome', 'like', "%{$nome}%"))
            ->get();
    }

    /**
     * @return array{bloqueado: bool, motivo?: string}
     */
    public function excluir(int $id): array
    {
        $fornecedor = Fornecedor::findOrFail($id);

        return $this->excluirComBloqueio($fornecedor, ['lancamentos', 'notasFiscais']);
    }

    /**
     * @return array{restaurado: bool, motivo?: string}
     */
    public function restaurarDaLixeira(int $id, bool $reautenticadoComoAdmin): array
    {
        $fornecedor = Fornecedor::onlyTrashed()->findOrFail($id);

        return $this->restaurar($fornecedor, $reautenticadoComoAdmin);
    }
}
