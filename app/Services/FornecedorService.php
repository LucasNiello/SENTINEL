<?php

namespace App\Services;

use App\Models\Fornecedor;
use App\Services\Concerns\ExclusaoSegura;
use App\Services\Concerns\FiltroPorNome;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class FornecedorService
{
    use ExclusaoSegura, FiltroPorNome;

    /** Lixeira (RF08/RF12): relações cujos registros impedem excluir — e, contando lixeira e arquivados, excluir de vez. */
    private const DEPENDENTES = ['lancamentos', 'notasFiscais'];

    /** Relações que precisam estar ativas para o registro voltar da lixeira (pai na lixeira: recusa, dizendo qual). */
    private const PAIS = [];

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
     * Atualizar pelo agente: acha o registro por id ou por parte do nome, só no
     * tenant; por padrão fora da lixeira (SoftDeletes), e a lixeira do agente (Bloco 4) pede
     * 'lixeira' ou 'arquivado'. Até 6, para o agente saber se ficou ambíguo.
     */
    public function localizar(?int $id, ?string $busca, int $tenantId, string $situacao = 'ativo'): Collection
    {
        return Fornecedor::query()
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
    public function atualizar(int $id, array $dados, int $tenantId): Fornecedor
    {
        $fornecedor = Fornecedor::where('tenant_id', $tenantId)->findOrFail($id);
        $fornecedor->update(Arr::only($dados, ['nome', 'documento', 'email', 'telefone']));

        return $fornecedor;
    }

    /**
     * $tenantId restringe a consulta ao tenant informado (obrigatório).
     */
    public function buscarPorFiltro(?string $nome, int $tenantId): Collection
    {
        return Fornecedor::query()
            ->where('tenant_id', $tenantId)
            ->when($nome, fn ($query) => $this->nomeContem($query, $nome))
            ->get();
    }

    /**
     * @return array{bloqueado: bool, motivo?: string}
     */
    public function excluir(int $id, int $tenantId): array
    {
        $fornecedor = Fornecedor::where('tenant_id', $tenantId)->findOrFail($id);

        return $this->excluirComBloqueio($fornecedor, self::DEPENDENTES);
    }

    /**
     * @return array{restaurado: bool, motivo?: string}
     */
    public function restaurarDaLixeira(int $id, int $tenantId, bool $reautenticadoComoAdmin): array
    {
        $fornecedor = Fornecedor::onlyTrashed()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);

        return $this->restaurar($fornecedor, $reautenticadoComoAdmin);
    }

    /**
     * RF12: exclusão física manual, só de registro arquivado e autorizada por um admin do tenant
     * ($autorizadoPor: quem teve a senha conferida).
     *
     * @return array{excluido: bool, motivo?: string}
     */
    public function excluirDefinitivo(int $id, int $tenantId, ?int $autorizadoPor): array
    {
        $fornecedor = Fornecedor::onlyTrashed()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);

        return $this->excluirDeVez($fornecedor, $autorizadoPor);
    }
}
