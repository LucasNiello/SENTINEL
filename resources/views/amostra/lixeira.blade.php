@extends('amostra.layout')

@section('conteudo')
@php use App\Amostra\DadosAmostra; @endphp
@if (!DadosAmostra::pode($papel, 'ver_lixeira'))
    @include('amostra.parciais.negado-papel', ['motivo' => 'A lixeira é para operadores e administradores.'])
@else
<div class="pagina">
    <h1>Lixeira</h1>
    <p class="lead">Itens excluídos ficam aqui até o prazo de retenção. Depois disso eles são arquivados (somem das telas, mas ficam guardados por exigência fiscal e trabalhista). Restaurar exige a senha de um administrador.</p>

    @if ($papel === 'operador')
        <div class="msg msg--neutra" role="note">Você é <span class="papel-tag">operador</span>: para restaurar, chame um administrador para digitar a senha dele.</div>
    @endif

    <div class="barra-tabela">
        <span class="status status--neutro">Prazo de retenção: 90 dias</span>
        <span class="espaco"></span>
        @if (DadosAmostra::pode($papel, 'retencao'))
            <a class="btn btn--pequeno" href="{{ $u('lixeira/retencao') }}">Mudar prazo</a>
        @endif
        @if (DadosAmostra::pode($papel, 'arquivados'))
            <a class="btn btn--pequeno" href="{{ $u('arquivados') }}">Ver arquivados</a>
        @endif
    </div>

    <div class="tabela-rolagem" tabindex="0" role="region" aria-label="Itens na lixeira">
        <table>
            <caption>{{ count($itens) }} itens · dados fictícios</caption>
            <thead>
                <tr><th scope="col" class="num">id</th><th scope="col">Tipo</th><th scope="col">Item</th><th scope="col">Excluído por</th><th scope="col">Em</th><th scope="col">Arquivado em</th><th scope="col">Ação</th></tr>
            </thead>
            <tbody>
                @foreach ($itens as $item)
                    <tr>
                        <td class="num">{{ $item['id'] }}</td>
                        <td>{{ $item['entidade'] }}</td>
                        <td>
                            {{ $item['nome'] }}
                            @if ($item['conflito']) <br><span class="tag tag--editado">depende de item na lixeira</span> @endif
                        </td>
                        <td>{{ $item['excluido_por'] }}</td>
                        <td>{{ $item['excluido_em'] }}</td>
                        <td>{{ $item['expira_em'] }}</td>
                        <td><button type="button" class="btn btn--pequeno" data-abrir="{{ $item['conflito'] ? 'modal-conflito' : 'modal-reautenticacao' }}">Restaurar</button></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p><button type="button" class="btn btn--erro" data-abrir="modal-exclusao">Ver confirmação de exclusão</button> <span class="lead">(a tela que aparece ao mandar um item para a lixeira)</span></p>

    <dialog id="modal-reautenticacao" class="card--risco-escrita" aria-labelledby="t-reauth">
        <h2 id="t-reauth">Restaurar com senha de administrador</h2>
        <p>Para restaurar, um administrador da empresa precisa se identificar. A auditoria registra quem autorizou.</p>
        <form data-amostra="Isto é uma amostra: ninguém é autenticado." novalidate>
            <div class="campo"><label for="reauth-email">E-mail do administrador</label><input id="reauth-email" type="email" value="admin@sentinel.local" autocomplete="off"></div>
            <div class="campo"><label for="reauth-senha">Senha do administrador</label><input id="reauth-senha" type="password" autocomplete="off"></div>
            <div class="acoes">
                <button type="button" class="btn-confirmar" data-hold="Nada foi restaurado: isto é uma amostra."><span class="btn-confirmar__fill"></span><span class="btn-confirmar__label">Restaurar</span></button>
                <button type="button" class="btn-cancelar" data-cancelar>Cancelar</button>
            </div>
            <p class="dica-segurar">Segure "Restaurar" por 2 segundos.</p>
            <div class="msg msg--aviso" data-aviso-soltou hidden role="status"></div>
        </form>
    </dialog>

    <dialog id="modal-conflito" class="card--risco-escrita" aria-labelledby="t-conflito">
        <h2 id="t-conflito">Não dá para restaurar só este item</h2>
        <p>O lançamento "Consultoria tributária — Oficina Rota 50" depende do cliente <strong>Oficina Rota 50</strong>, que também está na lixeira.</p>
        <div class="msg msg--aviso" role="note">Escolha: recusar a restauração, ou restaurar o cliente junto. (A regra final ainda precisa ser decidida.)</div>
        <div class="acoes">
            <button type="button" class="btn" data-fechar>Recusar</button>
            <button type="button" class="btn btn--aviso" data-abrir="modal-reautenticacao" data-fechar>Restaurar os dois</button>
        </div>
    </dialog>

    <dialog id="modal-exclusao" class="card--risco-exclusao" aria-labelledby="t-excl">
        <h2 id="t-excl">Mover para a lixeira?</h2>
        <dl class="resumo"><dt>Ação</dt><dd>Mover para a lixeira</dd><dt>Cliente</dt><dd>Oficina Rota 50</dd><dt>Depois</dt><dd>Dá para restaurar aqui (precisa de um administrador).</dd></dl>
        <div class="acoes">
            <button type="button" class="btn-confirmar" data-hold="Nada foi excluído: isto é uma amostra."><span class="btn-confirmar__fill"></span><span class="btn-confirmar__label">Excluir</span></button>
            <button type="button" class="btn-cancelar" data-cancelar>Cancelar</button>
        </div>
        <p class="dica-segurar">Segure "Excluir" por 2 segundos. Se soltar antes, nada acontece.</p>
        <div class="msg msg--aviso" data-aviso-soltou hidden role="status"></div>
    </dialog>
</div>
@endif
@endsection
