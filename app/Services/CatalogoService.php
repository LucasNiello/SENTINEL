<?php

namespace App\Services;

use App\Exceptions\RegraDeNegocioException;
use App\Models\CatalogoItem;
use App\Services\Concerns\ConfereTenant;
use App\Services\Concerns\FiltroPorNome;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Clínica vet: catálogo (serviços e produtos dos dois setores) e estoque. Preço em centavos (int).
 * O estoque só muda por entradaDeEstoque/baixarEstoque/devolverEstoque, nunca pelo atualizar().
 */
class CatalogoService
{
    use ConfereTenant, FiltroPorNome;

    /** Teto técnico (centavos): preço x quantidade nunca sai do inteiro nem da coluna. */
    private const PRECO_MAXIMO_CENTAVOS = 100_000_000;

    private const CAMPOS =['tipo', 'setor', 'categoria', 'nome_comercial', 'preco_centavos', 'cobranca_por', 'controla_estoque', 'estoque', 'exige_receita'];

    public function criar(array $dados, int $tenantId): CatalogoItem
    {
        $dados = [
            'cobranca_por' => 'unidade', 'controla_estoque' => false, 'estoque' => 0, 'exige_receita' => false,
            ...Arr::only($dados, self::CAMPOS),
        ];
        $this->conferir($dados);

        return CatalogoItem::create([...$dados, 'tenant_id' => $tenantId]);
    }

    /** Preço novo vale só daqui para frente: cobranças e vendas guardam a cópia do preço da época. */
    public function atualizar(int $id, array $dados, int $tenantId): CatalogoItem
    {
        $item = CatalogoItem::where('tenant_id', $tenantId)->findOrFail($id);
        $dados = Arr::except(Arr::only($dados, self::CAMPOS), ['estoque']);
        $this->conferir([...$item->only(self::CAMPOS), ...$dados]);

        $item->update($dados);

        return $item;
    }

    public function buscarPorFiltro(?string $setor, ?string $categoria, ?string $nome, int $tenantId): Collection
    {
        return CatalogoItem::query()
            ->where('tenant_id', $tenantId)
            ->when($setor, fn ($query) => $query->where('setor', $setor))
            ->when($categoria, fn ($query) => $query->where('categoria', $categoria))
            ->when($nome, fn ($query) => $this->nomeContem($query, $nome, 'nome_comercial'))
            ->orderBy('nome_comercial')
            ->get();
    }

    /** Reposição (compra de mercadoria). */
    public function entradaDeEstoque(int $id, int $quantidade, int $tenantId): CatalogoItem
    {
        $quantidade = $this->quantidadeValida($quantidade);
        $item = CatalogoItem::where('tenant_id', $tenantId)->findOrFail($id);

        if (! $item->controla_estoque) {
            throw new RegraDeNegocioException("{$item->nome_comercial} não controla estoque.");
        }

        // Atômico e com teto: o estoque nunca passa de QUANTIDADE_MAXIMA (o atualizar reconfere esse valor).
        $entrou = CatalogoItem::whereKey($item->id)->where('estoque', '<=', self::QUANTIDADE_MAXIMA - $quantidade)->increment('estoque', $quantidade);

        if ($entrou === 0) {
            throw new RegraDeNegocioException("A entrada levaria o estoque de {$item->nome_comercial} acima de ".self::QUANTIDADE_MAXIMA.'.');
        }

        return $item->refresh();
    }

