@extends('layouts.app')

@section('titulo', 'Ficha do pet')

@php

    $especies = ['cao' => 'Cão', 'gato' => 'Gato', 'ave' => 'Ave', 'roedor' => 'Roedor', 'reptil' => 'Réptil', 'outro' => 'Outro'];
    $sexos = ['macho' => 'Macho', 'femea' => 'Fêmea'];
    $selosAgenda = ['agendado' => ['Agendado', ''], 'confirmado' => ['Confirmado', 'status--info'], 'realizado' => ['Realizado', 'status--sucesso'],
        'cancelado' => ['Cancelado', 'status--erro'], 'faltou' => ['Faltou', 'status--expirado']];
    $selosConta = ['aberta' => ['Aberta', ''], 'fechada' => ['A receber', 'status--expirado'], 'paga' => ['Paga', 'status--sucesso'], 'cancelada' => ['Cancelada', 'status--erro']];
    $idade = $pet->data_nascimento ? $pet->data_nascimento->diff(now()) : null;
    $textoIdade = $idade ? ($idade->y > 0 ? $idade->y.' ano(s)' : $idade->m.' mês(es)') : null;
    $peso = $pet->peso_gramas !== null ? number_format($pet->peso_gramas / 1000, $pet->peso_gramas % 1000 === 0 ? 0 : 1, ',', '.').' kg' : null;
    // Estorno antigo (antes da T2): só a coluna da cobrança.
    $estornado = fn ($c) => (int) $c->estornado_centavos > 0 ? (int) $c->estornado_centavos : (int) ($c->estorno_valor_centavos ?? 0);
@endphp

@section('conteudo')
@if (request()->query('amostra') === 'estados')
    @include('amostras.estados', ['tipoAmostra' => 'pet'])
