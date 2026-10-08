@extends('amostra.layout')

@section('conteudo')
@php use App\Amostra\DadosAmostra; @endphp
@if (!DadosAmostra::pode($papel, 'retencao'))
    @include('amostra.parciais.negado-papel', ['motivo' => 'Só administradores mudam o prazo da lixeira.'])
@else
<div class="pagina pagina--estreita">
    <p><a href="{{ $u('lixeira') }}">← Lixeira</a></p>
    <h1>Prazo da lixeira</h1>
    <div class="msg msg--aviso" role="note">Tela inferida: o prazo "configurável" está na política de exclusão, mas ainda não está em nenhum checklist. Falta decidir onde ele é guardado.</div>
    <p class="lead">Quanto tempo um item excluído fica na lixeira antes de ser arquivado.</p>

    <form class="card card--risco-escrita" data-amostra="Para gravar, segure o botão Confirmar." novalidate aria-label="Prazo da lixeira">
        <div class="card__titulo"><span>Mudar o prazo da lixeira</span><span class="card__risco">risco: escrita</span></div>
        <div class="campo">
            <label for="retencao">Prazo</label>
            <select id="retencao" name="retencao" aria-describedby="retencao-ajuda">
                @foreach ($opcoes as $valor => $rotulo)
                    <option value="{{ $valor }}" @if ($valor === '90') selected @endif>{{ $rotulo }}</option>
                @endforeach
            </select>
            <span class="dica" id="retencao-ajuda">Vale para os próximos itens excluídos. O que já está na lixeira mantém o prazo antigo.</span>
        </div>
        <div class="acoes">
            <button type="button" class="btn-confirmar" data-hold="Nada foi gravado: isto é uma amostra."><span class="btn-confirmar__fill"></span><span class="btn-confirmar__label">Confirmar</span></button>
            <button type="button" class="btn-cancelar" data-cancelar>Cancelar</button>
        </div>
        <p class="dica-segurar">Segure "Confirmar" por 2 segundos. Se soltar antes, nada é gravado.</p>
        <div class="msg msg--aviso" data-aviso-soltou hidden role="status"></div>
    </form>
</div>
@endif
@endsection
