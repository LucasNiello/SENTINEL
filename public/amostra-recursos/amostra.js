/* AMOSTRA — comportamento das telas de demonstração (JS vanilla).
   Nada aqui fala com o servidor: confirmar, cancelar e enviar só mostram o resultado esperado. */
(function () {
    'use strict';

    var DURACAO_HOLD_MS = 2000; // mesmo valor do agente real (DURACAO_HOLD_MS)

    var overlay = document.getElementById('overlay-popup');
    var anuncio = document.getElementById('status-anuncio');
    var timerPopup = null;

    function anunciar(texto) {
        if (anuncio) anuncio.textContent = texto;
    }

    // Popup: sucesso e cancelamento somem em 2,5 s, sem fade. O erro fica até o usuário fechar.
    function mostrarPopup(estado, texto, detalhe) {
        if (!overlay) return;
        clearTimeout(timerPopup);
        overlay.replaceChildren();
        var caixa = document.createElement('div');
        caixa.className = 'popup popup--' + estado;
        caixa.setAttribute('role', estado === 'erro' ? 'alert' : 'status');
        caixa.append(document.createTextNode(texto));
        if (detalhe) {
            var pequeno = document.createElement('small');
            pequeno.textContent = detalhe;
            caixa.appendChild(pequeno);
        }
        if (estado === 'erro') {
            var fechar = document.createElement('button');
            fechar.type = 'button';
            fechar.className = 'btn btn--pequeno';
            fechar.textContent = 'Fechar';
            fechar.addEventListener('click', fecharPopup);
            caixa.appendChild(fechar);
        }
        overlay.appendChild(caixa);
        overlay.classList.add('aberto');
        if (estado === 'erro') {
            var botao = caixa.querySelector('button');
            if (botao) botao.focus();
        } else {
            timerPopup = setTimeout(fecharPopup, 2500);
        }
        anunciar(texto);
    }

    function fecharPopup() {
        if (overlay) overlay.classList.remove('aberto');
    }

    window.AMOSTRA = { popup: mostrarPopup };

    var NADA = 'AMOSTRA: nada foi gravado.';

    // ---------- segurar para confirmar ----------
    document.querySelectorAll('[data-hold]').forEach(function (botao) {
        var fill = botao.querySelector('.btn-confirmar__fill');
        var rotulo = botao.querySelector('.btn-confirmar__label');
        var textoOriginal = rotulo ? rotulo.textContent.trim() : 'Confirmar';
        var pequeno = document.createElement('span');
        pequeno.className = 'pequeno';
        pequeno.textContent = 'confirmando';
        var grande = document.createElement('span');
        grande.className = 'grande';
        var segurando = false;
        var inicio = 0;
        var raf = null;
        var raiz = botao.closest('.card, dialog, form');
        var aviso = raiz ? raiz.querySelector('[data-aviso-soltou]') : null;

        function reiniciar() {
            segurando = false;
            if (raf) cancelAnimationFrame(raf);
            if (fill) fill.style.width = '0%';
            if (rotulo) rotulo.textContent = textoOriginal;
        }

        function passo() {
            if (!segurando) return;
            var pct = Math.min(100, ((performance.now() - inicio) / DURACAO_HOLD_MS) * 100);
            if (fill) fill.style.width = pct + '%';
            grande.textContent = Math.floor(pct) + '%';
            if (rotulo && grande.parentNode !== rotulo) rotulo.replaceChildren(pequeno, grande);
            if (pct >= 100) {
                reiniciar();
                if (aviso) aviso.hidden = true;
                var dialogo = botao.closest('dialog');
                if (dialogo) dialogo.close();
                mostrarPopup('sucesso', '✔ Confirmado', botao.getAttribute('data-hold') || NADA);
                return;
            }
            raf = requestAnimationFrame(passo);
        }

        function iniciar() {
            if (segurando || botao.disabled) return;
            segurando = true;
            inicio = performance.now();
            if (aviso) aviso.hidden = true;
            anunciar('confirmando');
            passo();
        }

        function soltar() {
            if (!segurando) return;
            reiniciar();
            if (aviso) {
                aviso.hidden = false;
                aviso.textContent = 'Você soltou antes do fim. Nada foi gravado.';
            }
            anunciar('você soltou antes do fim, nada foi gravado');
        }

        botao.addEventListener('mousedown', iniciar);
        botao.addEventListener('touchstart', function (e) { e.preventDefault(); iniciar(); }, { passive: false });
        botao.addEventListener('mouseup', soltar);
        botao.addEventListener('mouseleave', soltar);
        botao.addEventListener('touchend', soltar);
        botao.addEventListener('touchcancel', soltar);
        botao.addEventListener('keydown', function (e) {
            if ((e.key === 'Enter' || e.key === ' ') && !e.repeat) { e.preventDefault(); iniciar(); }
        });
        botao.addEventListener('keyup', function (e) {
            if (e.key === 'Enter' || e.key === ' ') soltar();
        });
        botao.addEventListener('blur', soltar);
    });

    // ---------- cancelar (um clique) ----------
    document.querySelectorAll('[data-cancelar]').forEach(function (botao) {
        botao.addEventListener('click', function () {
            var dialogo = botao.closest('dialog');
            if (dialogo) dialogo.close();
            mostrarPopup('neutro', '✕ Cancelado', NADA);
        });
    });

    // ---------- modais (<dialog>) ----------
    document.querySelectorAll('[data-abrir]').forEach(function (botao) {
        botao.addEventListener('click', function () {
            var alvo = document.getElementById(botao.getAttribute('data-abrir'));
            if (alvo && typeof alvo.showModal === 'function') alvo.showModal();
        });
    });
    document.querySelectorAll('[data-fechar]').forEach(function (botao) {
        botao.addEventListener('click', function () {
            var dialogo = botao.closest('dialog');
            if (dialogo) dialogo.close();
        });
    });
    var inicial = document.body.getAttribute('data-modal-inicial');
    if (inicial) {
        var alvoInicial = document.getElementById('modal-' + inicial);
        if (alvoInicial && typeof alvoInicial.showModal === 'function') alvoInicial.showModal();
    }

    // ---------- formulários da amostra: nunca enviam nada ----------
    document.querySelectorAll('form[data-amostra]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var aviso = form.getAttribute('data-amostra');
            mostrarPopup('neutro', aviso || 'Para gravar, segure o botão Confirmar.', NADA);
        });
    });

    // ---------- botões que só existem na amostra (ex.: exportar CSV) ----------
    document.querySelectorAll('[data-amostra-acao]').forEach(function (botao) {
        botao.addEventListener('click', function () {
            mostrarPopup('neutro', botao.getAttribute('data-amostra-acao'), NADA);
        });
    });

    // ---------- linha de tabela clicável (Enter também abre) ----------
    document.querySelectorAll('tr[data-href]').forEach(function (linha) {
        function ir() { window.location.href = linha.getAttribute('data-href'); }
        linha.addEventListener('click', ir);
        linha.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); ir(); }
        });
    });

    // ---------- sugestões do chat vazio preenchem o campo ----------
    document.querySelectorAll('[data-sugestao]').forEach(function (botao) {
        botao.addEventListener('click', function () {
            var campo = document.getElementById('campo-mensagem');
            if (campo) { campo.value = botao.getAttribute('data-sugestao'); campo.focus(); }
        });
    });

    // Esc fecha o popup de erro
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') fecharPopup();
    });
})();
