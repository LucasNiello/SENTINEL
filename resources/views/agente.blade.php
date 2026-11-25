@extends('layouts.app')

@section('titulo', 'Agente')

@section('conteudo')
    <div class="command-layout">
    <section class="agent-workspace" aria-label="Conversa com o Sentinel">

    <header class="deck-header"><div><button type="button" id="painel-alternar" class="painel-alternar" aria-controls="painel-agente" aria-expanded="false" aria-label="Abrir painel"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/></svg></button><x-lighthouse class="deck-logo" /><p>Agente</p></div></header>
    <div class="agent-corpo">
    @include('partials.painel-agente')
    <div class="agent-conversa">
    <div id="historico" aria-live="polite" tabindex="0">
        <div class="agent-empty">
            <p class="eyebrow">SEU PRÓXIMO MOVIMENTO</p>
            <h2>Por onde começamos?</h2>
            <p>Uma pergunta abre caminhos. Uma ação começa pela sua revisão.</p>
            <div class="agent-suggestions" aria-label="Exemplos de pedidos">
                @if (app(\App\Services\ContextoUsuario::class)->dominio() === 'clinica')
                    {{-- F3 do front (14/11): na clínica, os exemplos são da clínica (antes eram os do domínio antigo). Só preenchem o campo. --}}
                    <button type="button" data-suggestion="mostre a agenda de hoje"><small>AGENDA</small>Agenda de hoje <span aria-hidden="true">↗</span></button>
                    <button type="button" data-suggestion="mostre as mensagens novas da caixa de entrada"><small>CAIXA DE ENTRADA</small>Mensagens novas <span aria-hidden="true">↗</span></button>
                    @if (app(\App\Services\AiAgentService::class)->podeExecutar('agendar_atendimento'))
                        <button type="button" data-suggestion="agende uma consulta para o pet "><small>AGENDAR</small>Agendar uma consulta <span aria-hidden="true">↗</span></button>
                    @endif
                    @if (app(\App\Services\AiAgentService::class)->podeExecutar('registrar_venda'))
                        <button type="button" data-suggestion="registre uma venda de "><small>VENDA</small>Registrar uma venda <span aria-hidden="true">↗</span></button>
                    @endif
                @else
                <button type="button" data-suggestion="liste meus clientes"><small>CONSULTAR</small>Liste meus clientes <span aria-hidden="true">↗</span></button>
                <button type="button" data-suggestion="quais lançamentos estão pendentes?"><small>FINANCEIRO</small>Lançamentos pendentes <span aria-hidden="true">↗</span></button>
                @if (app(\App\Services\AiAgentService::class)->podeExecutar('criar_cliente'))
                    <button type="button" data-suggestion="cadastre um cliente chamado "><small>CADASTRO</small>Cadastre um cliente <span aria-hidden="true">↗</span></button>
                @endif
                @endif
            </div>
        </div>
    </div>

    {{-- Fora do #historico (o observador de rolagem só olha os filhos dele); altura 0, o botão flutua sobre o fim da conversa. --}}
    <div class="novas-wrap"><button type="button" id="novas-mensagens" hidden aria-label="Novas mensagens abaixo. Ir para elas"><span aria-hidden="true">↓</span> Novas mensagens</button></div>

    <div id="carregando" class="carregando" hidden>
        <span>Consultando…</span>
        <div class="carregando__barra"></div>
    </div>

    <div class="agent-composer">
    <form id="form-comando">
        <label for="campo-mensagem" class="sr-only">Seu pedido ao agente</label>
        <input type="text" id="campo-mensagem" data-foco placeholder="Peça ou pergunte…" required>
        <button type="submit" id="botao-enviar" aria-label="Enviar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M4 12h15m-6-6 6 6-6 6"/></svg></button>
    </form>
    <p class="composer-note"><span aria-hidden="true">◇</span> Você revisa. Você decide. Nenhuma gravação sem confirmação.</p>
    </div>
    </div>
    </div>
    </section>
    </div>


    @include('partials.bancada')

    <script>
        const form = document.getElementById('form-comando');
        const campoMensagem = document.getElementById('campo-mensagem');
        const botaoEnviar = document.getElementById('botao-enviar');
        const indicadorCarregando = document.getElementById('carregando');

        // Enquanto espera o agente: campo e botão desativados, aria-busy e o texto "Consultando…".
        function definirCarregando(ativo) {
            campoMensagem.disabled = ativo;
            botaoEnviar.disabled = ativo;
            form.setAttribute('aria-busy', ativo ? 'true' : 'false');
            indicadorCarregando.hidden = !ativo;
            if (ativo) anunciar('consultando');
        }

        // P-B5: "Pedir ao agente" numa mensagem da caixa de entrada põe o texto dela (com o tutor e os pets) no campo do
        // pedido, sem enviar: o usuário vê o que vai para o agente, ajusta se quiser e envia (pendência "sem prévia", 13/11).
        // 24/11: com `enviar`, o painel lateral manda uma LEITURA (sem Revisar) direto; o submit passa pelo mesmo fluxo do botão.
        // Enviar um pedido novo descarta a proposta aberta (AgenteController::comando). Por isso o envio direto NÃO acontece com
        // um card aberto (expirado não conta) nem por cima do que a pessoa está digitando: devolve o motivo e não mexe em nada.
        // Retorno: null = feito; texto = motivo de não ter feito.
        window.pedirAoAgente = (texto, enviar = false) => {
            if (campoMensagem.disabled) return 'Aguarde a resposta do agente.';
            if (enviar) {
                if (document.querySelector('.card-confirmacao:not(.card--expirado)')) return 'Conclua ou cancele a ação proposta antes.';
                if (campoMensagem.value.trim() !== '') return 'Envie ou apague o que você está digitando antes.';
            }
            campoMensagem.value = String(texto).slice(0, 1000);
            campoMensagem.focus();
            campoMensagem.setSelectionRange(campoMensagem.value.length, campoMensagem.value.length);
            if (enviar) form.requestSubmit();
            return null;
        };

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
                    // 422: a frase do servidor diz o que faltou ("Não encontrei o cliente com esse número na lixeira.");
                    // é texto pensado para o usuário, sem detalhe técnico.
                    const doServidor = resp.status === 422 && typeof dados.mensagem === 'string' ? dados.mensagem : null;
                    adicionarMensagem(doServidor || mensagens[resp.status] || 'Não foi possível processar o pedido.', 'msg--erro');
                    return;
                }

                switch (dados.tipo) {
                    case 'texto':
                        adicionarMensagem(dados.mensagem || '(sem resposta)', 'msg--agente');
                        break;
                    case 'resultado_leitura':
                        adicionarTabela(dados.resultado || [], dados.colunas, dados.editar, dados.exportar);
                        break;
                    case 'confirmacao_pendente':
                        renderizarConfirmacaoPendente(dados);
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
                if (!document.activeElement.closest('.card-confirmacao')) campoMensagem.focus();
            }
        });

        // Rolagem da conversa (23/11): só o #historico rola; a barra de digitação fica sempre à vista.
        //  - mensagem do usuário: vai ao fim;
        //  - resposta ou card novo: vai ao INÍCIO do elemento novo (o título "Ação proposta" não é cortado);
        //  - se a pessoa rolou para cima de propósito, não puxa de volta: mostra "↓ Novas mensagens".
        // O observador olha só os filhos diretos (childList, sem subtree): o card que se atualiza por dentro não rola nada.
        (() => {
            const botaoNovas = document.getElementById('novas-mensagens');
            const MARGEM_FIM_PX = 48;
            let subiu = false;        // a pessoa rolou para cima por conta própria
            let ultimoTopo = 0;
            let ultimaAltura = 0;
            let primeiroNaoLido = null;

            // Sempre instantânea (23/11, Lucas): bancada sem movimento (skill sentinel-visual §1/§8).
            const comportamento = () => 'auto';
            const distanciaDoFim = () => historico.scrollHeight - historico.scrollTop - historico.clientHeight;
            const memorizar = () => { ultimoTopo = historico.scrollTop; ultimaAltura = historico.scrollHeight; };

            function esconderBotao() {
                botaoNovas.hidden = true;
                primeiroNaoLido = null;
            }

            function rolarAoFim() {
                historico.scrollTo({ top: historico.scrollHeight, behavior: comportamento() });
            }

            function rolarAoInicioDe(elemento) {
                const recuo = parseFloat(getComputedStyle(historico).paddingTop) || 0;
                const topo = elemento.getBoundingClientRect().top - historico.getBoundingClientRect().top + historico.scrollTop - recuo;
                historico.scrollTo({ top: Math.max(0, topo), behavior: comportamento() });
            }

            historico.addEventListener('scroll', () => {
                const topo = historico.scrollTop;
                // Conteúdo que encolheu (card fechado) também faz o topo baixar; isso não é a pessoa rolando para cima.
                const encolheu = historico.scrollHeight < ultimaAltura;
                if (!encolheu && topo < ultimoTopo - 1) subiu = true;
                if (distanciaDoFim() <= MARGEM_FIM_PX) {
                    subiu = false;
                    esconderBotao();
                }
                memorizar();
            }, { passive: true });

            botaoNovas.addEventListener('click', () => {
                const alvo = primeiroNaoLido;
                subiu = false;
                esconderBotao();
                if (alvo && alvo.isConnected) rolarAoInicioDe(alvo); else rolarAoFim();
                campoMensagem.focus({ preventScroll: true });
            });

            new MutationObserver((registros) => {
                const novos = [];
                for (const registro of registros) {
                    for (const no of registro.addedNodes) if (no.nodeType === 1) novos.push(no);
                }
                if (novos.length) {
                    const card = novos.find((no) => no.classList.contains('card-confirmacao'));
                    if (novos.some((no) => no.classList.contains('msg--usuario'))) {
                        subiu = false;
                        esconderBotao();
                        rolarAoFim();
                    } else if (card) {
                        // O card nasce de uma ação da própria pessoa (Editar, Novo, resposta ao pedido): sempre vai à vista.
                        subiu = false;
                        esconderBotao();
                        rolarAoInicioDe(card);
                    } else if (!subiu) {
                        rolarAoInicioDe(novos[0]);
                    } else {
                        if (!primeiroNaoLido) primeiroNaoLido = novos[0];
                        botaoNovas.hidden = false;
                    }
                }
                memorizar();
            }).observe(historico, { childList: true });

            // "Recarregar abre no fim": a conversa não é restaurada, então basta começar rolado ao fim.
            historico.scrollTop = historico.scrollHeight;
            memorizar();
        })();
    </script>
@endsection
