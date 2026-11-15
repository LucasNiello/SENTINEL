<?php

namespace App\Services;

use App\Models\Cobranca;
use App\Models\CobrancaItem;
use App\Models\Estorno;
use App\Models\Lancamento;
use App\Models\Venda;
use App\Models\VendaItem;
use Carbon\CarbonImmutable;

/**
 * Resultados (BI) da clínica + petshop, tela do dono (front, 14/11). SÓ LEITURA: nenhuma regra de negócio mora aqui e
 * nada é gravado. Tudo em centavos inteiros e sempre filtrado pelo tenant recebido (obrigatório, sem padrão).
 *
 * Definições (decisão registrada em .claude/decisoes/2026-11-14.md):
 * - faturamento = cobranças e vendas com pagamento no período (pago_em), pelo total cobrado;
 * - estornado = estornos gravados no período (tabela estornos; estorno antigo, de antes da T2, pela coluna da cobrança);
 * - líquido = faturamento − estornado; ticket médio = faturamento ÷ número de pagamentos;
 * - despesas = lançamentos de categoria "despesa" com data no período; lucro = líquido − despesas;
 * - a receber = cobranças fechadas e vendas em aberto HOJE (não depende do período).
 */
class ResultadosClinica
{
    public const PERIODOS = ['dia' => 'Dia', 'semana' => 'Semana', 'mes' => 'Mês'];

