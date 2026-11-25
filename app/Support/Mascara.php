<?php

namespace App\Support;

/**
 * Máscara única de CPF e telefone (LGPD), usada na auditoria, nas listas, nos cards, nas propostas e nas respostas do
 * agente. Mantém os 3 primeiros e os 3 últimos dígitos. Só a ficha do tutor mostra o valor completo (a recepção liga).
 *
 *   CPF       123.456.789-00     → 123.***.**9-00
 *   Celular   (19) 98765-4321    → (19) 9****-*321
 *   Fixo      (19) 3456-7890     → (19) 3***-*890
 *
 * Nulo e vazio passam como estão; valor que já tem "*" é considerado mascarado e passa intacto (idempotente); o que
 * não é número, ou é curto demais para esconder o miolo, vira "[redigido]" (nunca devolve o valor bruto).
 */
final class Mascara
{
    public const REDIGIDO = '[redigido]';

    /**
     * O valor parece uma máscara (tem "*" ou é "[redigido]")? Um valor assim NUNCA é dado real: quem recebe de um
     * formulário ou de uma tool trata como "manter o atual" e não grava.
     */
    public static function mascarado(mixed $valor): bool
    {
        return is_string($valor) && (str_contains($valor, '*') || trim($valor) === self::REDIGIDO);
    }

    public static function cpf(mixed $valor): mixed
    {
        if ($valor === null || $valor === '' || (is_string($valor) && str_contains($valor, '*'))) {
            return $valor;
        }
        if (! is_scalar($valor)) {
            return self::REDIGIDO;
        }

        $d = preg_replace('/\D/', '', (string) $valor);
        $n = strlen($d);
        if ($n === 11) {
            return substr($d, 0, 3).'.***.**'.$d[8].'-'.substr($d, 9, 2);
        }

        return self::generico($d);
    }

    public static function telefone(mixed $valor): mixed
    {
        if ($valor === null || $valor === '' || (is_string($valor) && str_contains($valor, '*'))) {
            return $valor;
        }
        if (! is_scalar($valor)) {
            return self::REDIGIDO;
        }

        $d = preg_replace('/\D/', '', (string) $valor);
        // +55 na frente: o número nacional são os 10 ou 11 dígitos seguintes.
        if ((strlen($d) === 12 || strlen($d) === 13) && str_starts_with($d, '55')) {
            $d = substr($d, 2);
        }
        $n = strlen($d);
        if ($n === 10 || $n === 11) {
            $local = substr($d, 2, $n - 6); // o que vem depois do DDD e antes dos 4 últimos dígitos
            $miolo = $local[0].str_repeat('*', strlen($local) - 1);

            return '('.substr($d, 0, 2).') '.$miolo.'-*'.substr($d, -3);
        }

        return self::generico($d);
    }

    /** Formato desconhecido: 3 primeiros e 3 últimos dígitos, o resto "*". Menos de 7 dígitos não sobra miolo. */
    private static function generico(string $digitos): string
    {
        $n = strlen($digitos);

        return $n < 7 ? self::REDIGIDO : substr($digitos, 0, 3).str_repeat('*', $n - 6).substr($digitos, -3);
    }
}
