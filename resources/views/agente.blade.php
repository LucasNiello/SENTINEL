@extends('layouts.app')

@section('titulo', 'Agente')

@section('conteudo')
    <div class="command-layout">
    <section class="agent-workspace" aria-label="Conversa com o Sentinel">

    <header class="deck-header"><div><button type="button" id="painel-alternar" class="painel-alternar" aria-controls="painel-agente" aria-expanded="false" aria-label="Abrir painel"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/></svg></button><x-lighthouse class="deck-logo" /><p>Agente</p></div></header>
    <div class="agent-corpo">
    @include('partials.painel-agente')
    <div class="agent-conversa">
    <div id="historico" aria-live="polite">
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
        window.pedirAoAgente = (texto) => {
            if (campoMensagem.disabled) return;
            campoMensagem.value = String(texto).slice(0, 1000);
            campoMensagem.focus();
            campoMensagem.setSelectionRange(campoMensagem.value.length, campoMensagem.value.length);
        };

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const mensagem = campoMensagem.value.trim();
            if (!mensagem) return;

            adicionarMensagem(mensagem, 'msg--usuario');
            // A janela do chat tem rolagem própria: o bloco novo (pergunta + resultado) fica no topo dela. Usa só
            // scrollTop do #historico (nunca scrollIntoView, que rola a página inteira).
            const blocoPergunta = historico.lastElementChild;
            const rolarAoBloco = () => {
                historico.scrollTop = Math.max(0, blocoPergunta.offsetTop - parseFloat(getComputedStyle(historico).paddingTop || 0));
            };
            rolarAoBloco();
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
                rolarAoBloco();
                if (!document.activeElement.closest('.card-confirmacao')) campoMensagem.focus();
            }
        });
    </script>
@endsection
