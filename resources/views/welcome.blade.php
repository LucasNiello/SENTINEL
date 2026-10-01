<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Sentinel — Agente de IA para operações contábeis</title>
    <meta name="description" content="Consulte dados e prepare cadastros em português. O Sentinel pede sua confirmação antes de gravar e registra as ações para auditoria.">
    <meta name="theme-color" content="#0A0E13">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon-sentinel.svg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    @vite(['resources/css/landing.css', 'resources/js/landing.js'])
</head>
<body>
    @php
        $acessoUrl = auth()->check() ? url('/agente') : route('login');
        $acessoTexto = auth()->check() ? 'Abrir Sentinel' : 'Entrar no Sentinel';
    @endphp
    <a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
    <div class="loader" data-loader aria-hidden="true" hidden>
        <div class="loader-inner"><x-lighthouse class="loader-symbol" /><span class="wordmark">SENTINEL</span><div class="loader-track"><span></span></div></div>
    </div>
    <header class="site-header">
        <a class="brand" href="#inicio" aria-label="Sentinel — início"><x-lighthouse /> <span>SENTINEL</span></a>
        <a class="header-access" href="{{ $acessoUrl }}" data-access>{{ $acessoTexto }} <span aria-hidden="true">↗</span></a>
    </header>
    <main id="conteudo">
        <div class="story" data-story>
            <div class="lighthouse-stage" data-stage aria-hidden="true">
                <div class="stage-halo"></div>
                <div class="lighthouse-fallback"><x-lighthouse class="hero-symbol" /></div>
                <div class="lighthouse-canvas" data-canvas></div>
                <div class="scene-light-wash"></div>
                <div class="stage-caption"><span class="signal-dot"></span><span data-scene-caption>Observar antes de agir.</span></div>
                <div class="story-progress"><i></i><i></i><i></i><i></i></div>
            </div>
            <section class="chapter hero" id="inicio" data-chapter="0" aria-labelledby="hero-title">
                <div class="chapter-content hero-content">
                    <p class="eyebrow"><span class="signal-dot"></span> Agente de IA para contabilidade</p>
                    <h1 id="hero-title">Peça em português.<br><em>O Sentinel executa.</em></h1>
                    <p class="lead">Consulta, cadastra e registra no seu sistema contábil a partir de uma conversa. Nenhum dado é gravado sem a sua confirmação.</p>
                    <div class="actions"><a class="button" href="{{ $acessoUrl }}" data-access>{{ $acessoTexto }} <span aria-hidden="true">↗</span></a><a class="text-link" href="#consulta">Ver como funciona <span aria-hidden="true">↓</span></a></div>
                </div>
                <div class="hero-baseline"><span>Inteligência para agir. Controle para decidir.</span><a href="#consulta" aria-label="Explorar a consulta">Role para explorar <span aria-hidden="true">↓</span></a></div>
            </section>
            <section class="chapter" id="consulta" data-chapter="1" aria-labelledby="consulta-title">
                <div class="chapter-content consult-layout">
                    <div class="chapter-intro">
                    <p class="eyebrow"><span class="chapter-number">01 /</span> Consulta</p>
                    <h2 id="consulta-title">Você pergunta.<br><em>Ele busca nos seus dados.</em></h2>
                    <p class="lead">Menos caminhos até a informação. Mais espaço para o que você precisa resolver.</p>
                    </div>
                    <div class="demo conversation" role="group" aria-label="Demonstração de consulta de fornecedores">
                        <div class="demo-heading"><span>CONVERSA</span><span>Dados de demonstração</span></div>
                        <div class="conversation-body">
                            <div class="question reveal-step"><span class="speaker">VOCÊ</span><p>liste os fornecedores cadastrados</p></div>
                            <div class="answer reveal-step"><span class="speaker">SENTINEL</span><p>3 fornecedores encontrados.</p></div>
                            <div class="table-scroll reveal-step" role="region" aria-label="Fornecedores fictícios" tabindex="0">
                                <table><caption class="sr-only">Dados fictícios de fornecedores</caption><thead><tr><th scope="col">Fornecedor</th><th scope="col">CNPJ</th></tr></thead><tbody>
                                    <tr><td>Papelaria Central Ltda</td><td class="mono">12.345.678/0001-90</td></tr>
                                    <tr><td>Gráfica Rio Claro ME</td><td class="mono">08.456.123/0001-77</td></tr>
                                    <tr><td>Limpa Bem Serviços</td><td class="mono">45.987.321/0001-12</td></tr>
                                </tbody></table>
                            </div>
                        </div>
                        <div class="demo-footnote"><span class="signal-dot"></span> Consulta de leitura. Nenhum dado alterado.</div>
                    </div>
                </div>
            </section>
            <section class="chapter" id="confirmacao" data-chapter="2" aria-labelledby="confirmacao-title">
                <div class="chapter-content confirm-layout">
                    <div class="chapter-intro">
                    <p class="eyebrow"><span class="chapter-number">02 /</span> Confirmação humana</p>
                    <h2 id="confirmacao-title">Nada é gravado<br><em>sem você.</em></h2>
                    <p class="lead">Antes de qualquer cadastro, o Sentinel mostra o que vai fazer. A última palavra é sua.</p>
                    </div>
                    <div class="demo confirmation" data-confirmation>
                        <div class="demo-heading"><span>REVISAR AÇÃO</span><span>Dados de demonstração</span></div>
                        <div class="confirmation-body">
                            <dl><div class="confirmation-operation"><dt>Ação</dt><dd>Cadastrar cliente</dd></div><div><dt>Nome</dt><dd>Mercado Bom Preço</dd></div><div><dt>CNPJ</dt><dd class="mono">23.456.789/0001-10</dd></div></dl>
                            <div class="confirmation-actions" data-hold-controls hidden><button class="hold-button" type="button" data-hold aria-describedby="hold-instructions hold-status"><span class="hold-fill" aria-hidden="true"></span><span class="hold-label">Segure para confirmar</span><span aria-hidden="true">↗</span></button><button class="cancel-button" type="button" data-cancel>Cancelar</button></div>
                            <p class="demo-note" id="hold-instructions">Segure por 1 segundo com mouse, toque, Espaço ou Enter. Esta demonstração não grava dados.</p>
                            <p class="hold-status" id="hold-status" role="status" aria-live="polite" aria-atomic="true">Aguardando sua confirmação.</p>
                            <noscript><p class="demo-note">No sistema, você revisa e confirma antes de gravar. Ative JavaScript para experimentar a demonstração.</p></noscript>
                        </div>
                    </div>
                </div>
            </section>
            <section class="chapter" id="auditoria" data-chapter="3" aria-labelledby="auditoria-title">
                <div class="chapter-content audit-layout">
                    <div class="chapter-intro">
                    <p class="eyebrow"><span class="chapter-number">03 /</span> Auditoria</p>
                    <h2 id="auditoria-title">Cada ação<br><em>fica registrada.</em></h2>
                    <p class="lead">Quem fez, o que pediu e o resultado. Inclusive o que foi negado.</p>
                    </div>
                    <div class="demo audit" role="group" aria-label="Registros fictícios de auditoria">
                        <div class="demo-heading"><span>TRILHA DE AUDITORIA</span><span>Dados de demonstração</span></div>
                        <ol class="audit-list">
                            <li class="reveal-step"><span class="audit-time">24/09 · 14:02</span><span class="event-marker" aria-hidden="true"></span><span><span class="audit-role">operador</span><br>criar_cliente</span><span class="audit-result">confirmado ↗</span></li>
                            <li class="reveal-step"><span class="audit-time">24/09 · 14:05</span><span class="event-marker" aria-hidden="true"></span><span><span class="audit-role">leitura</span><br>criar_cliente</span><span class="audit-result denied">negado: papel</span></li>
                            <li class="reveal-step"><span class="audit-time">24/09 · 14:07</span><span class="event-marker" aria-hidden="true"></span><span><span class="audit-role">admin</span><br>consultar_funcionarios</span><span class="audit-result">executado ↗</span></li>
                        </ol>
                        <div class="audit-private"><span>CPF ***.***.789-00</span><span>salário [redigido]</span></div>
                    </div>
                    <p class="section-note">CPF e salário aparecem mascarados também na auditoria.</p>
                </div>
            </section>
            <div class="light-transition" aria-hidden="true"><div class="transition-copy"><span>Da intenção à ação.</span><span>Clareza para seguir.</span></div></div>
        </div>
        <section class="workbench" id="trabalho" aria-labelledby="trabalho-title">
            <div class="workbench-grid">
                <div class="workbench-copy">
                    <p class="eyebrow">Pronto para trabalhar</p>
                    <h2 id="trabalho-title">Por dentro,<br>só o trabalho.</h2>
                    <p class="lead">A vitrine impressiona uma vez.<br>A bancada é feita para um dia inteiro de uso.</p>
                    <a class="button" href="{{ $acessoUrl }}" data-access>{{ auth()->check() ? 'Abrir Sentinel' : 'Entrar no Sentinel' }} <span aria-hidden="true">↗</span></a>
                </div>
                <div class="workspace-preview" role="img" aria-label="Demonstração da bancada: consulta de clientes com três resultados fictícios.">
                    <div class="preview-top"><span class="brand"><x-lighthouse /> <span>SENTINEL</span></span><span class="preview-tag"><span class="signal-dot"></span> Seu espaço de trabalho</span></div>
                    <div class="preview-body"><p class="preview-label">SUA PRÓXIMA TAREFA</p><p class="preview-prompt">liste os clientes <span aria-hidden="true">↗</span></p><div class="preview-response"><x-lighthouse /><div><span class="speaker">SENTINEL</span><span>Encontrei estes clientes para você.</span></div><span class="result-count">03</span></div><ul class="preview-list"><li><span class="client-monogram">PE</span><span>Padaria Estrela<small>Pessoa jurídica</small></span><span>Cliente 01</span></li><li><span class="client-monogram">AI</span><span>Auto Peças Irmãos<small>Pessoa jurídica</small></span><span>Cliente 02</span></li><li><span class="client-monogram">VP</span><span>Clínica Vida Plena<small>Pessoa jurídica</small></span><span>Cliente 03</span></li></ul><div class="preview-bottom"><p class="demo-note">Dados de demonstração</p><span>Consulta concluída <span aria-hidden="true">✓</span></span></div></div>
                </div>
            </div>
            <footer class="site-footer"><span>SENTINEL</span><p>O farol avisa do perigo antes do erro.</p><a href="#inicio">Voltar ao início ↑</a></footer>
        </section>
    </main>
</body>
</html>
