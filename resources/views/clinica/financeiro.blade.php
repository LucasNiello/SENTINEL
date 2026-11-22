@extends('layouts.app')

@push('estilos')
    <style>
        .financeiro-mobile td {overflow-wrap:anywhere;min-width:0}
    </style>
@endpush


@section('titulo', $titulo)

@php
    // Estado com dois canais: a cor do selo e o texto dele.
    $selos = ['aberta' => ['Aberta', ''], 'a receber' => ['A receber', 'status--expirado'], 'paga' => ['Paga', 'status--sucesso'], 'cancelada' => ['Cancelada', 'status--erro']];
    $pagamentos = ['dinheiro' => 'Dinheiro', 'pix' => 'Pix', 'cartao' => 'Cartão'];
    $numericas = ['total', 'estornado', 'liquido'];
    $temAcao = collect($linhas)->contains(fn ($l) => ! empty($acoes[$l['status']] ?? []));
@endphp

@section('conteudo')
    <header class="page-header">
        <p class="eyebrow">Financeiro</p>
        <h1>{{ $titulo }}</h1>
        <p class="intro">{{ $intro }}</p>
    </header>

    <form class="painel" method="GET" action="{{ $rota }}" aria-label="Filtros">
        <div class="campo">
            <label for="status">Situação</label>
            <select id="status" name="status" data-foco>
                <option value="">Todas</option>
                @foreach ($opcoesStatus as $valor => $rotulo)
                    <option value="{{ $valor }}" @selected($status === $valor)>{{ $rotulo }}</option>
                @endforeach
            </select>
        </div>
        <div class="campo">
            <label for="setor">Setor</label>
            <select id="setor" name="setor">
                <option value="">Todos</option>
                <option value="clinica" @selected($setor === 'clinica')>Clínica</option>
                <option value="petshop" @selected($setor === 'petshop')>Petshop</option>
            </select>
        </div>
        <div class="painel__acoes"><button type="submit" class="botao-principal">Filtrar</button></div>
    </form>

    @if ($erro)
        <p class="msg msg--erro" role="alert">{{ $erro }}</p>
    @endif

    <dl class="kpis kpis--3" aria-label="Soma do que está listado">
        <div class="kpi"><dt>Total listado</dt><dd>{{ $resumo['total'] }}</dd></div>
        <div class="kpi"><dt>Estornado</dt><dd>{{ $resumo['estornado'] }}</dd></div>
        <div class="kpi kpi--destaque"><dt>Líquido</dt><dd>{{ $resumo['liquido'] }}</dd><p>Só do que foi pago.</p></div>
    </dl>

    <p class="tabela-resumo"><span>{{ $resumo['registros'] }} registro(s)</span></p>
    @if ($resumo['registros'] >= 100)
        <p class="msg msg--aviso">A lista mostra só os 100 registros mais novos. Use os filtros para ver os demais; a soma acima é só do que está listado.</p>
    @endif

    @if ($linhas === [])
        @unless ($erro) <p class="vazio">Nenhum registro com estes filtros.</p> @endunless
    @else
        <div class="tabela-rolagem tabela-empilha financeiro-mobile" tabindex="0" role="region" aria-label="{{ $titulo }}">
            <table>
                <thead><tr>
                    @foreach ($colunas as $chave => $rotulo)
                        <th scope="col" @class(['num' => in_array($chave, $numericas, true)])>{{ $rotulo }}</th>
                    @endforeach
                    @if ($temAcao) <th scope="col">Ação</th> @endif
                </tr></thead>
                <tbody>
                    @foreach ($linhas as $l)
                        <tr>
                            @foreach ($colunas as $chave => $rotulo)
                                <td data-rotulo="{{ $rotulo }}" @class(['num' => in_array($chave, $numericas, true)])>
                                    @if ($chave === 'status')
                                        <span class="status-badge {{ $selos[$l['status']][1] ?? '' }}">{{ $selos[$l['status']][0] ?? $l['status'] }}</span>
                                    @elseif ($chave === 'forma_pagamento')
                                        {{ $pagamentos[$l[$chave] ?? ''] ?? '—' }}
                                    @else
                                        {{ ($l[$chave] ?? '') === '' || ($l[$chave] ?? null) === null ? '—' : $l[$chave] }}
                                    @endif
                                </td>
                            @endforeach
                            @if ($temAcao)
                                <td data-rotulo="Ação">
                                    @foreach ($acoes[$l['status']] ?? [] as $acao)
                                        <button type="button" class="btn-editar-linha" data-tool="{{ $acao['tool'] }}" data-id="{{ $l['id'] }}" aria-label="{{ $acao['rotulo'] }} o registro {{ $l['id'] }}">{{ $acao['rotulo'] }}</button>
                                    @endforeach
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @include('clinica._acoes')
@endsection
