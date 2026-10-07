<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Sentinel') }} — Agente</title>
    <style>
        /* Tokens da skill sentinel-visual (seção 2). Única fonte de cor da tela: nenhum hex fora do :root. */
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

        #historico {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .msg { padding: 0.6rem 0.8rem; border-radius: 6px; }
        .msg--usuario { background: var(--color-borda); align-self: flex-end; }
        .msg--agente { border: 1px solid var(--color-borda); }
        .msg--erro { background: var(--color-erro-faixa); color: var(--color-erro); }
        .msg--aviso { background: var(--color-aviso-faixa); color: var(--color-aviso); }

        table { border-collapse: collapse; width: 100%; font-size: 15px; }
        th, td { border: 1px solid var(--color-borda); padding: 0.4rem 0.6rem; text-align: left; }
        th { background: var(--color-borda); }

        form#form-comando { display: flex; gap: 0.5rem; }
        #campo-mensagem { flex: 1; padding: 0.6rem; font-size: 1rem; background: var(--color-creme); color: var(--color-tinta); }
        #campo-mensagem { border: 1px solid var(--color-borda); border-radius: 6px; }
        button { cursor: pointer; font: inherit; background: var(--color-creme); color: var(--color-tinta); border: 1px solid var(--color-tinta-2); border-radius: 6px; }
        button:disabled, input:disabled { cursor: not-allowed; opacity: 0.6; }

        /* Carregamento (seção 7): texto de estado + barra fina de 1px, sem animação. */
        .carregando { margin: 0 0 1rem; color: var(--color-tinta-2); }
        .carregando__barra { height: 1px; margin-top: 0.25rem; background: var(--color-aco); }

        /* Card de confirmação: borda de 2px na cor do risco da tool (seção 5). */
        .card-confirmacao {
            --cor-risco: var(--color-aviso);
            --cor-risco-faixa: var(--color-aviso-faixa);
            border: 2px solid var(--cor-risco);
            background: var(--color-creme);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .card--risco-leitura { --cor-risco: var(--color-tinta-2); --cor-risco-faixa: var(--color-borda); }
        .card--risco-escrita { --cor-risco: var(--color-aviso); --cor-risco-faixa: var(--color-aviso-faixa); }
        .card--risco-exclusao { --cor-risco: var(--color-erro); --cor-risco-faixa: var(--color-erro-faixa); }
        .card-confirmacao pre {
            background: var(--color-creme);
            border: 1px solid var(--color-borda);
            padding: 0.5rem;
            border-radius: 4px;
            font-family: var(--fonte-mono);
            font-size: 15px;
            overflow-x: auto;
        }
        .card-confirmacao .acoes {
            display: flex;
            gap: 0.75rem;
            margin-top: 0.75rem;
        }
        .card-expirado-texto { margin-top: 0.75rem; }

        /* Confirmar (segurar) e Cancelar (clique): mesmo formato e peso; Cancelar é neutro (seção 6). */
        .btn-confirmar,
        .btn-cancelar {
            min-height: 44px;
            min-width: 140px;
            padding: 0.5rem 1rem;
            font-size: 16px;
            font-weight: 600;
            background: var(--color-creme);
            border-width: 2px;
            border-style: solid;
        }
        .btn-confirmar {
            position: relative;
            overflow: hidden;
            border-color: var(--cor-risco);
            color: var(--cor-risco);
        }
        .btn-confirmar__fill {
            position: absolute;
            inset: 0;
            width: 0%;
            background: var(--cor-risco-faixa);
            border-bottom: 4px solid var(--cor-risco);
            z-index: 0;
        }
        .btn-confirmar__label {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            line-height: 1.1;
            pointer-events: none;
        }
        .btn-confirmar__label .pequeno { font-family: var(--fonte-mono); font-size: 12px; font-weight: 400; text-transform: uppercase; letter-spacing: 0.08em; }
        .btn-confirmar__label .grande { font-family: var(--fonte-mono); font-size: 18px; font-variant-numeric: tabular-nums; }

        .btn-cancelar {
            border-color: var(--color-tinta-2);
            color: var(--color-tinta);
        }

        /* Popup de resultado */
        #overlay-popup {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            background: color-mix(in srgb, var(--color-tinta) 15%, transparent);
        }
        #popup {
            background: var(--color-creme);
            border-radius: 10px;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.05rem;
            font-weight: 600;
            opacity: 0;
            transition: opacity 1.5s ease;
        }
        #popup.visivel { opacity: 1; }
        #popup.sucesso { color: var(--color-sucesso); border: 2px solid var(--color-sucesso); }
        #popup.erro { color: var(--color-erro); border: 2px solid var(--color-erro); }
        #popup.neutro { color: var(--color-tinta); border: 2px solid var(--color-tinta-2); }

        .sr-only {
            position: absolute;
            width: 1px; height: 1px;
            overflow: hidden;
            clip: rect(0 0 0 0);
        }
    </style>