@else
    <header class="page-header">
        <p class="eyebrow">Ficha do pet</p>
        <h1>{{ $pet->nome }}</h1>
        <p class="intro">Cadastro, alertas de atendimento, agenda e contas do pet. O prontuário não aparece aqui: ele é do veterinário.</p>
    </header>

    <p class="acoes-topo">
        <a class="botao" href="/cadastros"><span aria-hidden="true">←</span> Cadastros</a>
        @if ($podeEditar)
            <button type="button" data-tool="atualizar_pet" data-id="{{ $pet->id }}" data-foco>Editar o cadastro</button>
        @endif
    </p>

    <div class="ficha-grade">
        <section class="bloco" aria-labelledby="titulo-dados">
            <h2 id="titulo-dados">Dados</h2>
            <dl class="ficha">
                <dt>Espécie</dt><dd>{{ $especies[$pet->especie] ?? $pet->especie }}</dd>
                <dt>Raça</dt><dd>{{ $pet->raca ?: '—' }}</dd>
                <dt>Sexo</dt><dd>{{ $sexos[$pet->sexo] ?? '—' }}</dd>
                <dt>Nascimento</dt><dd>{{ $pet->data_nascimento ? $pet->data_nascimento->format('d/m/Y').' ('.$textoIdade.')' : '—' }}</dd>
                <dt>Peso</dt><dd>{{ $peso ?? '—' }}</dd>
                <dt>Microchip</dt><dd class="mono">{{ $pet->microchip ?: '—' }}</dd>
            </dl>
        </section>

        @if ($verTutor)
        <section class="bloco" aria-labelledby="titulo-tutor">
            <h2 id="titulo-tutor">Tutor</h2>
            @if ($pet->tutor)
                <dl class="ficha">
                    <dt>Nome</dt><dd><a href="/tutores/{{ $pet->tutor->id }}">{{ $pet->tutor->nome }}</a></dd>
                    <dt>Telefone</dt><dd class="mono">{{ $pet->tutor->telefone ?: '—' }}</dd>
                    <dt>E-mail</dt><dd>{{ $pet->tutor->email ?: '—' }}</dd>
                </dl>
            @else
                <p class="vazio">Tutor não encontrado.</p>
            @endif
        </section>
        @endif
    </div>

    <section class="bloco" aria-labelledby="titulo-alertas">
        <h2 id="titulo-alertas">Alertas de atendimento</h2>
        @if (filled($pet->alertas))
            <p class="msg msg--aviso"><strong>Atenção:</strong> {{ $pet->alertas }}</p>
        @else
            <p class="vazio">Nenhum alerta no cadastro.</p>
        @endif
    </section>

    @if ($agendamentos !== null)
        <section class="bloco" aria-labelledby="titulo-agenda">
            <h2 id="titulo-agenda">Agendamentos</h2>
            @if ($agendamentos->isEmpty())
                <p class="vazio">Nenhum agendamento.</p>
            @else
                <div class="tabela-rolagem tabela-empilha" tabindex="0" role="region" aria-label="Agendamentos do pet">
                    <table>
                        <thead><tr><th scope="col">Horário</th><th scope="col">Serviço</th><th scope="col">Profissional</th><th scope="col">Setor</th><th scope="col">Situação</th></tr></thead>
                        <tbody>
                            @foreach ($agendamentos as $a)
                                <tr>
                                    <td data-rotulo="Horário">{{ $a->inicio->format('d/m/Y H:i') }}–{{ $a->fim->format('H:i') }}</td>
                                    <td data-rotulo="Serviço">{{ $a->servico }}</td>
                                    <td data-rotulo="Profissional">{{ $a->profissional }}</td>
                                    <td data-rotulo="Setor">{{ $a->setor === 'clinica' ? 'Clínica' : 'Banho e tosa' }}</td>
                                    <td data-rotulo="Situação"><span class="status-badge {{ $selosAgenda[$a->status][1] ?? '' }}">{{ $selosAgenda[$a->status][0] ?? $a->status }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    @if ($cobrancas !== null)
        <section class="bloco" aria-labelledby="titulo-cobrancas">
            <h2 id="titulo-cobrancas">Cobranças</h2>
            @if ($cobrancas->isEmpty())
                <p class="vazio">Nenhuma cobrança.</p>
            @else
                <div class="tabela-rolagem tabela-empilha" tabindex="0" role="region" aria-label="Cobranças do pet">
                    <table>
                        <thead><tr><th scope="col">Nº</th><th scope="col">Serviço</th><th scope="col">Atendimento</th><th scope="col" class="num">Total</th><th scope="col" class="num">Estornado</th><th scope="col" class="num">Líquido</th><th scope="col">Situação</th></tr></thead>
                        <tbody>
                            @foreach ($cobrancas as $c)
                                @php $est = $estornado($c); @endphp
                                <tr>
                                    <td data-rotulo="Nº">{{ $c->id }}</td>
                                    <td data-rotulo="Serviço">{{ $c->servico }}</td>
                                    <td data-rotulo="Atendimento">{{ \Carbon\CarbonImmutable::parse($c->atendimento_em, config('app.timezone'))->format('d/m/Y H:i') }}</td>
                                    <td data-rotulo="Total" class="num">{{ \App\Services\ResultadosClinica::reais((int) $c->total_centavos) }}</td>
                                    <td data-rotulo="Estornado" class="num">{{ \App\Services\ResultadosClinica::reais($est) }}</td>
                                    <td data-rotulo="Líquido" class="num">{{ $c->status === 'paga' || $est > 0 ? \App\Services\ResultadosClinica::reais((int) $c->total_centavos - $est) : '—' }}</td>
                                    <td data-rotulo="Situação"><span class="status-badge {{ $selosConta[$c->status][1] ?? '' }}">{{ $selosConta[$c->status][0] ?? $c->status }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    @if ($vendas !== null)
        <section class="bloco" aria-labelledby="titulo-vendas">
            <h2 id="titulo-vendas">Vendas ligadas ao pet</h2>
            @if ($vendas->isEmpty())
                <p class="vazio">Nenhuma venda.</p>
            @else
                <div class="tabela-rolagem tabela-empilha" tabindex="0" role="region" aria-label="Vendas ligadas ao pet">
                    <table>
                        <thead><tr><th scope="col">Nº</th><th scope="col">Data</th><th scope="col">Setor</th><th scope="col" class="num">Total</th><th scope="col" class="num">Estornado</th><th scope="col" class="num">Líquido</th><th scope="col">Situação</th></tr></thead>
                        <tbody>
                            @foreach ($vendas as $v)
                                <tr>
                                    <td data-rotulo="Nº">{{ $v->id }}</td>
                                    <td data-rotulo="Data">{{ ($v->pago_em ?? $v->created_at)?->format('d/m/Y H:i') }}</td>
                                    <td data-rotulo="Setor">{{ $v->setor === 'clinica' ? 'Clínica' : 'Petshop' }}</td>
                                    <td data-rotulo="Total" class="num">{{ \App\Services\ResultadosClinica::reais((int) $v->total_centavos) }}</td>
                                    <td data-rotulo="Estornado" class="num">{{ \App\Services\ResultadosClinica::reais((int) $v->estornado_centavos) }}</td>
                                    <td data-rotulo="Líquido" class="num">{{ $v->status === 'paga' || (int) $v->estornado_centavos > 0 ? \App\Services\ResultadosClinica::reais((int) $v->total_centavos - (int) $v->estornado_centavos) : '—' }}</td>
                                    <td data-rotulo="Situação"><span class="status-badge {{ $selosConta[$v->status][1] ?? '' }}">{{ $selosConta[$v->status][0] ?? $v->status }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    @include('clinica._acoes')
@endif
@endsection
