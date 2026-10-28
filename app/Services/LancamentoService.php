<?php

namespace App\Services;

use App\Models\Lancamento;
use App\Services\Concerns\ExclusaoSegura;
use App\Services\Concerns\FiltroPorNome;
use Illuminate\Support\Collection;

class LancamentoService
{
    use ExclusaoSegura, FiltroPorNome;

    /** Lixeira (RF08/RF12): relações cujos registros impedem excluir — e, contando lixeira e arquivados, excluir de vez. */
    private const DEPENDENTES = ['notasFiscais'];

    /** Relações que precisam estar ativas para o registro voltar da lixeira (pai na lixeira: recusa, dizendo qual). */
    private const PAIS = ['categoriaLancamento', 'cliente', 'fornecedor', 'funcionario'];

    /**
     * Contrato: criar_lancamento.
     *
     * Lançamento sempre nasce como "pendente" — conciliação é uma ação
     * separada (marcarConciliado).
     */
    public function criar(array $dados): Lancamento
    {
        return Lancamento::create([
            'descricao' => $dados['descricao'],
            'valor' => $dados['valor'],
            'data' => $dados['data'],
            'tenant_id' => $dados['tenant_id'],
            'status' => 'pendente',
        ]);
    }

    /**
     * Contrato: buscar_lancamentos.
     *
     * $tenantId restringe a consulta ao tenant informado (obrigatório).
     */
    public function buscarPorFiltro(?string $status, ?string $dataInicio, ?string $dataFim, int $tenantId): Collection
    {
        return Lancamento::query()
            ->where('tenant_id', $tenantId)
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($dataInicio, fn ($query) => $query->whereDate('data', '>=', $dataInicio))
            ->when($dataFim, fn ($query) => $query->whereDate('data', '<=', $dataFim))
            ->get();
    }

