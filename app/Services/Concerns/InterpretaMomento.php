<?php

namespace App\Services\Concerns;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Data e hora que chegam nos dados (da tela ou da IA), no fuso da aplicação (America/Sao_Paulo).
 * Só aceita AAAA-MM-DD HH:MM ou AAAA-MM-DD HH:MM:SS (com espaço ou "T"), sem fuso na frase: formato solto
 * ("11/12/2026", "amanhã", "tomorrow", data sem hora) e sufixo de fuso (Z, +00:00) são recusados, porque
 * cada um seria lido de um jeito (12/11 em vez de 11/12, hora de outro fuso gravada como se fosse local).
 * Um objeto de data (o seeder, o código) é convertido para o fuso da aplicação.
 */
trait InterpretaMomento
{
    private function momento(mixed $valor): ?CarbonImmutable
    {
        $fuso = (string) config('app.timezone');

        if ($valor instanceof CarbonInterface) {
            return CarbonImmutable::instance($valor)->setTimezone($fuso);
        }

        if (! is_string($valor)) {
            return null;
        }

        $valor = trim($valor);

        foreach (['!Y-m-d H:i', '!Y-m-d H:i:s', '!Y-m-d\TH:i', '!Y-m-d\TH:i:s'] as $formato) {
            try {
                $momento = CarbonImmutable::createFromFormat($formato, $valor, $fuso);
            } catch (Throwable) {
                continue; // formato diferente deste: tenta o próximo
            }

            // 31/02 e 25:00 "transbordam" para outro dia: só vale se voltar igual ao que foi escrito.
            if ($momento !== false && $momento->format(ltrim($formato, '!')) === $valor) {
                return $momento;
            }
        }

        return null;
    }
}
