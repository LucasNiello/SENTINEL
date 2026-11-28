<?php

namespace App\Services\Concerns;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Estorno;
use App\Models\EstornoItem;
use App\Models\User;
use App\Support\RateioCobranca;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * T2 (14/11): estorno por itens, igual para a cobrança e para a venda (resposta E). Regras:
 * - por linha, a quantidade vai de 1 até o que ainda não foi estornado; vários estornos parciais até esgotar;
 * - valor = preço COPIADO no lançamento (preco_unitario_centavos da linha) × quantidade; nunca o preço atual do catálogo,
 *   na cobrança, desconta o rateio geral e usa o líquido cumulativo por quantidade; nunca um valor informado pela IA;
 * - estoque volta só para a linha avulsa que baixou estoque; a linha do prontuário nunca devolve (resposta D);
 * - modos: "tudo" (tudo o que resta), "tudo_menos_prontuario" (tudo o que resta, sem as linhas do prontuário) e "itens"
 *   (a lista [{item_id, quantidade}]).
 * Quem usa: trava o registro pai e as linhas (lockForUpdate) antes de planejar e grava tudo numa transação.
 * Precisa do ConfereTenant e de $this->catalogo (CatalogoService) na classe que usa.
 */
trait EstornoPorItens
{
    /** Quem pode pedir o estorno, por setor do registro (o dono pede em qualquer setor). */
    private static array $solicitanteDoEstorno = ['clinica' => 'recepcao', 'petshop' => 'atendente'];

    private static int $motivoMaximoDoEstorno = 500;

    /**
     * Cada linha do registro com o que já foi estornado e o que resta (só leitura).
     *
     * @param  Collection<int, Model>  $linhas  cobranca_itens ou venda_itens do registro, em ordem de id
     * @return array<int, array{item_id: int, catalogo_item_id: int, descricao: string, prontuario: bool, lancada: int, estornada: int, restante: int, preco_unitario_centavos: int, baixou_estoque: bool}>
     */
    private function linhasDoEstorno(string $colunaPai, int $paiId, int $tenantId, Collection $linhas, bool $travar = false): array
    {
        // Ao gravar ($travar), a leitura é TRAVADA: lê sempre o dado mais novo (no InnoDB, uma leitura simples dentro da
        // transação usaria a foto do 1º SELECT dela). A soma é feita aqui, linha a linha (FOR UPDATE com GROUP BY, não).
        $consulta = EstornoItem::query()
            ->join('estornos', 'estornos.id', '=', 'estorno_itens.estorno_id')
            ->where('estornos.tenant_id', $tenantId)
            ->where('estornos.'.$colunaPai, $paiId)
            ->select(['estorno_itens.item_id', 'estorno_itens.quantidade', 'estorno_itens.valor_centavos']);
        $estornadas = [];
        $valores = [];
        foreach (($travar ? $consulta->lockForUpdate() : $consulta)->get() as $ja) {
            $valores[(int) $ja->item_id] = ($valores[(int) $ja->item_id] ?? 0) + (int) $ja->valor_centavos;
            $estornadas[(int) $ja->item_id] = ($estornadas[(int) $ja->item_id] ?? 0) + (int) $ja->quantidade;
        }

        return $linhas->map(function (Model $l) use ($estornadas, $valores) {
            $estornada = $estornadas[$l->id] ?? 0;

            return [
                'item_id' => (int) $l->id,
                'catalogo_item_id' => (int) $l->catalogo_item_id,
                'descricao' => (string) $l->descricao,
                'prontuario' => $l->getAttribute('prontuario_item_id') !== null,
                'lancada' => (int) $l->quantidade,
                'estornada' => $estornada,
                'estornado_centavos' => $valores[$l->id] ?? 0,
                'restante' => max(0, (int) $l->quantidade - $estornada),
                'preco_unitario_centavos' => (int) $l->preco_unitario_centavos,
                'baixou_estoque' => (bool) $l->baixou_estoque,
            ];
        })->values()->all();
    }

