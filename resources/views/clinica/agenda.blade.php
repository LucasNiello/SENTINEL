@extends('layouts.app')

@push('estilos')
    <style>
        .agenda-mobile td {overflow-wrap:anywhere;min-width:0}
    </style>
@endpush


@section('titulo', 'Agenda')

@php
    $link = fn (array $troca) => '/agenda?'.http_build_query(array_filter([...['visao' => $visao, 'dia' => $dia->toDateString(), 'setor' => $setor], ...$troca]));
    $diasDaSemana = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
    // Estado com dois canais: a cor do selo e o texto dele.
    $selos = ['agendado' => ['Agendado', ''], 'confirmado' => ['Confirmado', 'status--info'], 'realizado' => ['Realizado', 'status--sucesso'],
        'cancelado' => ['Cancelado', 'status--erro'], 'faltou' => ['Faltou', 'status--expirado']];
@endphp

@section('conteudo')
    <header class="page-header">
        <p class="eyebrow">{{ $soUmSetor ? 'Banho e tosa' : 'Clínica e banho e tosa' }}</p>
        <h1>Agenda</h1>
        <p class="intro">{{ $soUmSetor ? 'Horários do banho e tosa.' : 'Horários da clínica e do banho e tosa.' }} Agendar e mudar o status abrem o card de revisão; nada é gravado sem confirmar.</p>
    </header>

    <nav class="barra-periodo" aria-label="Período da agenda">
        <div class="segmentos" role="group" aria-label="Visão">
            <a href="{{ $link(['visao' => 'dia']) }}" @if ($visao === 'dia') aria-current="true" @endif>Dia</a>
            <a href="{{ $link(['visao' => 'semana']) }}" @if ($visao === 'semana') aria-current="true" @endif>Semana</a>
        </div>
        @if ($soUmSetor)
        <p class="setor-fixo">Agenda de <strong>banho e tosa</strong></p>
        @else
        <div class="segmentos" role="group" aria-label="Setor">
            <a href="{{ $link(['setor' => null]) }}" @if ($setor === '') aria-current="true" @endif>Tudo</a>
            <a href="{{ $link(['setor' => 'clinica']) }}" @if ($setor === 'clinica') aria-current="true" @endif>Clínica</a>
            <a href="{{ $link(['setor' => 'petshop']) }}" @if ($setor === 'petshop') aria-current="true" @endif>Banho e tosa</a>
        </div>
        @endif
        <div class="passos">
            <a class="botao" href="{{ $link(['dia' => $anterior]) }}"><span aria-hidden="true">←</span> {{ $visao === 'semana' ? 'Semana anterior' : 'Dia anterior' }}</a>
            <a class="botao" href="{{ $link(['dia' => $hoje]) }}">Hoje</a>
            <a class="botao" href="{{ $link(['dia' => $proximo]) }}">{{ $visao === 'semana' ? 'Próxima semana' : 'Próximo dia' }} <span aria-hidden="true">→</span></a>
        </div>
        <form class="ir-para" method="GET" action="/agenda">
            <input type="hidden" name="visao" value="{{ $visao }}">
            @if ($setor !== '') <input type="hidden" name="setor" value="{{ $setor }}"> @endif
            <div class="campo">
                <label for="dia">Ir para o dia</label>
                <input type="date" id="dia" name="dia" value="{{ $dia->toDateString() }}" data-foco>
            </div>
            <button type="submit">Ver</button>
        </form>
    </nav>

    @if ($podeAgendar)
        <p class="acoes-topo"><button type="button" class="botao-principal" data-tool="agendar_atendimento">Agendar atendimento</button></p>
    @endif

    @if ($erro)
        <p class="msg msg--erro" role="alert">{{ $erro }}</p>
    @endif

    <p class="tabela-resumo"><span>{{ $total }} horário(s) no período</span></p>
    @if ($noTeto)
        <p class="msg msg--aviso">A agenda mostra só os 200 primeiros horários do período. Os últimos dias podem estar incompletos: veja um dia de cada vez ou filtre por setor.</p>
    @endif

    @foreach ($dias as $rotulo => $bloco)
        <section class="dia-agenda" aria-labelledby="dia-{{ $loop->index }}">
            <h2 id="dia-{{ $loop->index }}">{{ $diasDaSemana[$bloco['data']->dayOfWeek] }} <span class="mono">{{ $rotulo }}</span></h2>
            @if ($bloco['linhas'] === [])
                <p class="vazio">Nenhum horário neste dia.</p>
            @else
                <div class="tabela-rolagem tabela-empilha agenda-mobile" tabindex="0" role="region" aria-label="Agenda de {{ $rotulo }}">
                    <table>
                        <thead><tr><th scope="col">Horário</th><th scope="col">Pet</th><th scope="col">Tutor</th><th scope="col">Serviço</th><th scope="col">Profissional</th><th scope="col">Setor</th><th scope="col">Situação</th>@if ($podeMudarStatus)<th scope="col">Ação</th>@endif</tr></thead>
                        <tbody>
                            @foreach ($bloco['linhas'] as $l)
                                <tr>
                                    <td data-rotulo="Horário">{{ $l['hora'] }}</td>
                                    <td data-rotulo="Pet">{{ $l['pet'] }}</td>
                                    <td data-rotulo="Tutor">{{ $l['tutor'] }}</td>
                                    <td data-rotulo="Serviço">{{ $l['servico'] }}</td>
                                    <td data-rotulo="Profissional">{{ $l['profissional'] }}</td>
                                    <td data-rotulo="Setor">{{ $l['setor'] === 'clinica' ? 'Clínica' : 'Banho e tosa' }}</td>
                                    <td data-rotulo="Situação"><span class="status-badge {{ $selos[$l['status']][1] ?? '' }}">{{ $selos[$l['status']][0] ?? $l['status'] }}</span></td>
                                    @if ($podeMudarStatus)
                                        <td data-rotulo="Ação">
                                            @if (in_array($l['status'], ['agendado', 'confirmado'], true))
                                                <button type="button" class="btn-editar-linha" data-tool="alterar_status_agendamento" data-id="{{ $l['id'] }}" aria-label="Mudar o status do horário de {{ $l['pet'] }}, {{ $l['hora'] }}">Mudar status</button>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endforeach

    @include('clinica._acoes')
@endsection
