@extends('layouts.app')

@section('titulo', 'Ficha do tutor')

@php
    $especies = ['cao' => 'Cão', 'gato' => 'Gato', 'ave' => 'Ave', 'roedor' => 'Roedor', 'reptil' => 'Réptil', 'outro' => 'Outro'];
@endphp

{{-- 15/11: ficha do tutor, só leitura. Os pets dele com link para a ficha de cada um; o cadastro continua em Cadastros. --}}
@push('estilos')
    <style>
        .pets-do-tutor {list-style:none;padding:0;margin:0;display:grid;grid-template-columns:minmax(0,1fr);gap:16px}
        @media (min-width:768px) {
            .pets-do-tutor {grid-template-columns:repeat(2,minmax(0,1fr))}
        }
    </style>
@endpush

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
    </div>

    @if ($pets !== null)
        <section class="bloco" aria-labelledby="titulo-pets">
            <h2 id="titulo-pets">Pets</h2>
            @if ($pets->isEmpty())
                <p class="vazio">Nenhum pet cadastrado.</p>
            @else
                <ul class="pets-do-tutor" role="list">
                    @foreach ($pets as $pet)
                        <li>
                            <dl class="ficha">
                                <dt>Nome</dt><dd><a href="/pets/{{ $pet->id }}">{{ $pet->nome }}</a></dd>
                                <dt>Espécie</dt><dd>{{ $especies[$pet->especie] ?? $pet->especie }}</dd>
                                <dt>Raça</dt><dd>{{ $pet->raca ?: '—' }}</dd>
                            </dl>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    @include('clinica._acoes')
@endif
@endsection
