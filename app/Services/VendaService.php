<?php

namespace App\Services;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Agendamento;
use App\Models\CatalogoItem;
use App\Models\Pet;
use App\Models\Tutor;
use App\Models\User;
use App\Models\Venda;
use App\Models\VendaItem;
use App\Services\Concerns\ConfereTenant;
use App\Services\Concerns\EstornoPorItens;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Clínica vet: venda avulsa (decisão de 09/11), da loja do petshop ou da clínica. Regras:
 * - qualquer item do catálogo, serviço ou produto, todos do mesmo setor; o estoque só baixa se o item controla estoque;
 * - quem vende depende do setor: clínica = recepção, veterinário ou dono; petshop = vendedor, atendente ou dono;
 * - pode ficar ligada a um atendimento (agendamento) e a um pet, opcionais e do mesmo tenant; o pet é o do
 *   agendamento e o do tutor (sem tutor informado, a venda fica com o tutor do pet);
 * - item que exige receita NÃO é vendido por ninguém (agente, vendedor, recepção ou veterinário);
 * - descrição e preço são cópias do catálogo; total em centavos calculado aqui;
 * - a baixa do estoque acontece na MESMA transação da venda; sem saldo, a venda acontece e o estoque fica negativo
 *   (decisão de 10/11: o card avisa antes do segurar, sem bloquear);
 * - com forma de pagamento, a venda já nasce paga; sem, fica aberta. Cancelar (só aberta) devolve o estoque;
 * - venda PAGA se estorna por itens (T2, resposta E), com a mesma regra da cobrança (EstornoPorItens): parcial fica
 *   paga, com o estornado e o líquido à vista; o estorno que esgota a venda a deixa cancelada.
 */
class VendaService
{
    use ConfereTenant, EstornoPorItens;

    public function __construct(private CatalogoService $catalogo)
    {
    }

    /** Quem vende os itens de cada setor (regra do dado; o RBAC da tool é a lista de papéis dela). */
    private const QUEM_VENDE = ['clinica' => ['recepcao', 'veterinario', 'dono'], 'petshop' => ['vendedor', 'atendente', 'dono']];

    /** @param  array<int, array{catalogo_item_id: mixed, quantidade: int}>  $itens */
    public function registrar(array $itens, int $vendedorId, ?int $tutorId, ?string $formaPagamento, int $tenantId, ?int $petId = null, ?int $agendamentoId = null): Venda
    {
        return DB::transaction(function () use ($itens, $vendedorId, $tutorId, $formaPagamento, $tenantId, $petId, $agendamentoId) {
            $vendedor = $this->doTenant(User::class, $vendedorId, $tenantId, 'o vendedor');
            if ($formaPagamento !== null && ! in_array($formaPagamento, ['dinheiro', 'pix', 'cartao'], true)) {
                throw new RegraDeNegocioException('A forma de pagamento deve ser dinheiro, pix ou cartao.');
            }
            ['tutor_id' => $tutorId, 'pet_id' => $petId, 'agendamento_id' => $agendamentoId] = $this->vinculos($tutorId, $petId, $agendamentoId, $tenantId);

            $linhas = $this->conferirItens($itens, $tenantId);
            $setor = $linhas[0]['item']->setor;

            if (! in_array($vendedor->papel, self::QUEM_VENDE[$setor], true)) {
                throw new RegraDeNegocioException($setor === 'clinica'
                    ? 'Itens da clínica são vendidos pela recepção, por um veterinário ou pelo dono.'
                    : 'Itens do petshop são vendidos pela loja, pelo banho e tosa ou pelo dono.');
            }

            $venda = Venda::create([
                'setor' => $setor,
                'vendedor_id' => $vendedorId,
                'tutor_id' => $tutorId,
                'pet_id' => $petId,
                'agendamento_id' => $agendamentoId,
                'status' => $formaPagamento === null ? 'aberta' : 'paga',
                'total_centavos' => 0,
                'forma_pagamento' => $formaPagamento,
                'pago_em' => $formaPagamento === null ? null : now(),
                'tenant_id' => $tenantId,
            ]);

            $total = 0;
            foreach ($linhas as ['item' => $item, 'quantidade' => $quantidade]) {
                $baixou = $this->catalogo->baixarEstoque($item, $quantidade, $tenantId); // sem saldo: exceção e rollback de tudo
                $subtotal = $item->preco_centavos * $quantidade;
                $total += $subtotal;

                VendaItem::create([
                    'venda_id' => $venda->id,
                    'catalogo_item_id' => $item->id,
                    'descricao' => $item->nome_comercial,
                    'preco_unitario_centavos' => $item->preco_centavos,
                    'quantidade' => $quantidade,
                    'subtotal_centavos' => $subtotal,
                    'baixou_estoque' => $baixou,
                    'tenant_id' => $tenantId,
                ]);
            }

            $venda->update(['total_centavos' => $total]);

            return $venda;
        });
    }

