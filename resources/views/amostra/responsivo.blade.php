@extends('amostra.layout')

@section('conteudo')
<div class="pagina">
    <h1>Celular e tablet</h1>
    <p class="lead">A mesma tela do agente em três larguras. Dentro de cada moldura não deve haver rolagem horizontal da página.</p>
    <div class="moldura-celular">
        <figure>
            <figcaption>Celular — 375 px</figcaption>
            <iframe title="Agente em 375 pixels de largura" src="{{ $u('agente/conversa') }}" width="375"></iframe>
        </figure>
        <figure>
            <figcaption>Tablet — 768 px</figcaption>
            <iframe title="Agente em 768 pixels de largura" src="{{ $u('agente/conversa') }}" width="768"></iframe>
        </figure>
    </div>
</div>
@endsection