    /**
     * O que este estorno leva de cada linha, pelo modo. Recusa (RegraDeNegocioException) o modo desconhecido, item de
     * fora do registro, quantidade fora de 1..restante e o estorno vazio.
     *
     * @param  array<int, array<string, mixed>>  $linhas  de linhasDoEstorno()
     * @return array<int, int> item_id => quantidade (só as escolhidas, quantidade >= 1)
     */
    private function escolherDoEstorno(array $linhas, mixed $modo, mixed $itens, string $oQue): array
    {
        $porId = array_column($linhas, null, 'item_id');

        $escolha = match ($modo) {
            'tudo' => array_map(fn (array $l) => $l['restante'], $porId),
            'tudo_menos_prontuario' => array_map(fn (array $l) => $l['prontuario'] ? 0 : $l['restante'], $porId),
            'itens' => $this->itensEscolhidos($itens, $porId, $oQue),
            default => throw new RegraDeNegocioException('Escolha o que estornar: tudo, tudo menos o que veio do prontuário, ou os itens.'),
        };
        $escolha = array_filter($escolha, fn (int $q) => $q >= 1);

        if ($escolha === []) {
            throw new RegraDeNegocioException(match ($modo) {
                'itens' => 'Escolha pelo menos um item e a quantidade a estornar.',
                'tudo_menos_prontuario' => 'Não há item avulso a estornar: o que resta veio do prontuário.',
                default => 'Não há mais nada a estornar n'.($oQue === 'venda' ? 'esta venda.' : 'esta cobrança.'),
            });
        }

        return $escolha;
    }

    /** @return array<int, int> */
    private function itensEscolhidos(mixed $itens, array $porId, string $oQue): array
    {
        if (! is_array($itens)) {
            throw new RegraDeNegocioException('Escolha pelo menos um item e a quantidade a estornar.');
        }

        $escolha = [];
        foreach ($itens as $linha) {
            $id = is_array($linha) ? $this->inteiroPositivo($linha['item_id'] ?? null) : null;
            if ($id === null || ! isset($porId[$id])) {
                throw new RegraDeNegocioException('Não encontrei o item'.($id === null ? '' : " #{$id}").' n'.($oQue === 'venda' ? 'esta venda.' : 'esta cobrança.'));
            }
            $quantidade = $this->inteiroPositivo($linha['quantidade'] ?? null)
                ?? throw new RegraDeNegocioException("A quantidade de {$porId[$id]['descricao']} deve ser um número inteiro de 1 até {$porId[$id]['restante']}.");
            $escolha[$id] = ($escolha[$id] ?? 0) + $quantidade;
        }

        foreach ($escolha as $id => $quantidade) {
            $restante = $porId[$id]['restante'];
            if ($quantidade > $restante) {
                throw new RegraDeNegocioException($restante === 0
                    ? "{$porId[$id]['descricao']} já foi todo estornado."
                    : "Quantidade acima do que ainda não foi estornado em {$porId[$id]['descricao']}: restam {$restante}.");
            }
        }

        return $escolha;
    }

    private function inteiroPositivo(mixed $valor): ?int
    {
        return match (true) {
            is_int($valor) && $valor >= 1 => $valor,
            is_string($valor) && preg_match('/^[1-9]\d{0,8}$/', trim($valor)) === 1 => (int) trim($valor),
            default => null,
        };
    }

