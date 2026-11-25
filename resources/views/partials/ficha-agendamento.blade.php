{{-- Molde único da ficha de agendamento (15/11): agendar, remarcar e trocar o serviço. O card de sempre (bancada) monta o
     cabeçalho (o "resumo" que o servidor manda: pet e tutor com link, serviço, profissional e horário atuais) e os campos
     editáveis da ação; este molde troca os campos de profissional e início pelo calendário: profissional (lista do setor,
     com busca por digitação no MESMO campo) → dia (Hoje, Amanhã e mini mês; dia sem vaga apagado) → horário (clínica:
     "09:00 · 2 vagas", lotado desabilitado; petshop: os horários livres). 25/11: clicar num horário só SELECIONA (dá para
     trocar à vontade); quem confere e grava é o Confirmar, em um passo. "Qualquer" = profissional em branco: o servidor
     escolhe na gravação (menos atendimentos no dia), então não há pergunta "quem atende?". O calendário só mostra
     (GET /agenda/disponibilidade): a vaga é conferida de novo na gravação.
     Tudo por createElement/textContent; nada de HTML vindo do servidor. Sem animação (bancada). --}}
<template id="molde-ficha-agendamento">
    <div class="ficha-agenda">
        <div class="ficha-atalhos" hidden></div>
        <fieldset class="ficha-passo">
            <legend>1. Profissional</legend>
            <label class="ficha-rotulo ficha-rotulo-profissional">Profissional (digite para filtrar)</label>
            {{-- 25/11: UM campo só (cascata + busca por digitação), padrão ARIA combobox/listbox. Sem "name" de e-mail/busca e com os
                 atributos que silenciam gerenciadores de senha/alias (o balão "Novo alias de e-mail" aparecia em cima do campo). --}}
            <div class="ficha-combo">
                <input type="text" class="ficha-profissional" role="combobox" aria-autocomplete="list" aria-expanded="false"
                       autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="60"
                       data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other" data-protonpass-ignore="true">
                <ul class="ficha-combo-lista" role="listbox" aria-label="Profissionais" hidden></ul>
            </div>
        </fieldset>
        <fieldset class="ficha-passo">
            <legend>2. Dia</legend>
            <div class="ficha-dias-rapidos">
                <button type="button" class="ficha-hoje">Hoje</button>
                <button type="button" class="ficha-amanha">Amanhã</button>
            </div>
            <div class="ficha-mes">
                <div class="ficha-mes-topo">
                    <button type="button" class="ficha-mes-anterior" aria-label="Mês anterior">‹</button>
                    <strong class="ficha-mes-titulo" aria-live="polite"></strong>
                    <button type="button" class="ficha-mes-proximo" aria-label="Próximo mês">›</button>
                </div>
                <div class="ficha-semana" aria-hidden="true"><span>seg</span><span>ter</span><span>qua</span><span>qui</span><span>sex</span><span>sáb</span><span>dom</span></div>
                <div class="ficha-mes-grade"></div>
            </div>
        </fieldset>
        <fieldset class="ficha-passo">
            <legend>3. Horário</legend>
            <p class="ficha-estado" role="status"></p>
            <div class="ficha-horarios"></div>
            <p class="ficha-escolha" role="status" hidden></p>
        </fieldset>
    </div>
</template>

