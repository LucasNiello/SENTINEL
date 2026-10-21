<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Também numa tela de erro com a sessão fora do ar: o token não pode derrubar a casca. --}}
    <meta name="csrf-token" content="{{ rescue(fn () => csrf_token(), '', false) }}">
    <title>@yield('titulo', 'Sentinel') — {{ config('app.name', 'Sentinel') }}</title>
    <link rel="icon" href="/favicon-sentinel.svg" type="image/svg+xml">
    <style>
        /* Tokens da skill sentinel-visual (seção 2), inline (decisão D8). Única fonte de cor das telas: nenhum hex fora do :root. */
        :root {
            /* base */
            --color-noite: #0A0E13;     /* fundo da vitrine */
            --color-gelo: #A8C8E6;      /* destaque sobre fundo escuro */
            --color-creme: #FFFCEF;     /* fundo da bancada */
            --color-tinta: #1D232A;     /* texto sobre creme */
            --color-tinta-2: #5B6570;   /* texto secundário sobre creme; borda de risco neutro */
            --color-aco: #2E5B85;       /* destaque sobre creme: links, foco, rótulos */
            --color-borda: #E3DDC6;     /* divisórias sobre creme (1,3:1: não serve de contorno de campo) */
            --color-borda-campo: #5B6570; /* contorno de campo sobre creme: 5,8:1 (WCAG 1.4.11 pede ≥ 3:1) */

            /* estados (bancada) */
            --color-sucesso: #2F6B4F;  --color-sucesso-faixa: #E4EFE6;
            --color-aviso: #8A5A12;    --color-aviso-faixa: #F6EBD0;
            --color-erro: #A3412E;     --color-erro-faixa: #F5E0D9;

            /* estados (vitrine, sobre noite) */
            --color-sucesso-escuro: #8FC4A4;
            --color-aviso-escuro: #E0B870;
            --color-erro-escuro: #D9A38F;

            /* tipografia (seção 3): sem webfont carregada, a tela usa o fallback */
            --fonte-titulo: 'Sora', "Segoe UI", system-ui, sans-serif;
            --fonte-corpo: 'IBM Plex Sans', "Segoe UI", system-ui, sans-serif;
            --fonte-mono: 'IBM Plex Mono', ui-monospace, Consolas, monospace;

            /* foco visível (seção 9): destaque da bancada */
            --foco: var(--color-aco);
        }

        * { box-sizing: border-box; }
        [hidden] { display: none !important; }

        body {
            margin: 0;
            font-family: var(--fonte-corpo);
            font-size: 16px;
            line-height: 1.45;
            background: var(--color-creme);
            color: var(--color-tinta);
        }

        h1 { font-family: var(--fonte-titulo); font-weight: 400; font-size: 1.25rem; }
        a { color: var(--color-aco); }

        /* Foco visível em tudo que é clicável (seção 9). */
        :focus-visible { outline: 2px solid var(--foco); outline-offset: 2px; }

        button { cursor: pointer; font: inherit; background: var(--color-creme); color: var(--color-tinta); border: 1px solid var(--color-tinta-2); border-radius: 6px; }
        button:disabled, input:disabled, select:disabled { cursor: not-allowed; opacity: 0.6; }

        .sr-only {
            position: absolute;
            width: 1px; height: 1px;
            overflow: hidden;
            clip: rect(0 0 0 0);
            white-space: nowrap;
        }

        /* Atalho de teclado: aparece só ao receber foco. */
        .pular { position: absolute; left: -9999px; top: 0.5rem; padding: 0.5rem 0.75rem; background: var(--color-creme); }
        .pular:focus { left: 0.5rem; }

        /* Casca da bancada: topo, menu, conteúdo e rodapé (sem animação; seção 8). */
        .topo, .menu, .rodape, main { max-width: 720px; margin: 0 auto; padding-left: 1rem; padding-right: 1rem; }
        .topo {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding-top: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--color-borda);
        }
        .marca { display: inline-flex; align-items: center; gap: 0.5rem; min-height: 44px; color: var(--color-tinta); text-decoration: none; font-family: var(--fonte-titulo); font-size: 1.15rem; }
        .marca svg { width: 32px; height: 32px; }
        .marca__corpo { fill: var(--color-tinta); }
        .marca__janela { fill: var(--color-creme); }
        .marca__facho { fill: var(--color-aco); }
        .topo__direita { display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem; }
        .usuario { display: inline-flex; align-items: center; gap: 0.5rem; }
        .papel-tag { font-family: var(--fonte-mono); font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; padding: 0.15rem 0.5rem; border: 1px solid var(--color-tinta-2); border-radius: 4px; color: var(--color-tinta-2); }
        .topo form { margin: 0; }
        .topo button { min-height: 44px; padding: 0.4rem 1rem; }

        .menu { display: flex; flex-wrap: wrap; gap: 0.25rem 1rem; padding-top: 0.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--color-borda); }
        .menu a, .menu .em-breve { display: inline-flex; align-items: center; gap: 0.4rem; min-height: 44px; }
        .menu a[aria-current="page"] { color: var(--color-tinta); font-weight: 600; text-decoration: none; }
        .menu .em-breve { color: var(--color-tinta-2); }
        .tag { font-family: var(--fonte-mono); font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; padding: 0.1rem 0.4rem; border: 1px solid var(--color-borda-campo); border-radius: 4px; }

        main { padding-top: 1rem; padding-bottom: 2rem; }
        main:focus { outline: none; }

        .rodape { padding-top: 1rem; padding-bottom: 1.5rem; border-top: 1px solid var(--color-borda); color: var(--color-tinta-2); font-size: 15px; }

        /* Telas de erro (419, 429, 403, 404, 500): mensagem em português, sem detalhe técnico. */
        .erro-pagina__codigo { font-family: var(--fonte-mono); font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-tinta-2); margin-bottom: 0; }
        .erro-pagina a { display: inline-flex; align-items: center; min-height: 44px; }

        /* Celular (375 px): nada rola na horizontal; tabelas largas rolam dentro do próprio contêiner. */
        @media (max-width: 480px) {
            .topo { padding-top: 0.75rem; }
            .usuario { flex-wrap: wrap; }
        }
    </style>
    @stack('estilos')
