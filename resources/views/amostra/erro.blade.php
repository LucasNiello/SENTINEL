@extends('amostra.layout')

@section('conteudo')
<div class="pagina pagina--estreita">
    <div class="painel-amostra">
        <h2>Só da amostra: tipo de erro</h2>
        <nav class="chips" aria-label="Páginas de erro">
            @foreach ($todos as $cod => $info)
                <a class="chip" href="{{ $u('erro/' . $cod) }}" @if ($erro['codigo'] === (string) $cod) aria-current="true" @endif>{{ $cod }}</a>
            @endforeach
        </nav>
    </div>

    <div class="erro-pagina" role="alert">
        <div class="codigo" aria-hidden="true">{{ $erro['codigo'] }}</div>
        <h1>{{ $erro['titulo'] }}</h1>
        <p class="lead" style="margin-inline:auto">{{ $erro['texto'] }}</p>
        <div class="acoes" style="justify-content:center">
            @foreach ($erro['acoes'] as [$rotulo, $rota])
                <a class="btn btn--primario" href="{{ $u($rota) }}">{{ $rotulo }}</a>
            @endforeach
        </div>
    </div>
</div>
@endsection
