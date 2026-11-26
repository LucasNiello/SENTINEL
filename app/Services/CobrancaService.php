<?php

namespace App\Services;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Agendamento;
use App\Models\CatalogoItem;
use App\Models\Cobranca;
use App\Models\CobrancaItem;
use App\Models\Prontuario;
use App\Services\Concerns\ConfereTenant;
use App\Services\Concerns\EstornoPorItens;
use App\Services\Concerns\FiltroPorNome;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Clínica vet: cobrança de um agendamento (uma não cancelada por agendamento). Ciclo: aberta -> fechada
 * -> paga; aberta ou fechada -> cancelada; paga -> cancelada só por estorno autorizado. Regras:
 * - nasce com o serviço do agendamento como 1ª linha;
 * - cada linha COPIA descrição e preço do catálogo (mudar o catálogo não muda cobrança antiga);
 * - o total é sempre a soma dos subtotais, calculada aqui, em centavos (inteiro) — nunca informado
 *   por ninguém, nunca pela IA;
 * - itens do prontuário entram só depois da assinatura, uma única vez cada; item por diária cobra os
 *   dias de calendário entre a entrada e a alta (mínimo 1);
 * - item que exige receita não entra avulso: só pelo prontuário (decisão do veterinário);
 * - produto com controle de estoque baixa o estoque ao entrar na cobrança (mesma transação; sem saldo, fica
 *   negativo e o card avisa, decisão de 10/11). Item removido devolve. Cobrança cancelada ou estornada devolve só os itens avulsos: o que
 *   veio do prontuário foi aplicado no animal (não volta, e não baixa de novo numa cobrança seguinte);
 * - cancelada não conta: o agendamento pode ganhar uma cobrança nova;
 * - cobrança PAGA se estorna por itens (T2, 14/11; EstornoPorItens): vários parciais até esgotar; o que esgota a deixa
 *   cancelada, com o estado final de antes (colunas estorno_*).
 * Nenhum texto clínico passa para a cobrança: só o nome comercial do item.
 */
class CobrancaService
{
    use ConfereTenant, EstornoPorItens, FiltroPorNome;

    public function __construct(private CatalogoService $catalogo)
    {
    }

    public function abrir(int $agendamentoId, int $tenantId): Cobranca
    {
        return DB::transaction(function () use ($agendamentoId, $tenantId) {
            $agendamento = Agendamento::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($agendamentoId);

            // Só se cobra o que já aconteceu: agendado/confirmado ainda não; cancelado/faltou nunca.
            if ($agendamento->status !== 'realizado') {
                throw new RegraDeNegocioException("O agendamento está {$agendamento->status}: só o realizado é cobrado.");
            }
            // O agendamento está travado acima: duas aberturas simultâneas não passam juntas por aqui.
            if ($this->existeTravado(Cobranca::where('agendamento_id', $agendamento->id)->where('status', '!=', 'cancelada'))) {
                throw new RegraDeNegocioException('Este agendamento já tem uma cobrança.');
            }

            $cobranca = Cobranca::create([
                'agendamento_id' => $agendamento->id,
                'setor' => $agendamento->setor,
                'status' => 'aberta',
                'total_centavos' => 0,
                'tenant_id' => $tenantId,
            ]);

            $servico = CatalogoItem::withTrashed()->where('tenant_id', $tenantId)->findOrFail($agendamento->catalogo_item_id);
            $this->lancar($cobranca, $servico, 1);

            return $this->recalcular($cobranca);
        });
    }

    public function adicionarItem(int $cobrancaId, int $catalogoItemId, int $quantidade, int $tenantId): Cobranca
    {
        return DB::transaction(function () use ($cobrancaId, $catalogoItemId, $quantidade, $tenantId) {
            $cobranca = $this->aberta($cobrancaId, $tenantId);
            $item = $this->doTenant(CatalogoItem::class, $catalogoItemId, $tenantId, 'o item do catálogo');

            if ($item->setor !== $cobranca->setor) {
                throw new RegraDeNegocioException("{$item->nome_comercial} não é do setor desta cobrança.");
            }
            if ($item->exige_receita) {
                throw new RegraDeNegocioException("{$item->nome_comercial} exige receita: entra na cobrança só pelo prontuário.");
            }

            $this->lancar($cobranca, $item, $this->quantidadeValida($quantidade));

            return $this->recalcular($cobranca);
        });
    }

