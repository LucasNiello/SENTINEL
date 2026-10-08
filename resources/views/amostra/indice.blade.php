@extends('amostra.layout')

@section('conteudo')
@php
    $contagem = ['amostra' => 0, 'existe' => 0, 'hipotese' => 0];
    foreach ($grupos as $itensGrupo) { foreach ($itensGrupo as $item) { $contagem[$item['situacao']]++; } }
    $rotulosSituacao = ['amostra' => 'Amostra pronta', 'existe' => 'Já existe no repositório', 'hipotese' => 'Hipótese'];
@endphp
<div class="pagina">
    <h1>Índice das telas do Sentinel (amostra)</h1>
    <p class="lead">Todas as telas do sistema, em modo de demonstração. O visual é o que se pretende; a coluna "Falta no backend" mostra o que ainda precisa ser feito para a tela funcionar de verdade. Tela bonita não é tela pronta.</p>

    <div class="resumo-numeros" role="list">
        <div class="numero" role="listitem"><b>{{ $total }}</b> telas no inventário</div>
        <div class="numero" role="listitem"><b>{{ $contagem['amostra'] }}</b> amostras prontas</div>
        <div class="numero" role="listitem"><b>{{ $contagem['existe'] }}</b> já existem (vitrine e loader do Matheus)</div>
        <div class="numero" role="listitem"><b>{{ $contagem['hipotese'] }}</b> hipótese (fila de comandos)</div>
    </div>

    <div class="msg msg--aviso">
        <strong>Como ler:</strong> origem <span class="tag tag--neutro">N</span> = está nos checklists do Notion; origem <span class="tag tag--neutro">I</span> = inferida, precisa de confirmação do grupo. Use "Ver como" no topo para trocar o papel e ver o que muda.
    </div>

    @foreach ($grupos as $grupo => $itens)
        <section class="indice-grupo" aria-labelledby="g-{{ $loop->index }}">
            <h2 id="g-{{ $loop->index }}">{{ $grupo }}</h2>
            <div class="tabela-rolagem" tabindex="0" role="region" aria-label="Telas do grupo {{ $grupo }}">
                <table>
                    <thead>
                        <tr><th scope="col" class="num">#</th><th scope="col">Tela</th><th scope="col">Origem</th><th scope="col">Situação</th><th scope="col">Falta no backend</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($itens as $item)
                            <tr>
                                <td class="num">{{ $item['n'] }}</td>
                                <td>
                                    @if ($item['rota'] !== null)
                                        <a href="{{ $u($item['rota']) }}">{{ $item['tela'] }}</a>
                                    @else
                                        {{ $item['tela'] }}
                                    @endif
                                </td>
                                <td><span class="tag tag--neutro">{{ $item['origem'] }}</span></td>
                                <td><span class="selo selo--{{ $item['situacao'] }}">{{ $rotulosSituacao[$item['situacao']] }}</span></td>
                                <td>{{ $item['falta'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach
</div>
@endsection
