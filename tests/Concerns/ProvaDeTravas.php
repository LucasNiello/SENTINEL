<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Prova que uma conferência é feita com leitura TRAVADA (lockForUpdate), mesmo no SQLite dos testes, que
 * ignora FOR UPDATE: troca a gramática de consultas por uma que marca o SQL travado (GramaticaQueMostraATrava)
 * e procura a marca no log. Tirar o lockForUpdate() do código faz o teste falhar. Não prova disputa real
 * entre dois pedidos (isso só no MySQL), prova que a leitura que decide é a travada.
 */
trait ProvaDeTravas
{
    protected function mostrarTravas(): void
    {
        $conexao = DB::connection();
        $conexao->setQueryGrammar(new GramaticaQueMostraATrava($conexao));
        $conexao->enableQueryLog();
    }

    /**
     * @param  callable(): mixed  $acao
     * @return string[] o SQL de cada leitura travada que $acao fez
     */
    protected function leiturasTravadas(callable $acao): array
    {
        $conexao = DB::connection();
        $conexao->flushQueryLog();
        $acao();

        return array_values(array_filter(
            array_column($conexao->getQueryLog(), 'query'),
            fn (string $sql) => str_contains($sql, GramaticaQueMostraATrava::MARCA),
        ));
    }

    /** @param  string[]  $sqls  o retorno de leiturasTravadas() */
    protected function assertTravouLeitura(array $sqls, string $tabela, string $trecho = ''): void
    {
        $achou = array_filter($sqls, fn (string $sql) => str_contains($sql, "from \"{$tabela}\"") && str_contains($sql, $trecho));

        $this->assertNotEmpty($achou, "Nenhuma leitura travada em {$tabela}".($trecho === '' ? '' : " ({$trecho})").'. Leituras travadas: '.json_encode($sqls));
    }
}
