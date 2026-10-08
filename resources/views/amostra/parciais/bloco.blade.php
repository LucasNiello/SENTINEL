@switch($bloco['tipo'])
    @case('usuario')
        <div class="msg-usuario">{{ $bloco['texto'] }}</div>
        @break
    @case('agente')
        <div class="msg-agente">{{ $bloco['texto'] }}</div>
        @break
    @case('sugestoes')
        <div class="sugestoes" role="group" aria-label="Sugestões de pedido">
            @foreach ($bloco['itens'] as $item)
                <button type="button" class="chip" data-sugestao="{{ $item }}">{{ $item }}</button>
            @endforeach
        </div>
        @break
    @case('carregando')
        <div class="carregando" role="status" aria-busy="true">
            <span>{{ $bloco['texto'] }}</span>
            <div class="carregando__barra"></div>
        </div>
        @break
    @case('tabela')
        @include('amostra.parciais.tabela')
        @break
    @case('aviso')
        <div class="msg msg--aviso" role="status"><strong>Aviso:</strong> {{ $bloco['texto'] }}</div>
        @break
    @case('negado')
        <div class="msg msg--erro" role="alert"><strong>✕ {{ $bloco['texto'] }}</strong></div>
        @break
    @case('erro')
        <div class="msg msg--erro" role="alert"><strong>✕ {{ $bloco['texto'] }}</strong><span class="msg__acao">{{ $bloco['acao'] }}</span></div>
        @break
    @case('confirmacao')
        @include('amostra.parciais.confirmacao')
        @break
    @case('formulario')
        @include('amostra.parciais.formulario', ['noChat' => true])
        @break
    @case('popup')
        <div class="popup popup--{{ $bloco['estado'] }}" role="img" aria-label="Exemplo de popup">{{ $bloco['texto'] }}</div>
        @break
    @case('memoria')
        <div class="chip-memoria"><span aria-hidden="true">↺</span> {{ $bloco['texto'] }}</div>
        @break
    @case('nota')
        <p class="lead">{{ $bloco['texto'] }}</p>
        @break
@endswitch