    public function removerItem(int $cobrancaItemId, int $tenantId): Cobranca
    {
        return DB::transaction(function () use ($cobrancaItemId, $tenantId) {
            // Linha travada ANTES de decidir a devolução: num duplo clique, a 2ª transação espera e, depois
            // do commit da 1ª, não acha mais a linha (nunca devolve o estoque duas vezes).
            $linha = CobrancaItem::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($cobrancaItemId);
            $cobranca = $this->aberta($linha->cobranca_id, $tenantId);
            // O que veio do prontuário foi aplicado no animal: tirar a linha devolveria ao estoque algo que já saiu
            // e o item deixaria de ser cobrado. Para refazer a conta, cancele a cobrança.
            if ($linha->prontuario_item_id !== null) {
                throw new RegraDeNegocioException("{$linha->descricao} veio do prontuário (já foi aplicado no atendimento) e não sai da cobrança: para refazê-la, cancele a cobrança.");
            }
            if ($linha->baixou_estoque) {
                $this->catalogo->devolverEstoque($linha->catalogo_item_id, $linha->quantidade, $tenantId);
            }
            $linha->delete();

            return $this->recalcular($cobranca);
        });
    }

    /** Puxa os itens do prontuário ASSINADO do agendamento desta cobrança (cada item, uma vez só). */
    public function importarDoProntuario(int $cobrancaId, int $tenantId): Cobranca
    {
        return DB::transaction(function () use ($cobrancaId, $tenantId) {
            $cobranca = $this->aberta($cobrancaId, $tenantId);
            $prontuario = Prontuario::where('tenant_id', $tenantId)->where('agendamento_id', $cobranca->agendamento_id)->first();

            if ($prontuario === null) {
                throw new RegraDeNegocioException('Este agendamento ainda não tem prontuário.');
            }
            if (! $prontuario->estaAssinado()) {
                throw new RegraDeNegocioException('O prontuário ainda não foi assinado: os itens entram na cobrança depois da assinatura.');
            }

            if ($this->lancarDoProntuario($cobranca, $prontuario, $tenantId) === 0) {
                throw new RegraDeNegocioException('Os itens deste prontuário já estão na cobrança.');
            }

            return $this->recalcular($cobranca);
        });
    }

    /**
     * Cobrar o atendimento (P-B4), numa operação só: abre a cobrança (o serviço do agendamento é a 1ª linha), puxa
     * os itens do prontuário ASSINADO, se houver prontuário (com prontuário em rascunho, recusa: o veterinário assina
     * antes), e fecha. A cobrança fica "a receber"; receber é outra tool. Itens avulsos entram e saem pela tela.
     */
    public function cobrarAtendimento(int $agendamentoId, int $tenantId): Cobranca
    {
        return DB::transaction(function () use ($agendamentoId, $tenantId) {
            $cobranca = $this->abrir($agendamentoId, $tenantId);
            $prontuario = Prontuario::where('tenant_id', $tenantId)->where('agendamento_id', $agendamentoId)->first();

            if ($prontuario !== null) {
                if (! $prontuario->estaAssinado()) {
                    throw new RegraDeNegocioException('O prontuário deste atendimento ainda não foi assinado: o veterinário assina antes de cobrar.');
                }
                $this->lancarDoProntuario($cobranca, $prontuario, $tenantId);
                $this->recalcular($cobranca);
            }

            return $this->fechar($cobranca->id, $tenantId);
        });
    }

