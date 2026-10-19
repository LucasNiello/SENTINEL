@extends('amostra.layout')

@section('conteudo')
@php use App\Amostra\DadosAmostra; @endphp
@if (!DadosAmostra::pode($papel, 'arquivados'))
    @include('amostra.parciais.negado-papel', ['motivo' => 'Itens arquivados só são vistos por administradores.'])
@else
<div class="pagina">
    <p><a href="{{ $u('lixeira') }}">← Lixeira</a></p>
    <h1>Arquivados</h1>
    <p class="lead">Itens cujo prazo na lixeira terminou. Não aparecem em buscas normais, mas continuam guardados com suas ligações, porque a lei exige guardar. A exclusão definitiva só acontece quando um administrador decide, nunca sozinha.</p>

    <div class="tabela-rolagem" tabindex="0" role="region" aria-label="Itens arquivados">
        <table>
            <caption>{{ count($itens) }} itens · dados fictícios</caption>
            <thead>
                <tr><th scope="col" class="num">id</th><th scope="col">Tipo</th><th scope="col">Item</th><th scope="col">Arquivado em</th><th scope="col">Ligações guardadas</th><th scope="col">Ação</th></tr>
            </thead>
            <tbody>
                @foreach ($itens as $item)
                    <tr>
                        <td class="num">{{ $item['id'] }}</td><td>{{ $item['entidade'] }}</td><td>{{ $item['nome'] }}</td><td>{{ $item['arquivado_em'] }}</td><td>{{ $item['relacoes'] }}</td>
                        <td><button type="button" class="btn btn--pequeno btn--erro" data-abrir="modal-exclusao-fisica">Excluir de vez</button></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <dialog id="modal-exclusao-fisica" class="card--risco-exclusao" aria-labelledby="t-fisica">
        <h2 id="t-fisica">Excluir definitivamente?</h2>
        <div class="msg msg--erro" role="alert"><strong>Isto apaga do banco e não tem volta.</strong><span class="msg__acao">Só continue se a lei permitir descartar este registro.</span></div>
        <form data-amostra="Isto é uma amostra: ninguém é autenticado." novalidate>
            <div class="campo"><label for="fis-senha">Sua senha de administrador</label><input id="fis-senha" type="password" autocomplete="off"></div>
            <div class="acoes">
                <button type="button" class="btn-confirmar" data-hold="Nada foi excluído: isto é uma amostra."><span class="btn-confirmar__fill"></span><span class="btn-confirmar__label">Excluir de vez</span></button>
                <button type="button" class="btn-cancelar" data-cancelar>Cancelar</button>
            </div>
            <p class="dica-segurar">Segure "Excluir de vez" por 2 segundos. A auditoria registra quem excluiu.</p>
            <div class="msg msg--aviso" data-aviso-soltou hidden role="status"></div>
        </form>
    </dialog>
</div>
@endif
@endsection
