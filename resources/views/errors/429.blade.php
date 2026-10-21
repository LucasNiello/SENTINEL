@extends('layouts.app')

@section('titulo', 'Muitos pedidos em pouco tempo')

{{-- Erro 429: só a mensagem em português; nunca a exceção, o SQL ou o caminho do arquivo. --}}
@section('conteudo')
    <div class="erro-pagina">
        <p class="erro-pagina__codigo">Erro 429</p>
        <h1>Muitos pedidos em pouco tempo</h1>
        <p>Aguarde um minuto e tente de novo.</p>
        <p><a href="/agente">Voltar ao início</a></p>
    </div>
@endsection