    private const SETORES = ['clinica' => 'Clínica', 'petshop' => 'Petshop'];

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} início e fim do período que contém a data, no fuso da aplicação. */
    public function intervalo(string $periodo, CarbonImmutable $data): array
    {
        return match ($periodo) {
            'semana' => [$data->startOfWeek(CarbonImmutable::MONDAY), $data->endOfWeek(CarbonImmutable::SUNDAY)],
            'mes' => [$data->startOfMonth(), $data->endOfMonth()],
            default => [$data->startOfDay(), $data->endOfDay()],
        };
    }

    /** @return array<string, mixed> */
    public function resumo(int $tenantId, CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $setores = [];
        foreach (self::SETORES as $setor => $rotulo) {
            $setores[$setor] = ['rotulo' => $rotulo, 'faturamento' => 0, 'pagamentos' => 0, 'estornado' => 0];
        }

        foreach ([Cobranca::class, Venda::class] as $modelo) {
            $pagos = $modelo::query()->where('tenant_id', $tenantId)->whereNotNull('pago_em')->whereBetween('pago_em', [$inicio, $fim])
                ->groupBy('setor')->selectRaw('setor, count(*) as quantos, coalesce(sum(total_centavos), 0) as total')->get();
            foreach ($pagos as $linha) {
                if (isset($setores[$linha->setor])) {
                    $setores[$linha->setor]['faturamento'] += (int) $linha->total;
                    $setores[$linha->setor]['pagamentos'] += (int) $linha->quantos;
                }
            }
        }

        $estornos = Estorno::query()
            ->leftJoin('cobrancas', fn ($j) => $j->on('cobrancas.id', '=', 'estornos.cobranca_id')->where('cobrancas.tenant_id', $tenantId))
            ->leftJoin('vendas', fn ($j) => $j->on('vendas.id', '=', 'estornos.venda_id')->where('vendas.tenant_id', $tenantId))
            ->where('estornos.tenant_id', $tenantId)->whereBetween('estornos.created_at', [$inicio, $fim])
            ->selectRaw('coalesce(cobrancas.setor, vendas.setor) as setor_do_estorno, coalesce(sum(estornos.valor_centavos), 0) as total')
            ->groupByRaw('coalesce(cobrancas.setor, vendas.setor)')->get();
        foreach ($estornos as $linha) {
            if (isset($setores[$linha->setor_do_estorno])) {
                $setores[$linha->setor_do_estorno]['estornado'] += (int) $linha->total;
            }
        }
        // Estorno de antes da T2: só a coluna da cobrança, sem linha em "estornos".
        $antigos = Cobranca::query()->where('tenant_id', $tenantId)->whereBetween('estornado_em', [$inicio, $fim])
            ->whereNotExists(fn ($q) => $q->from('estornos')->whereColumn('estornos.cobranca_id', 'cobrancas.id'))
            ->groupBy('setor')->selectRaw('setor, coalesce(sum(estorno_valor_centavos), 0) as total')->get();
        foreach ($antigos as $linha) {
            if (isset($setores[$linha->setor])) {
                $setores[$linha->setor]['estornado'] += (int) $linha->total;
            }
        }

        foreach ($setores as &$s) {
            $s['liquido'] = $s['faturamento'] - $s['estornado'];
            $s['ticket_medio'] = $s['pagamentos'] > 0 ? intdiv($s['faturamento'], $s['pagamentos']) : 0;
        }
        unset($s);

        $faturamento = array_sum(array_column($setores, 'faturamento'));
        $pagamentos = array_sum(array_column($setores, 'pagamentos'));
        $estornado = array_sum(array_column($setores, 'estornado'));
        $despesas = $this->despesas($tenantId, $inicio, $fim);

        return [
            'setores' => $setores,
            'faturamento' => $faturamento,
            'pagamentos' => $pagamentos,
            'estornado' => $estornado,
            'liquido' => $faturamento - $estornado,
            'ticket_medio' => $pagamentos > 0 ? intdiv($faturamento, $pagamentos) : 0,
            'despesas' => $despesas,
            'lucro' => $faturamento - $estornado - $despesas,
            'top_itens' => $this->topItens($tenantId, $inicio, $fim),
            'a_receber' => $this->aReceber($tenantId),
        ];
    }

    private function despesas(int $tenantId, CarbonImmutable $inicio, CarbonImmutable $fim): int
    {
        $valores = Lancamento::query()->where('lancamentos.tenant_id', $tenantId)
            // whereDate: no SQLite dos testes a data é gravada com hora ("… 00:00:00") e o último dia ficaria de fora.
            ->whereDate('data', '>=', $inicio->toDateString())->whereDate('data', '<=', $fim->toDateString())
            ->whereHas('categoriaLancamento', fn ($q) => $q->where('tipo', 'despesa')->where('tenant_id', $tenantId))
            ->pluck('valor');

        // Soma em centavos inteiros (o valor é decimal(…,2) no banco): sem ponto flutuante acumulado.
        return (int) $valores->sum(fn ($valor) => (int) round(((float) $valor) * 100));
    }

    /** @return array<int, array{descricao: string, quantidade: int, total: int}> os 5 itens que mais faturaram no período. */
    private function topItens(int $tenantId, CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $porItem = [];
        foreach ([[CobrancaItem::class, 'cobranca_itens', 'cobrancas', 'cobranca_id'], [VendaItem::class, 'venda_itens', 'vendas', 'venda_id']] as [$modelo, $tabela, $pai, $chave]) {
            $linhas = $modelo::query()->join($pai, "{$pai}.id", '=', "{$tabela}.{$chave}")
                ->where("{$tabela}.tenant_id", $tenantId)->where("{$pai}.tenant_id", $tenantId)
                ->whereNotNull("{$pai}.pago_em")->whereBetween("{$pai}.pago_em", [$inicio, $fim])
                ->groupBy("{$tabela}.descricao")
                ->selectRaw("{$tabela}.descricao as descricao, coalesce(sum({$tabela}.quantidade), 0) as quantidade, coalesce(sum({$tabela}.subtotal_centavos), 0) as total")->get();
            foreach ($linhas as $linha) {
                $porItem[$linha->descricao] ??= ['descricao' => (string) $linha->descricao, 'quantidade' => 0, 'total' => 0];
                $porItem[$linha->descricao]['quantidade'] += (int) $linha->quantidade;
                $porItem[$linha->descricao]['total'] += (int) $linha->total;
            }
        }
        usort($porItem, fn (array $a, array $b) => [$b['total'], $a['descricao']] <=> [$a['total'], $b['descricao']]);

        return array_slice($porItem, 0, 5);
    }

    /** @return array{total: int, cobrancas: int, vendas: int} */
    private function aReceber(int $tenantId): array
    {
        $cobrancas = Cobranca::query()->where('tenant_id', $tenantId)->where('status', 'fechada');
        $vendas = Venda::query()->where('tenant_id', $tenantId)->where('status', 'aberta');

        return [
            'total' => (int) (clone $cobrancas)->sum('total_centavos') + (int) (clone $vendas)->sum('total_centavos'),
            'cobrancas' => $cobrancas->count(),
            'vendas' => $vendas->count(),
        ];
    }

    /** Centavos inteiros em "R$ 1.234,56" (negativo com o sinal na frente). */
    public static function reais(int $centavos): string
    {
        $abs = abs($centavos);

        return ($centavos < 0 ? '−' : '').'R$ '.number_format(intdiv($abs, 100), 0, '', '.').','.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
