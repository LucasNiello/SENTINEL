@extends('amostra.layout')

@section('conteudo')
<div class="pagina pagina--estreita">
    <div class="msg msg--erro" role="note">
        <strong>HIPÓTESE — a fila de comandos ainda não foi definida (Tarefa 5).</strong>
        <span class="msg__acao">Esta tela mostra uma interpretação possível: pedidos feitos com a IA fora do ar ficam numa fila e só são executados com a confirmação humana de sempre. Se a definição for outra, esta tela muda.</span>
    </div>

    <h1>Fila de comandos</h1>
    <p class="lead">O agente está fora do ar. Seus pedidos ficam guardados aqui e são enviados quando ele voltar. Nada é gravado sem você confirmar.</p>

    <div class="resumo-numeros">
        @foreach ($status as $chave => [$icone, $rotulo])
            <div class="numero"><b>{{ count(array_filter($itens, fn ($i) => $i['status'] === $chave)) }}</b> {{ $icone }} {{ $rotulo }}</div>
        @endforeach
    </div>

    <ul class="lista-fila" aria-label="Pedidos na fila">
        @foreach ($itens as $item)
            @php $classe = ['fila' => 'status--neutro', 'confirmar' => 'status--aviso', 'concluido' => 'status--sucesso', 'falhou' => 'status--erro'][$item['status']]; @endphp
            <li class="item-fila card--risco-{{ $item['tipo'] }}" style="border-left:6px solid var(--cor-risco, var(--color-tinta-2))">
                <div class="item-fila__texto">
                    <div>“{{ $item['texto'] }}” <span class="mono lead">{{ $item['enviado'] }}</span></div>
                    <div class="status {{ $classe }}">{{ $status[$item['status']][0] }} {{ $status[$item['status']][1] }}</div>
                    <div class="lead">{{ $item['detalhe'] }}</div>
                </div>
                @if ($item['status'] === 'fila')
                    <button type="button" class="btn btn--pequeno" data-amostra-acao="Tentaria enviar este pedido agora.">Tentar agora</button>
                    <button type="button" class="btn btn--pequeno" data-amostra-acao="Tiraria este pedido da fila.">Remover</button>
                @elseif ($item['status'] === 'confirmar')
                    <a class="btn btn--pequeno btn--erro" href="{{ $u('agente/confirmacao-exclusao') }}">Revisar e confirmar</a>
                @elseif ($item['status'] === 'falhou')
                    <button type="button" class="btn btn--pequeno" data-amostra-acao="Tentaria enviar de novo.">Tentar de novo</button>
                @endif
            </li>
        @endforeach
    </ul>
</div>
@endsection
