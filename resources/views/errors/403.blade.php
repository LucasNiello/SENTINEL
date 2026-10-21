@extends('layouts.app')

@section('titulo', 'Acesso negado')

{{-- Erro 403: só a mensagem em português; nunca a exceção, o SQL ou o caminho do arquivo. --}}
@section('conteudo')
    <div class="erro-pagina">
        <p class="erro-pagina__codigo">Erro 403</p>
        <h1>Acesso negado</h1>
        <p>Seu papel não permite abrir esta página. Se precisar dela, fale com um administrador.</p>
        <p><a href="/agente">Voltar ao início</a></p>
    </div>
@endsection