    /**
     * Só leitura, para o card antes do segurar: as linhas e o total que a cobrança do atendimento teria AGORA.
     * Roda o cobrarAtendimento() de verdade numa transação desfeita no fim: a prévia nunca diverge da regra (nem do
     * abatimento do serviço) e nada fica gravado, nem a baixa de estoque. Recusa: RegraDeNegocioException.
     *
     * @return array{linhas: array<int, array{descricao: string, quantidade: int, subtotal_centavos: int}>, total_centavos: int, avisos: string[]}
     */
    public function previaDaCobranca(int $agendamentoId, int $tenantId): array
    {
        DB::beginTransaction();

        try {
            $cobranca = $this->cobrarAtendimento($agendamentoId, $tenantId);

            return [
                'linhas' => $cobranca->itens()->orderBy('id')->get()
                    ->map(fn (CobrancaItem $l) => ['descricao' => $l->descricao, 'quantidade' => $l->quantidade, 'subtotal_centavos' => $l->subtotal_centavos])->all(),
                'total_centavos' => $cobranca->total_centavos,
                // Lido antes do rollBack: o saldo que a cobrança deixaria.
                'avisos' => $this->catalogo->avisosDeSaldoNegativo($cobranca->itens()->pluck('catalogo_item_id')->all(), $tenantId),
            ];
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Cobranças do tenant, mais novas primeiro, com o pet e o serviço do atendimento (para a tabela e para achar
     * "a cobrança do Thor"). Até 100.
     */
    public function buscarPorFiltro(?string $status, ?string $setor, int $tenantId, ?int $id = null): Collection
    {
        return Cobranca::query()
            ->join('agendamentos', 'agendamentos.id', '=', 'cobrancas.agendamento_id')
            ->join('pets', 'pets.id', '=', 'agendamentos.pet_id')
            ->join('catalogo_itens', 'catalogo_itens.id', '=', 'agendamentos.catalogo_item_id')
            ->where('cobrancas.tenant_id', $tenantId)
            ->when($status, fn ($query) => $query->where('cobrancas.status', $status))
            ->when($setor, fn ($query) => $query->where('cobrancas.setor', $setor))
            ->when($id, fn ($query) => $query->where('cobrancas.id', $id))
            ->orderByDesc('cobrancas.id')
            ->limit(100)
            ->select(['cobrancas.*', 'pets.nome as pet', 'catalogo_itens.nome_comercial as servico', 'agendamentos.inicio as atendimento_em'])
            // T2: o estornado (soma dos estornos por itens), para a consulta mostrar total, estornado e líquido.
            ->selectSub(fn ($q) => $q->from('estornos')->whereColumn('estornos.cobranca_id', 'cobrancas.id')->where('estornos.tenant_id', $tenantId)
                ->selectRaw('coalesce(sum(valor_centavos), 0)'), 'estornado_centavos')
            ->get();
    }

    /**
     * Acha a cobrança por id ou pelo nome do pet, só no tenant e só nos status dados (receber: fechada; cancelar:
     * aberta ou fechada). Até 6, para o agente saber se ficou ambíguo.
     *
     * @param  string[]  $status
     */
    public function localizar(?int $id, ?string $busca, int $tenantId, array $status): Collection
    {
        return Cobranca::query()
            ->join('agendamentos', 'agendamentos.id', '=', 'cobrancas.agendamento_id')
            ->join('pets', 'pets.id', '=', 'agendamentos.pet_id')
            ->where('cobrancas.tenant_id', $tenantId)
            ->whereIn('cobrancas.status', $status)
            ->when($id, fn ($query) => $query->where('cobrancas.id', $id), fn ($query) => $this->nomeContem($query, (string) $busca, 'pets.nome'))
            ->orderByDesc('cobrancas.id')
            ->limit(6)
            ->get(['cobrancas.*', 'pets.nome as pet']);
    }

    /** Atendimentos REALIZADOS e ainda sem cobrança viva, por id ou pelo nome do pet, só no tenant. Até 6. */
    public function atendimentosParaCobrar(?int $id, ?string $busca, int $tenantId): Collection
    {
        return Agendamento::query()
            ->join('pets', 'pets.id', '=', 'agendamentos.pet_id')
            ->where('agendamentos.tenant_id', $tenantId)
            ->where('agendamentos.status', 'realizado')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('cobrancas')
                ->whereColumn('cobrancas.agendamento_id', 'agendamentos.id')->where('cobrancas.status', '!=', 'cancelada'))
            ->when($id, fn ($query) => $query->where('agendamentos.id', $id), fn ($query) => $this->nomeContem($query, (string) $busca, 'pets.nome'))
            ->orderByDesc('agendamentos.inicio')
            ->limit(6)
            ->get(['agendamentos.*', 'pets.nome as pet']);
    }

    /**
     * Lança na cobrança os itens do prontuário assinado que ainda não estão nela; devolve quantas linhas entraram.
     * O serviço do agendamento já entrou ao abrir (1 unidade): se o prontuário traz o mesmo item, essa unidade
     * não é cobrada de novo; o que passar disso (2 vacinas, 3 diárias...) é cobrado.
     */
    private function lancarDoProntuario(Cobranca $cobranca, Prontuario $prontuario, int $tenantId): int
    {
        $jaLancados = CobrancaItem::whereNotNull('prontuario_item_id')->where('cobranca_id', $cobranca->id)->pluck('prontuario_item_id')->all();
        // Em ordem de item do catálogo: a baixa de estoque trava na mesma ordem da venda (sem deadlock).
        $novos = $prontuario->itens()->whereNotIn('id', $jaLancados)->orderBy('catalogo_item_id')->orderBy('id')->get();

        $abatido = false;
        $paraLancar = [];
        foreach ($novos as $itemDoProntuario) {
            $item = CatalogoItem::withTrashed()->where('tenant_id', $tenantId)->findOrFail($itemDoProntuario->catalogo_item_id);
            // Diária: os dias de calendário da internação; sem entrada/alta, vale a quantidade registrada.
            $quantidade = $item->cobranca_por === 'diaria' ? ($prontuario->diarias() ?? $itemDoProntuario->quantidade) : $itemDoProntuario->quantidade;

            if (! $abatido && $item->id === $cobranca->agendamento->catalogo_item_id) {
                $abatido = true;
                $quantidade--;
            }

            if ($quantidade >= 1) {
                $paraLancar[] = [$itemDoProntuario, $item, $quantidade];
            }
        }

        foreach ($paraLancar as [$itemDoProntuario, $item, $quantidade]) {
            // Já consumido numa cobrança cancelada antes (lá ficou baixou_estoque = true e não voltou): não baixa de novo.
            $jaConsumido = CobrancaItem::where('tenant_id', $tenantId)->where('prontuario_item_id', $itemDoProntuario->id)->where('baixou_estoque', true)->exists();
            $this->lancar($cobranca, $item, $quantidade, $itemDoProntuario->id, baixar: ! $jaConsumido);
        }

        return count($paraLancar);
    }

    public function fechar(int $id, int $tenantId): Cobranca
    {
        return DB::transaction(function () use ($id, $tenantId) {
            $cobranca = $this->aberta($id, $tenantId);

            if (! $cobranca->itens()->exists()) {
                throw new RegraDeNegocioException('A cobrança não tem itens.');
            }

            $this->recalcular($cobranca)->update(['status' => 'fechada', 'fechada_em' => now()]);

            return $cobranca;
        });
    }

    public function registrarPagamento(int $id, string $formaPagamento, int $tenantId): Cobranca
    {
        return DB::transaction(function () use ($id, $formaPagamento, $tenantId) {
            $cobranca = Cobranca::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);

            if ($cobranca->status !== 'fechada') {
                throw new RegraDeNegocioException('Só uma cobrança fechada pode ser paga (esta está '.$cobranca->status.').');
            }
            if (! in_array($formaPagamento, Cobranca::FORMAS_PAGAMENTO, true)) {
                throw new RegraDeNegocioException('A forma de pagamento deve ser dinheiro, pix ou cartao.');
            }

            $cobranca->update(['status' => 'paga', 'forma_pagamento' => $formaPagamento, 'pago_em' => now()]);

            return $cobranca;
        });
    }

