<?php

namespace App\Services;

use App\Models\Funcionario;
use App\Services\Concerns\ExclusaoSegura;
use App\Services\Concerns\FiltroPorNome;
use Illuminate\Support\Collection;

class FuncionarioService
{
    use ExclusaoSegura, FiltroPorNome;

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
     * $tenantId restringe a consulta ao tenant informado (obrigatório).
     */
    public function buscarPorFiltro(?string $nome, int $tenantId): Collection
    {
        return Funcionario::query()
            ->where('tenant_id', $tenantId)
            ->when($nome, fn ($query) => $this->nomeContem($query, $nome))
            ->get();
    }

    /**
     * @return array{bloqueado: bool, motivo?: string}
     */
    public function excluir(int $id, int $tenantId): array
    {
        $funcionario = Funcionario::where('tenant_id', $tenantId)->findOrFail($id);

        return $this->excluirComBloqueio($funcionario, ['lancamentos']);
    }

    /**
     * @return array{restaurado: bool, motivo?: string}
     */
    public function restaurarDaLixeira(int $id, int $tenantId, bool $reautenticadoComoAdmin): array
    {
        $funcionario = Funcionario::onlyTrashed()->where('tenant_id', $tenantId)->findOrFail($id);

        return $this->restaurar($funcionario, $reautenticadoComoAdmin);
    }
}
