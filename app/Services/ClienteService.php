<?php

namespace App\Services;

use App\Models\Cliente;
use App\Services\Concerns\ExclusaoSegura;
use Illuminate\Support\Collection;

class ClienteService
{
    use ExclusaoSegura;

    public function criar(array $dados): Cliente
    {
        return Cliente::create([
            'nome' => $dados['nome'],
            'documento' => $dados['documento'] ?? null,
            'email' => $dados['email'] ?? null,
            'telefone' => $dados['telefone'] ?? null,
            'tenant_id' => $dados['tenant_id'],
        ]);
    }

    public function buscarPorFiltro(?string $nome = null): Collection
    {
        return Cliente::query()
            ->when($nome, fn ($query) => $query->where('nome', 'like', "%{$nome}%"))
            ->get();
    }

    /**
     * @return array{bloqueado: bool, motivo?: string}
     */
    public function excluir(int $id): array
    {
        $cliente = Cliente::findOrFail($id);

        return $this->excluirComBloqueio($cliente, ['lancamentos', 'notasFiscais']);
    }

    /**
     * @return array{restaurado: bool, motivo?: string}
     */
    public function restaurarDaLixeira(int $id, bool $reautenticadoComoAdmin): array
    {
        $cliente = Cliente::onlyTrashed()->findOrFail($id);

        return $this->restaurar($cliente, $reautenticadoComoAdmin);
    }
}
