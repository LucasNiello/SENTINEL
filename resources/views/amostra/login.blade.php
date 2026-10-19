<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>[AMOSTRA] Entrar — Sentinel</title>
    <link rel="icon" href="/favicon-sentinel.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/amostra-recursos/amostra.css">
</head>
<body>
    <div class="faixa-amostra" role="note">
        <strong>Amostra</strong>
        <span>Tela de demonstração com dados fictícios. Ninguém é autenticado.</span>
        <a href="{{ $u('') }}">Índice das telas</a>
    </div>

    <main class="login-caixa">
        <div class="painel-amostra">
            <h2>Só da amostra: estados</h2>
            <div class="chips">
                @foreach (['vazio' => 'Vazio', 'erro' => 'Senha errada', 'carregando' => 'Entrando', 'limite' => 'Muitas tentativas'] as $chave => $rotulo)
                    <a class="chip" href="{{ $u('login', ['estado' => $chave]) }}" @if ($estado === $chave) aria-current="true" @endif>{{ $rotulo }}</a>
                @endforeach
            </div>
        </div>

        <h1>Sentinel — Entrar</h1>

        <form data-amostra="Isto é uma amostra: ninguém é autenticado." novalidate>
            @if ($estado === 'erro')
                <div class="msg msg--erro" role="alert">E-mail ou senha incorretos.</div>
            @elseif ($estado === 'limite')
                <div class="msg msg--erro" role="alert"><strong>Muitas tentativas.</strong><span class="msg__acao">Aguarde 1 minuto e tente de novo.</span></div>
            @endif

            <div class="campo">
                <label for="email">E-mail</label>
                <input id="email" name="email" type="email" value="{{ $estado === 'vazio' ? '' : 'operador@sentinel.local' }}" autocomplete="username" @if ($estado === 'carregando' || $estado === 'limite') disabled @endif>
            </div>
            <div class="campo">
                <label for="senha">Senha</label>
                <input id="senha" name="password" type="password" autocomplete="current-password" @if ($estado === 'carregando' || $estado === 'limite') disabled @endif>
            </div>

            @if ($estado === 'carregando')
                <div class="carregando" role="status" aria-busy="true"><span>Entrando…</span><div class="carregando__barra"></div></div>
            @endif
            <button type="submit" class="btn btn--primario" @if ($estado === 'carregando' || $estado === 'limite') disabled @endif>Entrar</button>
        </form>
    </main>
    <div id="overlay-popup" aria-live="polite"></div>
    <div id="status-anuncio" class="sr-only" aria-live="assertive"></div>
    <script src="/amostra-recursos/amostra.js" defer></script>
</body>
</html>