    /**
     * Só leitura, para o card antes do segurar: as linhas e o total que a venda teria AGORA (preço de agora,
     * mesmas regras). Roda o registrar() de verdade numa transação desfeita no fim: a prévia nunca diverge da
     * regra e nada fica gravado (nem a baixa de estoque). Recusa: RegraDeNegocioException com a frase.
     *
     * @return array{setor: string, linhas: array<int, array{descricao: string, quantidade: int, subtotal_centavos: int}>, total_centavos: int, avisos: string[]}
     */
    public function previa(array $itens, int $vendedorId, ?int $tutorId, int $tenantId, ?int $petId = null, ?int $agendamentoId = null): array
    {
        DB::beginTransaction();

        try {
            $venda = $this->registrar($itens, $vendedorId, $tutorId, null, $tenantId, $petId, $agendamentoId);

            return [
                'setor' => $venda->setor,
                'linhas' => $venda->itens()->orderBy('id')->get()
                    ->map(fn (VendaItem $l) => ['descricao' => $l->descricao, 'quantidade' => $l->quantidade, 'subtotal_centavos' => $l->subtotal_centavos])->all(),
                'total_centavos' => $venda->total_centavos,
                // Lido antes do rollBack: o saldo que a venda deixaria.
                'avisos' => $this->catalogo->avisosDeSaldoNegativo($venda->itens()->pluck('catalogo_item_id')->all(), $tenantId),
            ];
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Tutor, pet e agendamento da venda: todos do tenant e coerentes entre si (o pet é o do agendamento e o do
     * tutor). Sem tutor informado, vale o tutor do pet; sem pet, o pet do agendamento.
     *
     * @return array{tutor_id: ?int, pet_id: ?int, agendamento_id: ?int}
     */
    private function vinculos(?int $tutorId, ?int $petId, ?int $agendamentoId, int $tenantId): array
    {
        $agendamento = $agendamentoId === null ? null : $this->doTenant(Agendamento::class, $agendamentoId, $tenantId, 'o atendimento');
        if ($agendamento !== null) {
            if ($petId !== null && $petId !== $agendamento->pet_id) {
                throw new RegraDeNegocioException('O pet informado não é o do atendimento.');
            }
            $petId = $agendamento->pet_id;
        }

        $pet = $petId === null ? null : $this->doTenant(Pet::class, $petId, $tenantId, 'o pet');
        if ($pet !== null) {
            if ($pet->inativado_em !== null) {
                throw new RegraDeNegocioException("{$pet->nome} está arquivado: reative o cadastro antes de vender.");
            }
            if ($tutorId !== null && $tutorId !== $pet->tutor_id) {
                throw new RegraDeNegocioException('O pet informado é de outro tutor.');
            }
            $tutorId = $pet->tutor_id;
        }

        if ($tutorId !== null) {
            $this->doTenant(Tutor::class, $tutorId, $tenantId, 'o tutor');
        }

        return ['tutor_id' => $tutorId, 'pet_id' => $petId, 'agendamento_id' => $agendamento?->id];
    }

    public function registrarPagamento(int $id, string $formaPagamento, int $tenantId): Venda
    {
        return DB::transaction(function () use ($id, $formaPagamento, $tenantId) {
            $venda = Venda::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);

            if ($venda->status !== 'aberta') {
                throw new RegraDeNegocioException('Só uma venda aberta pode ser paga (esta está '.$venda->status.').');
            }
            if (! in_array($formaPagamento, ['dinheiro', 'pix', 'cartao'], true)) {
                throw new RegraDeNegocioException('A forma de pagamento deve ser dinheiro, pix ou cartao.');
            }

            $venda->update(['status' => 'paga', 'forma_pagamento' => $formaPagamento, 'pago_em' => now()]);

            return $venda;
        });
    }

    /** Só venda aberta; o estoque de cada item volta na mesma transação. */
    public function cancelar(int $id, int $tenantId): Venda
    {
        return DB::transaction(function () use ($id, $tenantId) {
            $venda = Venda::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);

            if ($venda->status !== 'aberta') {
                throw new RegraDeNegocioException('Só uma venda aberta pode ser cancelada (esta está '.$venda->status.').');
            }

            // Volta só o que a linha de fato baixou (registro da venda), não o que o catálogo diz hoje.
            foreach ($venda->itens()->where('baixou_estoque', true)->orderBy('catalogo_item_id')->get() as $linha) {
                $this->catalogo->devolverEstoque($linha->catalogo_item_id, $linha->quantidade, $tenantId);
            }

            $venda->update(['status' => 'cancelada']);

            return $venda;
        });
    }

