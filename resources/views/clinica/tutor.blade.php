@extends('layouts.app')

@section('titulo', 'Ficha do tutor')

@php
    $especies = ['cao' => 'Cão', 'gato' => 'Gato', 'ave' => 'Ave', 'roedor' => 'Roedor', 'reptil' => 'Réptil', 'outro' => 'Outro'];
@endphp

{{-- 15/11: ficha do tutor, só leitura. Os pets dele com link para a ficha de cada um; o cadastro continua em Cadastros. --}}
@section('conteudo')
@if (request()->query('amostra') === 'estados')
    @include('amostras.estados', ['tipoAmostra' => 'tutor'])
@else
    <header class="page-header">
        <p class="eyebrow">Ficha do tutor</p>
        <h1>{{ $tutor->nome }}</h1>
        <p class="intro">Contato do tutor e os pets dele. Para alterar o cadastro, use "Editar o cadastro".</p>
    </header>

    <p class="acoes-topo">
        <a class="botao" href="/cadastros"><span aria-hidden="true">←</span> Cadastros</a>
        @if ($podeEditar)
            <button type="button" data-tool="atualizar_tutor" data-id="{{ $tutor->id }}" data-foco>Editar o cadastro</button>
        @endif
    </p>

    <div class="ficha-grade">
        <section class="bloco" aria-labelledby="titulo-contato">
            <h2 id="titulo-contato">Contato</h2>
            <dl class="ficha">
                <dt>Telefone</dt><dd class="mono">{{ $tutor->telefone ?: '—' }}</dd>
                <dt>E-mail</dt><dd>{{ $tutor->email ?: '—' }}</dd>
                <dt>Endereço</dt><dd>{{ $tutor->endereco ?: '—' }}</dd>
            </dl>
        </section>

        @if ($pets !== null)
        <section class="bloco" aria-labelledby="titulo-pets">
            <h2 id="titulo-pets">Pets</h2>
            @if ($pets->isEmpty())
                <p class="vazio">Nenhum pet cadastrado.</p>
            @else
                <div class="tabela-rolagem tabela-empilha" tabindex="0" role="region" aria-label="Pets do tutor">
                    <table>
                        <thead><tr><th scope="col">Nome</th><th scope="col">Espécie</th><th scope="col">Raça</th></tr></thead>
                        <tbody>
                            @foreach ($pets as $p)
                                <tr>
                                    <td data-rotulo="Nome"><a href="/pets/{{ $p->id }}">{{ $p->nome }}</a></td>
                                    <td data-rotulo="Espécie">{{ $especies[$p->especie] ?? $p->especie }}</td>
                                    <td data-rotulo="Raça">{{ $p->raca ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
        @endif
    </div>

    @include('clinica._acoes')
@endif
@endsection