</head>
<body>
    @php
        // Uma tela de erro pode ser montada com o banco fora do ar: buscar o usuário não pode derrubar a casca.
        $usuario = rescue(fn () => auth()->user(), null, false);
        $papel = $usuario ? rescue(fn () => app(\App\Services\ContextoUsuario::class)->papel(), null, false) : null;
        $rotulosPapel = ['leitura' => 'Leitura', 'operador' => 'Operador', 'admin' => 'Administrador'];
    @endphp

    <a class="pular" href="#conteudo">Pular para o conteúdo</a>

    <header class="topo">
        <a class="marca" href="/agente" aria-label="Sentinel — ir para o agente">
            <svg viewBox="0 0 40 40" aria-hidden="true" focusable="false">
                <path class="marca__corpo" d="m14 9 6-5 6 5v7h2v2h-3l2.5 15H30v2H10v-2h2.5L15 18h-3v-2h2Z"/>
                <rect class="marca__janela" x="18" y="9" width="4" height="4"/>
                <path class="marca__facho" d="M23 10.5 38 6v9Z"/>
            </svg>
            <span>Sentinel</span>
        </a>
        <div class="topo__direita">
            @if ($usuario)
                <span class="usuario">
                    {{ $usuario->name }}
                    @if ($papel)
                        <span class="papel-tag"><span class="sr-only">Papel: </span>{{ $rotulosPapel[$papel] ?? $papel }}</span>
                    @endif
                </span>
                <form method="POST" action="/logout">
                    @csrf
                    <button type="submit">Sair</button>
                </form>
            @else
                <a href="/login">Entrar</a>
            @endif
        </div>
    </header>

    @if ($usuario)
        <nav class="menu" aria-label="Principal">
            <a href="/agente" @if (request()->is('agente')) aria-current="page" @endif>Agente</a>
            <span class="em-breve">Cadastros <span class="tag">em breve</span></span>
            <span class="em-breve">Lixeira <span class="tag">em breve</span></span>
            @if ($papel === 'admin')
                <span class="em-breve">Auditoria <span class="tag">em breve</span></span>
            @endif
        </nav>
    @endif

    <main id="conteudo" tabindex="-1">
        @yield('conteudo')
    </main>

    <footer class="rodape">
        Sentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP
    </footer>

    @stack('scripts')
</body>
</html>