    /**
     * T2: estorno por itens de uma venda PAGA (modo "tudo" ou "itens"; a venda não tem item de prontuário, então
     * "tudo_menos_prontuario" é o mesmo que "tudo"). A conferência do PIN fica no card; sem ela, nada muda. Venda e
     * linhas travadas; tudo ou nada.
     *
     * @param  array<int, array{item_id: mixed, quantidade: mixed}>  $itens
     */
    public function estornar(int $vendaId, string $motivo, int $solicitanteId, int $autorizadorId, int $tenantId, bool $autorizacaoConferida, string $modo = 'tudo', array $itens = []): Venda
    {
        return DB::transaction(function () use ($vendaId, $motivo, $solicitanteId, $autorizadorId, $tenantId, $autorizacaoConferida, $modo, $itens) {
            $venda = Venda::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($vendaId);

            if (! $autorizacaoConferida) {
                throw new RegraDeNegocioException('O estorno precisa da confirmação de quem autoriza (PIN do supervisor do setor ou do dono).');
            }
            if ($venda->status !== 'paga') {
                throw new RegraDeNegocioException('Só uma venda paga é estornada (esta está '.$venda->status.'); a aberta é cancelada.');
            }

            [$motivo, $solicitante, $autorizador] = $this->conferirQuemEstorna($venda->setor, $motivo, $solicitanteId, $autorizadorId, $tenantId, 'venda');
            $plano = $this->planoDaVenda($venda, $modo, $itens, travar: true);

            $estorno = $this->gravarEstorno('venda_id', $venda->id, $plano, $motivo, $solicitante->id, $autorizador->id, $tenantId);
            if ($plano['esgota']) {
                $venda->update(['status' => 'cancelada']);
            }
            app(\App\Services\DetalhesAuditoria::class)->anotar('valores_anteriores', $this->auditoriaDoEstorno($estorno, $plano));

            return $venda;
        });
    }

    /**
     * Só leitura, para o card: o que o estorno levaria AGORA (linhas, valor, já estornado, líquido). Venda de outro
     * tenant: ModelNotFoundException; não paga, modo ou itens inválidos: RegraDeNegocioException.
     */
    public function planoDoEstorno(int $vendaId, string $modo, array $itens, int $tenantId): array
    {
        $venda = Venda::where('tenant_id', $tenantId)->findOrFail($vendaId);
        if ($venda->status !== 'paga') {
            throw new RegraDeNegocioException('Só uma venda paga é estornada (esta está '.$venda->status.').');
        }

        return $this->planoDaVenda($venda, $modo, $itens, travar: false);
    }

    /** As linhas da venda paga com o que já foi estornado e o que resta (para o editor do card). */
    public function linhasParaEstornar(int $vendaId, int $tenantId): array
    {
        $venda = Venda::where('tenant_id', $tenantId)->where('status', 'paga')->findOrFail($vendaId);

        return $this->linhasDoEstorno('venda_id', $venda->id, $tenantId, $venda->itens()->orderBy('id')->get());
    }

