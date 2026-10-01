<?php

namespace App\Services\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * B1: filtro "contém" por nome em que %, _ e \ digitados são literais, não curingas
 * (um "%" não pode devolver tudo do tenant). O caractere de escape é "!" porque a
 * sintaxe é a mesma no SQLite (testes), no MySQL (dev) e no ilike do Postgres; com
 * ele, a barra invertida deixa de ser escape e também vira literal.
 */
trait FiltroPorNome
{
    protected function nomeContem(Builder $query, string $nome): Builder
    {
        $termo = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $nome);

        return $query->whereRaw("nome like ? escape '!'", ["%{$termo}%"]);
    }
}
