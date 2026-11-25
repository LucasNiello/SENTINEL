@extends('layouts.app')

@push('estilos')
    <style>
        .resultados-mobile td {overflow-wrap:anywhere;min-width:0}
    </style>
@endpush


@section('titulo', 'Resultados')

@php

    $reais = \App\Services\ResultadosClinica::reais(...);
    $link = fn (array $troca) => '/resultados?'.http_build_query([...['periodo' => $periodo, 'dia' => $dia->toDateString()], ...$troca]);
    $meses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $rotuloPeriodo = match ($periodo) {
        'dia' => $dia->format('d/m/Y'),
        'semana' => $inicio->format('d/m').' a '.$fim->format('d/m/Y'),
        'ano' => (string) $dia->year,
        default => $meses[$dia->month - 1].' de '.$dia->year,
    };
    $nomes = ['dia' => ['Dia anterior', 'Próximo dia'], 'semana' => ['Semana anterior', 'Próxima semana'], 'mes' => ['Mês anterior', 'Próximo mês'], 'ano' => ['Ano anterior', 'Próximo ano']][$periodo];
    // Barra de proporção por setor (largura = parte do faturamento); o número está sempre ao lado.
    $parte = fn (int $valor) => $r['faturamento'] > 0 ? (int) round($valor * 100 / $r['faturamento']) : 0;
@endphp

@section('conteudo')
@if (request()->query('amostra') === 'estados')
    @include('amostras.estados', ['tipoAmostra' => 'resultados'])
@elseif (request()->query('amostra') === 'painel-dono')
    @include('amostras.painel-dono')
@else
    <header class="page-header">
        <p class="eyebrow">Resultados</p>
        <h1>Resultados da clínica e do petshop</h1>
        <p class="intro">Faturamento do que foi pago no período, menos estornos e despesas. Valores simulados: não há caixa, banco nem imposto.</p>
    </header>

    <nav class="barra-periodo" aria-label="Período dos resultados">
        <div class="segmentos" role="group" aria-label="Período">
            @foreach ($periodos as $chave => $rotulo)
                <a href="{{ $link(['periodo' => $chave]) }}" @if ($periodo === $chave) aria-current="true" @endif>{{ $rotulo }}</a>
            @endforeach
        </div>
        <div class="passos">
            <a class="botao" href="{{ $link(['dia' => $anterior]) }}"><span aria-hidden="true">←</span> {{ $nomes[0] }}</a>
            <a class="botao" href="{{ $link(['dia' => $hoje]) }}">Hoje</a>
            <a class="botao" href="{{ $link(['dia' => $proximo]) }}">{{ $nomes[1] }} <span aria-hidden="true">→</span></a>
        </div>
    </nav>

    <p class="periodo-atual">Período: <strong>{{ $rotuloPeriodo }}</strong></p>

    <dl class="kpis" aria-label="Resumo do período">
        <div class="kpi"><dt>Faturamento</dt><dd>{{ $reais($r['faturamento']) }}</dd><p>{{ $r['pagamentos'] }} pagamento(s)</p></div>
        <div class="kpi"><dt>Estornado</dt><dd>{{ $reais($r['estornado']) }}</dd><p>Líquido: {{ $reais($r['liquido']) }}</p></div>
        <div class="kpi"><dt>Despesas</dt><dd>{{ $reais($r['despesas']) }}</dd><p>Lançamentos de despesa do cadastro inicial: a clínica ainda não tem tela para lançar despesa.</p></div>
        <div class="kpi kpi--destaque {{ $r['lucro'] < 0 ? 'kpi--negativo' : '' }}">
            <dt>{{ $r['lucro'] < 0 ? 'Prejuízo' : 'Lucro' }}</dt><dd>{{ $reais($r['lucro']) }}</dd><p>Faturamento − estornos − despesas</p>
        </div>
        <div class="kpi"><dt>Ticket médio</dt><dd>{{ $reais($r['ticket_medio']) }}</dd><p>Faturamento ÷ pagamentos</p></div>
        <div class="kpi"><dt>A receber hoje</dt><dd>{{ $reais($r['a_receber']['total']) }}</dd><p>{{ $r['a_receber']['cobrancas'] }} cobrança(s) e {{ $r['a_receber']['vendas'] }} venda(s) em aberto</p></div>
    </dl>

    <div class="blocos">
        <section class="bloco" aria-labelledby="titulo-setores">
            <h2 id="titulo-setores">Por setor</h2>
            <div class="tabela-rolagem tabela-empilha resultados-mobile" tabindex="0" role="region" aria-label="Resultados por setor">
                <table>
                    <thead><tr><th scope="col">Setor</th><th scope="col" class="num">Faturamento</th><th scope="col">Parte</th><th scope="col" class="num">Estornado</th><th scope="col" class="num">Líquido</th><th scope="col" class="num">Ticket médio</th></tr></thead>
                    <tbody>
                        @foreach ($r['setores'] as $s)
                            <tr>
                                <td data-rotulo="Setor">{{ $s['rotulo'] }}</td>
                                <td data-rotulo="Faturamento" class="num">{{ $reais($s['faturamento']) }}</td>
                                <td data-rotulo="Parte"><span class="proporcao"><span class="proporcao__barra" style="width: {{ $parte($s['faturamento']) }}%"></span></span> <span class="mono">{{ $parte($s['faturamento']) }}%</span></td>
                                <td data-rotulo="Estornado" class="num">{{ $reais($s['estornado']) }}</td>
                                <td data-rotulo="Líquido" class="num">{{ $reais($s['liquido']) }}</td>
                                <td data-rotulo="Ticket médio" class="num">{{ $reais($s['ticket_medio']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="bloco" aria-labelledby="titulo-top">
            <h2 id="titulo-top">Itens que mais faturaram</h2>
            @if ($r['top_itens'] === [])
                <p class="vazio">Nenhum pagamento no período.</p>
            @else
                <div class="tabela-rolagem tabela-empilha resultados-mobile" tabindex="0" role="region" aria-label="Itens que mais faturaram">
                    <table>
                        <thead><tr><th scope="col">Item</th><th scope="col" class="num">Quantidade</th><th scope="col" class="num">Total</th></tr></thead>
                        <tbody>
                            @foreach ($r['top_itens'] as $item)
                                <tr>
                                    <td data-rotulo="Item">{{ $item['descricao'] }}</td>
                                    <td data-rotulo="Quantidade" class="num">{{ $item['quantidade'] }}</td>
                                    <td data-rotulo="Total" class="num">{{ $reais($item['total']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endif
@endsection
