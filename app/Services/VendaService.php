<?php

namespace App\Services;

use App\Exceptions\RegraDeNegocioException;
use App\Models\CatalogoItem;
use App\Models\Tutor;
use App\Models\User;
use App\Models\Venda;
use App\Models\VendaItem;
use App\Services\Concerns\ConfereTenant;
use Illuminate\Support\Facades\DB;

/**
 * Clínica vet: venda de balcão (loja do petshop ou farmácia da clínica). Regras:
 * - só produtos, todos do mesmo setor; na clínica, só farmácia;
 * - item que exige receita NÃO é vendido por ninguém (agente, vendedor, recepção ou veterinário);
 * - descrição e preço são cópias do catálogo; total em centavos calculado aqui;
 * - a baixa do estoque acontece na MESMA transação da venda e nunca deixa saldo negativo: faltou
 *   saldo de um item, nada da venda é gravado;
 * - com forma de pagamento, a venda já nasce paga; sem, fica aberta. Cancelar (só aberta) devolve o estoque.
 */
class VendaService
{
    use ConfereTenant;

    public function __construct(private CatalogoService $catalogo)
    {
    }

    /** @param  array<int, array{catalogo_item_id: mixed, quantidade: int}>  $itens */
    public function registrar(array $itens, int $vendedorId, ?int $tutorId, ?string $formaPagamento, int $tenantId): Venda
    {
        return DB::transaction(function () use ($itens, $vendedorId, $tutorId, $formaPagamento, $tenantId) {
            $this->doTenant(User::class, $vendedorId, $tenantId, 'o vendedor');
            if ($tutorId !== null) {
                $this->doTenant(Tutor::class, $tutorId, $tenantId, 'o tutor');
            }
            if ($formaPagamento !== null && ! in_array($formaPagamento, ['dinheiro', 'pix', 'cartao'], true)) {
                throw new RegraDeNegocioException('A forma de pagamento deve ser dinheiro, pix ou cartao.');
            }

            $linhas = $this->conferirItens($itens, $tenantId);

            $venda = Venda::create([
                'setor' => $linhas[0]['item']->setor,
                'vendedor_id' => $vendedorId,
                'tutor_id' => $tutorId,
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

            if ($item->tipo !== 'produto') {
                throw new RegraDeNegocioException("{$item->nome_comercial} é um serviço: serviço é cobrado no agendamento, não vendido no balcão.");
            }
            if ($item->exige_receita) {
                throw new RegraDeNegocioException("{$item->nome_comercial} exige receita e não pode ser vendido no balcão.");
            }
            if ($item->setor === 'clinica' && $item->categoria !== 'farmacia') {
                throw new RegraDeNegocioException("Na clínica, só itens de farmácia são vendidos no balcão.");
            }

            $linhas[] = ['item' => $item, 'quantidade' => $quantidade];
        }

        if (count(array_unique(array_map(fn (array $l) => $l['item']->setor, $linhas))) > 1) {
            throw new RegraDeNegocioException('Uma venda é de um setor só: separe os itens da loja e os da farmácia.');
        }

        return $linhas;
    }
}
