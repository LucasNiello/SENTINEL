@extends('amostra.layout')

@section('conteudo')
@php use App\Amostra\DadosAmostra; @endphp
@if (!DadosAmostra::pode($papel, 'auditoria'))
    @include('amostra.parciais.negado-papel', ['motivo' => 'A auditoria é só para administradores (regra de exemplo; falta decidir quais papéis têm acesso).'])
@else
@php
    $ms = fn ($v) => number_format($v, 0, ',', '.') . ' ms';
    $classeResultado = ['sucesso' => 'status--sucesso', 'negado' => 'status--erro', 'erro' => 'status--erro', 'cancelado' => 'status--neutro', 'expirado' => 'status--aviso'];
@endphp
<div class="pagina">
    <h1>Auditoria</h1>
    <p class="lead">Quem fez o quê, quando e com que resultado. Cada gravação mostra o que a IA propôs e o que a pessoa confirmou.</p>

    <form method="get" action="{{ $u('auditoria') }}" class="grade-campos" aria-label="Filtros da auditoria">
        <input type="hidden" name="papel" value="{{ $papel }}">
        <div class="campo"><label for="f-usuario">Usuário</label>
            <select id="f-usuario" name="usuario"><option value="">Todos</option>@foreach ($usuarios as $nome)<option value="{{ $nome }}" @if (($filtros['usuario'] ?? '') === $nome) selected @endif>{{ $nome }}</option>@endforeach</select></div>
        <div class="campo"><label for="f-tool">Ação (tool)</label>
            <select id="f-tool" name="tool"><option value="">Todas</option>@foreach ($tools as $nome)<option value="{{ $nome }}" @if (($filtros['tool'] ?? '') === $nome) selected @endif>{{ $nome }}</option>@endforeach</select></div>
        <div class="campo"><label for="f-resultado">Resultado</label>
            <select id="f-resultado" name="resultado"><option value="">Todos</option>@foreach ($resultados as $chave => [$icone, $rotulo])<option value="{{ $chave }}" @if (($filtros['resultado'] ?? '') === $chave) selected @endif>{{ $rotulo }}</option>@endforeach</select></div>
        <div class="campo"><label for="f-de">De</label><input id="f-de" type="date" value="2026-10-01" aria-describedby="f-periodo"></div>
        <div class="campo"><label for="f-ate">Até</label><input id="f-ate" type="date" value="2026-10-06" aria-describedby="f-periodo"></div>
        <div class="campo"><span class="rotulo">&nbsp;</span><button type="submit" class="btn btn--primario">Filtrar</button></div>
    </form>
    <p class="dica-segurar" id="f-periodo">Na amostra, o filtro de período é só visual; usuário, ação e resultado funcionam.</p>

    <div class="resumo-numeros">
        <div class="numero"><b>{{ count($linhas) }}</b> registros</div>
        <div class="numero"><b>{{ $ms($mediaMs) }}</b> tempo médio da IA</div>
    </div>

    <div class="tabela-rolagem" tabindex="0" role="region" aria-label="Registros de auditoria">
        <table>
            <caption>Do mais novo para o mais antigo · dados fictícios</caption>
            <thead>
                <tr><th scope="col" class="num">#</th><th scope="col">Quando</th><th scope="col">Usuário</th><th scope="col">Ação</th><th scope="col">Resultado</th><th scope="col" class="num">Tempo da IA</th><th scope="col">Detalhe</th></tr>
            </thead>
            <tbody>
                @forelse ($linhas as $l)
                    <tr @if ($detalhe && $detalhe['id'] === $l['id']) aria-current="true" class="diferente" @endif>
                        <td class="num">{{ $l['id'] }}</td>
                        <td class="mono">{{ $l['quando'] }}</td>
                        <td>{{ $l['usuario'] }} <span class="papel-tag">{{ $l['papel'] }}</span></td>
                        <td><code>{{ $l['tool'] }}</code><br><span class="lead">{{ $l['entidade'] }}</span></td>
                        <td><span class="status {{ $classeResultado[$l['resultado']] }}">{{ $resultados[$l['resultado']][0] }} {{ $resultados[$l['resultado']][1] }}</span></td>
                        <td class="num">{{ $ms($l['ms']) }}</td>
                        <td><a class="btn btn--pequeno" href="{{ $u('auditoria', array_filter(['usuario' => $filtros['usuario'] ?? null, 'tool' => $filtros['tool'] ?? null, 'resultado' => $filtros['resultado'] ?? null]) + ['detalhe' => $l['id']]) }}#detalhe">Ver</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7">Nenhum registro com estes filtros. <a href="{{ $u('auditoria') }}">Limpar filtros</a></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($detalhe)
        <section id="detalhe" class="card card--risco-leitura" aria-labelledby="t-detalhe">
            <h2 id="t-detalhe" style="margin-top:0">Registro #{{ $detalhe['id'] }}: {{ $detalhe['tool'] }}</h2>
            <dl class="resumo">
                <dt>Quando</dt><dd class="mono">{{ $detalhe['quando'] }}</dd>
                <dt>Quem</dt><dd>{{ $detalhe['usuario'] }} ({{ $detalhe['papel'] }})</dd>
                <dt>Resultado</dt><dd>{{ $resultados[$detalhe['resultado']][0] }} {{ $resultados[$detalhe['resultado']][1] }}</dd>
                <dt>Tempo da IA</dt><dd class="mono">{{ $ms($detalhe['ms']) }}</dd>
                <dt>Mensagem</dt><dd>{{ $detalhe['mensagem'] ?: '—' }}</dd>
            </dl>

            @if ($detalhe['proposto'])
                <h3>Proposto pela IA × confirmado pela pessoa</h3>
                <div class="comparacao">
                    <div>
                        <h3>A IA propôs</h3>
                        <table><tbody>
                            @foreach ($detalhe['proposto'] as $campo => $valor)
                                <tr><th scope="row">{{ $campo }}</th><td>{{ $valor }}</td></tr>
                            @endforeach
                        </tbody></table>
                    </div>
                    <div>
                        <h3>A pessoa confirmou</h3>
                        @if ($detalhe['confirmado'])
                            <table><tbody>
                                @foreach ($detalhe['confirmado'] as $campo => $valor)
                                    @php $mudou = ($detalhe['proposto'][$campo] ?? null) !== $valor && $campo !== 'documento'; @endphp
                                    <tr @if ($mudou) class="diferente" @endif><th scope="row">{{ $campo }}</th><td>{{ $valor }} @if ($mudou) <span class="tag tag--editado">alterado</span> @endif</td></tr>
                                @endforeach
                            </tbody></table>
                        @else
                            <p class="msg msg--aviso">Nada foi confirmado, então nada foi gravado.</p>
                        @endif
                    </div>
                </div>
                <p class="dica-segurar">CPF e outros dados sensíveis aparecem mascarados na auditoria.</p>
            @else
                <p class="lead">Consulta sem alteração de dados: não há "proposto × confirmado".</p>
            @endif
            <a class="btn" href="{{ $u('auditoria') }}">Fechar detalhe</a>
        </section>
    @endif
</div>
@endif
@endsection
