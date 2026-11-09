<?php

namespace App\Services;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Agendamento;
use App\Models\CatalogoItem;
use App\Models\Cobranca;
use App\Models\CobrancaItem;
use App\Models\Prontuario;
use App\Models\User;
use App\Services\Concerns\ConfereTenant;
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
 * - produto com controle de estoque baixa o estoque ao entrar na cobrança (mesma transação, nunca
 *   negativo). Item removido devolve. Cobrança cancelada ou estornada devolve só os itens avulsos: o que
 *   veio do prontuário foi aplicado no animal (não volta, e não baixa de novo numa cobrança seguinte);
 * - cancelada não conta: o agendamento pode ganhar uma cobrança nova.
 * Nenhum texto clínico passa para a cobrança: só o nome comercial do item.
 */
class CobrancaService
{
    use ConfereTenant;

    /** Quem pode pedir o estorno, por setor da cobrança (o dono pede em qualquer setor). */
    private const SOLICITANTE_DO_ESTORNO = ['clinica' => 'recepcao', 'petshop' => 'atendente'];

    private const MOTIVO_MAXIMO = 500;

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
            if (Cobranca::where('agendamento_id', $agendamento->id)->where('status', '!=', 'cancelada')->exists()) {
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

            $jaLancados = CobrancaItem::whereNotNull('prontuario_item_id')->where('cobranca_id', $cobranca->id)->pluck('prontuario_item_id')->all();
            // Em ordem de item do catálogo: a baixa de estoque trava na mesma ordem da venda (sem deadlock).
            $novos = $prontuario->itens()->whereNotIn('id', $jaLancados)->orderBy('catalogo_item_id')->orderBy('id')->get();

            if ($novos->isEmpty()) {
                throw new RegraDeNegocioException('Os itens deste prontuário já estão na cobrança.');
            }

            foreach ($novos as $itemDoProntuario) {
                $item = CatalogoItem::withTrashed()->where('tenant_id', $tenantId)->findOrFail($itemDoProntuario->catalogo_item_id);
                // Diária: os dias de calendário da internação; sem entrada/alta, vale a quantidade registrada.
                $quantidade = $item->cobranca_por === 'diaria' ? ($prontuario->diarias() ?? $itemDoProntuario->quantidade) : $itemDoProntuario->quantidade;
                // Já consumido numa cobrança cancelada antes (lá ficou baixou_estoque = true e não voltou): não baixa de novo.
                $jaConsumido = CobrancaItem::where('tenant_id', $tenantId)->where('prontuario_item_id', $itemDoProntuario->id)->where('baixou_estoque', true)->exists();
                $this->lancar($cobranca, $item, $quantidade, $itemDoProntuario->id, baixar: ! $jaConsumido);
            }

            return $this->recalcular($cobranca);
        });
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
     * Estorno: cobrança PAGA -> cancelada, com duas pessoas. Pede: a recepção (cobrança da clínica), o
     * atendente (do petshop) ou o dono. Autoriza: o dono, ou um supervisor do setor da cobrança (marcado
     * pelo dono) que já definiu o PIN. Quem pede não autoriza o próprio pedido, salvo o dono. Motivo
     * obrigatório; quem pediu, quem autorizou, o motivo, o valor e a hora ficam gravados na cobrança.
     *
     * A conferência do PIN do supervisor (ou da senha do dono) NÃO é feita aqui: fica no card (Fase B),
     * que passa o resultado em $autorizacaoConferida. Sem ela (false), o estorno é recusado.
     * Itens que vieram do prontuário não voltam ao estoque: foram aplicados no animal.
     */
    public function estornar(int $cobrancaId, string $motivo, int $solicitanteId, int $autorizadorId, int $tenantId, bool $autorizacaoConferida): Cobranca
    {
        return DB::transaction(function () use ($cobrancaId, $motivo, $solicitanteId, $autorizadorId, $tenantId, $autorizacaoConferida) {
            $cobranca = Cobranca::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($cobrancaId);

            // Falha fechada (padrão do restaurarDaLixeira): sem a conferência do autorizador no card, nada muda.
            if (! $autorizacaoConferida) {
                throw new RegraDeNegocioException('O estorno precisa da confirmação de quem autoriza (PIN do supervisor ou senha do dono).');
            }

            if ($cobranca->status !== 'paga') {
                throw new RegraDeNegocioException('Só uma cobrança paga é estornada (esta está '.$cobranca->status.'); aberta ou fechada é cancelada.');
            }

            $motivo = trim($motivo);
            if ($motivo === '') {
                throw new RegraDeNegocioException('Informe o motivo do estorno.');
            }
            if (mb_strlen($motivo) > self::MOTIVO_MAXIMO) {
                throw new RegraDeNegocioException('O motivo do estorno passa de '.self::MOTIVO_MAXIMO.' caracteres.');
            }

            $solicitante = $this->doTenant(User::class, $solicitanteId, $tenantId, 'quem pediu o estorno');
            $dono = $solicitante->papel === 'dono';
            if (! $dono && $solicitante->papel !== self::SOLICITANTE_DO_ESTORNO[$cobranca->setor]) {
                throw new RegraDeNegocioException('O estorno de uma cobrança do setor '.$cobranca->setor.' é pedido pela '
                    .($cobranca->setor === 'clinica' ? 'recepção' : 'equipe de atendimento do petshop').' ou pelo dono.');
            }

            $autorizador = $this->doTenant(User::class, $autorizadorId, $tenantId, 'quem autorizou o estorno');
            if ($autorizador->id === $solicitante->id && ! $dono) {
                throw new RegraDeNegocioException('Quem pede o estorno não pode autorizá-lo: chame o dono ou outro supervisor do setor.');
            }
            if ($autorizador->papel !== 'dono') {
                if ($autorizador->supervisor_setor !== $cobranca->setor) {
                    throw new RegraDeNegocioException('Só o dono ou um supervisor do setor '.$cobranca->setor.' autoriza este estorno.');
                }
                if ($autorizador->pin_autorizacao === null) {
                    throw new RegraDeNegocioException('Este supervisor ainda não definiu o PIN de autorização: não pode autorizar.');
                }
            }

            $this->devolverEstoqueDe($cobranca);
            $cobranca->update([
                'status' => 'cancelada',
                'estorno_solicitado_por' => $solicitante->id,
                'estorno_autorizado_por' => $autorizador->id,
                'estorno_motivo' => $motivo,
                'estorno_valor_centavos' => $cobranca->total_centavos,
                'estornado_em' => now(),
            ]);

            return $cobranca;
        });
    }

    /**
     * Cobrança cancelada ou estornada: as linhas AVULSAS que baixaram estoque devolvem o que baixaram, em
     * ordem de item (trava sem deadlock). Linha vinda do prontuário não volta: o produto foi consumido no
     * atendimento (e não baixa de novo se o item for cobrado em outra cobrança; ver importarDoProntuario).
     */
    private function devolverEstoqueDe(Cobranca $cobranca): void
    {
        foreach ($cobranca->itens()->where('baixou_estoque', true)->whereNull('prontuario_item_id')->orderBy('catalogo_item_id')->get() as $linha) {
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
