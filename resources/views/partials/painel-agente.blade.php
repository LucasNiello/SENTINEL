{{-- Painel lateral do /agente (24/11). Só leitura: "Pendentes agora" (negócio) e "Recentes" (ações do próprio usuário).
     Dados de GET /agente/painel; tudo entra por textContent. A conversa continua só na sessão (sem lista de conversas). --}}
<aside class="painel-agente" id="painel-agente" aria-label="Painel do agente">
    <div class="painel-agente__topo">
        <p class="eyebrow">PAINEL</p>
        <button type="button" id="painel-fechar" class="painel-agente__fechar" aria-label="Fechar painel"><span aria-hidden="true">✕</span> Fechar</button>
    </div>
    <section aria-labelledby="painel-pendentes-titulo">
        <h2 id="painel-pendentes-titulo" class="painel-agente__titulo">Pendentes agora</h2>
        <ul id="painel-pendentes" class="painel-agente__lista"><li class="painel-agente__vazio">Carregando…</li></ul>
    </section>
    <section aria-labelledby="painel-recentes-titulo">
        <h2 id="painel-recentes-titulo" class="painel-agente__titulo">Recentes</h2>
        <ul id="painel-recentes" class="painel-agente__lista"><li class="painel-agente__vazio">Carregando…</li></ul>
    </section>
</aside>

<script>
    // O parcial vem antes do botão e do #historico na página: só inicia com o DOM pronto.
    document.addEventListener('DOMContentLoaded', () => {
        const layout = document.querySelector('.command-layout');
        const painel = document.getElementById('painel-agente');
        const botao = document.getElementById('painel-alternar');
        const botaoFechar = document.getElementById('painel-fechar');
        const listaPendentes = document.getElementById('painel-pendentes');
        const listaRecentes = document.getElementById('painel-recentes');
        const largo = window.matchMedia('(min-width:1280px)');
        const CHAVE = 'sentinel.painelAgente';
        const lerPreferencia = () => { try { return localStorage.getItem(CHAVE); } catch (e) { return null; } };
        const gravarPreferencia = (v) => { try { localStorage.setItem(CHAVE, v); } catch (e) { /* sem armazenamento: só não lembra */ } };

        // Largo (>= 1280 px): começa aberto, a menos que a pessoa tenha recolhido. Abaixo disso: começa recolhido e, aberto,
        // vira gaveta por cima do chat (não empurra nem encolhe a conversa).
        let aberto = largo.matches && lerPreferencia() !== 'fechado';
        let carregando = false;

        function aplicar() {
            layout.classList.toggle('painel-aberto', aberto);
            botao.setAttribute('aria-expanded', String(aberto));
            botao.setAttribute('aria-label', aberto ? 'Fechar painel' : 'Abrir painel');
            if (aberto) carregar();
        }

        function alternar(novo) {
            aberto = novo;
            if (largo.matches) gravarPreferencia(aberto ? 'aberto' : 'fechado');
            aplicar();
            if (aberto && !largo.matches) botaoFechar.focus();
        }

        botao.addEventListener('click', () => alternar(!aberto));
        botaoFechar.addEventListener('click', () => { alternar(false); botao.focus(); });
        // Gaveta (< 1280 px): Esc fecha de onde o foco estiver, e o foco nunca fica escondido atrás dela.
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && aberto && !largo.matches) { alternar(false); botao.focus(); }
        });
        document.addEventListener('focusin', (e) => {
            if (aberto && !largo.matches && !painel.contains(e.target) && e.target !== botao) alternar(false);
        });
        largo.addEventListener('change', () => { aberto = largo.matches && lerPreferencia() !== 'fechado'; aplicar(); });

        function vazio(lista, texto) {
            const li = document.createElement('li');
            li.className = 'painel-agente__vazio';
            li.textContent = texto;
            lista.replaceChildren(li);
        }

        function itemPendente(p) {
            const li = document.createElement('li');
            const alvo = document.createElement(p.href ? 'a' : 'button');
            if (p.href) alvo.href = p.href; else alvo.type = 'button';
            alvo.className = 'painel-agente__item';
            const rotulo = document.createElement('span');
            rotulo.textContent = p.rotulo;
            const total = document.createElement('span');
            total.className = 'painel-agente__total' + (p.total === 0 ? ' painel-agente__total--zero' : '');
            total.textContent = String(p.total);
            alvo.append(rotulo, total);
            if (p.pedido) {
                alvo.addEventListener('click', () => {
                    if (typeof window.pedirAoAgente === 'function') window.pedirAoAgente(p.pedido);
                    if (!largo.matches) alternar(false);
                });
            }
            li.appendChild(alvo);
            return li;
        }

        function itemRecente(r) {
            const li = document.createElement('li');
            li.className = 'painel-agente__recente';
            const acao = document.createElement('span');
            acao.textContent = r.acao;
            const meta = document.createElement('small');
            const quando = r.quando ? new Date(r.quando).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '';
            meta.textContent = r.resultado + (quando ? ' · ' + quando : '');
            li.append(acao, meta);
            return li;
        }

        async function carregar() {
            if (carregando) return;
            carregando = true;
            try {
                const resp = await fetch('/agente/painel', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                if (resp.status === 401 || resp.status === 419) { window.location.href = '/login'; return; }
                if (!resp.ok) throw new Error('http ' + resp.status);
                const dados = await resp.json();
                if (dados.pendentes.length) listaPendentes.replaceChildren(...dados.pendentes.map(itemPendente));
                else vazio(listaPendentes, 'Nada para o seu papel aqui.');
                if (dados.recentes.length) listaRecentes.replaceChildren(...dados.recentes.map(itemRecente));
                else vazio(listaRecentes, 'Nenhuma ação sua ainda.');
            } catch (e) {
                vazio(listaPendentes, 'Não foi possível carregar o painel.');
                vazio(listaRecentes, 'Tente de novo em instantes.');
            } finally {
                carregando = false;
            }
        }

        // Atualiza sozinho depois que o agente responde (a conversa cresce), sem mexer no fluxo de envio.
        let espera = null;
        new MutationObserver(() => {
            if (!aberto) return;
            clearTimeout(espera);
            espera = setTimeout(carregar, 1200);
        }).observe(document.getElementById('historico'), { childList: true });

        aplicar();
    });
</script>
