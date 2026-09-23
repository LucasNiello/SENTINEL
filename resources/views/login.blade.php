<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Sentinel') }} — Entrar</title>
    <style>
        /* Mesmo visual base do agente.blade.php (redesenho é a Tarefa B). */
        * { box-sizing: border-box; }

        body {
            font-family: system-ui, sans-serif;
            max-width: 640px;
            margin: 2rem auto;
            padding: 0 1rem;
            color: #1a1a1a;
        }

        h1 { font-size: 1.25rem; }

        form { display: flex; flex-direction: column; gap: 0.75rem; max-width: 360px; }
        label { display: flex; flex-direction: column; gap: 0.25rem; }
        input { padding: 0.6rem; font-size: 1rem; border: 1px solid #d1d5db; border-radius: 6px; }
        button { cursor: pointer; background: #fff; border: 1px solid #d1d5db; border-radius: 6px; padding: 0.6rem; font-size: 1rem; }

        .msg--erro { padding: 0.6rem 0.8rem; border-radius: 6px; background: #fee2e2; color: #dc2626; }
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
