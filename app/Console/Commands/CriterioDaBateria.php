<?php

namespace App\Console\Commands;

/**
 * Leitura do CSV e critério de acerto do sentinel:bateria, sem banco e sem IA (para testar sem chamada real).
 * Critério de 05/11 (PROGRESSO.md, item 6), mais as marcas da observacao da bateria da clínica (15/11):
 *  - arg:campo=valor   o argumento da proposta tem de ter esse valor;
 *  - posicao=N|ultima  continuação: o id proposto é o da linha N (ou da última) da consulta da qual depende;
 *  - confirmar         a bateria confirma a proposta como o card faria;
 *  - id_gravado:campo  o campo da proposta é o id que a confirmação do pedido do qual depende gravou.
 * esperado_tool aceita alternativas com "|". Grupo "recusa": texto sem tool, sem exigir pergunta.
 */
final class CriterioDaBateria
{
    /** @return array<int, array<string, mixed>> */
    public static function lerPedidos(string $arquivo): array
    {
        $linhas = array_values(array_filter(array_map('trim', file($arquivo))));
        $cabecalho = str_getcsv(array_shift($linhas), ';', '"', '');
        $pedidos = [];
        foreach ($linhas as $linha) {
            $p = array_combine($cabecalho, array_pad(str_getcsv($linha, ';', '"', ''), count($cabecalho), ''));
            $p['n'] = (int) $p['n'];
            $pedidos[$p['n']] = $p;
        }

        return $pedidos;
    }

    /** Critério de 05/11 (PROGRESSO.md, item 6 do desenho), mais as marcas da observacao (bateria da clínica, 15/11). */
    public static function acertou(array $p, string $tipo, ?string $obtido, array $resposta, ?array $consulta, mixed $gravadoNaDependencia = null): bool
    {
        if ($p['esperado_tool'] === '-') {
            // Ambíguo: texto com pergunta. Recusa (ex.: prontuário): texto sem tool, pergunta não exigida.
            return $tipo === 'texto' && $obtido === null && ($p['grupo'] === 'recusa' || str_contains((string) ($resposta['mensagem'] ?? ''), '?'));
        }
        if (! in_array($obtido, explode('|', $p['esperado_tool']), true) || ! in_array($tipo, explode('|', $p['esperado_tipo']), true)) {
            return false;
        }

        $marcas = self::marcas($p);
        $argumentos = (array) ($resposta['argumentos'] ?? []);
        foreach ($marcas['args'] as $campo => $valor) {
            if (! array_key_exists($campo, $argumentos) || (string) $argumentos[$campo] !== $valor) {
                return false;
            }
        }
        if ($marcas['id_gravado'] !== null && ($gravadoNaDependencia === null || (string) ($argumentos[$marcas['id_gravado']] ?? '') !== (string) $gravadoNaDependencia)) {
            return false;
        }
        if ($p['grupo'] !== 'continuacao') {
            return true;
        }

        // Continuação: o id proposto tem de ser o da linha certa da consulta anterior.
        $ids = array_column($consulta['resultado'] ?? [], 'id');
        $esperado = match (true) {
            $marcas['posicao'] === 'ultima' => $ids === [] ? null : $ids[array_key_last($ids)],
            $marcas['posicao'] !== null => $ids[(int) $marcas['posicao'] - 1] ?? null,
            $marcas['id_gravado'] !== null => $gravadoNaDependencia,
            // CSV original (05/11): as posições ficavam fixas pelo número do pedido.
            default => match ((int) $p['n']) {
                27 => $ids[1] ?? null,
                28 => $ids[0] ?? null,
                29 => $ids[2] ?? null,
                30 => $ids === [] ? null : $ids[array_key_last($ids)],
                default => null,
            },
        };
        if ($marcas['id_gravado'] !== null) {
            return $esperado !== null;
        }

        return $esperado !== null && (int) ($argumentos['id'] ?? 0) === (int) $esperado;
    }

    /** @return array{args: array<string, string>, posicao: ?string, confirmar: bool, id_gravado: ?string} */
    public static function marcas(array $p): array
    {
        $observacao = (string) ($p['observacao'] ?? '');
        preg_match_all('/\barg:([a-z_]+)=(\S+)/', $observacao, $args, PREG_SET_ORDER);
        preg_match('/\bposicao=(\d+|ultima)\b/', $observacao, $posicao);
        preg_match('/\bid_gravado:([a-z_]+)\b/', $observacao, $idGravado);

        return [
            'args' => array_column($args, 2, 1),
            'posicao' => $posicao[1] ?? null,
            'confirmar' => (bool) preg_match('/\bconfirmar\b/', $observacao),
            'id_gravado' => $idGravado[1] ?? null,
        ];
    }

}
