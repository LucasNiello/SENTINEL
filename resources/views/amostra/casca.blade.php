@extends('amostra.layout')

@section('conteudo')
<div class="pagina pagina--estreita">
    <div class="painel-amostra">
        <h2>Só da amostra: variações da casca</h2>
        <div class="chips">
            <a class="chip" href="{{ $u('casca') }}" @if ($menuModo === 'normal') aria-current="true" @endif>Menu completo</a>
            <a class="chip" href="{{ $u('casca', ['menu' => 'breve']) }}" @if ($menuModo === 'breve') aria-current="true" @endif>Menu com itens "em breve"</a>
            <a class="chip" href="{{ $u('responsivo') }}">Ver em celular e tablet</a>
        </div>
    </div>

    <h1>Casca da aplicação</h1>
    <p class="lead">A moldura que todas as telas logadas herdam: header, menu e rodapé. O conteúdo de cada tela entra no meio.</p>

    <dl class="resumo">
        <dt>Header</dt><dd>logo do farol, nome, estado do agente, usuário com o papel visível e Sair.</dd>
        <dt>Menu</dt><dd>só mostra o que o papel pode usar. Itens de fases futuras nascem como "em breve".</dd>
        <dt>Rodapé</dt><dd>versão, TCC e equipe.</dd>
        <dt>Celular</dt><dd>os itens quebram de linha; tabelas largas rolam dentro do próprio quadro; alvos de toque de 44 px.</dd>
        <dt>Teclado</dt><dd>Tab percorre tudo com contorno de foco visível.</dd>
    </dl>

    <h2>Seu papel agora: <span class="papel-tag">{{ $papel }}</span></h2>
    <ul>
        @foreach ($menuItens as $item)
            <li>{{ $item['rotulo'] }}@if ($item['breve']) (em breve) @endif</li>
        @endforeach
    </ul>
    <p class="lead">Troque o papel em "Ver como" (topo) e veja o menu mudar.</p>
</div>
@endsection
