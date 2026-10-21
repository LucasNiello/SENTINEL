@extends('layouts.app')

@section('titulo', 'Página não encontrada')

{{-- Erro 404: só a mensagem em português; nunca a exceção, o SQL ou o caminho do arquivo. --}}
@section('conteudo')
    <div class="erro-pagina">
        <p class="erro-pagina__codigo">Erro 404</p>
        <h1>Página não encontrada</h1>
        <p>O endereço que você abriu não existe ou foi removido.</p>
        <p><a href="/agente">Voltar ao início</a></p>
    </div>
@endsection
