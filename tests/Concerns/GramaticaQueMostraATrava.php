<?php

namespace Tests\Concerns;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;

/**
 * Só para os testes: o SQLite ignora FOR UPDATE (compileLock devolve texto vazio) e por isso uma leitura
 * travada e uma leitura comum geram o mesmo SQL. Esta gramática deixa uma marca no SQL de toda leitura
 * travada, e o teste procura a marca no log de consultas. Sem a trava no código, a marca não aparece.
 */
class GramaticaQueMostraATrava extends SQLiteGrammar
{
    public const MARCA = '/*LEITURA-TRAVADA*/';

    protected function compileLock(Builder $query, $value)
    {
        return $value ? ' '.self::MARCA : '';
    }
}
