<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Sentinel') }} — Entrar</title>
    <style>
        /* Mesmos tokens do agente.blade.php (skill sentinel-visual, seção 2): nenhum hex fora do :root. */
        :root {
            /* base */
            --color-noite: #0A0E13;     /* fundo da vitrine */
            --color-gelo: #A8C8E6;      /* destaque sobre fundo escuro */
            --color-creme: #FFFCEF;     /* fundo da bancada */
            --color-tinta: #1D232A;     /* texto sobre creme */
            --color-tinta-2: #5B6570;   /* texto secundário sobre creme; borda de risco neutro */
            --color-aco: #2E5B85;       /* destaque sobre creme: links, foco, rótulos */
            --color-borda: #E3DDC6;     /* divisórias e contornos de campo sobre creme */

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

        body {
            font-family: var(--fonte-corpo);
            font-size: 16px;
            max-width: 640px;
            margin: 2rem auto;
            padding: 0 1rem;
            background: var(--color-creme);
            color: var(--color-tinta);
        }

        h1 { font-family: var(--fonte-titulo); font-weight: 400; font-size: 1.25rem; }

        :focus-visible { outline: 2px solid var(--foco); outline-offset: 2px; }

        form { display: flex; flex-direction: column; gap: 0.75rem; max-width: 360px; }
        label { display: flex; flex-direction: column; gap: 0.25rem; }
        input { padding: 0.6rem; font-size: 1rem; background: var(--color-creme); color: var(--color-tinta); border: 1px solid var(--color-borda); border-radius: 6px; }
        button { cursor: pointer; font: inherit; background: var(--color-creme); color: var(--color-tinta); border: 1px solid var(--color-tinta-2); border-radius: 6px; padding: 0.6rem; font-size: 1rem; }

        .msg--erro { padding: 0.6rem 0.8rem; border-radius: 6px; background: var(--color-erro-faixa); color: var(--color-erro); }
    </style>
</head>
<body>
    <h1>Sentinel — Entrar</h1>

    <form method="POST" action="/login">
        @csrf

        @if ($errors->any())
            <div class="msg--erro" role="alert">{{ $errors->first() }}</div>
        @endif

        <label>
            E-mail
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        </label>

        <label>
            Senha
            <input type="password" name="password" required autocomplete="current-password">
        </label>

        <button type="submit">Entrar</button>
    </form>
</body>
</html>