<script>
    (function () {
        const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        const doisDigitos = (n) => String(n).padStart(2, '0');
        const isoDia = (d) => d.getFullYear() + '-' + doisDigitos(d.getMonth() + 1) + '-' + doisDigitos(d.getDate());
        const deIso = (s) => { const [a, m, d] = s.split('-').map(Number); return new Date(a, m - 1, d); };
        const diaPorExtenso = (s) => { const d = deIso(s); return d.getDate() + ' de ' + MESES[d.getMonth()]; };

        // Chamado pelo card (bancada) a cada montagem dos campos (abertura e volta do Revisar com erro). O estado (profissional,
        // dia, mês) fica guardado no próprio card: a ficha recusada volta para "escolhendo" no mesmo dia, com o motivo.
        window.montarFichaAgendamento = function ({ card, form, campos, formulario, prefixo, revisar, abrirOutra }) {
            const ficha = formulario.ficha;
            const molde = document.getElementById('molde-ficha-agendamento');
            if (!ficha || !molde) return;

            const entradaProfissional = form.elements.namedItem('profissional_id');
            const entradaInicio = form.elements.namedItem('inicio');
            const entradaServico = form.elements.namedItem('catalogo_item_id');
            // O calendário substitui os dois campos (continuam no formulário, escondidos: é o que vai no Revisar).
            const erros = [];
            [entradaProfissional, entradaInicio].forEach((entrada) => {
                if (!entrada) return;
                const bloco = entrada.closest('.campo');
                const erro = bloco && bloco.querySelector('.campo-erro');
                if (erro) erros.push(erro.textContent);
                if (bloco) bloco.hidden = true;
            });

            const raiz = molde.content.firstElementChild.cloneNode(true);
            campos.appendChild(raiz);
            const $ = (seletor) => raiz.querySelector(seletor);
            const campoProfissional = $('.ficha-profissional');
            const listaProfissional = $('.ficha-combo-lista');
            campoProfissional.id = prefixo + '-ficha-profissional';
            listaProfissional.id = prefixo + '-ficha-profissional-lista';
            campoProfissional.setAttribute('aria-controls', listaProfissional.id);
            $('.ficha-rotulo-profissional').htmlFor = campoProfissional.id;
            const estadoTexto = $('.ficha-estado');
            const horarios = $('.ficha-horarios');
            const escolhaTexto = $('.ficha-escolha');

            const hoje = new Date();
            const estado = card.fichaEstado || (card.fichaEstado = {
                profissional: entradaProfissional && entradaProfissional.value ? entradaProfissional.value : '',
                dia: ficha.dia && ficha.dia >= isoDia(hoje) ? ficha.dia : isoDia(hoje),
                mes: null,
                profissionais: [],
            });
            estado.mes = estado.mes || estado.dia.slice(0, 7);
            let pedido = 0;

            // Atalhos: a ficha de outra ação do mesmo agendamento (Trocar serviço, Mudar status), sem IA.
            const atalhos = $('.ficha-atalhos');
            (ficha.atalhos || []).forEach((a) => {
                const botao = document.createElement('button');
                botao.type = 'button';
                botao.className = 'btn-editar-linha';
                botao.textContent = a.rotulo;
                botao.addEventListener('click', () => abrirOutra(a.tool, ficha.agendamento_id));
                atalhos.appendChild(botao);
            });
            atalhos.hidden = !atalhos.children.length;

            const servicoAtual = () => (entradaServico && entradaServico.value) || (ficha.servico_id ? String(ficha.servico_id) : '');
            const nomeDe = (id) => (estado.profissionais.find((p) => String(p.id) === String(id)) || {}).nome || ('#' + id);
            const qualquer = () => (estado.setor === 'petshop' ? 'Qualquer atendente' : 'Qualquer veterinário');
            const semAcento = (t) => String(t).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

            function dizer(texto) { estadoTexto.textContent = texto; }

            async function buscar(parametros) {
                const url = '/agenda/disponibilidade?' + new URLSearchParams(Object.entries({
                    servico: servicoAtual(), profissional: estado.profissional, ignorar: ficha.agendamento_id || '', ...parametros,
                }).filter(([, v]) => v !== '' && v !== null && v !== undefined));
                const resp = await fetch(url, { headers: { Accept: 'application/json' } });
                if (resp.status === 401 || resp.status === 419) { window.location.href = '/login'; return null; }
                const dados = await resp.json().catch(() => ({}));
                if (!resp.ok) throw new Error(dados.mensagem || 'Não consegui carregar os horários.');
                return dados;
            }

            // ---- Combobox do profissional (ARIA): cascata (Qualquer + os do setor) e filtro por digitação, no mesmo campo.
            let opcoesVisiveis = [];
            let ativa = -1;
            let digitando = false;

            const rotuloDoSelecionado = () => (estado.profissional ? nomeDe(estado.profissional) : qualquer());

            function fecharLista(restaurar = true) {
                listaProfissional.hidden = true;
                campoProfissional.setAttribute('aria-expanded', 'false');
                campoProfissional.removeAttribute('aria-activedescendant');
                digitando = false;
                ativa = -1;
                if (restaurar) campoProfissional.value = rotuloDoSelecionado();
            }

            function desenharLista() {
                // "Qualquer" sempre fica (mesmo sem lista carregada ou com falha): é o padrão seguro e o servidor escolhe.
                const termo = digitando ? semAcento(campoProfissional.value.trim()) : '';
                opcoesVisiveis = [{ id: '', nome: qualquer() }, ...estado.profissionais.filter((p) => !termo || semAcento(p.nome).includes(termo))];
                const itens = opcoesVisiveis.map((p, i) => {
                    const li = document.createElement('li');
                    li.id = listaProfissional.id + '-' + i;
                    li.setAttribute('role', 'option');
                    li.className = 'ficha-combo-opcao';
                    li.textContent = p.nome;
                    const marcado = String(p.id) === estado.profissional;
                    li.setAttribute('aria-selected', marcado ? 'true' : 'false');
                    if (i === ativa) li.classList.add('is-ativa');
                    // mousedown (e não click) para o campo não perder o foco antes de escolher.
                    li.addEventListener('mousedown', (e) => { e.preventDefault(); escolherProfissional(p.id); });
                    return li;
                });
                if (itens.length === 1 && termo) {
                    const vazio = document.createElement('li');
                    vazio.className = 'ficha-combo-vazio';
                    vazio.setAttribute('role', 'presentation');
                    vazio.textContent = 'Nenhum profissional com esse nome.';
                    itens.push(vazio);
                }
                listaProfissional.replaceChildren(...itens);
                if (ativa >= 0 && itens[ativa]) campoProfissional.setAttribute('aria-activedescendant', itens[ativa].id);
                else campoProfissional.removeAttribute('aria-activedescendant');
            }

            function abrirLista() {
                if (campoProfissional.disabled) return;
                digitando = false; // abre mostrando TODAS as opções; só filtra depois que a pessoa digita
                ativa = -1;
                desenharLista(); // calcula opcoesVisiveis
                ativa = Math.max(0, opcoesVisiveis.findIndex((p) => String(p.id) === estado.profissional));
                desenharLista();
                listaProfissional.hidden = false;
                campoProfissional.setAttribute('aria-expanded', 'true');
            }

            function escolherProfissional(id) {
                const novo = String(id);
                fecharLista(false);
                const mudou = novo !== estado.profissional;
                estado.profissional = novo;
                campoProfissional.value = rotuloDoSelecionado();
                if (mudou) {
                    // O horário já escolhido segue o novo profissional (ou "qualquer"); se ele não tiver vaga ali, montarHorarios() o tira.
                    if (estado.escolhido) estado.escolhido.profissional = novo;
                    // O campo do formulário SEMPRE acompanha o combobox (senão a tela mostra "Qualquer" e o servidor grava o profissional antigo).
                    if (entradaProfissional) entradaProfissional.value = novo;
                    resumirEscolha();
                    carregarMes();
                }
            }

            function montarProfissionais() {
                if (!estado.profissionais.some((p) => String(p.id) === estado.profissional)) estado.profissional = '';
                if (document.activeElement !== campoProfissional) campoProfissional.value = rotuloDoSelecionado();
                if (!listaProfissional.hidden) desenharLista();
            }

            campoProfissional.addEventListener('focus', () => { campoProfissional.select(); abrirLista(); });
            campoProfissional.addEventListener('click', () => { if (listaProfissional.hidden) abrirLista(); });
            campoProfissional.addEventListener('input', () => { digitando = true; ativa = 0; if (listaProfissional.hidden) { listaProfissional.hidden = false; campoProfissional.setAttribute('aria-expanded', 'true'); } desenharLista(); });
            campoProfissional.addEventListener('blur', () => fecharLista());
            campoProfissional.addEventListener('keydown', (e) => {
                const aberta = !listaProfissional.hidden;
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (!aberta) { abrirLista(); return; }
                    const passo = e.key === 'ArrowDown' ? 1 : -1;
                    ativa = (ativa + passo + opcoesVisiveis.length) % opcoesVisiveis.length;
                    desenharLista();
                    const li = listaProfissional.children[ativa];
                    if (li && li.scrollIntoView) li.scrollIntoView({ block: 'nearest' });
                } else if (e.key === 'Enter' && aberta) {
                    e.preventDefault(); // não envia o formulário do card
                    if (opcoesVisiveis[ativa]) escolherProfissional(opcoesVisiveis[ativa].id);
                } else if (e.key === 'Escape' && aberta) {
                    e.preventDefault();
                    fecharLista();
                } else if (e.key === 'Home' || e.key === 'End') {
                    if (aberta) { e.preventDefault(); ativa = e.key === 'Home' ? 0 : opcoesVisiveis.length - 1; desenharLista(); }
                }
            });

            async function carregarMes() {
                const meu = ++pedido;
                if (!servicoAtual()) {
                    $('.ficha-mes-grade').replaceChildren();
                    horarios.replaceChildren();
                    dizer('Escolha o serviço para ver os horários.');
                    return;
                }
                try {
                    const dados = await buscar({ mes: estado.mes });
                    if (!dados || meu !== pedido) return;
                    estado.setor = dados.setor;
                    estado.profissionais = dados.profissionais || [];
                    // Profissional que não é do setor do serviço (ex.: troca de setor): volta para "qualquer".
                    if (estado.profissional && !estado.profissionais.some((p) => String(p.id) === estado.profissional)) {
                        estado.profissional = '';
                        if (entradaProfissional) entradaProfissional.value = '';
                        if (estado.escolhido) estado.escolhido.profissional = '';
                    }
                    montarProfissionais();
                    montarMes(dados.dias || {});
                    await carregarDia(meu);
                } catch (e) {
                    if (meu === pedido) dizer(e.message);
                }
            }

            function montarMes(dias) {
                const [ano, mes] = estado.mes.split('-').map(Number);
                $('.ficha-mes-titulo').textContent = MESES[mes - 1] + ' de ' + ano;
                const primeiro = new Date(ano, mes - 1, 1);
                const vazios = (primeiro.getDay() + 6) % 7; // segunda = 0
                const celulas = [];
                for (let i = 0; i < vazios; i++) celulas.push(document.createElement('span'));
                Object.keys(dias).sort().forEach((iso) => {
                    const botao = document.createElement('button');
                    botao.type = 'button';
                    botao.className = 'ficha-dia';
                    botao.textContent = String(deIso(iso).getDate());
                    botao.disabled = !dias[iso];
                    botao.setAttribute('aria-label', diaPorExtenso(iso) + (dias[iso] ? ', com vaga' : ', sem vaga'));
                    botao.dataset.iso = iso;
                    botao.setAttribute('aria-pressed', iso === estado.dia ? 'true' : 'false');
                    botao.addEventListener('click', () => escolherDia(iso));
                    celulas.push(botao);
                });
                $('.ficha-mes-grade').replaceChildren(...celulas);
                $('.ficha-mes-anterior').disabled = estado.mes <= isoDia(hoje).slice(0, 7);
            }

            async function carregarDia(meuMes) {
                const meu = meuMes || ++pedido;
                dizer('Carregando os horários de ' + diaPorExtenso(estado.dia) + '…');
                try {
                    const dados = await buscar({ dia: estado.dia });
                    if (!dados || meu !== pedido) return;
                    montarHorarios(dados);
                } catch (e) {
                    if (meu === pedido) dizer(e.message);
                }
            }

            function montarHorarios(dados) {
                const blocos = dados.modelo === 'blocos';
                // Só a consulta divide o bloco ("09:00 · 2 vagas"); os demais serviços aparecem como livre/ocupado.
                const comVagas = blocos && dados.mostra_vagas;
                estado.comVagas = comVagas;
                const botoes = (dados.horarios || []).map((h) => {
                    const botao = document.createElement('button');
                    botao.type = 'button';
                    botao.className = 'ficha-horario mono';
                    const lotado = h.vagas < 1;
                    botao.textContent = comVagas ? h.hora + ' · ' + (lotado ? 'lotado' : h.vagas + (h.vagas === 1 ? ' vaga' : ' vagas'))
                        : blocos ? h.hora + ' · ' + (lotado ? 'ocupado' : 'livre') : h.hora;
                    botao.disabled = lotado;
                    botao.dataset.inicio = h.inicio;
                    botao.setAttribute('aria-pressed', entradaInicio && entradaInicio.value === h.inicio ? 'true' : 'false');
                    if (lotado) botao.setAttribute('aria-label', h.hora + ', ' + (blocos ? 'sem vaga' : 'indisponível'));
                    botao.addEventListener('click', () => escolherHorario(h));
                    return botao;
                });
                horarios.replaceChildren(...botoes);
                // O horário escolhido neste dia que o profissional atual não oferece mais (ocupado): sai, em vez de ficar valendo calado.
                const escolhido = estado.escolhido;
                if (escolhido && escolhido.dia === estado.dia && !(dados.horarios || []).some((h) => h.inicio === escolhido.inicio && h.vagas > 0)) {
                    limparHorarioEscolhido();
                }
                const livres = (dados.horarios || []).filter((h) => h.vagas > 0).length;
                const motivo = formulario.erro || erros[0];
                dizer((motivo ? motivo + ' ' : '') + (livres
                    ? diaPorExtenso(estado.dia) + ': ' + livres + (livres === 1 ? ' horário livre.' : ' horários livres.') + ' Escolha um horário e clique em Confirmar.'
                    : diaPorExtenso(estado.dia) + ': nenhum horário livre. Escolha outro dia.'));
                resumirEscolha();
            }

            function escolherDia(iso) {
                estado.dia = iso;
                if (iso.slice(0, 7) !== estado.mes) {
                    estado.mes = iso.slice(0, 7);
                    carregarMes();
                    return;
                }
                // Marca o dia na hora (não espera a resposta): quadrado cheio no escolhido, todos os outros desmarcados.
                raiz.querySelectorAll('.ficha-dia').forEach((b) => b.setAttribute('aria-pressed', b.dataset.iso === iso ? 'true' : 'false'));
                carregarDia();
            }

            // Clicar num horário só SELECIONA (dá para trocar à vontade); o Confirmar revisa e grava em um passo.
            // Profissional em branco = "qualquer": o servidor escolhe na gravação, então não há pergunta "quem atende?".
            function escolherHorario(h) {
                if (h.vagas < 1) return;
                estado.escolhido = { inicio: h.inicio, hora: h.hora, dia: estado.dia, profissional: estado.profissional };
                if (entradaProfissional) entradaProfissional.value = estado.profissional;
                if (entradaInicio) entradaInicio.value = h.inicio;
                horarios.querySelectorAll('.ficha-horario').forEach((b) => b.setAttribute('aria-pressed', b.dataset.inicio === h.inicio ? 'true' : 'false'));
                resumirEscolha();
            }

            function limparHorarioEscolhido() {
                estado.escolhido = null;
                if (entradaInicio) entradaInicio.value = '';
                horarios.querySelectorAll('.ficha-horario').forEach((b) => b.setAttribute('aria-pressed', 'false'));
                resumirEscolha();
            }

            function resumirEscolha() {
                const e = estado.escolhido;
                escolhaTexto.hidden = !e;
                if (!e) return;
                const quem = e.profissional ? nomeDe(e.profissional) : qualquer() + ' (o sistema escolhe quem atende)';
                escolhaTexto.textContent = 'Escolhido: ' + diaPorExtenso(e.dia) + ', ' + e.hora + ', com ' + quem + '.';
            }

            $('.ficha-hoje').addEventListener('click', () => escolherDia(isoDia(new Date())));
            $('.ficha-amanha').addEventListener('click', () => { const d = new Date(); d.setDate(d.getDate() + 1); escolherDia(isoDia(d)); });
            const andarMes = (passo) => {
                const [a, m] = estado.mes.split('-').map(Number);
                const novo = new Date(a, m - 1 + passo, 1);
                estado.mes = isoDia(novo).slice(0, 7);
                carregarMes();
            };
            $('.ficha-mes-anterior').addEventListener('click', () => andarMes(-1));
            $('.ficha-mes-proximo').addEventListener('click', () => andarMes(1));
            // Trocou o serviço (agendar, trocar o serviço): o setor pode mudar; a lista e o calendário recarregam.
            if (entradaServico && !entradaServico.dataset.fichaOuvindo) {
                entradaServico.dataset.fichaOuvindo = '1';
                entradaServico.addEventListener('change', () => { if (card.fichaRecarregar) card.fichaRecarregar(); });
            }
            card.fichaRecarregar = carregarMes;

            campoProfissional.value = rotuloDoSelecionado();
            resumirEscolha();
            carregarMes();
        };
    })();
</script>