    /**
     * O plano completo (para o card e para gravar): cada linha com a quantidade deste estorno e o valor, o total do
     * estorno, o já estornado antes e o líquido que fica. "esgota": depois deste estorno, nada mais resta.
     *
     * @return array{linhas: array<int, array<string, mixed>>, valor_centavos: int, estornado_antes_centavos: int, total_centavos: int, liquido_centavos: int, esgota: bool}
     */
    private function planoDoEstornoDe(array $linhas, array $escolha, int $totalCentavos): array
    {
        $estornadoAntes = 0;
        $valor = 0;
        $resta = 0;
        foreach ($linhas as $i => $l) {
            $ja = $l['estornada'] * $l['preco_unitario_centavos'];
            if (isset($l['liquido_linha_centavos'])) {
                $ja = RateioCobranca::proporcao($l['liquido_linha_centavos'], $l['estornada'], $l['lancada']);
                if ($ja !== $l['estornado_centavos']) {
                    throw new RegraDeNegocioException('Histórico de estornos incompatível com o rateio. Confira a cobrança antes de estornar.');
                }
            }
            $estornadoAntes += $ja;
            $quantidade = $escolha[$l['item_id']] ?? 0;
            $linhas[$i]['quantidade'] = $quantidade;
            $linhas[$i]['valor_centavos'] = isset($l['liquido_linha_centavos'])
                ? RateioCobranca::proporcao($l['liquido_linha_centavos'], $l['estornada'] + $quantidade, $l['lancada']) - $ja
                : $quantidade * $l['preco_unitario_centavos'];
            // Resposta D: o que veio do prontuário foi aplicado no animal; só o valor é estornado.
            $linhas[$i]['devolve_estoque'] = $quantidade >= 1 && $l['baixou_estoque'] && ! $l['prontuario'];
            $valor += $linhas[$i]['valor_centavos'];
            $resta += $l['restante'] - $quantidade;
        }

        if ($valor < 0 || $estornadoAntes + $valor > $totalCentavos) {
            throw new RegraDeNegocioException('O estorno ultrapassaria o valor líquido pago.');
        }

        return [
            'linhas' => $linhas,
            'valor_centavos' => $valor,
            'estornado_antes_centavos' => $estornadoAntes,
            'total_centavos' => $totalCentavos,
            'liquido_centavos' => $totalCentavos - $estornadoAntes - $valor,
            'esgota' => $resta === 0,
        ];
    }

