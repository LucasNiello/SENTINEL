<!DOCTYPE html>
<html lang="pt-BR"@if (rescue(fn () => auth()->id(), null, false)) data-usuario="{{ (int) auth()->id() }}"@endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ rescue(fn () => csrf_token(), '', false) }}">
    <meta name="theme-color" content="#0A0E13">
    <title>@yield('titulo', 'Sentinel') — {{ config('app.name', 'Sentinel') }}</title>
    <link rel="icon" href="/favicon-sentinel.svg" type="image/svg+xml">
    @vite(['resources/css/workspace.css', 'resources/js/workspace.js'])
    {{-- T3 (14/11): PWA. O service worker só guarda o shell estático (ícones, manifest, build); nunca /agente, JSON, login ou dados. --}}
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/icons/sentinel-192.png">
    <script>if ('serviceWorker' in navigator) { window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {})); }</script>
    @if (rescue(fn () => auth()->id(), null, false))
        {{-- Aplica o tamanho do texto escolhido antes de pintar a tela (sem piscar). Só lê a preferência deste usuário, neste navegador. --}}
        <script>try { var t = localStorage.getItem('sentinel:texto:{{ (int) auth()->id() }}'); if (t === '2' || t === '3') document.documentElement.dataset.texto = t; } catch (e) {}</script>
    @endif
    @stack('estilos')
