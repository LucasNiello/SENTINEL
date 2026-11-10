<?php

namespace App\Services;

use App\Exceptions\RegraDeNegocioException;
use App\Models\CatalogoItem;
use App\Services\Concerns\ConfereTenant;
use App\Services\Concerns\ExclusaoSegura;
use App\Services\Concerns\FiltroPorNome;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Clínica vet: catálogo (serviços e produtos dos dois setores) e estoque. Preço em centavos (int).
 * O estoque só muda por entradaDeEstoque/baixarEstoque/devolverEstoque, nunca pelo atualizar().
 * Decisão de 10/11: o estoque PODE ficar negativo (o saldo do sistema pode estar errado em relação à prateleira e
 * isso não trava venda nem atendimento). O estoque inicial do cadastro continua de zero ao teto.
 */
class CatalogoService
{
    use ConfereTenant, ExclusaoSegura, FiltroPorNome;

    /** Lixeira: onde o item já foi usado ele não sai (as cobranças e vendas antigas guardam a cópia, mas a chave estrangeira impede a exclusão de vez). */
    private const DEPENDENTES = ['agendamentos', 'prontuarioItens', 'cobrancaItens', 'vendaItens'];

    private const PAIS = [];

    /** Teto técnico (centavos): preço x quantidade nunca sai do inteiro nem da coluna. */
    private const PRECO_MAXIMO_CENTAVOS = 100_000_000;

    private const CAMPOS =['tipo', 'setor', 'categoria', 'nome_comercial', 'preco_centavos', 'cobranca_por', 'controla_estoque', 'estoque', 'exige_receita'];

    public function criar(array $dados, int $tenantId): CatalogoItem
    {
        $dados = [
            'cobranca_por' => 'unidade', 'controla_estoque' => false, 'estoque' => 0, 'exige_receita' => false,
            ...Arr::only($dados, self::CAMPOS),
        ];
        $this->conferir($dados, criacao: true);

        return CatalogoItem::create([...$dados, 'tenant_id' => $tenantId]);
    }

    /** Preço novo vale só daqui para frente: cobranças e vendas guardam a cópia do preço da época. */
    public function atualizar(int $id, array $dados, int $tenantId): CatalogoItem
    {
        $item = CatalogoItem::where('tenant_id', $tenantId)->findOrFail($id);
        $dados = Arr::except(Arr::only($dados, self::CAMPOS), ['estoque']);
        // O estoque de agora não é conferido aqui: ele pode estar negativo e isso não impede corrigir preço ou nome.
        $this->conferir([...$item->only(self::CAMPOS), ...$dados], criacao: false);

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

    /** Acha o item por id ou por parte do nome comercial, só no tenant; por padrão fora da lixeira. Até 6. */
    public function localizar(?int $id, ?string $busca, int $tenantId, string $situacao = 'ativo'): Collection
    {
        return CatalogoItem::query()
            ->naSituacao($situacao)
            ->where('tenant_id', $tenantId)
            ->when($id, fn ($query) => $query->whereKey($id), fn ($query) => $this->nomeContem($query, (string) $busca, 'nome_comercial'))
            ->limit(6)
            ->get();
    }

    /** @return array{bloqueado: bool, motivo?: string} */
    public function excluir(int $id, int $tenantId): array
    {
        return $this->excluirComBloqueio(CatalogoItem::where('tenant_id', $tenantId)->findOrFail($id), self::DEPENDENTES);
    }

    /** @return array{restaurado: bool, motivo?: string} */
    public function restaurarDaLixeira(int $id, int $tenantId, bool $reautenticadoComoAdmin): array
    {
        return $this->restaurar(CatalogoItem::onlyTrashed()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id), $reautenticadoComoAdmin);
    }

    /** @return array{excluido: bool, motivo?: string} */
    public function excluirDefinitivo(int $id, int $tenantId, ?int $autorizadoPor): array
    {
        return $this->excluirDeVez(CatalogoItem::onlyTrashed()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id), $autorizadoPor);
    }

    /**
     * Frases para o card antes do segurar (o card mostra em aviso, sem bloquear): os itens destes ids que controlam
     * estoque e estão com saldo negativo AGORA. A prévia chama isto dentro da transação que vai desfazer, então o
     * saldo lido é o que a operação deixaria.
     *
     * @param  int[]  $ids
     * @return string[]
     */
    public function avisosDeSaldoNegativo(array $ids, int $tenantId): array
    {
        return CatalogoItem::withTrashed()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->where('controla_estoque', true)
            ->where('estoque', '<', 0)
            ->orderBy('nome_comercial')
            ->get(['nome_comercial', 'estoque'])
            ->map(fn (CatalogoItem $i) => "Estoque de {$i->nome_comercial} ficará em {$i->estoque}. Confira a prateleira; a operação não é bloqueada.")
            ->all();
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
     * Baixa atômica: um único UPDATE relativo (estoque = estoque - quantidade), então duas vendas ao mesmo tempo
     * nunca perdem uma baixa. Sem saldo, a baixa acontece do mesmo jeito e o estoque fica NEGATIVO (decisão de
     * 10/11: o saldo pode estar errado e não pode travar a venda nem o atendimento; o card avisa antes do segurar).
     * Item sem controle de estoque: nada muda. Roda dentro da transação de quem chama (venda ou cobrança).
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
            ->decrement('estoque', $this->quantidadeValida($quantidade));

        // Nenhuma linha: o item não é deste tenant (nunca é "falta de saldo": saldo negativo é permitido).
        if ($baixou === 0) {
            throw new RegraDeNegocioException('Não encontrei o item do catálogo #'.$item->id.'.');
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

    private function conferir(array $d, bool $criacao): void
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
        // Só o estoque inicial do cadastro: depois ele muda por entrada, venda e cobrança, e pode ficar negativo.
        if ($criacao && (! is_int($d['estoque']) || $d['estoque'] < 0 || $d['estoque'] > self::QUANTIDADE_MAXIMA)) {
            $recusar('O estoque deve ser um número inteiro, de zero a '.self::QUANTIDADE_MAXIMA.'.');
        }
    }
}
