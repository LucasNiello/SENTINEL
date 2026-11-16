{{-- Molde único da ficha de agendamento (15/11): agendar, remarcar e trocar o serviço. O card de sempre (bancada) monta o
     cabeçalho (o "resumo" que o servidor manda: pet e tutor com link, serviço, profissional e horário atuais) e os campos
     editáveis da ação; este molde troca os campos de profissional e início pelo calendário: profissional (lista do setor,
     com busca) → dia (Hoje, Amanhã e mini mês; dia sem vaga apagado) → horário (clínica: "09:00 · 2 vagas", lotado
     desabilitado; petshop: os horários livres). Clicar num horário VALE como Revisar: o servidor confere e emite o token;
     sobra só o Confirmar. O calendário só mostra (GET /agenda/disponibilidade): a vaga é conferida de novo na gravação.
     Tudo por createElement/textContent; nada de HTML vindo do servidor. Sem animação (bancada). --}}
<template id="molde-ficha-agendamento">
    <div class="ficha-agenda">
        <div class="ficha-atalhos" hidden></div>
        <fieldset class="ficha-passo">
            <legend>1. Profissional</legend>
            <label class="ficha-rotulo ficha-rotulo-busca">Buscar profissional</label>
            <input type="search" class="ficha-busca" autocomplete="off" maxlength="60">
            <label class="ficha-rotulo ficha-rotulo-profissional">Profissional</label>
            <select class="ficha-profissional"></select>
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
            <div class="ficha-quem" hidden>
                <p class="ficha-quem-titulo"></p>
                <div class="ficha-quem-lista"></div>
            </div>
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
            const busca = $('.ficha-busca');
            const selProfissional = $('.ficha-profissional');
            busca.id = prefixo + '-ficha-busca';
            selProfissional.id = prefixo + '-ficha-profissional';
            $('.ficha-rotulo-busca').htmlFor = busca.id;
            $('.ficha-rotulo-profissional').htmlFor = selProfissional.id;
            const estadoTexto = $('.ficha-estado');
            const horarios = $('.ficha-horarios');
            const quem = $('.ficha-quem');

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

            function montarProfissionais() {
                const termo = busca.value.trim().toLowerCase();
                const opcoes = [new Option(qualquer(), '')];
                estado.profissionais
                    .filter((p) => !termo || p.nome.toLowerCase().includes(termo) || String(p.id) === estado.profissional)
                    .forEach((p) => opcoes.push(new Option(p.nome, String(p.id))));
                selProfissional.replaceChildren(...opcoes);
                selProfissional.value = estado.profissionais.some((p) => String(p.id) === estado.profissional) ? estado.profissional : '';
            }

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
                    if (estado.profissional && !estado.profissionais.some((p) => String(p.id) === estado.profissional)) estado.profissional = '';
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
                    botao.setAttribute('aria-pressed', iso === estado.dia ? 'true' : 'false');
                    botao.addEventListener('click', () => escolherDia(iso));
                    celulas.push(botao);
                });
                $('.ficha-mes-grade').replaceChildren(...celulas);
                $('.ficha-mes-anterior').disabled = estado.mes <= isoDia(hoje).slice(0, 7);
            }

            async function carregarDia(meuMes) {
                const meu = meuMes || ++pedido;
                quem.hidden = true;
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
                    if (entradaInicio && entradaInicio.value === h.inicio && (!estado.profissional || !entradaProfissional || entradaProfissional.value === estado.profissional)) {
                        botao.setAttribute('aria-current', 'true');
                    }
                    botao.addEventListener('click', () => escolherHorario(h));
                    return botao;
                });
                horarios.replaceChildren(...botoes);
                const livres = (dados.horarios || []).filter((h) => h.vagas > 0).length;
                const motivo = formulario.erro || erros[0];
                dizer((motivo ? motivo + ' ' : '') + (livres
                    ? diaPorExtenso(estado.dia) + ': ' + livres + (livres === 1 ? ' horário livre.' : ' horários livres.') + ' Clicar num horário já revisa.'
                    : diaPorExtenso(estado.dia) + ': nenhum horário livre. Escolha outro dia.'));
            }

            function escolherDia(iso) {
                estado.dia = iso;
                if (iso.slice(0, 7) !== estado.mes) {
                    estado.mes = iso.slice(0, 7);
                    carregarMes();
                    return;
                }
                raiz.querySelectorAll('.ficha-dia').forEach((b) => b.setAttribute('aria-pressed', 'false'));
                carregarDia();
                const marcado = Array.from(raiz.querySelectorAll('.ficha-dia')).find((b) => b.textContent === String(deIso(iso).getDate()));
                if (marcado) marcado.setAttribute('aria-pressed', 'true');
            }

            function escolherHorario(h) {
                const comVaga = h.profissionais || [];
                if (estado.profissional) return confirmarEscolha(estado.profissional, h);
                if (comVaga.length === 1) return confirmarEscolha(String(comVaga[0].id), h);
                // "Qualquer": às 09:00, só os profissionais com vaga nesse horário.
                $('.ficha-quem-titulo').textContent = 'Quem atende às ' + h.hora + '?';
                $('.ficha-quem-lista').replaceChildren(...comVaga.map((p) => {
                    const botao = document.createElement('button');
                    botao.type = 'button';
                    botao.className = 'ficha-horario';
                    botao.textContent = nomeDe(p.id) + (estado.comVagas ? ' · ' + p.vagas + (p.vagas === 1 ? ' vaga' : ' vagas') : '');
                    botao.addEventListener('click', () => confirmarEscolha(String(p.id), h));
                    return botao;
                }));
                quem.hidden = false;
                const primeiro = $('.ficha-quem-lista button');
                if (primeiro) primeiro.focus();
            }

            // A escolha vai para os campos do formulário e o Revisar sai na hora (o servidor confere a vaga de novo).
            function confirmarEscolha(profissionalId, h) {
                if (entradaProfissional) entradaProfissional.value = profissionalId;
                if (entradaInicio) entradaInicio.value = h.inicio;
                dizer('Escolhido: ' + diaPorExtenso(estado.dia) + ', ' + h.hora + ', com ' + nomeDe(profissionalId) + '. Conferindo…');
                revisar();
            }

            busca.addEventListener('input', montarProfissionais);
            selProfissional.addEventListener('change', () => { estado.profissional = selProfissional.value; carregarMes(); });
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

            carregarMes();
        };
    })();
</script>
