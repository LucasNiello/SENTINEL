{{-- Bancada compartilhada (B3): estilos, popup e o JS das tabelas e do card de confirmação (Revisar → segurar).
     Usada pelas telas Agente, Cadastros e Lixeira. Cada tela tem o próprio #historico e marca com data-foco
     o elemento que recebe o foco quando um popup de erro é fechado. --}}
@push('estilos')
    <style>
        /* Tela do agente. Tokens, foco, botões e .sr-only vêm da casca (layouts/app.blade.php). */
        #historico {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .msg { padding: 0.6rem 0.8rem; border-radius: 6px; overflow-wrap: anywhere; }
        .tabela-rolagem { max-width: 100%; overflow-x: auto; }
        .btn-editar-linha { min-height: 44px; padding: 0.3rem 0.8rem; }
        .btn-exportar { align-self: flex-start; min-height: 44px; padding: 0.4rem 1rem; }
        .msg--usuario { background: var(--color-borda); align-self: flex-end; }
        .msg--agente { border: 1px solid var(--color-borda); }
        .msg--erro { background: var(--color-erro-faixa); color: var(--color-erro); }
        .msg--aviso { background: var(--color-aviso-faixa); color: var(--color-aviso); }

        table { border-collapse: collapse; width: 100%; font-size: 15px; }
        th, td { border: 1px solid var(--color-borda); padding: 0.4rem 0.6rem; text-align: left; }
        th { background: var(--color-borda); }

        form#form-comando { display: flex; gap: 0.5rem; }
        #campo-mensagem { flex: 1; min-width: 0; min-height: 44px; padding: 0.6rem; font-size: 1rem; background: var(--color-creme); color: var(--color-tinta); }
        #campo-mensagem { border: 1px solid var(--color-borda-campo); border-radius: 6px; }
        #botao-enviar { min-height: 44px; padding: 0.5rem 1rem; }

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
        /* RF04: o card é o formulário (ação + dados), nunca nome de tool nem JSON. */
        .card-titulo { display: block; margin-bottom: 0.75rem; }
        .card-form { display: grid; gap: 0.75rem; }
        .campo { display: flex; flex-direction: column; gap: 0.25rem; }
        .campo[hidden] { display: none; }
        .campo label { font-weight: 500; }
        .campo-obrigatorio { font-weight: 400; color: var(--color-tinta-2); }
        .campo input, .campo select {
            width: 100%;
            min-height: 44px;
            padding: 0.5rem;
            font: inherit;
            background: var(--color-creme);
            color: var(--color-tinta);
            border: 1px solid var(--color-borda-campo);
            border-radius: 6px;
        }
        .campo-info, .campo-falta, .campo-erro { margin: 0; font-size: 15px; }
        .campo-info { color: var(--color-tinta-2); }
        .campo-falta { color: var(--color-aviso); }
        .campo-erro { color: var(--color-erro); }
        .campo--faltando input, .campo--faltando select { border: 2px solid var(--color-aviso); }
        .campo--erro input, .campo--erro select { border: 2px solid var(--color-erro); }
        .card-confirmacao .acoes {
            display: flex;
            gap: 0.75rem;
            margin-top: 0.75rem;
        }
        .card-expirado-texto { margin-top: 0.75rem; }
        .card-campos { border: 0; margin: 0; padding: 0; min-width: 0; display: grid; gap: 0.75rem; }
        /* Bloco 4: o registro que a ação vai mover (só leitura) e a autorização do admin, separada do formulário. */
        .card-registro { display: grid; grid-template-columns: max-content 1fr; gap: 0.25rem 0.75rem; margin: 0; }
        .card-registro dt { font-weight: 500; }
        .card-registro dd { margin: 0; overflow-wrap: anywhere; }
        /* RF03: plano do lote, item a item (só leitura). Números em mono; item recusado com a frase, não só a cor. */
        .card-lote td.num { font-family: var(--fonte-mono); font-variant-numeric: tabular-nums; white-space: nowrap; }
        .card-lote tr.item-erro td { background: var(--color-erro-faixa); }
        .card-lote .item-erro-texto { display: block; color: var(--color-erro); font-weight: 500; }
        .card-autorizacao { border: 1px solid var(--color-borda-campo); border-radius: 6px; margin: 0; padding: 0.75rem; min-width: 0; display: grid; gap: 0.75rem; }
        .card-autorizacao legend { font-weight: 600; padding: 0 0.25rem; }
        .card-etapa { margin: 0.75rem 0 0; color: var(--color-tinta-2); }
        .acoes { flex-wrap: wrap; }

        /* Revisar, Confirmar (segurar), Editar e Cancelar: mesmo formato e peso; Editar e Cancelar são neutros (seção 6). */
        .btn-revisar,
        .btn-editar,
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

        .btn-editar,
        .btn-cancelar {
            border-color: var(--color-tinta-2);
            color: var(--color-tinta);
        }
        /* Revisar só valida (não grava): destaque da bancada, não a cor do risco. */
        .btn-revisar {
            border-color: var(--color-aco);
            color: var(--color-aco);
        }

        /* Popup de resultado. Sem fade (bancada não anima); o de erro fica até o usuário fechar (seção 7). */
        #overlay-popup {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: color-mix(in srgb, var(--color-tinta) 15%, transparent);
        }
        #popup {
            background: var(--color-creme);
            border-radius: 10px;
            padding: 1.5rem 2rem;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.75rem;
            max-width: 460px;
            font-size: 1.05rem;
            font-weight: 600;
        }
        #popup .popup-fechar { min-height: 44px; padding: 0.5rem 1rem; font-weight: 400; }
        #popup.sucesso { color: var(--color-sucesso); border: 2px solid var(--color-sucesso); }
        #popup.erro { color: var(--color-erro); border: 2px solid var(--color-erro); }
        #popup.neutro { color: var(--color-tinta); border: 2px solid var(--color-tinta-2); }

    </style>
