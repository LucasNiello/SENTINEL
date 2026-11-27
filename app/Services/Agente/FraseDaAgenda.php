<?php

namespace App\Services\Agente;

/**
 * 26/11: a frase que responde a uma consulta da agenda ("O último agendamento realizado foi o #13: Bidu (Bruno Carvalho)…").
 * É montada pelo SERVIDOR, só com os campos das linhas que ele mesmo acabou de ler: a IA não vê as linhas, então não há o que
 * inventar. Também diz quais linhas a tela deve realçar (piscar uma vez).
 */
class FraseDaAgenda
{
    private const STATUS_NA_FRASE = ['agendado' => 'agendado', 'confirmado' => 'confirmado', 'realizado' => 'realizado', 'cancelado' => 'cancelado', 'faltou' => 'com falta'];

    private const CRITERIOS = ['marcado' => 'pelo dia marcado', 'gravacao' => 'pela ordem de gravação', 'alteracao' => 'pela última alteração'];

    /**
     * @param  array<int, array<string, mixed>>  $linhas  as linhas já mapeadas da consulta (id, horario, pet, tutor, servico, profissional, status, gravado_em, alterado_em)
     * @param  array<string, mixed>  $f  os filtros pedidos
     * @return array{frase: string, destacar: int[]}
     */
    public static function montar(array $linhas, array $f): array
    {
        $total = count($linhas);
        if ($total === 0) {
            return ['frase' => 'Não encontrei nenhum agendamento com esse pedido. Se quiser, amplie o período ou tire algum filtro.', 'destacar' => []];
        }

        $ordenou = isset($f['ordenar']) || isset($f['limite']);
        $status = is_string($f['status'] ?? null) ? (self::STATUS_NA_FRASE[$f['status']] ?? null) : null;
        $primeira = $linhas[0];
        $atras = \App\Services\AgendaService::sentidoEfetivo($f) === 'desc';
        $criterio = self::CRITERIOS[$f['ordenar'] ?? 'marcado'] ?? self::CRITERIOS['marcado'];

        if ($total === 1) {
            $abre = $ordenou ? 'O '.($atras ? 'último' : 'primeiro').' agendamento'.($status ? " {$status}" : '').' '.($criterio === self::CRITERIOS['marcado'] ? '' : "({$criterio}) ").'foi' : 'Encontrei 1 agendamento,';
            $frase = trim(preg_replace('/\s+/', ' ', $abre)).' '.self::resumo($primeira, true);
        } else {
            $frase = "Encontrei {$total} agendamentos".($status ? " {$status}s" : '').self::filtros($f).'.';
            if ($ordenou) {
                $frase .= ' O '.($atras ? 'primeiro da lista, '.$criterio.',' : 'primeiro da lista').' é '.self::resumo($primeira, false);
            }
            if ($total >= 200) {
                $frase .= ' Mostrei os 200 primeiros: refine o pedido para ver os demais.';
            }
        }

        // Pedido que aponta UM registro (o último, o primeiro, um id) realça a linha; uma lista curta realça todas.
        $destacar = $ordenou || isset($f['id']) ? [(int) $primeira['id']] : ($total <= 3 ? array_map(fn ($l) => (int) $l['id'], $linhas) : []);

        // 26/11: ordenado pela última alteração (ou pela gravação), com ou sem limite, "o que foi remarcado" é o LOTE: todas as linhas que
        // compartilham o mesmo minuto da primeira ficam realçadas (um lote grava vários agendamentos de uma vez), não só a primeira.
        $campo = ['alteracao' => 'alterado_em', 'gravacao' => 'gravado_em'][$f['ordenar'] ?? ''] ?? null;
        if ($campo !== null && $total > 1 && ! empty($primeira[$campo])) {
            $mesmoMomento = array_values(array_filter($linhas, fn ($l) => ($l[$campo] ?? null) === $primeira[$campo]));
            $destacar = array_map(fn ($l) => (int) $l['id'], array_slice($mesmoMomento, 0, 25));

            // A frase diz o que o realce significa (senão "5 encontrados" e 3 realçados parece contradição).
            $n = count($destacar);
            if ($n > 1) {
                $hora = preg_replace('/^\S+ (\d{2}:\d{2}).*$/u', 'às $1', (string) $primeira[$campo]);
                $frase = "Encontrei {$total} agendamentos".self::filtros($f).". Os {$n} realçados foram "
                    .($campo === 'alterado_em' ? 'alterados' : 'gravados')." juntos, {$hora} (o mais recente)"
                    .($total > $n ? '; os outros '.($total - $n).' são de '.($campo === 'alterado_em' ? 'alterações' : 'gravações').' anteriores.' : '.');
            }
        }

        return ['frase' => $frase, 'destacar' => $destacar];
    }

    /** "o #13: Bidu (Bruno Carvalho), Banho porte pequeno, 19/10/2026 às 09:00, com Carlos Mendes. Gravado em 12/10/2026 às 14:32." */
    private static function resumo(array $l, bool $comGravacao): string
    {
        $quando = preg_replace('/^(\S+) (\d{2}:\d{2}).*$/u', '$1 às $2', (string) ($l['horario'] ?? ''));
        $texto = "o #{$l['id']}: {$l['pet']} ({$l['tutor']}), {$l['servico']}, {$quando}, com {$l['profissional']} — {$l['status']}.";
        if ($comGravacao && ! empty($l['gravado_em'])) {
            $texto .= ' Criado no sistema em '.preg_replace('/^(\S+) (\S+)$/', '$1 às $2', (string) $l['gravado_em']).'.';
        }

        return $texto;
    }

    /** " do pet Thor, do cliente Maria, …": só o que o usuário pediu. */
    private static function filtros(array $f): string
    {
        $partes = [];
        foreach (['pet' => 'do pet', 'tutor' => 'do cliente', 'profissional' => 'com', 'servico' => 'de'] as $chave => $prefixo) {
            if (is_string($f[$chave] ?? null) && trim($f[$chave]) !== '') {
                $partes[] = "{$prefixo} ".trim($f[$chave]);
            }
        }

        return $partes === [] ? '' : ' '.implode(', ', $partes);
    }
}