    /**
     * Baixa atômica: um UPDATE só acontece se houver saldo (estoque >= quantidade), então o estoque
     * nunca fica negativo, nem com duas vendas ao mesmo tempo. Item sem controle de estoque: nada muda.
     * Deve rodar dentro da transação de quem chama (venda ou cobrança): se faltar saldo, nada é gravado.
     * Devolve se baixou; quem chama grava isso na linha (baixou_estoque), e o estorno segue esse registro.
     * Com withTrashed: item que foi para a lixeira depois de usado no prontuário ainda tem o seu saldo.
     */
    public function baixarEstoque(CatalogoItem $item, int $quantidade, int $tenantId): bool
    {
        if (! $item->controla_estoque) {
            return false;
        }

        $baixou = CatalogoItem::withTrashed()
            ->whereKey($item->id)
            ->where('tenant_id', $tenantId)
            ->where('estoque', '>=', $this->quantidadeValida($quantidade))
            ->decrement('estoque', $quantidade);

        if ($baixou === 0) {
            $saldo = (int) CatalogoItem::withTrashed()->whereKey($item->id)->where('tenant_id', $tenantId)->value('estoque');

            throw new RegraDeNegocioException("Estoque insuficiente de {$item->nome_comercial}: há {$saldo} e foram pedidos {$quantidade}. Nada foi gravado.");
        }

        return true;
    }

    /**
     * Estorno de uma linha que BAIXOU estoque (baixou_estoque = true), venda ou cobrança cancelada. Não
     * consulta o controla_estoque de agora: ligar/desligar o controle depois não muda o que volta.
     * Dentro da transação de quem chama.
     */
    public function devolverEstoque(int $catalogoItemId, int $quantidade, int $tenantId): void
    {
        CatalogoItem::withTrashed()->whereKey($catalogoItemId)->where('tenant_id', $tenantId)->increment('estoque', $this->quantidadeValida($quantidade));
    }

    private function conferir(array $d): void
    {
        $recusar = fn (string $frase) => throw new RegraDeNegocioException($frase);

        if (! is_string($d['nome_comercial'] ?? null) || trim($d['nome_comercial']) === '') {
            $recusar('Informe o nome comercial.');
        }
        if (! in_array($d['tipo'] ?? null, CatalogoItem::TIPOS, true)) {
            $recusar('O tipo deve ser "servico" ou "produto".');
        }
        if (! in_array($d['setor'] ?? null, CatalogoItem::SETORES, true)) {
            $recusar('O setor deve ser "clinica" ou "petshop".');
        }
        if (! in_array($d['categoria'] ?? null, CatalogoItem::CATEGORIAS[$d['setor']], true)) {
            $recusar('Categoria inválida para o setor '.$d['setor'].': use '.implode(', ', CatalogoItem::CATEGORIAS[$d['setor']]).'.');
        }
        // Dinheiro só em centavos inteiros: 12.5 (float) ou "12,50" são recusados.
        if (! is_int($d['preco_centavos'] ?? null) || $d['preco_centavos'] < 0) {
            $recusar('O preço deve ser um número inteiro de centavos (ex.: 4590 para R$ 45,90).');
        }
        if ($d['preco_centavos'] > self::PRECO_MAXIMO_CENTAVOS) {
            $recusar('O preço passa do limite de R$ 1.000.000,00 por item.');
        }
        if (! in_array($d['cobranca_por'], CatalogoItem::COBRANCA_POR, true)) {
            $recusar('A cobrança deve ser por "unidade" ou por "diaria".');
        }
        if ($d['cobranca_por'] === 'diaria' && $d['tipo'] !== 'servico') {
            $recusar('Só serviço (ex.: internação) é cobrado por diária.');
        }
        if ($d['tipo'] === 'servico' && ($d['controla_estoque'] || $d['exige_receita'])) {
            $recusar('Serviço não tem estoque nem receita.');
        }
        if ($d['exige_receita'] && $d['categoria'] !== 'farmacia') {
            $recusar('Só item de farmácia pode exigir receita.');
        }
        if (! is_int($d['estoque']) || $d['estoque'] < 0 || $d['estoque'] > self::QUANTIDADE_MAXIMA) {
            $recusar('O estoque deve ser um número inteiro, de zero a '.self::QUANTIDADE_MAXIMA.'.');
        }
    }
}