</head>
@php
    // Erros continuam renderizando se o banco/sessão não puder fornecer o usuário.
    $usuario = rescue(fn () => auth()->user(), null, false);
    $papel = $usuario ? rescue(fn () => app(\App\Services\ContextoUsuario::class)->papel(), null, false) : null;
    $rotulosPapel = (array) config('sentinel.rotulos_papeis', []);
    $iniciais = $usuario ? collect(preg_split('/[^\p{L}\p{N}]+/u', trim($usuario->name)))->filter()->take(2)->map(fn ($nome) => mb_substr($nome, 0, 1))->implode('') : '';
    // T1: o Administrador da clínica só gerencia PINs (a /agente e os cadastros o levam à /supervisao).
    $adminDaClinica = $papel !== null && rescue(fn () => app(\App\Services\ContextoUsuario::class)->ehAdminDaClinica(), false, false);
    $itens = $adminDaClinica ? [] : [
        ['agente', 'Agente', 'M4 5h16v11H8l-4 4V5m4 4h8m-8 3h5'],
        ['cadastros', 'Cadastros', 'M4 4h6v6H4zm10 0h6v6h-6zM4 14h6v6H4zm10 0h6v6h-6z'],
    ];
    // Front de 14/11 (F2): telas de leitura da clínica. Cada item segue a tool de leitura da tela (a rota confere de novo);
    // Resultados é só do dono.
    $naClinica = $papel !== null && ! $adminDaClinica && rescue(fn () => app(\App\Services\ContextoUsuario::class)->dominio() === 'clinica', false, false);
    if ($naClinica) {
        $podeTool = fn (string $tool) => rescue(fn () => app(\App\Services\AiAgentService::class)->podeExecutar($tool), false, false);
        $daClinica = [];
        if ($podeTool('consultar_agenda') && $papel !== 'vendedor') $daClinica[] = ['agenda', 'Agenda', 'M5 5h14v15H5zM5 10h14M9 3v4m6-4v4'];
        if ($podeTool('consultar_cobrancas')) $daClinica[] = ['cobrancas', 'Cobranças', 'M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6m-6 4h6'];
        if ($podeTool('consultar_vendas')) $daClinica[] = ['vendas', 'Vendas', 'M4 6h2l2 10h10l2-7H8m1 11h.01M17 20h.01'];
        if (rescue(fn () => app(\App\Services\ContextoUsuario::class)->ehDono(), false, false)) $daClinica[] = ['resultados', 'Resultados', 'M5 20V10m7 10V4m7 16v-7'];
        if (rescue(fn () => app(\App\Services\ContextoUsuario::class)->ehDono(), false, false)) $daClinica[] = ['painel', 'Painel', 'M4 4h7v7H4zm9 0h7v4h-7zM4 13h7v7H4zm9-3h7v10h-7z'];
        // Agente, Agenda, Cadastros e o resto: a agenda logo depois do agente, que é o que a recepção mais abre.
        array_splice($itens, 1, 0, array_slice($daClinica, 0, 1));
        array_push($itens, ...array_slice($daClinica, 1));
    }
    // O menu segue as mesmas regras do servidor (que confere de novo em cada rota): a lixeira, a lista de
    // papéis da tool; a auditoria, o papel administrativo (ConsultaAuditoria).
    $pode = fn (callable $regra) => $papel !== null && rescue($regra, false, false);
    if ($pode(fn () => app(\App\Services\AiAgentService::class)->podeExecutar('consultar_lixeira'))) $itens[] = ['lixeira', 'Lixeira', 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13m-8 4v5m4-5v5'];
    if ($pode(fn () => app(\App\Services\ContextoUsuario::class)->ehAdministrativo())) $itens[] = ['auditoria', 'Auditoria', 'M6 3h9l3 3v15H6V3m4 5h4m-4 4h4m-4 4h4'];
    // Item I (clínica), T1: supervisão, para o dono (marca o setor) e para o Administrador (PINs); a rota confere de novo.
    if ($pode(fn () => app(\App\Services\ContextoUsuario::class)->dominio() === 'clinica' && (app(\App\Services\ContextoUsuario::class)->ehDono() || app(\App\Services\ContextoUsuario::class)->ehAdminDaClinica()))) $itens[] = ['supervisao', 'Supervisão', 'M12 3l7 3v5c0 4.5-3 8.5-7 10-4-1.5-7-5.5-7-10V6l7-3m-3 9 2 2 4-4'];
@endphp
<body class="workspace{{ $usuario ? '' : ' workspace--guest' }}">
    <a class="pular" href="#conteudo">Pular para o conteúdo</a>
    <aside id="workspace-sidebar" class="workspace-sidebar" aria-label="Navegação lateral">
        <header class="topo">
            <a class="marca" href="/agente" aria-label="Sentinel — ir para o agente"><x-lighthouse /><span>SENTINEL<small>CONTROL SYSTEM</small></span></a>
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
                    <a href="/{{ $caminho }}" @if (request()->is($caminho) || ($caminho === 'cadastros' && request()->is('pets/*'))) aria-current="page" @endif><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="{{ $icone }}"/></svg><span>{{ $rotulo }}</span></a>
                @endforeach
            </nav>
            <p class="sidebar-note">Inteligência para agir.<br>Controle para decidir.</p>
            {{-- F4 do front (14/11): tamanho do texto em 3 níveis. A escolha fica no navegador, por usuário (localStorage), sem migration. --}}
            <div class="texto-tamanho" role="group" aria-labelledby="rotulo-texto">
                <span id="rotulo-texto">Tamanho do texto</span>
                <div class="texto-tamanho__botoes">
                    <button type="button" data-texto="1" aria-pressed="true" aria-label="Texto normal">A</button>
                    <button type="button" data-texto="2" aria-pressed="false" aria-label="Texto grande">A+</button>
                    <button type="button" data-texto="3" aria-pressed="false" aria-label="Texto maior">A++</button>
                </div>
            </div>
            <div class="sidebar-account">
                <span class="account-initials" aria-hidden="true">{{ mb_strtoupper($iniciais) }}</span>
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
            <div class="workspace-context"><span>Sentinel</span><span class="context-divider" aria-hidden="true">/</span><span>@yield('titulo', 'Sentinel')</span></div>
            @if ($usuario)
                <span class="workspace-topbar__note"><i aria-hidden="true"></i> Sessão autenticada <span>{{ $rotulosPapel[$papel] ?? $papel }}</span></span>
            @endif
        </div>
        <main id="conteudo" tabindex="-1">
            @yield('conteudo')
        </main>
        <footer class="rodape">Sentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP</footer>
    </div>
    @stack('scripts')
</body>
</html>
