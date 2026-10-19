@extends('amostra.layout')

@section('conteudo')
<div class="pagina pagina--estreita">
    <div class="painel-amostra">
        <h2>Só da amostra: estados do agente</h2>
        <nav class="chips" aria-label="Estados do agente">
            @foreach ($estados as $chave => $info)
                <a class="chip" href="{{ $u('agente/' . $chave) }}" @if ($estadoAtual === $chave) aria-current="true" @endif>{{ $info['titulo'] }}</a>
            @endforeach
        </nav>
    </div>

    <h1>Agente</h1>
    <p class="lead">{{ $estados[$estadoAtual]['titulo'] }}</p>

    <div class="conversa" aria-live="polite">
        @foreach ($estados[$estadoAtual]['blocos'] as $bloco)
            @include('amostra.parciais.bloco')
        @endforeach
    </div>

    <form class="form-comando" data-amostra="Isto é uma amostra: o agente não responde de verdade.">
        <label class="sr-only" for="campo-mensagem">Mensagem para o agente</label>
        <input type="text" id="campo-mensagem" placeholder="Pergunte algo ou peça um cadastro…" autocomplete="off">
        <button type="submit" class="btn btn--primario">Enviar</button>
    </form>
</div>
@endsection
