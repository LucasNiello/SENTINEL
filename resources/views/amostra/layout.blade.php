<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>[AMOSTRA] {{ $titulo }} — Sentinel</title>
    <link rel="icon" href="/favicon-sentinel.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/amostra-recursos/amostra.css">
</head>
<body data-modal-inicial="{{ $modalInicial ?? '' }}">
    <div class="faixa-amostra" role="note">
        <strong>Amostra</strong>
        <span>Tela de demonstração com dados fictícios. Nada é lido nem gravado.</span>
        <a href="{{ $u('') }}">Índice das telas</a>
        <span class="faixa-amostra__papeis">
            <span id="rotulo-papeis">Ver como:</span>
            @foreach (['leitura', 'operador', 'admin'] as $p)
                <a href="{{ \App\Amostra\DadosAmostra::url($caminhoAtual, $p, $consultaAtual ?? []) }}" @if ($papel === $p) aria-current="true" @endif aria-describedby="rotulo-papeis">{{ $p }}</a>
            @endforeach
        </span>
    </div>

    <header class="topo">
        <a class="marca" href="{{ $u('agente') }}" aria-label="Sentinel — ir para o agente">
            <svg viewBox="0 0 40 40" aria-hidden="true" focusable="false">
                <path class="marca__corpo" d="m14 9 6-5 6 5v7h2v2h-3l2.5 15H30v2H10v-2h2.5L15 18h-3v-2h2Z"/>
                <rect class="marca__janela" x="18" y="9" width="4" height="4"/>
                <path class="marca__facho" d="M23 10.5 38 6v9Z"/>
            </svg>
            <span>Sentinel</span>
        </a>
        @if (($agenteOffline ?? false))
            <span class="estado-agente estado-agente--offline"><span class="estado-agente__ponto" aria-hidden="true"></span>Agente fora do ar</span>
        @else
            <span class="estado-agente"><span class="estado-agente__ponto" aria-hidden="true"></span>Agente pronto</span>
        @endif
        <div class="topo__direita">
            <div class="usuario">
                {{ $usuario['nome'] }} <span class="papel-tag">{{ $usuario['papel'] }}</span>
                <small>{{ $usuario['email'] }} · empresa {{ $usuario['tenant'] }}</small>
            </div>
            <button type="button" class="btn btn--pequeno" data-amostra-acao="Sair levaria você para a tela de login.">Sair</button>
        </div>
    </header>

    <nav class="menu" aria-label="Principal">
        @foreach ($menuItens as $item)
            @if ($item['breve'])
                <span class="em-breve">{{ $item['rotulo'] }} <span class="tag tag--neutro">em breve</span></span>
            @else
                <a href="{{ $u($item['rota']) }}" @if (($menuAtual ?? '') === $item['chave']) aria-current="page" @endif>
                    {{ $item['rotulo'] }}
                    @if (!empty($item['hipotese'])) <span class="tag tag--faltante">hipótese</span> @endif
                </a>
            @endif
        @endforeach
    </nav>

    <main id="conteudo">
        @yield('conteudo')
    </main>

    <footer class="rodape">
        <span>Sentinel v1.0 (amostra)</span>
        <span>TCC — Desenvolvimento de Sistemas, SENAI Limeira/SP</span>
        <span>Equipe: Lucas, Matheus, Jefferson e Júlio</span>
    </footer>

    <div id="overlay-popup" aria-live="polite"></div>
    <div id="status-anuncio" class="sr-only" aria-live="assertive"></div>
    <script src="/amostra-recursos/amostra.js" defer></script>
</body>
</html>