    /**
     * Motivo, quem pede e quem autoriza (as regras do estorno de cobrança, H, valem para a venda: resposta E). Quem pede:
     * a recepção (setor clínica), o atendente (petshop) ou o dono. Autoriza: o dono ou um supervisor do setor, ambos
     * com PIN (T1); quem pede não autoriza o próprio pedido, salvo o dono; o Administrador nunca.
     *
     * @return array{0: string, 1: User, 2: User} motivo aparado, solicitante, autorizador
     */
    private function conferirQuemEstorna(string $setor, string $motivo, int $solicitanteId, int $autorizadorId, int $tenantId, string $oQue): array
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new RegraDeNegocioException('Informe o motivo do estorno.');
        }
        if (mb_strlen($motivo) > self::$motivoMaximoDoEstorno) {
            throw new RegraDeNegocioException('O motivo do estorno passa de '.self::$motivoMaximoDoEstorno.' caracteres.');
        }

        $nomeDoSetor = $setor === 'clinica' ? 'clínica' : $setor;
        $solicitante = $this->doTenant(User::class, $solicitanteId, $tenantId, 'quem pediu o estorno');
        $dono = $solicitante->papel === 'dono';
        if (! $dono && $solicitante->papel !== self::$solicitanteDoEstorno[$setor]) {
            throw new RegraDeNegocioException('O estorno de uma '.$oQue.' do setor '.$nomeDoSetor.' é pedido pela '
                .($setor === 'clinica' ? 'recepção' : 'equipe de atendimento do petshop').' ou pelo dono.');
        }

        $autorizador = $this->doTenant(User::class, $autorizadorId, $tenantId, 'quem autorizou o estorno');
        if ($autorizador->ativo === false) {
            throw new RegraDeNegocioException('Quem autorizou o estorno está com a conta desativada.');
        }
        if ($autorizador->id === $solicitante->id && ! $dono) {
            throw new RegraDeNegocioException('Quem pede o estorno não pode autorizá-lo: chame o dono ou outro supervisor do setor.');
        }
        // Papel que saiu da lista de papéis válidos não autoriza nada, mesmo com a marcação e o PIN ainda gravados.
        if (! in_array($autorizador->papel, (array) config('sentinel.papeis', []), true)) {
            throw new RegraDeNegocioException('Quem autorizou não tem um perfil válido no sistema: o estorno não pode ser autorizado por essa pessoa.');
        }
        // T1 (resposta F): o Administrador só gerencia PINs; nunca autoriza, mesmo com um código ou marcação antiga.
        if ($autorizador->papel === 'admin') {
            throw new RegraDeNegocioException('O Administrador não autoriza estorno: chame o dono ou um supervisor do setor.');
        }
        if ($autorizador->papel === 'dono' && $autorizador->pin_autorizacao === null) {
            throw new RegraDeNegocioException('O dono ainda não tem PIN de autorização: ele ou o Administrador gera um na tela Supervisão.');
        }
        if ($autorizador->papel !== 'dono') {
            if ($autorizador->supervisor_setor !== $setor) {
                throw new RegraDeNegocioException('Só o dono ou um supervisor do setor '.$nomeDoSetor.' autoriza este estorno.');
            }
            if ($autorizador->pin_autorizacao === null) {
                throw new RegraDeNegocioException('Este supervisor ainda não tem PIN de autorização: peça ao dono ou ao Administrador para gerar um.');
            }
        }

        return [$motivo, $solicitante, $autorizador];
    }

    /**
     * Grava o estorno e as linhas, e devolve ao estoque o que a linha avulsa baixou (na ordem do item do catálogo: a
     * trava do estoque segue a mesma ordem da venda e da cobrança, sem deadlock). Dentro da transação de quem chama.
     */
    private function gravarEstorno(string $colunaPai, int $paiId, array $plano, string $motivo, int $solicitanteId, int $autorizadorId, int $tenantId): Estorno
    {
        $estorno = Estorno::create([
            $colunaPai => $paiId,
            'solicitado_por' => $solicitanteId,
            'autorizado_por' => $autorizadorId,
            'motivo' => $motivo,
            'valor_centavos' => $plano['valor_centavos'],
            'tenant_id' => $tenantId,
        ]);

        $escolhidas = array_filter($plano['linhas'], fn (array $l) => $l['quantidade'] >= 1);
        usort($escolhidas, fn (array $a, array $b) => [$a['catalogo_item_id'], $a['item_id']] <=> [$b['catalogo_item_id'], $b['item_id']]);

        foreach ($escolhidas as $l) {
            if ($l['devolve_estoque']) {
                $this->catalogo->devolverEstoque($l['catalogo_item_id'], $l['quantidade'], $tenantId);
            }
            EstornoItem::create([
                'estorno_id' => $estorno->id,
                'item_id' => $l['item_id'],
                'quantidade' => $l['quantidade'],
                'valor_centavos' => $l['valor_centavos'],
                'devolveu_estoque' => $l['devolve_estoque'],
            ]);
        }

        return $estorno;
    }

    /** O que a auditoria do estorno leva (no valores_anteriores): a situação de antes e os itens deste estorno, sem preço de catálogo. */
    private function auditoriaDoEstorno(Estorno $estorno, array $plano): array
    {
        return [
            'status' => 'paga',
            'estornado_antes_centavos' => $plano['estornado_antes_centavos'],
            'estorno_id' => $estorno->id,
            'estorno_valor_centavos' => $plano['valor_centavos'],
            'itens_estornados' => array_values(array_map(fn (array $l) => [
                'item_id' => $l['item_id'], 'quantidade' => $l['quantidade'], 'valor_centavos' => $l['valor_centavos'], 'devolveu_estoque' => $l['devolve_estoque'],
            ], array_filter($plano['linhas'], fn (array $l) => $l['quantidade'] >= 1))),
        ];
    }
}
