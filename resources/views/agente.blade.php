@extends('layouts.app')

@section('titulo', 'Agente')

@section('conteudo')
    <header class="page-header agent-hero">
        <p class="eyebrow">Workspace / Inteligência assistida</p>
        <h1>Converse com seus dados.<br><span>Decida com contexto.</span></h1>
        <p class="intro">Consulte informações e prepare operações em linguagem natural.</p>
    <div class="agent-orbit" aria-hidden="true"><span></span><x-lighthouse /></div>
    </header>
    <div class="command-layout">
    <section class="agent-workspace" aria-label="Conversa com o Sentinel">

    <header class="deck-header"><div><span class="eyebrow">SENTINEL AGENT</span><p>Consulta e operações assistidas</p></div><span class="deck-badge">REVISÃO HUMANA</span></header>
    <div id="historico" aria-live="polite">
        <div class="agent-empty">
            <p class="eyebrow">SEU PRÓXIMO MOVIMENTO</p>
            <h2>Por onde começamos?</h2>
            <p>Uma pergunta abre caminhos. Uma ação começa pela sua revisão.</p>
            <div class="agent-suggestions" aria-label="Exemplos de pedidos">
                <button type="button" data-suggestion="liste meus clientes"><small>CONSULTAR</small>Liste meus clientes <span aria-hidden="true">↗</span></button>
                <button type="button" data-suggestion="quais lançamentos estão pendentes?"><small>FINANCEIRO</small>Lançamentos pendentes <span aria-hidden="true">↗</span></button>
                @if (in_array(auth()->user()->papel, ['operador', 'admin'], true))
                    <button type="button" data-suggestion="cadastre um cliente chamado "><small>CADASTRO</small>Cadastre um cliente <span aria-hidden="true">↗</span></button>
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
        <span class="composer-mark" aria-hidden="true">⌘</span>
        <input type="text" id="campo-mensagem" data-foco placeholder="Pergunte ou descreva uma ação…" required>
        <kbd class="composer-key" aria-hidden="true">ENTER ↵</kbd>
        <button type="submit" id="botao-enviar">Enviar <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M4 12h15m-6-6 6 6-6 6"/></svg></button>
    </form>
    <p class="composer-note"><span aria-hidden="true">◇</span> Você revisa. Você decide. Nenhuma gravação sem confirmação.</p>
    </div>
    </section>
    <aside class="context-rail" aria-label="Contexto da sessão">
        <section class="session-context">
            <p class="eyebrow">CONTEXTO ATUAL</p>
            <h2>Seu espaço de decisão.</h2>
            <dl><dt>CONTA</dt><dd>{{ auth()->user()->name }}</dd><dt>PERFIL</dt><dd>{{ ['leitura' => 'Leitura', 'operador' => 'Operador', 'admin' => 'Administrador'][auth()->user()->papel] ?? auth()->user()->papel }}</dd><dt>MÓDULO</dt><dd>Agente</dd></dl>
        </section>
        <section class="decision-flow" aria-label="Fluxo de decisão">
            <p class="eyebrow">DO PEDIDO À DECISÃO</p>
            <ol><li><span>01</span><div><h3>Peça</h3><p>Descreva o que precisa em linguagem natural.</p></div></li><li><span>02</span><div><h3>Revise</h3><p>Confira os dados e ajuste antes de prosseguir.</p></div></li><li><span>03</span><div><h3>Confirme</h3><p>Uma gravação exige sua confirmação deliberada.</p></div></li></ol>
        </section>
        <p class="context-protection"><span aria-hidden="true">◇</span> Dados isolados por organização.<br>Operações registradas em auditoria.</p>
    </aside>
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
    </script>
@endsection
