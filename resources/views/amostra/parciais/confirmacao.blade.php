@php
    $situacao = $bloco['situacao'];
    $risco = $bloco['risco'];
    $rotulosRisco = ['leitura' => 'leitura', 'escrita' => 'escrita', 'exclusao' => 'exclusão'];
    $inativa = in_array($situacao, ['expirada'], true);
@endphp
<section class="card card--risco-{{ $risco }} @if ($situacao === 'expirada') card--expirado @endif" aria-label="Confirmação necessária: {{ $bloco['titulo'] }}">
    <div class="card__titulo">
        <span>Confirmação necessária:</span> <span>{{ $bloco['titulo'] }}</span>
        <span class="card__risco">risco: {{ $rotulosRisco[$risco] }}</span>
    </div>
    <dl class="resumo">
        @foreach ($bloco['dados'] as [$rotulo, $valor])
            <dt>{{ $rotulo }}</dt><dd>{{ $valor }}</dd>
        @endforeach
    </dl>
    <div class="acoes">
        <button type="button" class="btn-confirmar" @if ($situacao === 'ativa' || $situacao === 'soltou') data-hold="Nada foi gravado: isto é uma amostra." @else disabled @endif>
            <span class="btn-confirmar__fill"></span>
            <span class="btn-confirmar__label">{{ $bloco['botao'] }}</span>
        </button>
        <button type="button" class="btn-cancelar" data-cancelar @if ($inativa) disabled @endif>Cancelar</button>
    </div>
    @if ($situacao === 'ativa')
        <p class="dica-segurar">Segure "{{ $bloco['botao'] }}" por 2 segundos. Se soltar antes, nada é gravado.</p>
        <div class="msg msg--aviso" data-aviso-soltou hidden role="status"></div>
    @elseif ($situacao === 'soltou')
        <div class="msg msg--aviso" data-aviso-soltou role="status">Você soltou antes do fim. Nada foi gravado.</div>
    @elseif ($situacao === 'quase')
        <div class="msg msg--aviso" role="status">Tempo quase esgotado. Peça de novo.</div>
    @elseif ($situacao === 'expirada')
        <div class="msg msg--aviso" role="status">Confirmação expirada. Nada foi gravado. Peça de novo.</div>
    @endif
</section>
