<?php

namespace App\Services;

/**
 * Etapa A3: o CSV da exportação. UTF-8 com BOM (o Excel em português abre os acentos
 * certos) e ";" como separador (o padrão do Excel com vírgula decimal).
 */
class PlanilhaCsv
{
    private const BOM = "\xEF\xBB\xBF";

    /**
     * @param  string[]  $colunas  cabeçalho, na ordem das células
     * @param  array<int, array<string, mixed>>  $linhas
     */
    public static function gerar(array $colunas, array $linhas): string
    {
        $arquivo = fopen('php://temp', 'r+');
        fwrite($arquivo, self::BOM);
        // Dinheiro em reais: a coluna "preco_centavos" sai como "preco_reais", com "80,00" (vírgula decimal do Excel em português).
        $cabecalho = array_map(fn (string $c) => str_ends_with($c, '_centavos') ? substr($c, 0, -9).'_reais' : $c, $colunas);
        fputcsv($arquivo, array_map(self::celula(...), $cabecalho), ';', '"', '');

        foreach ($linhas as $linha) {
            fputcsv($arquivo, array_map(function (string $coluna) use ($linha) {
                $valor = $linha[$coluna] ?? null;

                return self::celula(str_ends_with($coluna, '_centavos') && is_int($valor) ? \App\Support\Dinheiro::emReais($valor) : $valor);
            }, $colunas), ';', '"', '');
        }

        rewind($arquivo);
        $csv = (string) stream_get_contents($arquivo);
        fclose($arquivo);

        return $csv;
    }

    /**
     * Anti-fórmula (injeção de CSV): célula que começa com = + - @ (ou tabulação e retorno,
     * que a planilha também interpreta) ganha um apóstrofo na frente e vira texto. Número
     * puro (ex.: -150.00) não é fórmula e sai como está. Lista ou objeto não vira célula.
     *
     * Aberto com outro separador (planilha em inglês usa ","), o valor pode se partir em
     * vírgulas ou quebras de linha: por isso quebra de linha e tabulação viram espaço, e o
     * trecho depois de "," ou ";" que comece com = + - @ também ganha o apóstrofo.
     */
    public static function celula(mixed $valor): string
    {
        if ($valor === null || ! is_scalar($valor)) {
            return '';
        }

        if (is_bool($valor)) {
            return $valor ? 'sim' : 'não';
        }

        $texto = (string) $valor;

        if (is_int($valor) || is_float($valor) || preg_match('/^-?\d+(\.\d+)?$/D', $texto) === 1) {
            return $texto;
        }

        // UTF-8 inválido vira "?" antes das regex (com /u, elas devolveriam null e a célula sumiria).
        // Entre o separador (ou o início) e o =, valem espaço, espaço não separável e aspas.
        $texto = mb_scrub(strtr($texto, ["\r" => ' ', "\n" => ' ', "\t" => ' ']), 'UTF-8');
        $texto = (string) preg_replace('/([,;][\s"\x{00A0}]*)([=+\-@])/u', "\$1'\$2", $texto);

        return preg_match('/^[\s"\x{00A0}]*[=+\-@]/u', $texto) === 1 ? "'".$texto : $texto;
    }
}
