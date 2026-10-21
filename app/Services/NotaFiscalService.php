<?php

namespace App\Services;

use App\Models\NotaFiscal;
use App\Services\Concerns\ExclusaoSegura;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class NotaFiscalService
{
    use ExclusaoSegura;

    /** Lixeira (RF08/RF12): relações cujos registros impedem excluir — e, contando lixeira e arquivados, excluir de vez. */
    private const DEPENDENTES = [];

    /** Relações que precisam estar ativas para o registro voltar da lixeira (pai na lixeira: recusa, dizendo qual). */
    private const PAIS = ['cliente', 'fornecedor', 'lancamento'];

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
     * tenant; por padrão fora da lixeira (SoftDeletes), e a lixeira do agente (Bloco 4) pede
     * 'lixeira' ou 'arquivado'. Até 6, para o agente saber se ficou ambíguo.
     */
    public function localizar(?int $id, ?string $busca, int $tenantId, string $situacao = 'ativo'): Collection
    {
        return NotaFiscal::query()
            ->naSituacao($situacao)
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

        return $this->excluirComBloqueio($notaFiscal, self::DEPENDENTES);
    }

    /**
     * @return array{restaurado: bool, motivo?: string}
     */
    public function restaurarDaLixeira(int $id, int $tenantId, bool $reautenticadoComoAdmin): array
    {
        $notaFiscal = NotaFiscal::onlyTrashed()->where('tenant_id', $tenantId)->findOrFail($id);

        return $this->restaurar($notaFiscal, $reautenticadoComoAdmin);
    }

    /**
     * RF12: exclusão física manual, só de registro arquivado e com a senha de um admin.
     *
     * @return array{excluido: bool, motivo?: string}
     */
    public function excluirDefinitivo(int $id, int $tenantId, bool $reautenticadoComoAdmin): array
    {
        $notaFiscal = NotaFiscal::onlyTrashed()->where('tenant_id', $tenantId)->findOrFail($id);

        return $this->excluirDeVez($notaFiscal, $reautenticadoComoAdmin);
    }
}