    private function planoDaVenda(Venda $venda, string $modo, array $itens, bool $travar): array
    {
        $consulta = $venda->itens()->orderBy('id');
        $linhas = $this->linhasDoEstorno('venda_id', $venda->id, $venda->tenant_id, ($travar ? $consulta->lockForUpdate() : $consulta)->get(), $travar);
        $escolha = $this->escolherDoEstorno($linhas, $modo === 'tudo_menos_prontuario' ? 'tudo' : $modo, $itens, 'venda');

        return $this->planoDoEstornoDe($linhas, $escolha, (int) $venda->total_centavos);
    }

    /**
     * Vendas do tenant, mais novas primeiro, com o tutor, o pet e o estornado (soma dos estornos). Até 100.
     */
    public function buscarPorFiltro(?string $status, ?string $setor, int $tenantId, ?int $id = null): Collection
    {
        return $this->consultaDeVendas($tenantId)
            ->when($status, fn ($query) => $query->where('vendas.status', $status))
            ->when($setor, fn ($query) => $query->where('vendas.setor', $setor))
            ->when($id, fn ($query) => $query->where('vendas.id', $id))
            ->orderByDesc('vendas.id')
            ->limit(100)
            ->get();
    }

    /**
     * Acha a venda por id ou pelo nome do tutor ou do pet, só no tenant e só nos status dados (estornar: paga). Até 6,
     * para o agente saber se ficou ambíguo.
     *
     * @param  string[]  $status
     */
    public function localizar(?int $id, ?string $busca, int $tenantId, array $status): Collection
    {
        $busca = trim((string) $busca);

        return $this->consultaDeVendas($tenantId)
            ->whereIn('vendas.status', $status)
            ->when($id, fn ($query) => $query->where('vendas.id', $id), fn ($query) => $query->where(fn ($q) => $q
                ->where('tutores.nome', 'like', '%'.addcslashes($busca, '%_\\').'%')
                ->orWhere('pets.nome', 'like', '%'.addcslashes($busca, '%_\\').'%')))
            ->orderByDesc('vendas.id')
            ->limit(6)
            ->get();
    }

    private function consultaDeVendas(int $tenantId): \Illuminate\Database\Eloquent\Builder
    {
        return Venda::query()
            ->leftJoin('tutores', 'tutores.id', '=', 'vendas.tutor_id')
            ->leftJoin('pets', 'pets.id', '=', 'vendas.pet_id')
            ->where('vendas.tenant_id', $tenantId)
            ->select(['vendas.*', 'tutores.nome as tutor', 'pets.nome as pet'])
            ->selectSub(fn ($q) => $q->from('estornos')->whereColumn('estornos.venda_id', 'vendas.id')->where('estornos.tenant_id', $tenantId)
                ->selectRaw('coalesce(sum(valor_centavos), 0)'), 'estornado_centavos');
    }

    /**
     * Itens repetidos são somados (uma linha por item). Travados até o commit.
     *
     * @return array<int, array{item: CatalogoItem, quantidade: int}>
     */
    private function conferirItens(array $itens, int $tenantId): array
    {
        if ($itens === []) {
            throw new RegraDeNegocioException('A venda precisa de pelo menos um item.');
        }

        $quantidades = [];
        foreach ($itens as $linha) {
            $id = $this->idValido($linha['catalogo_item_id'] ?? null) ?? throw new RegraDeNegocioException('Não encontrei o item do catálogo.');
            $quantidades[$id] = ($quantidades[$id] ?? 0) + $this->quantidadeValida($linha['quantidade'] ?? null);
        }

        // Trava sempre na ordem do id: duas vendas com os mesmos itens em ordens diferentes não se travam (deadlock).
        ksort($quantidades);

        $linhas = [];
        foreach ($quantidades as $id => $quantidade) {
            $item = CatalogoItem::where('tenant_id', $tenantId)->lockForUpdate()->find($id)
                ?? throw new RegraDeNegocioException("Não encontrei o item do catálogo #{$id}.");

            if ($item->exige_receita) {
                throw new RegraDeNegocioException("{$item->nome_comercial} exige receita e não pode ser vendido no balcão.");
            }

            $linhas[] = ['item' => $item, 'quantidade' => $quantidade];
        }

        if (count(array_unique(array_map(fn (array $l) => $l['item']->setor, $linhas))) > 1) {
            throw new RegraDeNegocioException('Uma venda é de um setor só: separe os itens da loja e os da farmácia.');
        }

        return $linhas;
    }
}
