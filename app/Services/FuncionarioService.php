<?php

namespace App\Services;

use App\Models\Funcionario;
use App\Services\Concerns\ExclusaoSegura;
use Illuminate\Support\Collection;

class FuncionarioService
{
    use ExclusaoSegura;

    public function criar(array $dados): Funcionario
    {
        return Funcionario::create([
            'nome' => $dados['nome'],
            'cpf' => $dados['cpf'],
            'cargo' => $dados['cargo'] ?? null,
            'salario' => $dados['salario'],
            'data_admissao' => $dados['data_admissao'],
            'data_demissao' => $dados['data_demissao'] ?? null,
            'tenant_id' => $dados['tenant_id'],
        ]);
    }

    /**
     * $tenantId restringe a consulta ao tenant informado (null = sem filtro).
     */
    public function buscarPorFiltro(?string $nome = null, ?int $tenantId = null): Collection
    {
        return Funcionario::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($nome, fn ($query) => $query->where('nome', 'like', "%{$nome}%"))
            ->get();
    }

    /**
     * @return array{bloqueado: bool, motivo?: string}
     */
    public function excluir(int $id): array
    {
        $funcionario = Funcionario::findOrFail($id);

        return $this->excluirComBloqueio($funcionario, ['lancamentos']);
    }

    /**
     * @return array{restaurado: bool, motivo?: string}
     */
    public function restaurarDaLixeira(int $id, bool $reautenticadoComoAdmin): array
    {
        $funcionario = Funcionario::onlyTrashed()->findOrFail($id);

        return $this->restaurar($funcionario, $reautenticadoComoAdmin);
    }
}
