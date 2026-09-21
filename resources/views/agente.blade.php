<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Sentinel') }} — Agente</title>
    <style>
        :root {
            --risco-neutro: #6b7280;
            --risco-ambar: #f59e0b;
            --risco-verde: #16a34a;
            --risco-vermelho: #dc2626;
        }

        * { box-sizing: border-box; }

        body {
            font-family: system-ui, sans-serif;
            max-width: 640px;
            margin: 2rem auto;
            padding: 0 1rem;
            color: #1a1a1a;
        }

        h1 { font-size: 1.25rem; }

        #historico {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .msg { padding: 0.6rem 0.8rem; border-radius: 6px; }
        .msg--usuario { background: #eef2ff; align-self: flex-end; }
        .msg--agente { background: #f3f4f6; }
        .msg--erro { background: #fee2e2; color: var(--risco-vermelho); }

        table { border-collapse: collapse; width: 100%; font-size: 0.9rem; }
        th, td { border: 1px solid #d1d5db; padding: 0.4rem 0.6rem; text-align: left; }
        th { background: #f9fafb; }

        form#form-comando { display: flex; gap: 0.5rem; }
        #campo-mensagem { flex: 1; padding: 0.6rem; font-size: 1rem; }
        #campo-mensagem, button { border: 1px solid #d1d5db; border-radius: 6px; }
        button { cursor: pointer; background: #fff; }
        button:disabled { cursor: not-allowed; opacity: 0.6; }

        /* Card de confirmação */
        .card-confirmacao {
            border: 1px solid var(--risco-ambar);
            background: #fffbeb;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .card-confirmacao pre {
            background: #fff;
            border: 1px solid #e5e7eb;
            padding: 0.5rem;
            border-radius: 4px;
            font-size: 0.85rem;
            overflow-x: auto;
        }
        .card-confirmacao .acoes {
            display: flex;
            gap: 0.75rem;
            margin-top: 0.75rem;
        }

        .btn-confirmar {
            position: relative;
            min-height: 44px;
            min-width: 140px;
            padding: 0.5rem 1rem;
            font-size: 16px;
            font-weight: 600;
            overflow: hidden;
            background: #fff;
            border: 2px solid var(--risco-verde);
            color: var(--risco-verde);
        }
        .btn-confirmar__fill {
            position: absolute;
            inset: 0;
            width: 0%;
            background: var(--risco-verde);
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
        .btn-confirmar.segurando .btn-confirmar__label { color: #fff; mix-blend-mode: difference; }
        .btn-confirmar__label .pequeno { font-size: 11px; font-weight: 400; }
        .btn-confirmar__label .grande { font-size: 18px; }

        .btn-cancelar {
            min-height: 44px;
            padding: 0.5rem 1rem;
            font-size: 16px;
            border: 2px solid var(--risco-vermelho);
            color: var(--risco-vermelho);
        }

        /* Popup de resultado */
        #overlay-popup {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(0, 0, 0, 0.15);
        }
        #popup {
            background: #fff;
            border-radius: 10px;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.05rem;
            font-weight: 600;
            opacity: 0;
            transition: opacity 1.5s ease;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }
        #popup.visivel { opacity: 1; }
        #popup.verde { color: var(--risco-verde); border: 2px solid var(--risco-verde); }
        #popup.vermelho { color: var(--risco-vermelho); border: 2px solid var(--risco-vermelho); }
        #popup.ambar { color: var(--risco-ambar); border: 2px solid var(--risco-ambar); }

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

    <div id="historico" aria-live="polite"></div>

    <form id="form-comando">
        <input type="text" id="campo-mensagem" placeholder="Pergunte algo sobre os lançamentos..." required>
        <button type="submit">Enviar</button>
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
        const statusAnuncio = document.getElementById('status-anuncio');
        const overlayPopup = document.getElementById('overlay-popup');
        const popup = document.getElementById('popup');

        const DURACAO_HOLD_MS = 2000;
        const DURACAO_IDLE_MS = 10000;

        function anunciar(texto) {
            statusAnuncio.textContent = texto;
        }

        function adicionarMensagem(texto, classe) {
            const div = document.createElement('div');
            div.className = 'msg ' + classe;
            div.textContent = texto;
            historico.appendChild(div);
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

        function mostrarPopup(estado, textoEl) {
            popup.className = estado;
            popup.innerHTML = textoEl;
            overlayPopup.style.display = 'flex';
            requestAnimationFrame(() => popup.classList.add('visivel'));
            setTimeout(() => {
                popup.classList.remove('visivel');
                setTimeout(() => { overlayPopup.style.display = 'none'; }, 400);
            }, 2500);
        }

        function popupConfirmado() {
            mostrarPopup('verde', '✔ Confirmado');
            anunciar('confirmado');
        }
        function popupCancelado() {
            mostrarPopup('vermelho', '✕ Cancelado');
            anunciar('cancelado');
        }
        function popupErro(mensagem) {
            mostrarPopup('vermelho', '✕ ' + (mensagem || 'Erro ao confirmar — tente novamente'));
            anunciar(mensagem || 'erro ao confirmar');
        }
        function popupTimeout() {
            mostrarPopup('ambar', '⏱ Tempo esgotado — nada foi confirmado');
            anunciar('tempo esgotado');
        }

        function renderizarConfirmacaoPendente(tool, argumentos, token) {
            const card = document.createElement('div');
            card.className = 'card-confirmacao';
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

            let segurando = false;
            let inicioHold = 0;
            let rafId = null;
            let idleTimer = setTimeout(() => {
                encerrarCard();
                popupTimeout();
            }, DURACAO_IDLE_MS);

            function encerrarCard() {
                clearTimeout(idleTimer);
                if (rafId) cancelAnimationFrame(rafId);
                card.remove();
            }

            function iniciarHold() {
                if (segurando) return;
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
                label.innerHTML = `<span class="pequeno">confirmando</span><span class="grande">${Math.floor(pct)}%</span>`;

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
            }

            async function confirmarAcao() {
                clearTimeout(idleTimer);
                try {
                    const resp = await fetch('/agente/confirmar', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({ token }),
                    });
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

            try {
                const resp = await fetch('/agente/comando', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({ mensagem }),
                });
                const dados = await resp.json();

                switch (dados.tipo) {
                    case 'texto':
                        adicionarMensagem(dados.mensagem || '(sem resposta)', 'msg--agente');
                        break;
                    case 'resultado_leitura':
                        adicionarTabela(dados.resultado || [], dados.colunas);
                        break;
                    case 'confirmacao_pendente':
                        renderizarConfirmacaoPendente(dados.tool, dados.argumentos, dados.token);
                        break;
                    case 'erro':
                    default:
                        adicionarMensagem(dados.mensagem || 'Erro desconhecido.', 'msg--erro');
                        break;
                }
            } catch (err) {
                adicionarMensagem('Falha ao contatar o servidor.', 'msg--erro');
            }
        });
    </script>
</body>
</html>