    /**
     * RF03: ids dos lançamentos PENDENTES e ativos do período (e da categoria, se informada),
     * só no tenant, em ordem de data. Traz até $limite; quem chama pede um a mais para saber
     * se o filtro passou do teto (nunca trunca em silêncio).
     *
     * @return array<int, int>
     */
    public function idsPendentesNoPeriodo(string $dataInicio, string $dataFim, ?int $categoriaId, int $tenantId, int $limite): array
    {
        return Lancamento::query()
            ->naSituacao('ativo')
            ->where('tenant_id', $tenantId)
            ->where('status', 'pendente')
            ->whereDate('data', '>=', $dataInicio)
            ->whereDate('data', '<=', $dataFim)
            ->when($categoriaId, fn ($query) => $query->where('categoria_lancamento_id', $categoriaId))
            ->orderBy('data')->orderBy('id')
            ->limit($limite)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * RF03: os lançamentos do lote (ativos, no tenant), na ordem dos ids. Id que sumiu ou saiu
     * do tenant fica de fora; quem chama compara com a lista e aponta o item.
     *
     * @param  int[]  $ids
     * @return Collection<int, Lancamento>
     */
    public function doLote(array $ids, int $tenantId, bool $travar = false): Collection
    {
        return Lancamento::query()
            ->naSituacao('ativo')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->when($travar, fn ($query) => $query->lockForUpdate())
            ->get()
            ->keyBy('id');
    }

    /**
     * RF03: por que cada item do lote não pode ser conciliado ([id => frase]); vazio = todos podem.
     * A mesma conferência vale no Revisar (sem trava) e no Confirmar (com os registros travados).
     *
     * @param  int[]  $ids
     * @param  Collection<int, Lancamento>  $lancamentos  resultado de doLote()
     * @return array<int, string>
     */
    public function motivosParaNaoConciliarLote(array $ids, Collection $lancamentos): array
    {
        $motivos = [];

        foreach ($ids as $id) {
            $lancamento = $lancamentos->get($id);

            if ($lancamento === null) {
                $motivos[$id] = "O lançamento #{$id} não existe mais.";
            } elseif ($lancamento->status !== 'pendente') {
                $motivos[$id] = "O lançamento #{$id} não está mais pendente.";
            }
        }

        return $motivos;
    }

    /**
     * RF03: concilia o lote inteiro ou nada. Trava os registros (lockForUpdate), reconfere
     * tenant e status de CADA um e só então grava; com qualquer motivo, não grava nenhum.
     * Deve rodar dentro da transação de quem chama (a trava vale até o commit).
     *
     * @param  int[]  $ids
     * @return array{conciliados: int[], motivos: array<int, string>}
     */
    public function conciliarLote(array $ids, int $tenantId): array
    {
        $lancamentos = $this->doLote($ids, $tenantId, travar: true);
        $motivos = $this->motivosParaNaoConciliarLote($ids, $lancamentos);

        if ($motivos !== []) {
            return ['conciliados' => [], 'motivos' => $motivos];
        }

        foreach ($ids as $id) {
            $lancamentos->get($id)->update(['status' => 'conciliado']);
        }

        return ['conciliados' => array_values($ids), 'motivos' => []];
    }

    /**
     * Lixeira pelo agente (Bloco 4): acha o lançamento por id ou por parte da descrição, só
     * no tenant e na situação pedida ('ativo', 'lixeira' ou 'arquivado'). Até 6, para o
     * agente saber se ficou ambíguo.
     */
    public function localizar(?int $id, ?string $busca, int $tenantId, string $situacao = 'ativo'): Collection
    {
        return Lancamento::query()
            ->naSituacao($situacao)
            ->where('tenant_id', $tenantId)
            ->when($id, fn ($query) => $query->whereKey($id), fn ($query) => $this->nomeContem($query, (string) $busca, 'descricao'))
            ->limit(6)
            ->get();
    }

    /**
     * Contrato: marcar_conciliado.
     *
     * Pré-requisito: só altera lançamentos cujo status atual é "pendente".
     * IDs inexistentes ou que não atendem ao pré-requisito são reportados
     * em "ignorados" (erro parcial), nunca alterados silenciosamente.
     *
     * $tenantId restringe a alteração ao tenant informado (obrigatório);
     * lançamento de outro tenant cai em "ignorados", como se não existisse.
     *
     * @param  int[]  $ids
     * @return array{atualizados: int[], ignorados: int[]}
     */
    public function marcarConciliado(array $ids, int $tenantId): array
    {
        return $this->alterarStatus($ids, de: 'pendente', para: 'conciliado', tenantId: $tenantId);
    }

    /**
     * Contrato: desmarcar_conciliado.
     *
     * Pré-requisito: só altera lançamentos cujo status atual é "conciliado".
     * Mesma política de erro parcial de marcarConciliado().
     *
     * @param  int[]  $ids
     * @return array{atualizados: int[], ignorados: int[]}
     */
    public function desmarcarConciliado(array $ids, int $tenantId): array
    {
        return $this->alterarStatus($ids, de: 'conciliado', para: 'pendente', tenantId: $tenantId);
    }

    /**
     * Contrato: atualizar_status_lancamento.
     *
     * Reaproveita marcarConciliado/desmarcarConciliado (mesmo pré-requisito de
     * status atual e mesma política de "ignorados") e, quando não atualiza,
     * devolve o motivo em linguagem simples — sem termo técnico.
     *
     * @return array{atualizado: bool, lancamento?: Lancamento, motivo?: string}
     */
    public function atualizarStatus(int $id, string $novoStatus, int $tenantId): array
    {
        $resultado = match ($novoStatus) {
            'conciliado' => $this->marcarConciliado([$id], $tenantId),
            'pendente' => $this->desmarcarConciliado([$id], $tenantId),
            default => null,
        };

        if ($resultado === null) {
            return ['atualizado' => false, 'motivo' => 'Status inválido: use "pendente" ou "conciliado".'];
        }

        $lancamento = Lancamento::query()
            ->where('tenant_id', $tenantId)
            ->find($id);

        if ($resultado['atualizados'] !== []) {
            return ['atualizado' => true, 'lancamento' => $lancamento];
        }

        // Ignorado: ou não existe (para este tenant), ou já está no status pedido.
        return [
            'atualizado' => false,
            'motivo' => $lancamento === null
                ? "Não encontrei o lançamento #{$id}."
                : "O lançamento #{$id} já está {$novoStatus}.",
        ];
    }

    /**
     * Conferência só de leitura, com as mesmas frases de atualizarStatus(): usada no
     * /agente/revisar para a recusa aparecer antes do segurar. Devolve o campo do
     * formulário que causou a recusa e a frase, ou null se dá para atualizar.
     *
     * @return array{campo: string, motivo: string}|null
     */
    public function motivoParaNaoAtualizarStatus(int $id, string $novoStatus, int $tenantId): ?array
    {
        $lancamento = Lancamento::query()->where('tenant_id', $tenantId)->find($id);

        return match (true) {
            $lancamento === null => ['campo' => 'id', 'motivo' => "Não encontrei o lançamento #{$id}."],
            $lancamento->status === $novoStatus => ['campo' => 'novo_status', 'motivo' => "O lançamento #{$id} já está {$novoStatus}."],
            default => null,
        };
    }

    /**
     * @param  int[]  $ids
     * @return array{atualizados: int[], ignorados: int[]}
     */
    private function alterarStatus(array $ids, string $de, string $para, int $tenantId): array
    {
        $lancamentos = Lancamento::query()
            ->whereIn('id', $ids)
            ->where('tenant_id', $tenantId)
            ->get()
            ->keyBy('id');

        $atualizados = [];
        $ignorados = [];

        foreach ($ids as $id) {
            $lancamento = $lancamentos->get($id);

            if (! $lancamento || $lancamento->status !== $de) {
                $ignorados[] = $id;

                continue;
            }

            $lancamento->update(['status' => $para]);
            $atualizados[] = $id;
        }

        return [
            'atualizados' => $atualizados,
            'ignorados' => $ignorados,
        ];
    }

    /**
     * @return array{bloqueado: bool, motivo?: string}
     */
    public function excluir(int $id, int $tenantId): array
    {
        $lancamento = Lancamento::where('tenant_id', $tenantId)->findOrFail($id);

        return $this->excluirComBloqueio($lancamento, self::DEPENDENTES);
    }

    /**
     * @return array{restaurado: bool, motivo?: string}
     */
    public function restaurarDaLixeira(int $id, int $tenantId, bool $reautenticadoComoAdmin): array
    {
        $lancamento = Lancamento::onlyTrashed()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);

        return $this->restaurar($lancamento, $reautenticadoComoAdmin);
    }

    /**
     * RF12: exclusão física manual, só de registro arquivado e autorizada por um admin do tenant
     * ($autorizadoPor: quem teve a senha conferida).
     *
     * @return array{excluido: bool, motivo?: string}
     */
    public function excluirDefinitivo(int $id, int $tenantId, ?int $autorizadoPor): array
    {
        $lancamento = Lancamento::onlyTrashed()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);

        return $this->excluirDeVez($lancamento, $autorizadoPor);
    }
}
