<?php

namespace App\Services;

use App\Models\Funcionario;
use App\Services\Concerns\ExclusaoSegura;
use App\Services\Concerns\FiltroPorNome;
use Illuminate\Support\Arr;
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
     * Atualizar pelo agente: acha o registro por id ou por parte do nome, só no
     * tenant e fora da lixeira (SoftDeletes). Até 6, para o agente saber se ficou ambíguo.
     */
    public function localizar(?int $id, ?string $busca, int $tenantId): Collection
    {
        return Funcionario::query()
            ->where('tenant_id', $tenantId)
            ->when($id, fn ($query) => $query->whereKey($id), fn ($query) => $this->nomeContem($query, (string) $busca))
            ->limit(6)
            ->get();
    }

    /**
     * Contrato: atualizar_*. Recebe só dados já validados pelo Form Request; registro de
     * outro tenant ou na lixeira é tratado como inexistente (ModelNotFoundException).
     */
    public function atualizar(int $id, array $dados, int $tenantId): Funcionario
    {
        $funcionario = Funcionario::where('tenant_id', $tenantId)->findOrFail($id);
        $funcionario->update(Arr::only($dados, ['nome', 'cpf', 'cargo', 'salario', 'data_admissao', 'data_demissao']));

        return $funcionario;
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
