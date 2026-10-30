<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ rescue(fn () => csrf_token(), '', false) }}">
    <meta name="theme-color" content="#0A0E13">
    <title>@yield('titulo', 'Sentinel') — {{ config('app.name', 'Sentinel') }}</title>
    <link rel="icon" href="/favicon-sentinel.svg" type="image/svg+xml">
    @vite(['resources/css/workspace.css', 'resources/js/workspace.js'])
    @stack('estilos')
</head>
@php
    // Erros continuam renderizando se o banco/sessão não puder fornecer o usuário.
    $usuario = rescue(fn () => auth()->user(), null, false);
    $papel = $usuario ? rescue(fn () => app(\App\Services\ContextoUsuario::class)->papel(), null, false) : null;
    $rotulosPapel = ['leitura' => 'Leitura', 'operador' => 'Operador', 'admin' => 'Administrador'];
    $itens = [
        ['agente', 'Agente', 'M4 5h16v11H8l-4 4V5m4 4h8m-8 3h5'],
        ['cadastros', 'Cadastros', 'M4 4h6v6H4zm10 0h6v6h-6zM4 14h6v6H4zm10 0h6v6h-6z'],
    ];
    if (in_array($papel, ['operador', 'admin'], true)) $itens[] = ['lixeira', 'Lixeira', 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13m-8 4v5m4-5v5'];
    if ($papel === 'admin') $itens[] = ['auditoria', 'Auditoria', 'M6 3h9l3 3v15H6V3m4 5h4m-4 4h4m-4 4h4'];
@endphp
<body class="workspace{{ $usuario ? '' : ' workspace--guest' }}">
    <a class="pular" href="#conteudo">Pular para o conteúdo</a>
    <aside id="workspace-sidebar" class="workspace-sidebar" aria-label="Navegação lateral">
        <header class="topo">
            <a class="marca" href="/agente" aria-label="Sentinel — ir para o agente"><x-lighthouse /><span>SENTINEL</span></a>
            @if ($usuario)
                <button class="sidebar-close" type="button" aria-label="Fechar menu" data-close-menu><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6"/></svg></button>
            @else
                <a href="/login" class="guest-access">Entrar</a>
            @endif
        </header>
        @if ($usuario)
            <p class="sidebar-caption">Área de trabalho</p>
            <nav class="menu" aria-label="Principal">
                @foreach ($itens as [$caminho, $rotulo, $icone])
                    <a href="/{{ $caminho }}" @if (request()->is($caminho)) aria-current="page" @endif><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="{{ $icone }}"/></svg><span>{{ $rotulo }}</span></a>
                @endforeach
            </nav>
            <p class="sidebar-note">Inteligência para agir.<br>Controle para decidir.</p>
            <div class="sidebar-account">
                <span class="usuario"><span>{{ $usuario->name }}</span><span class="papel-tag"><span class="sr-only">Papel: </span>{{ $rotulosPapel[$papel] ?? $papel }}</span></span>
                <form method="POST" action="/logout">
                    @csrf
                    <button type="submit">Sair <span aria-hidden="true">↗</span></button>
                </form>
            </div>
        @endif
    </aside>
    <div class="workspace-backdrop" data-menu-backdrop hidden></div>
    <div class="workspace-main">
        <div class="workspace-topbar">
            @if ($usuario)
                <button class="workspace-menu-toggle" type="button" aria-label="Abrir menu" aria-expanded="false" aria-controls="workspace-sidebar"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
            @endif
            <div class="workspace-context"><span>Bancada</span><span class="context-divider" aria-hidden="true">/</span><span>@yield('titulo', 'Sentinel')</span></div>
            <span class="workspace-topbar__note">Área operacional</span>
        </div>
        <main id="conteudo" tabindex="-1">
            @yield('conteudo')
        </main>
        <footer class="rodape">Sentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP</footer>
    </div>
    @stack('scripts')
</body>
</html>
