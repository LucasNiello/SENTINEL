<?php

namespace App\Support;

/**
 * Dinheiro para o humano em reais (R$ 0,00); o banco e as regras seguem em centavos inteiros. Texto que a pessoa digita
 * num card vira centavos aqui; centavos viram "80,00" para mostrar no card.
 */
final class Dinheiro
{
    /** 8000 → "80,00". Sem separador de milhar, para o campo aceitar de volta sem ambiguidade. */
    public static function emReais(int $centavos): string
    {
        return ($centavos < 0 ? '-' : '').intdiv(abs($centavos), 100).','.str_pad((string) (abs($centavos) % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * "80", "80,5", "80,50", "1.250,50", "1250.50", "R$ 80,00" → centavos. Até 2 casas decimais; negativo, letra, mais
     * de 2 casas ou formato ambíguo ("1.250" sem decimais é R$ 1.250,00 só se o ponto separar milhar) → null.
     */
    public static function paraCentavos(mixed $texto): ?int
    {
        if (! is_string($texto)) {
            return null;
        }
        $t = trim(str_ireplace(['R$', ' '], '', $texto));
        if ($t === '' || preg_match('/^[0-9.,]+$/', $t) !== 1) {
            return null;
        }

        if (str_contains($t, ',')) {
            // Vírgula é o separador decimal; ponto antes dela é milhar.
            if (substr_count($t, ',') !== 1) {
                return null;
            }
            [$inteira, $decimal] = explode(',', $t);
            $inteira = str_replace('.', '', $inteira);
        } elseif (preg_match('/^\d+\.\d{1,2}$/', $t) === 1) {
            [$inteira, $decimal] = explode('.', $t); // "1250.50": ponto decimal (estilo americano)
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $t) === 1) {
            [$inteira, $decimal] = [str_replace('.', '', $t), '']; // "1.250": milhar
        } elseif (preg_match('/^\d+$/', $t) === 1) {
            [$inteira, $decimal] = [$t, ''];
        } else {
            return null;
        }

        if ($inteira === '' || preg_match('/^\d+$/', $inteira) !== 1 || ($decimal !== '' && preg_match('/^\d{1,2}$/', $decimal) !== 1)) {
            return null;
        }
        if (strlen($inteira) > 12) {
            return null; // nunca estoura o inteiro
        }

        return (int) $inteira * 100 + (int) str_pad($decimal, 2, '0');
    }
}
