@extends('layouts.app')

@section('titulo', 'Entrada necessária')

{{-- Erro 401: só a mensagem em português; nunca a exceção, o SQL ou o caminho do arquivo. --}}
@section('conteudo')
    <div class="erro-pagina">
        <p class="erro-pagina__codigo">Erro 401</p>
        <h1>Entrada necessária</h1>
        <p>Para abrir esta página, entre com o seu usuário.</p>
        <p><a href="/login">Entrar</a></p>
    </div>
@endsection
