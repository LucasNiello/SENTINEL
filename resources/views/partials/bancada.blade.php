{{-- Bancada compartilhada (B3): estilos, popup e o JS das tabelas e do card de confirmação (Revisar → Confirmar; segurar só nas destrutivas).
     Usada pelas telas Agente, Cadastros e Lixeira. Cada tela tem o próprio #historico e marca com data-foco
     o elemento que recebe o foco quando um popup de erro é fechado. --}}


<div id="status-anuncio" class="sr-only" aria-live="assertive"></div>

<div id="overlay-popup">
    <div id="popup" role="dialog" aria-modal="true" aria-label="Resultado da operação"></div>
</div>

<script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const historico = document.getElementById('historico');
        const statusAnuncio = document.getElementById('status-anuncio');
        const overlayPopup = document.getElementById('overlay-popup');
        const popup = document.getElementById('popup');

        // Decisão B (13/11): segurar 2 s é só gesto visual, nas ações destrutivas (o /agente/revisar manda "segurar").
        // As demais escritas confirmam com um clique. O servidor não tem mais janela de 2 a 12 s.
        const DURACAO_HOLD_MS = 2000;
        // Clique no Confirmar logo depois de ele aparecer (no lugar do Revisar) é ignorado: evita duplo clique virar gravação.
        const ESPERA_CLIQUE_MS = 400;
        // Prazo da proposta (rascunho e token dela), contado desde a proposta (PRAZO_RASCUNHO_S no servidor).
        const PRAZO_RASCUNHO_MS = 300000;
        const RISCOS = ['leitura', 'escrita', 'exclusao'];

        // Limpa antes de gravar: o mesmo texto repetido (ex.: dois Editar seguidos) não muda o DOM
        // e o leitor de tela não o relê.
        function anunciar(texto) {
            statusAnuncio.textContent = '';
            setTimeout(() => { statusAnuncio.textContent = texto; }, 50);
        }

        // Depois de fechar um popup de erro, o foco volta ao campo principal da tela (marcado com data-foco).
        function focarPagina() {
            const alvo = document.querySelector('[data-foco]');
            if (alvo) alvo.focus();
        }

        // A3/B3 (RF02): baixa a tabela em CSV. O corpo leva só o id da consulta; o servidor a refaz
        // com o RBAC, o tenant e a máscara de agora.
        async function exportarTabela(consulta, botao) {
            botao.disabled = true;
            try {
                const resp = await fetch('/agente/exportar', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ consulta }),
                });
                if (resp.status === 401 || resp.status === 419) {
                    window.location.href = '/login';
                    return;
                }
                if (!resp.ok) {
                    const dados = await resp.json().catch(() => ({}));
                    adicionarMensagem(dados.mensagem || 'Não consegui exportar esta tabela agora.', 'msg--erro');
                    return;
                }
                const nome = (resp.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/);
                const url = URL.createObjectURL(await resp.blob());
                const link = document.createElement('a');
                link.href = url;
                link.download = nome ? nome[1] : 'consulta.csv';
                document.body.appendChild(link);
                link.click();
                link.remove();
                URL.revokeObjectURL(url);
                anunciar('planilha baixada');
            } catch (e) {
                adicionarMensagem('Falha ao contatar o servidor.', 'msg--erro');
            } finally {
                botao.disabled = false;
            }
        }

        function adicionarMensagem(texto, classe) {
            const div = document.createElement('div');
            div.className = 'msg ' + classe;
            const speaker = document.createElement('span');
            speaker.className = 'msg__speaker';
            speaker.textContent = classe === 'msg--usuario' ? 'Você' : classe === 'msg--erro' ? 'Atenção' : 'Sentinel';
            const content = document.createElement('span');
            content.textContent = texto;
            div.append(speaker, content);
            historico.appendChild(div);
        }

        // Campo "aviso" do JSON (ex.: só o primeiro de vários pedidos foi atendido). Texto + cor, nunca só a cor.
        function adicionarAviso(texto) {
            adicionarMensagem('Aviso: ' + texto, 'msg--aviso');
        }


        // As colunas vêm do servidor (catálogo de tools); textContent evita XSS com dados gravados no banco.
        // "editar" (RF02): nome da tool de atualizar; cada linha ganha um botão que abre o formulário sem IA.
        // Linha com "acao" própria (Bloco 4, lixeira): {tool, rotulo} só daquela linha, já filtrada pelo papel no servidor.
        // "exportar": id da consulta guardada no servidor (A3); com ele, a tabela ganha o botão Exportar.
        function adicionarTabela(linhas, colunasServidor, editar, exportar) {
            if (!linhas.length) {
                adicionarMensagem('Nenhum registro encontrado.', 'msg--agente');
                return;
            }
            const colunas = colunasServidor && colunasServidor.length ? colunasServidor : ['id', 'descricao', 'valor', 'status', 'data'];
            // P-B5 (caixa de entrada): a linha com "pedido" ganha "Pedir ao agente", só na tela que tem o chat.
            const temPedido = typeof window.pedirAoAgente === 'function' && linhas.some((l) => typeof l.pedido === 'string');
            const temAcao = editar || temPedido || linhas.some((l) => l.acao && l.acao.tool);
            const table = document.createElement('table');
            const thead = document.createElement('thead');
            const trCabecalho = document.createElement('tr');
            colunas.forEach(c => {
                const th = document.createElement('th');
                th.textContent = c;
                th.scope = 'col';
                trCabecalho.appendChild(th);
            });
            if (temAcao) {
                const th = document.createElement('th');
                th.textContent = 'Ação';
                trCabecalho.appendChild(th);
            }
            thead.appendChild(trCabecalho);
            const tbody = document.createElement('tbody');
            linhas.forEach(l => {
                const tr = document.createElement('tr');
                // Linha que pede atenção (ex.: estoque negativo): fundo de aviso; o texto vem na coluna "alerta".
                if (l.destaque === 'aviso') tr.className = 'linha--aviso';
                colunas.forEach(c => {
                    const td = document.createElement('td');
                    td.textContent = l[c] ?? '';
                    tr.appendChild(td);
                });
                if (temAcao) {
                    const td = document.createElement('td');
                    const botao = document.createElement('button');
                    botao.type = 'button';
                    botao.className = 'btn-editar-linha';
                    if (l.acao && l.acao.tool) {
                        const acao = l.acao;
                        botao.textContent = acao.rotulo;
                        if (acao.tool.startsWith('excluir_definitivo_')) botao.classList.add('btn-danger');
                        botao.setAttribute('aria-label', acao.rotulo + ' o registro ' + l.id);
                        botao.addEventListener('click', () => abrirFormulario(acao.tool, l.id, botao));
                        td.appendChild(botao);
                    } else if (editar) {
                        botao.textContent = 'Editar';
                        botao.setAttribute('aria-label', 'Editar o registro ' + l.id);
                        botao.addEventListener('click', () => abrirFormulario(editar, l.id, botao));
                        td.appendChild(botao);
                    }
                    // O texto vira um pedido normal: o agente propõe e a escrita continua passando por Revisar e segurar.
                    if (temPedido && typeof l.pedido === 'string') {
                        const pedir = document.createElement('button');
                        pedir.type = 'button';
                        pedir.className = 'btn-editar-linha';
                        pedir.textContent = 'Pedir ao agente';
                        pedir.setAttribute('aria-label', 'Pedir ao agente o que a mensagem ' + l.id + ' pede');
                        pedir.addEventListener('click', () => window.pedirAoAgente(l.pedido));
                        td.appendChild(pedir);
                    }
                    tr.appendChild(td);
                }
                tbody.appendChild(tr);
            });
            table.appendChild(thead);
            table.appendChild(tbody);
            // Tabela larga rola dentro do próprio contêiner, nunca a página (375 px; skill seção 9).
            const rolagem = document.createElement('div');
            rolagem.className = 'tabela-rolagem';
            rolagem.tabIndex = 0;
            rolagem.setAttribute('role', 'region');
            rolagem.setAttribute('aria-label', 'Resultado da consulta');
            rolagem.appendChild(table);
            const resumo = document.createElement('div');
            resumo.className = 'tabela-resumo';
            resumo.textContent = linhas.length + ' registro(s) encontrado(s)';
            historico.appendChild(resumo);
            historico.appendChild(rolagem);
            if (exportar) {
                const botaoExportar = document.createElement('button');
                botaoExportar.type = 'button';
                botaoExportar.className = 'btn-exportar';
                botaoExportar.textContent = 'Exportar CSV';
                botaoExportar.addEventListener('click', () => exportarTabela(exportar, botaoExportar));
                historico.appendChild(botaoExportar);
            }
        }

        let timerPopup = null;

        function fecharPopup() {
            clearTimeout(timerPopup);
            overlayPopup.style.display = 'none';
            popup.replaceChildren();
        }

        // textContent: a mensagem pode vir do servidor, nunca vira HTML.
        // Erro é mensagem crítica: fica até o usuário fechar (botão ou Esc). Os demais somem sozinhos.
        function mostrarPopup(estado, texto) {
            clearTimeout(timerPopup);
            popup.className = estado;
            popup.setAttribute('role', estado === 'erro' ? 'alertdialog' : 'status');
            if (estado === 'erro') popup.setAttribute('aria-modal', 'true');
            else popup.removeAttribute('aria-modal');
            popup.textContent = texto;
            overlayPopup.style.display = 'flex';
            if (estado === 'erro') {
                const fechar = document.createElement('button');
                fechar.type = 'button';
                fechar.className = 'popup-fechar';
                fechar.textContent = 'Fechar';
                fechar.addEventListener('click', () => {
                    fecharPopup();
                    focarPagina();
                });
                popup.appendChild(fechar);
                fechar.focus();
            } else {
                timerPopup = setTimeout(fecharPopup, 2500);
            }
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Tab' && popup.classList.contains('erro') && overlayPopup.style.display === 'flex') {
                e.preventDefault();
                popup.querySelector('.popup-fechar')?.focus();
            }
            if (e.key === 'Escape' && popup.classList.contains('erro') && overlayPopup.style.display === 'flex') {
                fecharPopup();
                focarPagina();
            }
        });

        // Cancelar e fim de prazo: o servidor tira a proposta da sessão e audita o desfecho. Se foi
        // cancelado ou expirado, quem decide é ele, pelo horário da proposta. Sem rede, a proposta
        // vence na sessão e a varredura do servidor a audita como expirada na requisição seguinte.
        // corpo: { token } na etapa de segurar, { proposta } ainda no formulário.
        function encerrarSemGravar(corpo) {
            fetch('/agente/cancelar', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify(corpo),
                keepalive: true,
            }).catch(() => {});
        }

        // RF02 / Bloco 3: abre o formulário de atualizar sem chamar a IA. O card segue o fluxo de sempre
        // (Revisar → segurar); o servidor aplica RBAC e tenant e acha o registro pelo id.
        async function abrirFormulario(tool, id, botao) {
            if (botao) botao.disabled = true;
            try {
                const resp = await fetch('/agente/formulario', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ tool, id }),
                });
                if (resp.status === 401 || resp.status === 419) {
                    window.location.href = '/login';
                    return;
                }
                const dados = await resp.json().catch(() => ({}));
                if (resp.ok && dados.tipo === 'confirmacao_pendente') {
                    renderizarConfirmacaoPendente(dados);
                } else {
                    adicionarMensagem(dados.mensagem || 'Não consegui abrir esse formulário.', 'msg--erro');
                }
            } catch (e) {
                adicionarMensagem('Falha ao contatar o servidor.', 'msg--erro');
            } finally {
                if (botao) botao.disabled = false;
            }
        }

        function popupConfirmado() {
            mostrarPopup('sucesso', '✔ Confirmado');
            anunciar('confirmado');
        }
        function popupCancelado() {
            mostrarPopup('neutro', '✕ Cancelado. Nada foi gravado.');
            anunciar('cancelado, nada foi gravado');
        }
        function popupErro(mensagem) {
            mostrarPopup('erro', '✕ ' + (mensagem || 'Erro ao confirmar — tente novamente'));
            anunciar(mensagem || 'erro ao confirmar');
        }

        let contadorCards = 0;

        function paragrafo(classe, id, texto) {
            const p = document.createElement('p');
            p.className = classe;
            p.id = id;
            p.textContent = texto;
            return p;
        }

        // RF04: um campo do formulário, descrito pelo servidor a partir das regras da tool.
        // Tudo por createElement/textContent: o servidor nunca manda HTML.
        function montarCampo(campo, prefixo) {
            const id = prefixo + '-' + campo.nome;
            const bloco = document.createElement('div');
            bloco.className = 'campo';
            bloco.dataset.campo = campo.nome;

            const rotulo = document.createElement('label');
            rotulo.htmlFor = id;
            rotulo.textContent = campo.rotulo;
            const marca = document.createElement('span');
            marca.className = 'campo-obrigatorio';
            marca.textContent = ' (obrigatório)';
            marca.hidden = !campo.obrigatorio;
            rotulo.appendChild(marca);
            bloco.appendChild(rotulo);

            let entrada;
            if (campo.tipo === 'opcoes' || campo.tipo === 'referencia') {
                entrada = document.createElement('select');
                const nenhuma = document.createElement('option');
                nenhuma.value = '';
                nenhuma.textContent = '— escolha —';
                entrada.appendChild(nenhuma);
                (campo.opcoes || []).forEach((opcao) => {
                    const item = document.createElement('option');
                    item.value = String(opcao.valor);
                    item.textContent = opcao.rotulo;
                    entrada.appendChild(item);
                });
            } else {
                entrada = document.createElement('input');
                entrada.type = { email: 'email', data: 'date' }[campo.tipo] || 'text';
                if (campo.tipo === 'numero') {
                    // Texto com teclado numérico, não type=number: lá "4.567,89" vira vazio calado e, num
                    // campo sensível, vazio = manter. Como texto, chega ao servidor e volta com a frase do erro.
                    entrada.inputMode = campo.inteiro ? 'numeric' : 'decimal';
                } else if (campo.max !== undefined) {
                    entrada.maxLength = campo.max;
                }
            }
            entrada.id = id;
            entrada.name = campo.nome;
            // Campo sensível (CPF, salário, documento): o servidor só manda a máscara. O campo fica vazio, e vazio = manter.
            if (!campo.sensivel && campo.valor !== null && campo.valor !== undefined) entrada.value = String(campo.valor);
            bloco.appendChild(entrada);

            const descricoes = [];
            if (campo.sensivel) {
                // Com erro, manter repetiria o mesmo erro: aí só resta digitar de novo.
                const texto = campo.erro ? 'Digite o valor de novo.'
                    : !campo.informado ? 'Não informado.'
                    : campo.valor ? 'Informado: ' + campo.valor + '. Deixe em branco para manter.'
                    : 'Informado (oculto por segurança). Deixe em branco para manter.';
                bloco.appendChild(paragrafo('campo-info', id + '-info', texto));
                descricoes.push(id + '-info');
            }
            if (campo.tipo === 'numero' && !campo.inteiro) {
                bloco.appendChild(paragrafo('campo-info', id + '-dica', 'Só números, com ponto nos centavos (ex.: 4567.89).'));
                descricoes.push(id + '-dica');
            }
            if (campo.faltando && !campo.erro) {
                bloco.classList.add('campo--faltando');
                bloco.appendChild(paragrafo('campo-falta', id + '-falta', 'Falta preencher.'));
                descricoes.push(id + '-falta');
            }
            if (campo.erro) {
                bloco.classList.add('campo--erro');
                entrada.setAttribute('aria-invalid', 'true');
                bloco.appendChild(paragrafo('campo-erro', id + '-erro', campo.erro));
                descricoes.push(id + '-erro');
            }
            if (descricoes.length) entrada.setAttribute('aria-describedby', descricoes.join(' '));

            return bloco;
        }

        // Campos que dependem de outro (ex.: cliente só em nota de saída): some quando é proibido, marca quando é obrigatório.
        function aplicarCondicoes(form, campos) {
            campos.filter((campo) => campo.condicoes).forEach((campo) => {
                let proibido = false;
                let obrigatorio = campo.obrigatorio;
                campo.condicoes.forEach((condicao) => {
                    const controle = form.elements.namedItem(condicao.campo);
                    if (!controle || controle.value !== condicao.valor) return;
                    if (condicao.efeito === 'proibido') proibido = true;
                    if (condicao.efeito === 'obrigatorio') obrigatorio = true;
                });
                const entrada = form.elements.namedItem(campo.nome);
                entrada.disabled = proibido;
                entrada.closest('.campo').hidden = proibido;
                entrada.closest('.campo').querySelector('.campo-obrigatorio').hidden = !obrigatorio;
            });
        }

        function coletarValores(form, campos) {
            const valores = {};
            campos.forEach((campo) => {
                const entrada = form.elements.namedItem(campo.nome);
                if (!entrada) return;
                // Escondido pela situação: vai vazio, para não valer o que a IA tinha posto ali.
                if (entrada.disabled) {
                    valores[campo.nome] = '';
                    return;
                }
                if (campo.sensivel && entrada.value === '') return; // vazio = manter o valor informado
                valores[campo.nome] = entrada.value;
            });
            return valores;
        }

        // RF04 + Bloco 2b: o card de confirmação É o formulário, em duas etapas.
        // 1) Rascunho: o humano edita e clica Revisar; o servidor valida sem gravar. Prazo de 5 min desde a proposta.
        // 2) Revisada: campos travados e sai o token de confirmação (vale até o fim dos 5 min da proposta). Confirmar é
        //    um clique; nas ações destrutivas ("segurar" na resposta do Revisar), é segurar 2 s.
        //    "Editar" volta à etapa 1 e o token morre; o prazo de 5 min não reinicia.
        function renderizarConfirmacaoPendente(dados) {
            let formulario = dados.formulario || { titulo: 'Confirmar a ação', campos: [] };
            const proposta = dados.proposta;
            const risco = dados.risco;
            const prefixo = 'card-' + (++contadorCards);
            let token = null;

            const card = document.createElement('section');
            // Risco desconhecido cai no mais severo (exclusao), nunca no mais brando.
            card.className = 'card-confirmacao card--risco-' + (RISCOS.includes(risco) ? risco : 'exclusao');
            card.setAttribute('aria-label', 'Confirmação necessária: ' + formulario.titulo);
            card.innerHTML = `
                <div class="card-overline"><span>AÇÃO PROPOSTA</span><span class="card-risco"></span></div>
                <strong class="card-titulo">Confirmação necessária: <span class="card-acao"></span></strong>
                <div class="msg msg--erro card-resumo-erro" role="alert" hidden>Confira os campos destacados. Nada foi gravado.</div>
                <div class="msg msg--aviso card-avisos" role="status" hidden></div>
                <dl class="card-registro" hidden></dl>
                <div class="tabela-rolagem card-lote" hidden></div>
                <form class="card-form" novalidate>
                    <fieldset class="card-campos"></fieldset>
                    <fieldset class="card-autorizacao" hidden>
                        <legend class="autorizacao-titulo">Autorização de um administrador</legend>
                        <p class="campo-info autorizacao-info">Um administrador digita o e-mail e a senha dele aqui. A senha só vai junto com a confirmação e não fica guardada.</p>
                        <div class="campo">
                            <label class="rotulo-admin-email">E-mail do administrador</label>
                            <input type="email" class="admin-email" autocomplete="off" maxlength="255">
                        </div>
                        <div class="campo">
                            <label class="rotulo-admin-senha">Senha do administrador</label>
                            <input type="password" class="admin-senha" autocomplete="new-password" maxlength="255">
                        </div>
                        <p class="campo-erro admin-erro" role="alert" hidden></p>
                    </fieldset>
                </form>
                <p class="card-etapa" role="status"></p>
                <div class="acoes">
                    <button type="button" class="btn-revisar">Revisar</button>
                    <button type="button" class="btn-confirmar" hidden>
                        <span class="btn-confirmar__fill"></span>
                        <span class="btn-confirmar__label">Confirmar</span>
                    </button>
                    <button type="button" class="btn-editar" hidden>Editar</button>
                    <button type="button" class="btn-cancelar">Cancelar</button>
                </div>
            `;
            card.querySelector('.card-acao').textContent = formulario.titulo;

            card.querySelector('.card-risco').textContent = ({ leitura: 'Leitura', escrita: 'Escrita', exclusao: 'Exclusão' })[risco] || 'Exclusão';
            const form = card.querySelector('.card-form');
            const campos = card.querySelector('.card-campos');
            const registro = card.querySelector('.card-registro');
            const lote = card.querySelector('.card-lote');
            // Bloco 4: e-mail e senha do admin ficam FORA dos campos do formulário — não vão no Revisar,
            // só no corpo do /agente/confirmar (e a senha é apagada daqui assim que sai).
            const autorizacao = card.querySelector('.card-autorizacao');
            const adminEmail = card.querySelector('.admin-email');
            const adminSenha = card.querySelector('.admin-senha');
            const adminErro = card.querySelector('.admin-erro');
            adminEmail.id = prefixo + '-admin-email';
            adminSenha.id = prefixo + '-admin-senha';
            adminErro.id = prefixo + '-admin-erro';
            card.querySelector('.rotulo-admin-email').htmlFor = adminEmail.id;
            card.querySelector('.rotulo-admin-senha').htmlFor = adminSenha.id;
            autorizacao.hidden = !formulario.reautenticar;
            // Item H (estorno): quem autoriza é o supervisor do setor, com o PIN, ou o dono, com a senha de login.
            // A chave vem do servidor (formulario.tipo_autorizacao); sem ela, o texto é o da lixeira (administrador).
            const textosAutorizacao = formulario.tipo_autorizacao === 'estorno' ? {
                titulo: 'Autorização do supervisor do setor ou do dono',
                info: 'Supervisor do setor ou dono: e-mail e PIN (o dono usa a senha). O PIN só vai junto com a confirmação e não fica guardado.',
                email: 'E-mail de quem autoriza',
                segredo: 'PIN do supervisor (o dono usa a senha)',
                etapa: 'Confira os dados, chame o supervisor do setor (PIN) ou o dono (senha) para digitar aqui e clique em Revisar.',
                apagado: 'O PIN ou a senha de quem autoriza foi apagado. Digite o e-mail e o PIN (ou a senha do dono) de novo antes de Revisar.',
                falta: 'Informe o e-mail e o PIN do supervisor do setor (ou o e-mail e a senha do dono).',
            } : {
                titulo: 'Autorização de um administrador',
                info: 'Um administrador digita o e-mail e a senha dele aqui. A senha só vai junto com a confirmação e não fica guardada.',
                email: 'E-mail do administrador',
                segredo: 'Senha do administrador',
                etapa: 'Confira os dados, chame um administrador para digitar o e-mail e a senha dele aqui e clique em Revisar.',
                apagado: 'A senha do administrador foi apagada. Digite o e-mail e a senha de novo antes de Revisar.',
                falta: 'Informe o e-mail e a senha de um administrador.',
            };
            card.querySelector('.autorizacao-titulo').textContent = textosAutorizacao.titulo;
            card.querySelector('.autorizacao-info').textContent = textosAutorizacao.info;
            card.querySelector('.rotulo-admin-email').textContent = textosAutorizacao.email;
            card.querySelector('.rotulo-admin-senha').textContent = textosAutorizacao.segredo;
            const resumoErro = card.querySelector('.card-resumo-erro');
            const avisos = card.querySelector('.card-avisos');
            const etapa = card.querySelector('.card-etapa');
            const btnRevisar = card.querySelector('.btn-revisar');
            const btnConfirmar = card.querySelector('.btn-confirmar');
            const btnEditar = card.querySelector('.btn-editar');
            const btnCancelar = card.querySelector('.btn-cancelar');
            const fill = card.querySelector('.btn-confirmar__fill');
            const label = card.querySelector('.btn-confirmar__label');

            form.addEventListener('submit', (e) => e.preventDefault());
            form.addEventListener('change', () => aplicarCondicoes(form, formulario.campos));

            // Campos montados a partir do servidor; com erro de revisão, voltam com a frase de cada campo.
            function preencherFormulario(novo) {
                formulario = novo;
                campos.replaceChildren(...formulario.campos.map((campo) => montarCampo(campo, prefixo)));
                aplicarCondicoes(form, formulario.campos);
                // Lixeira (Bloco 4): o registro que vai sair ou voltar, só para conferir.
                const linhasRegistro = [];
                (formulario.resumo || []).forEach((item) => {
                    const dt = document.createElement('dt');
                    dt.textContent = item.rotulo;
                    const dd = document.createElement('dd');
                    dd.textContent = item.valor === null || item.valor === undefined || item.valor === '' ? '—' : String(item.valor);
                    linhasRegistro.push(dt, dd);
                });
                registro.replaceChildren(...linhasRegistro);
                registro.hidden = !linhasRegistro.length;
                // Aviso que não bloqueia (ex.: "Estoque de Dipirona ficará em -2"): texto na cor de aviso, antes do segurar.
                const frasesAviso = (formulario.avisos || []).map((frase) => {
                    const p = document.createElement('p');
                    p.textContent = 'Aviso: ' + frase;
                    return p;
                });
                avisos.replaceChildren(...frasesAviso);
                avisos.hidden = !frasesAviso.length;
                montarLote(formulario.itens || []);
                // Recusa que não é de um campo (ex.: "existem lançamentos vinculados"): vai no topo, com a frase do servidor.
                resumoErro.textContent = formulario.erro ? formulario.erro + ' Nada foi gravado.' : 'Confira os campos destacados. Nada foi gravado.';
                resumoErro.hidden = !formulario.erro && !formulario.campos.some((campo) => campo.erro);
                const primeiroComErro = form.querySelector('[aria-invalid="true"]');
                if (primeiroComErro) primeiroComErro.focus();
            }
            // RF03: a lista do lote vem pronta do servidor (do banco, pelos ids da sessão); aqui só vira tabela, via textContent.
            function montarLote(itens) {
                lote.hidden = !itens.length;
                if (!itens.length) {
                    lote.replaceChildren();
                    return;
                }
                const table = document.createElement('table');
                const cabecalho = document.createElement('tr');
                ['Nº', 'Descrição', 'Data', 'Valor', 'Status'].forEach((titulo) => {
                    const th = document.createElement('th');
                    th.scope = 'col';
                    th.textContent = titulo;
                    cabecalho.appendChild(th);
                });
                const thead = document.createElement('thead');
                thead.appendChild(cabecalho);
                const tbody = document.createElement('tbody');
                itens.forEach((item) => {
                    const tr = document.createElement('tr');
                    if (item.erro) tr.className = 'item-erro';
                    [['#' + item.id, true], [item.descricao, false], [item.data, true], [item.valor, true]].forEach(([texto, num]) => {
                        const td = document.createElement('td');
                        if (num) td.className = 'num';
                        td.textContent = texto ?? '—';
                        tr.appendChild(td);
                    });
                    const status = document.createElement('td');
                    status.textContent = item.antes + ' → ' + item.depois;
                    if (item.erro) {
                        const motivo = document.createElement('span');
                        motivo.className = 'item-erro-texto';
                        motivo.textContent = '✕ ' + item.erro;
                        status.appendChild(motivo);
                    }
                    tr.appendChild(status);
                    tbody.appendChild(tr);
                });
                table.append(thead, tbody);
                lote.replaceChildren(table);
            }

            // O prazo do card começa no Revisar: as credenciais são digitadas antes dele.
            // Função (não const): o formulario é trocado em preencherFormulario() e o Editar a chama depois.
            function textoPrimeiraEtapa() {
                return formulario.reautenticar
                    ? textosAutorizacao.etapa
                    : 'Confira os dados e clique em Revisar.';
            }

            preencherFormulario(formulario);
            etapa.textContent = textoPrimeiraEtapa();
            historico.appendChild(card);
            // O alvo precisa estar no DOM para receber foco. Assim quem abre
            // Novo/Editar por teclado entra no formulário, sem voltar à tabela.
            const primeiroCampo = form.querySelector('input:not([type="hidden"]), select');
            (primeiroCampo || btnRevisar).focus();

            const pequeno = document.createElement('span');
            pequeno.className = 'pequeno';
            pequeno.textContent = 'confirmando';
            const grande = document.createElement('span');
            grande.className = 'grande';

            // Soltou antes do fim: nada vai ao servidor e o card continua valendo (dá para segurar de novo).
            const avisoSoltou = document.createElement('div');
            avisoSoltou.className = 'msg msg--aviso card-expirado-texto';
            avisoSoltou.setAttribute('role', 'status');
            avisoSoltou.textContent = 'Você soltou antes do fim. Nada foi gravado.';
            avisoSoltou.hidden = true;
            card.appendChild(avisoSoltou);

            let segurando = false;
            let inicioHold = 0;
            let rafId = null;
            // Vem do /agente/revisar: true só nas ações destrutivas. Antes da resposta, o padrão seguro é segurar.
            let precisaSegurar = true;
            let entrouNaEtapa2Em = 0;

            // Prazo da proposta: conta desde a proposta e não reinicia no "Editar" (vale também depois do Revisar).
            const rascunhoTimer = setTimeout(expirarRascunho, PRAZO_RASCUNHO_MS);

            function encerrarCard() {
                clearTimeout(rascunhoTimer);
                if (rafId) cancelAnimationFrame(rafId);
                adminSenha.value = '';
                card.remove();
            }

            function desativarTudo(texto) {
                clearTimeout(rascunhoTimer);
                avisoSoltou.hidden = true;
                segurando = false;
                if (rafId) cancelAnimationFrame(rafId);
                fill.style.width = '0%';
                label.textContent = 'Confirmar';
                btnConfirmar.classList.remove('segurando');
                campos.disabled = true;
                autorizacao.disabled = true;
                autorizacao.hidden = true;
                adminSenha.value = '';
                [btnRevisar, btnConfirmar, btnEditar, btnCancelar].forEach((botao) => { botao.disabled = true; });
                card.classList.add('card--expirado');
                etapa.textContent = '';
                const aviso = document.createElement('div');
                aviso.className = 'msg msg--aviso card-expirado-texto';
                aviso.textContent = texto;
                card.appendChild(aviso);
            }

            // Proposta venceu (5 min, antes ou depois do Revisar): o card não some calado — fica visível, marcado, com os
            // botões desativados. O aviso ao servidor sai logo depois, quando ele já conta como expirado.
            function expirarRascunho() {
                const corpo = token ? { token } : { proposta };
                desativarTudo(token ? 'Confirmação expirada. Nada foi gravado. Peça de novo.' : 'O prazo para revisar terminou. Nada foi gravado. Peça de novo.');
                setTimeout(() => encerrarSemGravar(corpo), 500);
                anunciar(token ? 'confirmação expirada, nada foi gravado' : 'prazo para revisar terminou, nada foi gravado');
            }

            // Etapa 2: campos travados e Confirmar liberado (clique, ou segurar 2 s nas ações destrutivas).
            function entrarNoSegurar(novoToken, segurar) {
                token = novoToken;
                precisaSegurar = segurar !== false;
                entrouNaEtapa2Em = performance.now();
                campos.disabled = true;
                autorizacao.disabled = true;
                resumoErro.hidden = true;
                btnRevisar.hidden = true;
                btnConfirmar.hidden = false;
                btnConfirmar.disabled = false;
                // Sem campo nem senha para corrigir, "Editar" não teria o que editar.
                btnEditar.hidden = !formulario.campos.length && !formulario.reautenticar;
                etapa.textContent = precisaSegurar
                    ? 'Dados conferidos. Segure Confirmar por 2 segundos. Se soltar antes, nada é gravado.'
                    : 'Dados conferidos. Clique em Confirmar para gravar.';
                btnConfirmar.focus();
                anunciar(precisaSegurar ? 'dados conferidos, segure confirmar' : 'dados conferidos, clique em confirmar');
            }

            // "Editar": o token morre no servidor (sem auditoria) e o card volta à etapa 1. O Revisar só volta
            // depois da resposta, para um Revisar rápido não disputar a sessão com o Editar.
            async function voltarAoFormulario() {
                segurando = false;
                if (rafId) cancelAnimationFrame(rafId);
                const tokenAntigo = token;
                token = null;
                precisaSegurar = true;
                avisoSoltou.hidden = true;
                fill.style.width = '0%';
                label.textContent = 'Confirmar';
                btnConfirmar.classList.remove('segurando');
                btnConfirmar.hidden = true;
                btnEditar.hidden = true;
                btnRevisar.hidden = false;
                btnRevisar.disabled = true;
                campos.disabled = false;
                autorizacao.disabled = false;
                adminEmail.value = '';
                adminSenha.value = '';
                aplicarCondicoes(form, formulario.campos);
                etapa.textContent = textoPrimeiraEtapa();
                // A senha foi apagada: quem usa leitor de tela precisa saber que ela é pedida de novo.
                if (formulario.reautenticar) anunciar(textosAutorizacao.apagado);
                const primeiro = form.querySelector('input, select');
                if (primeiro) primeiro.focus();
                const resp = await fetch('/agente/editar', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ token: tokenAntigo }),
                }).catch(() => null);
                if (!card.isConnected || card.classList.contains('card--expirado')) return;
                // A proposta acabou no servidor (ex.: cancelada em outra aba ou vencida): o card não volta a valer.
                if (resp && !resp.ok) {
                    const dados = await resp.json().catch(() => ({}));
                    encerrarCard();
                    popupErro(dados.mensagem);
                    return;
                }
                btnRevisar.disabled = false;
            }

            async function revisar() {
                // A senha só é conferida na confirmação; aqui o card só não deixa seguir com ela em branco.
                adminErro.hidden = true;
                adminSenha.removeAttribute('aria-invalid');
                if (formulario.reautenticar && (adminEmail.value.trim() === '' || adminSenha.value === '')) {
                    adminErro.textContent = textosAutorizacao.falta;
                    adminErro.hidden = false;
                    const vazio = adminEmail.value.trim() === '' ? adminEmail : adminSenha;
                    vazio.setAttribute('aria-invalid', 'true');
                    vazio.setAttribute('aria-describedby', adminErro.id);
                    vazio.focus();
                    return;
                }
                btnRevisar.disabled = true;
                try {
                    const resp = await fetch('/agente/revisar', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                        body: JSON.stringify({ proposta, valores: coletarValores(form, formulario.campos) }),
                    });
                    if (resp.status === 401 || resp.status === 419) {
                        window.location.href = '/login';
                        return;
                    }
                    const dados = await resp.json().catch(() => ({}));
                    // Cancelado ou vencido enquanto a revisão ia e voltava: não mexe mais neste card.
                    if (!card.isConnected || card.classList.contains('card--expirado')) return;
                    if (resp.ok && dados.token) {
                        entrarNoSegurar(dados.token, dados.segurar);
                        return;
                    }
                    if (dados.formulario) {
                        // Erro de validação: o formulário volta com a frase de cada campo; a proposta segue em rascunho.
                        preencherFormulario(dados.formulario);
                        // RF03: item do lote que mudou não volta a valer; revisar de novo só falharia. Fica o Cancelar.
                        if ((dados.formulario.itens || []).some((item) => item.erro)) {
                            btnRevisar.hidden = true;
                            etapa.textContent = 'Peça de novo para montar outro plano, ou clique em Cancelar.';
                            anunciar('o lote mudou, nada foi gravado');
                            return;
                        }
                        btnRevisar.disabled = false;
                        anunciar('confira os campos destacados, nada foi gravado');
                        return;
                    }
                    // Falha passageira (limite de pedidos, erro do servidor): a proposta segue; dá para revisar de novo.
                    if (resp.status === 429 || resp.status >= 500) {
                        btnRevisar.disabled = false;
                        popupErro(dados.mensagem);
                        return;
                    }
                    // Proposta negada, vencida ou indisponível: o servidor já deu (ou dará) o desfecho; o card sai.
                    encerrarCard();
                    popupErro(dados.mensagem);
                } catch (e) {
                    if (card.isConnected && !card.classList.contains('card--expirado')) btnRevisar.disabled = false;
                    popupErro('Não foi possível revisar agora. Tente de novo.');
                }
            }

            function iniciarHold() {
                // Ação não destrutiva: o Confirmar é um clique (tratado no evento click), sem segurar.
                if (!precisaSegurar || segurando || btnConfirmar.disabled || btnConfirmar.hidden) return;
                avisoSoltou.hidden = true;
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
                avisoSoltou.hidden = false;
                anunciar('você soltou antes do fim, nada foi gravado');
            }

            async function confirmarAcao() {
                // A confirmação saiu: o prazo de 5 min não pode mais marcar o card como expirado enquanto ela vai e volta.
                clearTimeout(rascunhoTimer);
                // Confirmação a caminho: Cancelar ou Editar agora disputariam o mesmo token com ela.
                btnConfirmar.disabled = true;
                btnEditar.disabled = true;
                btnCancelar.disabled = true;
                try {
                    // Só o token: os valores confirmados são os revisados, guardados no servidor. Restaurar e
                    // excluir de vez levam também o e-mail e a senha do admin, e a senha sai do card na hora.
                    const corpo = formulario.reautenticar
                        ? JSON.stringify({ token, email_admin: adminEmail.value, senha_admin: adminSenha.value })
                        : JSON.stringify({ token });
                    adminSenha.value = '';
                    const resp = await fetch('/agente/confirmar', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                        body: corpo,
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
                        // Frase do servidor: o que gravou e o que ficou vazio.
                        if (dados.mensagem) adicionarMensagem(dados.mensagem, 'msg--agente');
                        popupConfirmado();
                        // Avisa a tela (ex.: a Lixeira recarrega a lista depois de restaurar ou excluir de vez).
                        document.dispatchEvent(new CustomEvent('sentinel:gravado', { detail: { tool: dados.tool || null, mensagem: dados.mensagem || '' } }));
                    }
                } catch (e) {
                    encerrarCard();
                    popupErro();
                }
            }

            btnRevisar.addEventListener('click', revisar);
            btnEditar.addEventListener('click', voltarAoFormulario);

            btnConfirmar.addEventListener('mousedown', iniciarHold);
            btnConfirmar.addEventListener('touchstart', iniciarHold);
            btnConfirmar.addEventListener('mouseup', soltarHold);
            btnConfirmar.addEventListener('blur', soltarHold);
            btnConfirmar.addEventListener('mouseleave', soltarHold);
            btnConfirmar.addEventListener('touchend', soltarHold);
            btnConfirmar.addEventListener('touchcancel', soltarHold);
            // Clique simples (mouse, toque, Enter ou Espaço) confirma as ações não destrutivas. O Confirmar aparece no
            // lugar do Revisar: o 2º clique de um duplo clique (detail > 1) ou um clique logo depois de entrar na etapa 2
            // não confirma, para ninguém gravar sem ver "Dados conferidos".
            btnConfirmar.addEventListener('click', (e) => {
                if (precisaSegurar || btnConfirmar.disabled || btnConfirmar.hidden) return;
                if (e.detail > 1 || performance.now() - entrouNaEtapa2Em < ESPERA_CLIQUE_MS) return;
                confirmarAcao();
            });
            btnConfirmar.addEventListener('keydown', (e) => {
                // Sem segurar, Enter/Espaço seguem o comportamento normal do botão (disparam o click), mas a repetição
                // automática de uma tecla segurada desde o Revisar não confirma.
                if (!precisaSegurar) {
                    if (e.repeat) e.preventDefault();
                    return;
                }
                if ((e.key === 'Enter' || e.key === ' ') && !e.repeat) {
                    e.preventDefault();
                    iniciarHold();
                }
            });
            btnConfirmar.addEventListener('keyup', (e) => {
                if (e.key === 'Enter' || e.key === ' ') soltarHold();
            });

            btnCancelar.addEventListener('click', () => {
                encerrarSemGravar(token ? { token } : { proposta });
                encerrarCard();
                popupCancelado();
            });
        }
</script>
