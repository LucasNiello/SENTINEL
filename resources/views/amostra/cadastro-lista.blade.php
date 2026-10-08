@extends('amostra.layout')

@section('conteudo')
@php
    use App\Amostra\DadosAmostra;
    $podeEscrever = DadosAmostra::pode($papel, $slug === 'funcionarios' ? 'criar_funcionario' : 'escrever');
    $podeExcluir = DadosAmostra::pode($papel, 'excluir') && ($slug !== 'funcionarios' || DadosAmostra::pode($papel, 'criar_funcionario'));
    $numericas = DadosAmostra::colunasNumericas();
@endphp
<div class="pagina">
    <p><a href="{{ $u('cadastros') }}">← Cadastros</a></p>
    <h1>{{ $entidade['rotulo'] }}</h1>

    @if (!$podeEscrever)
        <div class="msg msg--neutra" role="note">Seu papel (<span class="papel-tag">{{ $papel }}</span>) só consulta {{ $slug === 'funcionarios' ? 'funcionários (só administradores cadastram)' : 'este cadastro' }}. Os botões de gravar não aparecem.</div>
    @endif

    <div class="barra-tabela">
        <form method="get" action="{{ $u('cadastros/' . $slug) }}" role="search" class="barra-tabela" style="margin:0">
            <input type="hidden" name="papel" value="{{ $papel }}">
            <label class="sr-only" for="busca">Buscar em {{ $entidade['rotulo'] }}</label>
            <input class="campo-busca" id="busca" name="q" type="search" value="{{ $termo }}" placeholder="Buscar…">
            <button type="submit" class="btn btn--primario">Buscar</button>
        </form>
        <span class="espaco"></span>
        <button type="button" class="btn" data-amostra-acao="Exportaria o CSV desta lista, com CPF e salário mascarados.">Exportar CSV</button>
        @if ($podeEscrever)
            <a class="btn btn--primario" href="{{ $u('formulario/' . $entidade['formulario'], ['modo' => 'criar']) }}">Novo {{ $entidade['singular'] }}</a>
        @endif
    </div>

    <div class="tabela-rolagem" tabindex="0" role="region" aria-label="Lista de {{ $entidade['rotulo'] }}">
        <table>
            <caption>{{ count($linhas) }} {{ count($linhas) === 1 ? 'registro' : 'registros' }}@if ($termo !== '') para "{{ $termo }}" @endif · dados fictícios · CPF mascarado</caption>
            <thead>
                <tr>
                    @foreach ($entidade['colunas'] as $col)
                        <th scope="col" @if (in_array($col, $numericas, true)) class="num" @endif>{{ $col }}</th>
                    @endforeach
                    @if ($podeEscrever || $podeExcluir) <th scope="col">Ações</th> @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($linhas as $linha)
                    <tr>
                        @foreach ($entidade['colunas'] as $col)
                            <td @if (in_array($col, $numericas, true)) class="num" @endif>{{ $linha[$col] ?? '' }}</td>
                        @endforeach
                        @if ($podeEscrever || $podeExcluir)
                            <td>
                                @if ($podeEscrever) <a class="btn btn--pequeno" href="{{ $u('formulario/' . $entidade['formulario'], ['modo' => 'atualizar']) }}">Editar</a> @endif
                                @if ($podeExcluir) <button type="button" class="btn btn--pequeno btn--erro" data-abrir="modal-exclusao">Excluir</button> @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ count($entidade['colunas']) + 1 }}">Nenhum registro encontrado para "{{ $termo }}". <a href="{{ $u('cadastros/' . $slug) }}">Limpar busca</a></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <dialog id="modal-exclusao" class="card--risco-exclusao" aria-labelledby="titulo-exclusao">
        <h2 id="titulo-exclusao">Mover para a lixeira?</h2>
        <p>O {{ $entidade['singular'] }} sai das listas, mas dá para restaurar depois pela Lixeira (precisa de um administrador).</p>
        <div class="acoes">
            <button type="button" class="btn-confirmar" data-hold="Nada foi excluído: isto é uma amostra.">
                <span class="btn-confirmar__fill"></span><span class="btn-confirmar__label">Excluir</span>
            </button>
            <button type="button" class="btn-cancelar" data-cancelar>Cancelar</button>
        </div>
        <p class="dica-segurar">Segure "Excluir" por 2 segundos. Se soltar antes, nada acontece.</p>
        <div class="msg msg--aviso" data-aviso-soltou hidden role="status"></div>
    </dialog>
</div>
@endsection