@endpush

<div id="status-anuncio" class="sr-only" aria-live="assertive"></div>

<div id="overlay-popup">
    <div id="popup"></div>
</div>

<script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const historico = document.getElementById('historico');
        const statusAnuncio = document.getElementById('status-anuncio');
        const overlayPopup = document.getElementById('overlay-popup');
        const popup = document.getElementById('popup');

        const DURACAO_HOLD_MS = 2000;
        const DURACAO_IDLE_MS = 10000;
        // Até 12 s o servidor ainda aceita a confirmação (VALIDADE_MAXIMA_S); só depois disso encerrar é "expirado".
        const VALIDADE_SERVIDOR_MS = 12000;
        // Prazo do rascunho (formulário em edição), contado desde a proposta (PRAZO_RASCUNHO_S no servidor).
        const PRAZO_RASCUNHO_MS = 300000;
        // Segurar só começa se ainda couber o hold (2 s) + margem antes do fim da janela: o servidor aceita até 12 s.
        const MINIMO_PARA_SEGURAR_MS = 2500;
        const RISCOS = ['leitura', 'escrita', 'exclusao'];

        function anunciar(texto) {
            statusAnuncio.textContent = texto;
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
            div.textContent = texto;
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
            const temAcao = editar || linhas.some((l) => l.acao && l.acao.tool);
            const table = document.createElement('table');
            const thead = document.createElement('thead');
            const trCabecalho = document.createElement('tr');
            colunas.forEach(c => {
                const th = document.createElement('th');
                th.textContent = c;
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
                        botao.setAttribute('aria-label', acao.rotulo + ' o registro ' + l.id);
                        botao.addEventListener('click', () => abrirFormulario(acao.tool, l.id, botao));
                        td.appendChild(botao);
                    } else if (editar) {
                        botao.textContent = 'Editar';
                        botao.setAttribute('aria-label', 'Editar o registro ' + l.id);
                        botao.addEventListener('click', () => abrirFormulario(editar, l.id, botao));
                        td.appendChild(botao);
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
            rolagem.appendChild(table);
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
        // 2) Revisada: campos travados, sai o token de segurar e correm os prazos de sempre (10 s na tela, 12 s no servidor).
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
                <strong class="card-titulo">Confirmação necessária: <span class="card-acao"></span></strong>
                <div class="msg msg--erro card-resumo-erro" role="alert" hidden>Confira os campos destacados. Nada foi gravado.</div>
                <dl class="card-registro" hidden></dl>
                <div class="tabela-rolagem card-lote" hidden></div>
                <form class="card-form" novalidate>
                    <fieldset class="card-campos"></fieldset>
                    <fieldset class="card-autorizacao" hidden>
                        <legend>Autorização de um administrador</legend>
                        <p class="campo-info">Um administrador digita o e-mail e a senha dele aqui. A senha só vai junto com a confirmação e não fica guardada.</p>
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
            autorizacao.hidden = true;
            const resumoErro = card.querySelector('.card-resumo-erro');
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

            preencherFormulario(formulario);
            etapa.textContent = 'Confira os dados e clique em Revisar.';
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
            let idleTimer = null;
            let quaseEsgotadoTimer = null;
            let avisoTempo = null;
            let tokenEmitidoEm = 0;

            // Prazo do rascunho: conta desde a proposta e não reinicia no "Editar".
            const rascunhoTimer = setTimeout(expirarRascunho, PRAZO_RASCUNHO_MS);

            function restanteMs() {
                return DURACAO_IDLE_MS - (performance.now() - tokenEmitidoEm);
            }

            function pararTimers() {
                clearTimeout(idleTimer);
                clearTimeout(quaseEsgotadoTimer);
            }

            // Janela ociosa contada desde a revisão. Segurar pausa os timers (M2); soltar sem completar retoma com o que resta.
            function agendarExpiracao() {
                const restante = restanteMs();
                idleTimer = setTimeout(expirarCard, Math.max(0, restante));
                quaseEsgotadoTimer = setTimeout(bloquearPorTempo, Math.max(0, restante - MINIMO_PARA_SEGURAR_MS));
            }

            function encerrarCard() {
                pararTimers();
                clearTimeout(rascunhoTimer);
                if (rafId) cancelAnimationFrame(rafId);
                adminSenha.value = '';
                card.remove();
            }

            function desativarTudo(texto) {
                pararTimers();
                clearTimeout(rascunhoTimer);
                if (avisoTempo) avisoTempo.remove();
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

            // Rascunho venceu (5 min): o aviso ao servidor sai logo depois, quando ele já conta como expirado.
            function expirarRascunho() {
                desativarTudo('O prazo para revisar terminou. Nada foi gravado. Peça de novo.');
                setTimeout(() => encerrarSemGravar({ proposta }), 500);
                anunciar('prazo para revisar terminou, nada foi gravado');
            }

            // Menos de 2,5 s na janela: um hold novo terminaria fora do prazo do servidor. Desativa o Confirmar.
            function bloquearPorTempo() {
                if (segurando || btnConfirmar.disabled) return;
                btnConfirmar.disabled = true;
                avisoSoltou.hidden = true;
                avisoTempo = document.createElement('div');
                avisoTempo.className = 'msg msg--aviso card-expirado-texto';
                avisoTempo.textContent = 'Tempo quase esgotado. Peça de novo.';
                card.appendChild(avisoTempo);
                anunciar('tempo quase esgotado, peça de novo');
            }

            // Expirou a janela de segurar: o card não some calado — fica visível, marcado, com os botões desativados.
            function expirarCard() {
                const tokenVencido = token;
                desativarTudo('Confirmação expirada. Nada foi gravado. Peça de novo.');
                // O card fecha aos 10 s, mas o aviso ao servidor espera a validade dele passar: antes disso seria lido como cancelamento.
                setTimeout(() => encerrarSemGravar({ token: tokenVencido }), VALIDADE_SERVIDOR_MS - DURACAO_IDLE_MS + 500);
                anunciar('confirmação expirada, nada foi gravado');
            }

            // Etapa 2: campos travados, segurar liberado e a janela de 10 s começa.
            function entrarNoSegurar(novoToken) {
                token = novoToken;
                tokenEmitidoEm = performance.now();
                campos.disabled = true;
                autorizacao.disabled = !formulario.reautenticar;
                autorizacao.hidden = !formulario.reautenticar;
                resumoErro.hidden = true;
                btnRevisar.hidden = true;
                btnConfirmar.hidden = false;
                btnConfirmar.disabled = false;
                // Sem campo nem senha para corrigir, "Editar" não teria o que editar.
                btnEditar.hidden = !formulario.campos.length && !formulario.reautenticar;
                etapa.textContent = formulario.reautenticar
                    ? 'Dados conferidos. Um administrador informa o e-mail e a senha nesta etapa final. Depois, segure Confirmar por 2 segundos.'
                    : 'Dados conferidos. Segure Confirmar por 2 segundos. Se soltar antes, nada é gravado.';
                agendarExpiracao();
                if (formulario.reautenticar) adminEmail.focus();
                else btnConfirmar.focus();
                anunciar('dados conferidos, segure confirmar');
            }

            // "Editar": o token morre no servidor (sem auditoria) e o card volta à etapa 1. O Revisar só volta
            // depois da resposta, para um Revisar rápido não disputar a sessão com o Editar.
            async function voltarAoFormulario() {
                pararTimers();
                segurando = false;
                if (rafId) cancelAnimationFrame(rafId);
                const tokenAntigo = token;
                token = null;
                if (avisoTempo) avisoTempo.remove();
                avisoSoltou.hidden = true;
                fill.style.width = '0%';
                label.textContent = 'Confirmar';
                btnConfirmar.classList.remove('segurando');
                btnConfirmar.hidden = true;
                btnEditar.hidden = true;
                btnRevisar.hidden = false;
                btnRevisar.disabled = true;
                campos.disabled = false;
                autorizacao.disabled = true;
                autorizacao.hidden = true;
                adminSenha.value = '';
                aplicarCondicoes(form, formulario.campos);
                etapa.textContent = 'Confira os dados e clique em Revisar.';
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
                        entrarNoSegurar(dados.token);
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
                if (segurando || btnConfirmar.disabled || btnConfirmar.hidden) return;
                // Credenciais só são solicitadas e validadas na etapa final.
                adminErro.hidden = true;
                adminSenha.removeAttribute('aria-invalid');
                if (formulario.reautenticar && (adminEmail.value.trim() === '' || adminSenha.value === '')) {
                    adminErro.textContent = 'Informe o e-mail e a senha de um administrador.';
                    adminErro.hidden = false;
                    const vazio = adminEmail.value.trim() === '' ? adminEmail : adminSenha;
                    vazio.setAttribute('aria-invalid', 'true');
                    vazio.setAttribute('aria-describedby', adminErro.id);
                    vazio.focus();
                    return;
                }

                if (restanteMs() < MINIMO_PARA_SEGURAR_MS) {
                    bloquearPorTempo();
                    return;
                }
                pararTimers();
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
                agendarExpiracao();
            }

            async function confirmarAcao() {
                pararTimers();
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
                encerrarSemGravar(token ? { token } : { proposta });
                encerrarCard();
                popupCancelado();
            });
        }
</script>
