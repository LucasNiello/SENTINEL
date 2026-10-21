<?php

namespace App\Services;

use App\Models\Cliente;
use App\Services\Concerns\ExclusaoSegura;
use App\Services\Concerns\FiltroPorNome;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class ClienteService
{
    use ExclusaoSegura, FiltroPorNome;

    /** Lixeira (RF08/RF12): relações cujos registros impedem excluir — e, contando lixeira e arquivados, excluir de vez. */
    private const DEPENDENTES = ['lancamentos', 'notasFiscais'];

    /** Relações que precisam estar ativas para o registro voltar da lixeira (pai na lixeira: recusa, dizendo qual). */
    private const PAIS = [];

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

    /**
     * Atualizar pelo agente: acha o registro por id ou por parte do nome, só no
     * tenant; por padrão fora da lixeira (SoftDeletes), e a lixeira do agente (Bloco 4) pede
     * 'lixeira' ou 'arquivado'. Até 6, para o agente saber se ficou ambíguo.
     */
    public function localizar(?int $id, ?string $busca, int $tenantId, string $situacao = 'ativo'): Collection
    {
        return Cliente::query()
            ->naSituacao($situacao)
            ->where('tenant_id', $tenantId)
            ->when($id, fn ($query) => $query->whereKey($id), fn ($query) => $this->nomeContem($query, (string) $busca))
            ->limit(6)
            ->get();
    }

    /**
     * Contrato: atualizar_*. Recebe só dados já validados pelo Form Request; registro de
     * outro tenant ou na lixeira é tratado como inexistente (ModelNotFoundException).
     */
    public function atualizar(int $id, array $dados, int $tenantId): Cliente
    {
        $cliente = Cliente::where('tenant_id', $tenantId)->findOrFail($id);
        $cliente->update(Arr::only($dados, ['nome', 'documento', 'email', 'telefone']));

        return $cliente;
    }

    /**
     * $tenantId restringe a consulta ao tenant informado (obrigatório).
     */
    public function buscarPorFiltro(?string $nome, int $tenantId): Collection
    {
        return Cliente::query()
            ->where('tenant_id', $tenantId)
            ->when($nome, fn ($query) => $this->nomeContem($query, $nome))
            ->get();
    }

    /**
     * @return array{bloqueado: bool, motivo?: string}
     */
    public function excluir(int $id, int $tenantId): array
    {
        $cliente = Cliente::where('tenant_id', $tenantId)->findOrFail($id);

        return $this->excluirComBloqueio($cliente, self::DEPENDENTES);
    }

    /**
     * @return array{restaurado: bool, motivo?: string}
     */
    public function restaurarDaLixeira(int $id, int $tenantId, bool $reautenticadoComoAdmin): array
    {
        $cliente = Cliente::onlyTrashed()->where('tenant_id', $tenantId)->findOrFail($id);

        return $this->restaurar($cliente, $reautenticadoComoAdmin);
    }

    /**
     * RF12: exclusão física manual, só de registro arquivado e com a senha de um admin.
     *
     * @return array{excluido: bool, motivo?: string}
     */
    public function excluirDefinitivo(int $id, int $tenantId, bool $reautenticadoComoAdmin): array
    {
        $cliente = Cliente::onlyTrashed()->where('tenant_id', $tenantId)->findOrFail($id);

        return $this->excluirDeVez($cliente, $reautenticadoComoAdmin);
    }
}
