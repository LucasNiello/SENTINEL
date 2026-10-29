@extends('layouts.app')

@section('titulo', 'Agente')

@section('conteudo')
    <h1>Agente</h1>

    <div id="historico" aria-live="polite"></div>

    <div id="carregando" class="carregando" hidden>
        <span>Consultando…</span>
        <div class="carregando__barra"></div>
    </div>

    <form id="form-comando">
        <label for="campo-mensagem" class="sr-only">Seu pedido ao agente</label>
        <input type="text" id="campo-mensagem" data-foco placeholder="Pergunte algo sobre os lançamentos..." required>
        <button type="submit" id="botao-enviar">Enviar</button>
    </form>


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
