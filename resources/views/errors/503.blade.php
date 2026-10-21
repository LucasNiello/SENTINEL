@extends('layouts.app')

@section('titulo', 'Sistema em manutenção')

{{-- Erro 503: só a mensagem em português; nunca a exceção, o SQL ou o caminho do arquivo. --}}
@section('conteudo')
    <div class="erro-pagina">
        <p class="erro-pagina__codigo">Erro 503</p>
        <h1>Sistema em manutenção</h1>
        <p>O Sentinel está em manutenção agora. Tente de novo em alguns minutos.</p>
        <p><a href="/agente">Voltar ao início</a></p>
    </div>
@endsection
