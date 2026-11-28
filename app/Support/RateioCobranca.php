<?php

namespace App\Support;

use App\Exceptions\RegraDeNegocioException;

/** Rateio cumulativo por ID: centavos inteiros, sem ponto flutuante e sem overflow no produto. */
final class RateioCobranca
{
    public static function proporcao(int $valor, int $parte, int $todo): int
    {
        if ($valor < 0 || $todo <= 0 || $parte < 0 || $parte > $todo) {
            throw new RegraDeNegocioException('Valores incompatíveis para o rateio da cobrança.');
        }
        // floor(valor * parte / todo): separa o quociente e multiplica o resto por duplicação modular.
        // Cada soma compara antes de somar; nenhum intermediário ultrapassa o inteiro suportado.
        $resultado = intdiv($valor, $todo) * $parte;
        $r = 0;
        $q = 0;
        $tr = $valor % $todo;
        $tq = 0;
        for ($n = $parte; $n > 0; $n = intdiv($n, 2)) {
            if ($n % 2 === 1) {
                $q += $tq;
                if ($r >= $todo - $tr) {
                    $r -= $todo - $tr;
                    $q++;
                } else {
                    $r += $tr;
                }
            }
            if ($n > 1) {
                $tq *= 2;
                if ($tr >= $todo - $tr) {
                    $tr -= $todo - $tr;
                    $tq++;
                } else {
                    $tr *= 2;
                }
            }
        }

        return $resultado + $q;
    }

    /** @param array<int, int> $subtotais ID => subtotal; retorno ID => líquido. */
    public static function liquidos(array $subtotais, int $desconto): array
    {
        ksort($subtotais, SORT_NUMERIC);
        $total = array_sum($subtotais);
        if (! is_int($total) || $desconto < 0 || $desconto > $total || min([0, ...$subtotais]) < 0) {
            throw new RegraDeNegocioException('Subtotal ou desconto incompatível com o rateio.');
        }
        $acumulado = $anterior = 0;
        $liquidos = [];
        foreach ($subtotais as $id => $subtotal) {
            $acumulado += $subtotal;
            $atual = $total === 0 ? 0 : self::proporcao($desconto, $acumulado, $total);
            $liquidos[$id] = $subtotal - ($atual - $anterior);
            $anterior = $atual;
        }

        return $liquidos;
    }
}
