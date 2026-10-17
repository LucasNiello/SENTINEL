<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow">
  <meta name="theme-color" content="#0B1119">
  <title>SENTINEL — Acesso</title>
  <link rel="icon" type="image/svg+xml" href="{{ asset('favicon-sentinel.svg') }}">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  @vite(['resources/css/login.css', 'resources/js/login.js'])
</head>
<body>
<a class="skip-link" href="#email">Ir para o login</a>
<main class="layout">
  <section class="visual" aria-labelledby="visual-title">
    <div class="visual-shading" aria-hidden="true"></div>
    <a class="brand" href="{{ url('/') }}" aria-label="SENTINEL — Voltar à página inicial">
      <x-lighthouse />
      <span>SENTINEL</span>
    </a>
    <div class="visual-copy">
      <h1 id="visual-title">Clareza para<br><span>decidir melhor.</span></h1>
      <p>Inteligência sob o seu controle.</p>
    </div>
    <div id="art-stage" class="art-stage" role="img" aria-label="Farol decorativo com luz girando automaticamente quando o 3D está disponível.">
      <div class="stage-halo" aria-hidden="true"></div>
      <div class="stage-fallback" aria-hidden="true">
        <x-lighthouse />
      </div>
      <div id="stage-canvas" class="stage-canvas" aria-hidden="true"></div>
    </div>
    <span class="visually-hidden" id="model-status" role="status" aria-live="polite">Carregando visual 3D</span>
    <footer class="visual-footer">Uma nova perspectiva para cada decisão.</footer>
  </section>

  <section class="access" aria-labelledby="login-heading">
    <header class="access-header">
      <a class="mobile-brand" href="{{ url('/') }}" aria-label="SENTINEL — Voltar à página inicial">
        <x-lighthouse />
        <span>SENTINEL</span>
      </a>
      <span class="access-tag">ÁREA RESTRITA</span>
    </header>

    <div class="access-main">
      <div class="form-card">
        <h2 id="login-heading">Bem-vindo<span>.</span></h2>
        <p class="form-subtitle">Entre para continuar.</p>
        <form id="login-form" method="POST" action="{{ route('login') }}">
          @csrf
          @if ($errors->any())
            <div class="form-notice is-error" role="alert">{{ $errors->first() }}</div>
          @endif
          <div class="field">
            <label for="email">E-mail</label>
            <div class="input-wrap">
              <input id="email" type="email" name="email" value="{{ is_string(old('email')) ? old('email') : '' }}" inputmode="email" autocomplete="username" spellcheck="false" autocapitalize="none" placeholder="seu.email@empresa.com" required aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}" aria-describedby="email-error">
            </div>
            <p id="email-error" class="field-error" hidden></p>
          </div>
          <div class="field">
            <label for="password">Senha</label>
            <div class="input-wrap password-wrap">
              <input id="password" type="password" name="password" autocomplete="current-password" placeholder="Digite sua senha" required aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}" aria-describedby="password-error caps-warning">
              <button class="password-toggle" id="show-password" type="button" aria-controls="password" aria-label="Mostrar senha" aria-pressed="false">Mostrar</button>
            </div>
            <p id="password-error" class="field-error" hidden></p>
            <p id="caps-warning" class="field-error" hidden>Caps Lock está ativado.</p>
          </div>
          <button type="submit" id="submit-button" class="submit-button"><span id="submit-label">Entrar</span><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 12h15m-6-6 6 6-6 6"/></svg></button>
          <p class="form-notice" id="form-notice" role="status" aria-live="polite" hidden></p>
        </form>
        <button type="button" class="help-link" id="open-support">Precisa de ajuda para acessar?</button>
      </div>
    </div>
    <footer class="access-footer"><span>© SENTINEL</span><span>Acesso autorizado</span></footer>
  </section>
</main>
<div class="modal-backdrop" id="support-modal" hidden>
  <div class="support-dialog" role="dialog" aria-modal="true" aria-labelledby="support-heading" aria-describedby="support-description">
    <button type="button" id="close-support" class="dialog-close" aria-label="Fechar">×</button>
    <h2 id="support-heading">Ajuda com o acesso</h2>
    <p id="support-description">O SENTINEL é destinado a usuários autorizados. Para criar ou recuperar seu acesso, fale com o administrador responsável.</p>
    <button type="button" id="understood" class="dialog-confirm">Entendi</button>
  </div>
</div>
</body>
</html>
