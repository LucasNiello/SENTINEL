{{-- Painel lateral do /agente (24/11). Só leitura: "Pendentes agora" (negócio) e "Atividade" (ações do próprio usuário).
     Dados de GET /agente/painel; tudo entra por textContent. A conversa continua só na sessão (sem lista de conversas). --}}
<aside class="painel-agente" id="painel-agente" aria-label="Painel do agente">
    <div class="painel-agente__topo">
        <p class="eyebrow">PAINEL</p>
        <button type="button" id="painel-fechar" class="painel-agente__fechar" aria-label="Fechar painel"><span aria-hidden="true">✕</span> Fechar</button>
    </div>
    <section aria-labelledby="painel-pendentes-titulo">
        <h2 id="painel-pendentes-titulo" class="painel-agente__titulo">Pendentes agora</h2>
        <ul id="painel-pendentes" class="painel-agente__lista"><li class="painel-agente__vazio">Carregando…</li></ul>
        <p id="painel-aviso" class="painel-agente__aviso" role="status" hidden></p>
    </section>
    <section aria-labelledby="painel-atividade-titulo">
        <h2 id="painel-atividade-titulo" class="painel-agente__titulo">Atividade</h2>
        <ul id="painel-atividade" class="painel-agente__lista"><li class="painel-agente__vazio">Carregando…</li></ul>
        <details id="painel-sem-efeito" class="painel-agente__sem-efeito" hidden>
            <summary id="painel-sem-efeito-titulo"></summary>
            <ul id="painel-sem-efeito-lista" class="painel-agente__lista"></ul>
        </details>
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
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const aviso = document.getElementById('painel-aviso');
        const listaAtividade = document.getElementById('painel-atividade');
        const blocoSemEfeito = document.getElementById('painel-sem-efeito');
        const tituloSemEfeito = document.getElementById('painel-sem-efeito-titulo');
        const listaSemEfeito = document.getElementById('painel-sem-efeito-lista');
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
            const zero = p.total === 0;
            // Zero: esmaecido e sem clique (nada a ver). Com pedido: o clique ENVIA a leitura ao chat; com href: abre a tela.
            const alvo = document.createElement(zero ? 'div' : (p.href ? 'a' : 'button'));
            if (!zero && p.href) alvo.href = p.href; else if (!zero) alvo.type = 'button';
            alvo.className = 'painel-agente__item' + (zero ? ' painel-agente__item--zero' : '');
            const rotulo = document.createElement('span');
            rotulo.className = 'painel-agente__rotulo';
            rotulo.textContent = p.rotulo;
            // De onde vêm (ex.: o e-mail de contato da clínica): sem isso "Mensagens novas" parece uma caixa sem dono.
            if (typeof p.origem === 'string' && p.origem !== '') {
                const origem = document.createElement('small');
                origem.textContent = p.origem;
                rotulo.appendChild(origem);
            }
            const total = document.createElement('span');
            total.className = 'painel-agente__total' + (zero ? ' painel-agente__total--zero' : '');
            total.textContent = String(p.total);
            alvo.append(rotulo, total);
            if (!zero && p.pedido) {
                alvo.addEventListener('click', async () => {
                    // Mensagens: traz o que chegou ao e-mail de contato antes de pedir, para a tabela vir atualizada.
                    if (p.chave === 'mensagens') await importarCaixa();
                    const motivo = typeof window.pedirAoAgente === 'function' ? window.pedirAoAgente(p.pedido, true) : 'Chat indisponível.';
                    // Não enviou: o motivo fica à vista no painel (não some calado) e a gaveta continua aberta.
                    aviso.textContent = '';
                    if (motivo) aviso.textContent = motivo; // limpar antes: o mesmo texto de novo é anunciado de novo
                    aviso.hidden = !motivo;
                    if (!motivo && !largo.matches) alternar(false);
                });
            }
            li.appendChild(alvo);
            return li;
        }

        // "há 5 min", "há 2 h" ou a data, para o que já passou de um dia.
        function haQuanto(iso) {
            const t = Date.parse(iso);
            if (Number.isNaN(t)) return '';
            const min = Math.max(0, Math.round((Date.now() - t) / 60000));
            if (min < 1) return 'agora';
            if (min < 60) return 'há ' + min + ' min';
            if (min < 1440) return 'há ' + Math.round(min / 60) + ' h';
            return new Date(t).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
        }

        function itemAtividade(r) {
            const li = document.createElement('li');
            // O href vem do servidor; só caminho interno ("/x"), nunca "//host" nem esquema.
            const interno = typeof r.href === 'string' && /^\/(?![\/\\])/.test(r.href);
            const corpo = document.createElement(interno ? 'a' : 'div');
            if (interno) corpo.href = r.href;
            corpo.className = 'painel-agente__atividade';
            const acao = document.createElement('span');
            acao.textContent = r.acao + (r.quantidade > 1 ? ' ×' + r.quantidade : '');
            const meta = document.createElement('small');
            const chip = document.createElement('span');
            chip.className = 'painel-agente__chip' + (r.resultado === 'Concluída' ? ' painel-agente__chip--ok' : '');
            chip.textContent = r.resultado;
            const quando = haQuanto(r.quando);
            meta.append(chip, quando ? ' ' + quando : '');
            corpo.append(acao, meta);
            li.appendChild(corpo);
            return li;
        }

        function mostrarAtividade(a) {
            if (a.com_efeito.length) listaAtividade.replaceChildren(...a.com_efeito.map(itemAtividade));
            else vazio(listaAtividade, 'Nada concluído por você ainda.');
            // Recolhido por padrão; só existe quando houve tentativa sem efeito.
            const semEfeito = a.sem_efeito;
            blocoSemEfeito.hidden = semEfeito.total === 0;
            tituloSemEfeito.textContent = 'Tentativas sem efeito (' + semEfeito.total + ')';
            listaSemEfeito.replaceChildren(...semEfeito.itens.map(itemAtividade));
        }

        // POST (grava mensagens novas vindas do Mailpit). Melhor esforço: o servidor tem trava de ~10 s e nunca falha por causa
        // do Mailpit; aqui, qualquer erro é ignorado e o painel segue com o que já existe.
        async function importarCaixa() {
            try {
                await fetch('/agente/caixa/importar', { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }, credentials: 'same-origin' });
            } catch (e) { /* sem rede: só não importa agora */ }
        }

        async function carregar() {
            if (carregando) return;
            carregando = true;
            try {
                await importarCaixa();
                const resp = await fetch('/agente/painel', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                if (resp.status === 401 || resp.status === 419) { window.location.href = '/login'; return; }
                if (!resp.ok) throw new Error('http ' + resp.status);
                const dados = await resp.json();
                if (dados.pendentes.length) listaPendentes.replaceChildren(...dados.pendentes.map(itemPendente));
                else vazio(listaPendentes, 'Nada para o seu papel aqui.');
                mostrarAtividade(dados.atividade);
                aviso.hidden = true; // o aviso de "não enviei" é de um instante; a atualização (card que saiu, resposta nova) o encerra
            } catch (e) {
                vazio(listaPendentes, 'Não foi possível carregar o painel.');
                vazio(listaAtividade, 'Tente de novo em instantes.');
                blocoSemEfeito.hidden = true;
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
