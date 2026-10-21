@extends('layouts.app')

@section('titulo', 'Sessão expirada')

{{-- Erro 419: só a mensagem em português; nunca a exceção, o SQL ou o caminho do arquivo. --}}
@section('conteudo')
    <div class="erro-pagina">
        <p class="erro-pagina__codigo">Erro 419</p>
        <h1>Sessão expirada</h1>
        <p>Por segurança, a página ficou parada tempo demais. Nada foi gravado. Entre de novo para continuar.</p>
        <p><a href="/login">Entrar de novo</a></p>
    </div>
@endsection
