<?php

namespace App\Services;

use Illuminate\Support\Str;

/** Vocabulário explícito de comandos; não infere intenção de nomes, citações ou histórico. */
class OperacaoSolicitada
{
    private const VERBOS = [
        'exclua' => 'excluir', 'excluir' => 'excluir',
        'delete' => 'excluir', 'deletar' => 'excluir',
        'remova' => 'excluir', 'remover' => 'excluir',
        'apague' => 'excluir', 'apagar' => 'excluir',
        'cadastre' => 'criar', 'cadastrar' => 'criar', 'crie' => 'criar', 'criar' => 'criar',
        'registre' => 'criar', 'registrar' => 'criar', 'adicione' => 'criar', 'adicionar' => 'criar',
        'altere' => 'atualizar', 'alterar' => 'atualizar', 'atualize' => 'atualizar', 'atualizar' => 'atualizar',
        'edite' => 'atualizar', 'editar' => 'atualizar', 'restaure' => 'restaurar', 'restaurar' => 'restaurar',
    ];

    private const ENTIDADES = [
        'categoria de lancamento' => 'CategoriaLancamento', 'categoria' => 'CategoriaLancamento',
        'nota fiscal' => 'NotaFiscal', 'cliente' => 'Cliente', 'fornecedor' => 'Fornecedor',
        'funcionario' => 'Funcionario', 'lancamento' => 'Lancamento',
    ];

    /**
     * Reconhece verbo no início de um pedido, com prefixos de cortesia/desejo e objeto explícito.
     * A cauda (nome, id, valores) continua sendo interpretada/validada pelo fluxo existente.
     * Sem correspondência inequívoca, não inventa entidade nem usa uma mensagem anterior.
     *
     * @return array{acao: string, entidade: string}|null
     */
    public function reconhecer(string $mensagem): ?array
    {
        $texto = Str::lower(Str::ascii(trim($mensagem)));
        $texto = preg_replace('/\s+/', ' ', $texto);
        $texto = preg_replace('/^(?:por favor[, ]+)?(?:eu )?(?:(?:quero|desejo|preciso|gostaria)(?: de)? |(?:voce )?(?:pode|poderia) )?(?:por favor[, ]+)?/', '', $texto);
        [$verbo, $objeto] = array_pad(explode(' ', $texto, 2), 2, '');
        $acao = self::VERBOS[$verbo] ?? null;
        if ($acao === null) return null;

        // Exclusão definitiva é uma operação distinta; nunca convertê-la em exclusão lógica.
        if ($acao === 'excluir' && preg_match('/^(?:definitivamente|de vez)\b ?/', $objeto, $m)) {
            $acao = 'excluir_definitivo';
            $objeto = substr($objeto, strlen($m[0]));
        }
        $objeto = preg_replace('/^(?:o|a|um|uma) /', '', $objeto);
        foreach (self::ENTIDADES as $nome => $entidade) {
            if ($objeto === $nome || str_starts_with($objeto, $nome.' ')) {
                // Também aceita "exclua o cliente de vez ...".
                if ($acao === 'excluir' && preg_match('/^'.preg_quote($nome, '/').' (?:definitivamente|de vez)\b/', $objeto)) {
                    $acao = 'excluir_definitivo';
                }
                return ['acao' => $acao, 'entidade' => $entidade];
            }
        }
        return null;
    }
}
