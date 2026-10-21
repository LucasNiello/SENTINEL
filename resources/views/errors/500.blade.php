@extends('layouts.app')

@section('titulo', 'Algo deu errado')

{{-- Erro 500: só a mensagem em português; nunca a exceção, o SQL ou o caminho do arquivo. --}}
@section('conteudo')
    <div class="erro-pagina">
        <p class="erro-pagina__codigo">Erro 500</p>
        <h1>Algo deu errado</h1>
        <p>Não foi possível concluir agora. Tente de novo em instantes. Se continuar, avise o responsável pelo sistema.</p>
        <p><a href="/agente">Voltar ao início</a></p>
    </div>
@endsection
