{{-- Front de 14/11 (F2): as telas de leitura da clínica não gravam nada. O botão com data-tool abre o card de sempre
     (/agente/formulario; agenda em um passo, demais ações Revisar → Confirmar; segurar só nas destrutivas), com RBAC e tenant
     conferidos no servidor.
     25/11 (decisão do Lucas): depois de gravar, a lista da tela SE ATUALIZA sozinha (nada de faixa "Atualizar a tela"). A tela
     refaz o pedido da própria URL (mesmos filtros: dia, semana, setor...), troca só o conteúdo da página e preserva o que está
     aberto no #historico (cards). O item novo ou alterado fica destacado por um instante e a mudança é anunciada. Se o refresh
     falhar, aparece um aviso com "Tentar de novo" (nunca fica lista velha calada). --}}
<div id="historico" class="tela-acoes" aria-live="polite"></div>

@include('partials.bancada')

<script>
    // Delegação: os botões vêm e vão a cada atualização da lista, então o clique é tratado no documento.
    document.addEventListener('click', async (e) => {
        const botao = e.target.closest('[data-tool]');
        if (!botao || botao.disabled) return;
        await abrirFormulario(botao.dataset.tool, botao.dataset.id ? Number(botao.dataset.id) : null, botao);
        const card = historico.lastElementChild;
        if (card) card.scrollIntoView({ block: 'nearest' });
    });

    (function () {
        let emAndamento = false;
        let repetir = false;

        // Fica onde está (no conteúdo atual e fora do que vem novo): os cards abertos, os scripts, os moldes e as regiões de aviso
        // (#toasts e #status-anuncio), cujas referências o script da bancada guarda: trocá-las deixaria toast e leitor de tela mudos.
        const manter = (no) => no.nodeType === 1 && (['historico', 'toasts', 'status-anuncio'].includes(no.id) || no.tagName === 'SCRIPT' || no.tagName === 'TEMPLATE');
        const textoDasLinhas = (raiz) => Array.from(raiz.querySelectorAll('tbody tr, .linha-registro')).map((tr) => tr.textContent.replace(/\s+/g, ' ').trim());

        function avisoDeFalha() {
            if (document.getElementById('aviso-atualizar')) return;
            const aviso = document.createElement('div');
            aviso.id = 'aviso-atualizar';
            aviso.className = 'msg msg--erro faixa-atualizar';
            aviso.setAttribute('role', 'alert');
            const texto = document.createElement('span');
            texto.textContent = 'Gravado, mas não consegui atualizar esta lista agora.';
            const botao = document.createElement('button');
            botao.type = 'button';
            botao.textContent = 'Tentar de novo';
            botao.addEventListener('click', () => atualizarLista());
            aviso.append(texto, botao);
            historico.appendChild(aviso);
        }

        async function atualizarLista() {
            if (emAndamento) { repetir = true; return; }
            emAndamento = true;
            try {
                const resp = await fetch(window.location.href, { headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                if (resp.status === 401 || resp.status === 419) { window.location.href = '/login'; return; }
                if (resp.redirected && /\/login/.test(resp.url)) { window.location.href = '/login'; return; }
                if (!resp.ok) throw new Error('refresh');
                const doc = new DOMParser().parseFromString(await resp.text(), 'text/html');
                const novo = doc.getElementById('conteudo');
                const atual = document.getElementById('conteudo');
                if (!novo || !atual || !novo.querySelector('#historico')) throw new Error('refresh'); // sessão vencida ou outra página
                const antes = new Set(textoDasLinhas(atual));

                const novosAntes = [];
                const novosDepois = [];
                let passouDoHistorico = false;
                Array.from(novo.childNodes).forEach((no) => {
                    if (no.nodeType === 1 && no.id === 'historico') { passouDoHistorico = true; return; }
                    if (manter(no)) return;
                    (passouDoHistorico ? novosDepois : novosAntes).push(document.importNode(no, true));
                });
                // Troca só o conteúdo da página; #historico (cards abertos), scripts e templates ficam onde estão.
                Array.from(atual.childNodes).filter((no) => !manter(no)).forEach((no) => no.remove());
                historico.before(...novosAntes);
                historico.after(...novosDepois);
                document.getElementById('aviso-atualizar')?.remove();

                // Linha nova ou alterada: destaque por alguns segundos (texto + contorno, não só cor) e anúncio.
                let mudadas = 0;
                atual.querySelectorAll('tbody tr, .linha-registro').forEach((tr) => {
                    if (!antes.has(tr.textContent.replace(/\s+/g, ' ').trim())) {
                        tr.classList.add('linha-nova');
                        mudadas++;
                    }
                });
                if (mudadas) setTimeout(() => atual.querySelectorAll('.linha-nova').forEach((tr) => tr.classList.remove('linha-nova')), 4000);
                anunciar(mudadas ? 'lista atualizada: ' + mudadas + (mudadas === 1 ? ' registro novo ou alterado' : ' registros novos ou alterados') : 'lista atualizada');
            } catch (e) {
                avisoDeFalha();
            } finally {
                emAndamento = false;
                if (repetir) { repetir = false; atualizarLista(); }
            }
        }

        document.addEventListener('sentinel:gravado', () => atualizarLista());
    })();
</script>