</head>
<body>
    <h1>Sentinel — Agente</h1>

    <form method="POST" action="/logout">
        @csrf
        <button type="submit">Sair</button>
    </form>

    <div id="historico" aria-live="polite"></div>

    <div id="carregando" class="carregando" hidden>
        <span>Consultando…</span>
        <div class="carregando__barra"></div>
    </div>

    <form id="form-comando">
        <input type="text" id="campo-mensagem" placeholder="Pergunte algo sobre os lançamentos..." required>
        <button type="submit" id="botao-enviar">Enviar</button>
    </form>

    <div id="status-anuncio" class="sr-only" aria-live="assertive"></div>

    <div id="overlay-popup">
        <div id="popup"></div>
    </div>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const historico = document.getElementById('historico');
        const form = document.getElementById('form-comando');
        const campoMensagem = document.getElementById('campo-mensagem');
        const botaoEnviar = document.getElementById('botao-enviar');
        const indicadorCarregando = document.getElementById('carregando');
        const statusAnuncio = document.getElementById('status-anuncio');
        const overlayPopup = document.getElementById('overlay-popup');
        const popup = document.getElementById('popup');

        const DURACAO_HOLD_MS = 2000;
        const DURACAO_IDLE_MS = 10000;
        // Segurar só começa se ainda couber o hold (2 s) + margem antes do fim da janela: o servidor aceita até 12 s.
        const MINIMO_PARA_SEGURAR_MS = 2500;
        const RISCOS = ['leitura', 'escrita', 'exclusao'];

        function anunciar(texto) {
            statusAnuncio.textContent = texto;
        }

        function adicionarMensagem(texto, classe) {
            const div = document.createElement('div');
            div.className = 'msg ' + classe;
            div.textContent = texto;
            historico.appendChild(div);
        }

        // Campo "aviso" do JSON (ex.: só o primeiro de vários pedidos foi atendido). Texto + cor, nunca só a cor.
        function adicionarAviso(texto) {
            adicionarMensagem('Aviso: ' + texto, 'msg--aviso');
        }

        // Enquanto espera o agente: campo e botão desativados, aria-busy e o texto "Consultando…".
        function definirCarregando(ativo) {
            campoMensagem.disabled = ativo;
            botaoEnviar.disabled = ativo;
            form.setAttribute('aria-busy', ativo ? 'true' : 'false');
            indicadorCarregando.hidden = !ativo;
            if (ativo) anunciar('consultando');
        }

        // As colunas vêm do servidor (catálogo de tools); textContent evita XSS com dados gravados no banco.
        function adicionarTabela(linhas, colunasServidor) {
            if (!linhas.length) {
                adicionarMensagem('Nenhum registro encontrado.', 'msg--agente');
                return;
            }
            const colunas = colunasServidor && colunasServidor.length ? colunasServidor : ['id', 'descricao', 'valor', 'status', 'data'];
            const table = document.createElement('table');
            const thead = document.createElement('thead');
            const trCabecalho = document.createElement('tr');
            colunas.forEach(c => {
                const th = document.createElement('th');
                th.textContent = c;
                trCabecalho.appendChild(th);
            });
            thead.appendChild(trCabecalho);
            const tbody = document.createElement('tbody');
            linhas.forEach(l => {
                const tr = document.createElement('tr');
                colunas.forEach(c => {
                    const td = document.createElement('td');
                    td.textContent = l[c] ?? '';
                    tr.appendChild(td);
                });
                tbody.appendChild(tr);
            });
            table.appendChild(thead);
            table.appendChild(tbody);
            historico.appendChild(table);
        }

        // textContent: a mensagem pode vir do servidor, nunca vira HTML.
        function mostrarPopup(estado, texto) {
            popup.className = estado;
            popup.textContent = texto;
            overlayPopup.style.display = 'flex';
            requestAnimationFrame(() => popup.classList.add('visivel'));
            setTimeout(() => {
                popup.classList.remove('visivel');
                setTimeout(() => { overlayPopup.style.display = 'none'; }, 400);
            }, 2500);
        }

        function popupConfirmado() {
            mostrarPopup('sucesso', '✔ Confirmado');
            anunciar('confirmado');
        }
        function popupCancelado() {
            mostrarPopup('neutro', '✕ Cancelado');
            anunciar('cancelado');
        }
        function popupErro(mensagem) {
            mostrarPopup('erro', '✕ ' + (mensagem || 'Erro ao confirmar — tente novamente'));
            anunciar(mensagem || 'erro ao confirmar');
        }

        function renderizarConfirmacaoPendente(tool, argumentos, token, risco) {
            const card = document.createElement('div');
            // Risco desconhecido cai no mais severo (exclusao), nunca no mais brando.
            card.className = 'card-confirmacao card--risco-' + (RISCOS.includes(risco) ? risco : 'exclusao');
            card.innerHTML = `
                <strong>Confirmação necessária:</strong> <span class="card-tool"></span>
                <pre class="card-argumentos"></pre>
                <div class="acoes">
                    <button type="button" class="btn-confirmar">
                        <span class="btn-confirmar__fill"></span>
                        <span class="btn-confirmar__label">Confirmar</span>
                    </button>
                    <button type="button" class="btn-cancelar">Cancelar</button>
                </div>
            `;
            card.querySelector('.card-tool').textContent = tool;
            card.querySelector('.card-argumentos').textContent = JSON.stringify(argumentos, null, 2);
            historico.appendChild(card);

            const btnConfirmar = card.querySelector('.btn-confirmar');
            const fill = card.querySelector('.btn-confirmar__fill');
            const label = card.querySelector('.btn-confirmar__label');
            const btnCancelar = card.querySelector('.btn-cancelar');

            const pequeno = document.createElement('span');
            pequeno.className = 'pequeno';
            pequeno.textContent = 'confirmando';
            const grande = document.createElement('span');
            grande.className = 'grande';

            let segurando = false;
            let inicioHold = 0;
            let rafId = null;
            let idleTimer = null;
            let quaseEsgotadoTimer = null;
            let avisoTempo = null;
            const criadoEm = performance.now();

            function restanteMs() {
                return DURACAO_IDLE_MS - (performance.now() - criadoEm);
            }

            function pararTimers() {
                clearTimeout(idleTimer);
                clearTimeout(quaseEsgotadoTimer);
            }

            // Janela ociosa contada desde o card. Segurar pausa os timers (M2); soltar sem completar retoma com o que resta.
            function agendarExpiracao() {
                const restante = restanteMs();
                idleTimer = setTimeout(expirarCard, Math.max(0, restante));
                quaseEsgotadoTimer = setTimeout(bloquearPorTempo, Math.max(0, restante - MINIMO_PARA_SEGURAR_MS));
            }
            agendarExpiracao();

            function encerrarCard() {
                pararTimers();
                if (rafId) cancelAnimationFrame(rafId);
                card.remove();
            }

            // Menos de 2,5 s na janela: um hold novo terminaria fora do prazo do servidor. Desativa o Confirmar.
            function bloquearPorTempo() {
                if (segurando || btnConfirmar.disabled) return;
                btnConfirmar.disabled = true;
                avisoTempo = document.createElement('div');
                avisoTempo.className = 'msg msg--aviso card-expirado-texto';
                avisoTempo.textContent = 'Tempo quase esgotado. Peça de novo.';
                card.appendChild(avisoTempo);
                anunciar('tempo quase esgotado, peça de novo');
            }

            // Expirou: o card não some calado — fica visível, marcado, com os botões desativados.
            function expirarCard() {
                pararTimers();
                if (avisoTempo) avisoTempo.remove();
                segurando = false;
                if (rafId) cancelAnimationFrame(rafId);
                fill.style.width = '0%';
                label.textContent = 'Confirmar';
                btnConfirmar.classList.remove('segurando');
                btnConfirmar.disabled = true;
                btnCancelar.disabled = true;
                card.classList.add('card--expirado');
                const texto = document.createElement('div');
                texto.className = 'msg msg--aviso card-expirado-texto';
                texto.textContent = 'Confirmação expirada. Nada foi gravado. Peça de novo.';
                card.appendChild(texto);
                anunciar('confirmação expirada, nada foi gravado');
            }

            function iniciarHold() {
                if (segurando || btnConfirmar.disabled) return;
                if (restanteMs() < MINIMO_PARA_SEGURAR_MS) {
                    bloquearPorTempo();
                    return;
                }
                pararTimers();
                segurando = true;
                inicioHold = performance.now();
                btnConfirmar.classList.add('segurando');
                anunciar('confirmando');
                passoHold();
            }

            function passoHold() {
                if (!segurando) return;
                const decorrido = performance.now() - inicioHold;
                const pct = Math.min(100, (decorrido / DURACAO_HOLD_MS) * 100);
                fill.style.width = pct + '%';
                grande.textContent = Math.floor(pct) + '%';
                if (grande.parentNode !== label) label.replaceChildren(pequeno, grande);

                if (pct >= 100) {
                    segurando = false;
                    confirmarAcao();
                    return;
                }
                rafId = requestAnimationFrame(passoHold);
            }

            function soltarHold() {
                if (!segurando) return;
                segurando = false;
                if (rafId) cancelAnimationFrame(rafId);
                fill.style.width = '0%';
                label.textContent = 'Confirmar';
                btnConfirmar.classList.remove('segurando');
                agendarExpiracao();
            }

            async function confirmarAcao() {
                pararTimers();
                try {
                    const resp = await fetch('/agente/confirmar', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({ token }),
                    });
                    if (resp.status === 401 || resp.status === 419) {
                        window.location.href = '/login';
                        return;
                    }
                    const dados = await resp.json().catch(() => ({}));
                    encerrarCard();
                    if (!resp.ok || dados.tipo === 'erro') {
                        popupErro(dados.mensagem);
                    } else {
                        popupConfirmado();
                    }
                } catch (e) {
                    encerrarCard();
                    popupErro();
                }
            }

            btnConfirmar.addEventListener('mousedown', iniciarHold);
            btnConfirmar.addEventListener('touchstart', iniciarHold);
            btnConfirmar.addEventListener('mouseup', soltarHold);
            btnConfirmar.addEventListener('blur', soltarHold);
            btnConfirmar.addEventListener('mouseleave', soltarHold);
            btnConfirmar.addEventListener('touchend', soltarHold);
            btnConfirmar.addEventListener('touchcancel', soltarHold);
            btnConfirmar.addEventListener('keydown', (e) => {
                if ((e.key === 'Enter' || e.key === ' ') && !e.repeat) {
                    e.preventDefault();
                    iniciarHold();
                }
            });
            btnConfirmar.addEventListener('keyup', (e) => {
                if (e.key === 'Enter' || e.key === ' ') soltarHold();
            });

            btnCancelar.addEventListener('click', () => {
                encerrarCard();
                popupCancelado();
            });
        }

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const mensagem = campoMensagem.value.trim();
            if (!mensagem) return;

            adicionarMensagem(mensagem, 'msg--usuario');
            campoMensagem.value = '';
            definirCarregando(true);

            const abortController = new AbortController();
            const timeout = setTimeout(() => abortController.abort(), 35000);
            try {
                const resp = await fetch('/agente/comando', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({ mensagem }),
                    signal: abortController.signal,
                });
                if (resp.status === 401 || resp.status === 419) {
                    window.location.href = '/login';
                    return;
                }
                const dados = await resp.json().catch(() => ({}));
                if (!resp.ok) {
                    const mensagens = {
                        422: 'Confira os dados do pedido.',
                        403: 'Você não tem permissão para esta operação.',
                        429: 'Muitos pedidos. Aguarde antes de tentar novamente.',
                        502: 'O serviço de IA retornou uma resposta inesperada. Tente novamente.',
                        503: 'O serviço de IA está indisponível no momento. Tente novamente.',
                        504: 'O serviço de IA demorou demais para responder. Tente novamente.',
                    };
                    adicionarMensagem(mensagens[resp.status] || 'Não foi possível processar o pedido.', 'msg--erro');
                    return;
                }

                switch (dados.tipo) {
                    case 'texto':
                        adicionarMensagem(dados.mensagem || '(sem resposta)', 'msg--agente');
                        break;
                    case 'resultado_leitura':
                        adicionarTabela(dados.resultado || [], dados.colunas);
                        break;
                    case 'confirmacao_pendente':
                        renderizarConfirmacaoPendente(dados.tool, dados.argumentos, dados.token, dados.risco);
                        break;
                    case 'erro':
                    default:
                        adicionarMensagem(dados.mensagem || 'Erro desconhecido.', 'msg--erro');
                        break;
                }

                // O aviso acompanha qualquer tipo de resposta (skill, seção 7).
                if (dados.aviso) adicionarAviso(dados.aviso);
            } catch (err) {
                adicionarMensagem(err.name === 'AbortError' ? 'O serviço de IA demorou demais para responder. Tente novamente.' : 'Falha ao contatar o servidor.', 'msg--erro');
            } finally {
                clearTimeout(timeout);
                definirCarregando(false);
                campoMensagem.focus();
            }
        });
    </script>
</body>
</html>