    public function cancelar(int $id, int $tenantId): Cobranca
    {
        return DB::transaction(function () use ($id, $tenantId) {
            $cobranca = Cobranca::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);

            if (! in_array($cobranca->status, ['aberta', 'fechada'], true)) {
                throw new RegraDeNegocioException('Uma cobrança '.$cobranca->status.' não pode ser cancelada.');
            }

            $this->devolverEstoqueDe($cobranca);
            $cobranca->update(['status' => 'cancelada']);

            return $cobranca;
        });
    }

    /**
     * Estorno por itens (T2, 14/11) de uma cobrança PAGA, com duas pessoas. Pede: a recepção (cobrança da clínica), o
     * atendente (do petshop) ou o dono. Autoriza: o dono ou um supervisor do setor da cobrança, com o PIN gerado pelo
     * Administrador (T1). Quem pede não autoriza o próprio pedido, salvo o dono. Motivo obrigatório.
     *
     * $modo: "tudo" (o que resta), "tudo_menos_prontuario" ou "itens" ($itens = [{item_id, quantidade}]). O valor é
     * sempre o preço copiado na linha × quantidade. Parcial: continua paga. O estorno que esgota a cobrança a deixa
     * cancelada e preenche as colunas estorno_* (quem pediu e quem autorizou o último, o motivo dele, o total estornado).
     * Cobrança e linhas travadas; tudo ou nada.
     *
     * A conferência do PIN NÃO é feita aqui: fica no card, que passa o resultado em $autorizacaoConferida. Sem ela
     * (false), o estorno é recusado. Linha do prontuário estorna só o valor: o estoque nunca volta (resposta D).
     *
     * @param  array<int, array{item_id: mixed, quantidade: mixed}>  $itens
     */
    public function estornar(int $cobrancaId, string $motivo, int $solicitanteId, int $autorizadorId, int $tenantId, bool $autorizacaoConferida, string $modo = 'tudo', array $itens = []): Cobranca
    {
        return DB::transaction(function () use ($cobrancaId, $motivo, $solicitanteId, $autorizadorId, $tenantId, $autorizacaoConferida, $modo, $itens) {
            $cobranca = Cobranca::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($cobrancaId);

            // Falha fechada (padrão do restaurarDaLixeira): sem a conferência do autorizador no card, nada muda.
            if (! $autorizacaoConferida) {
                throw new RegraDeNegocioException('O estorno precisa da confirmação de quem autoriza (PIN do supervisor do setor ou do dono).');
            }

            if ($cobranca->status !== 'paga') {
                throw new RegraDeNegocioException('Só uma cobrança paga é estornada (esta está '.$cobranca->status.'); aberta ou fechada é cancelada.');
            }

            [$motivo, $solicitante, $autorizador] = $this->conferirQuemEstorna($cobranca->setor, $motivo, $solicitanteId, $autorizadorId, $tenantId, 'cobrança');
            $plano = $this->planoDaCobranca($cobranca, $modo, $itens, travar: true);

            $estorno = $this->gravarEstorno('cobranca_id', $cobranca->id, $plano, $motivo, $solicitante->id, $autorizador->id, $tenantId);
            if ($plano['esgota']) {
                $cobranca->update([
                    'status' => 'cancelada',
                    'estorno_solicitado_por' => $solicitante->id,
                    'estorno_autorizado_por' => $autorizador->id,
                    'estorno_motivo' => $motivo,
                    'estorno_valor_centavos' => $plano['estornado_antes_centavos'] + $plano['valor_centavos'],
                    'estornado_em' => now(),
                ]);
            }
            app(DetalhesAuditoria::class)->anotar('valores_anteriores', $this->auditoriaDoEstorno($estorno, $plano));

            return $cobranca;
        });
    }

    /**
     * Só leitura, para o card: o que o estorno levaria AGORA (linhas, valor, já estornado, líquido). Cobrança de outro
     * tenant: ModelNotFoundException; não paga, modo ou itens inválidos: RegraDeNegocioException.
     */
    public function planoDoEstorno(int $cobrancaId, string $modo, array $itens, int $tenantId): array
    {
        $cobranca = Cobranca::where('tenant_id', $tenantId)->findOrFail($cobrancaId);
        if ($cobranca->status !== 'paga') {
            throw new RegraDeNegocioException('Só uma cobrança paga é estornada (esta está '.$cobranca->status.').');
        }

        return $this->planoDaCobranca($cobranca, $modo, $itens, travar: false);
    }

    /** As linhas da cobrança paga com o que já foi estornado e o que resta (para o editor do card). */
    public function linhasParaEstornar(int $cobrancaId, int $tenantId): array
    {
        $cobranca = Cobranca::where('tenant_id', $tenantId)->where('status', 'paga')->findOrFail($cobrancaId);

        return $this->linhasDoEstorno('cobranca_id', $cobranca->id, $tenantId, $cobranca->itens()->orderBy('id')->get());
    }

    private function planoDaCobranca(Cobranca $cobranca, string $modo, array $itens, bool $travar): array
    {
        $consulta = $cobranca->itens()->orderBy('id');
        $linhas = $this->linhasDoEstorno('cobranca_id', $cobranca->id, $cobranca->tenant_id, ($travar ? $consulta->lockForUpdate() : $consulta)->get(), $travar);

        return $this->planoDoEstornoDe($linhas, $this->escolherDoEstorno($linhas, $modo, $itens, 'cobrança'), (int) $cobranca->total_centavos);
    }

    /**
     * Cobrança cancelada (aberta ou fechada; o estorno devolve por linha, no EstornoPorItens): as linhas AVULSAS que baixaram estoque devolvem o que baixaram, em
     * ordem de item (trava sem deadlock). Linha vinda do prontuário não volta: o produto foi consumido no
     * atendimento (e não baixa de novo se o item for cobrado em outra cobrança; ver importarDoProntuario).
     */
    private function devolverEstoqueDe(Cobranca $cobranca): void
    {
        foreach ($cobranca->itens()->where('baixou_estoque', true)->whereNull('prontuario_item_id')->orderBy('catalogo_item_id')->lockForUpdate()->get() as $linha) {
            $this->catalogo->devolverEstoque($linha->catalogo_item_id, $linha->quantidade, $cobranca->tenant_id);
        }
    }

    private function aberta(int $id, int $tenantId): Cobranca
    {
        $cobranca = Cobranca::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);

        if ($cobranca->status !== 'aberta') {
            throw new RegraDeNegocioException('A cobrança está '.$cobranca->status.': só a aberta recebe ou perde itens.');
        }

        return $cobranca;
    }

    /**
     * Uma linha com a CÓPIA do nome comercial e do preço de agora; subtotal em centavos (inteiro).
     * Produto com controle de estoque baixa aqui (sem saldo: exceção e rollback de tudo).
     */
    private function lancar(Cobranca $cobranca, CatalogoItem $item, int $quantidade, ?int $prontuarioItemId = null, bool $baixar = true): void
    {
        $baixou = $baixar && $this->catalogo->baixarEstoque($item, $quantidade, $cobranca->tenant_id);

        CobrancaItem::create([
            'cobranca_id' => $cobranca->id,
            'catalogo_item_id' => $item->id,
            'prontuario_item_id' => $prontuarioItemId,
            'descricao' => $item->nome_comercial,
            'preco_unitario_centavos' => $item->preco_centavos,
            'quantidade' => $quantidade,
            'subtotal_centavos' => $item->preco_centavos * $quantidade,
            'baixou_estoque' => $baixou,
            'tenant_id' => $cobranca->tenant_id,
        ]);
    }

    private function recalcular(Cobranca $cobranca): Cobranca
    {
        $cobranca->update(['total_centavos' => (int) $cobranca->itens()->sum('subtotal_centavos')]);

        return $cobranca;
    }
}
