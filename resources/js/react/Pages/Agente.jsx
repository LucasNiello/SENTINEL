import { useEffect, useRef, useState } from 'react';
import Workspace from '../Layouts/Workspace';
import Confirmacao from '../Components/Confirmacao';
import TabelaConsulta from '../Components/TabelaConsulta';
import Aviso from '../Components/ui/Aviso';
import FarolCarregando from '../Components/ui/FarolCarregando';
import './Agente.css';

const errosHttp = {
    403: 'Você não tem permissão para esta operação.',
    422: 'Confira os dados do pedido.',
    429: 'Muitos pedidos. Aguarde antes de tentar novamente.',
    502: 'O serviço de IA retornou uma resposta inesperada. Tente novamente.',
    503: 'O serviço de IA está indisponível no momento. Tente novamente.',
    504: 'O serviço de IA demorou demais para responder. Tente novamente.',
};

export default function Agente({ historico = [] }) {
    const [mensagens, setMensagens] = useState(() => historico
        .filter((m) => ['user', 'assistant'].includes(m.role) && typeof m.content === 'string')
        .map((m) => ({ texto: m.content, classe: m.role === 'user' ? 'msg--usuario' : 'msg--agente' })));
    const [texto, setTexto] = useState('');
    const [pensando, setPensando] = useState(false);
    const campo = useRef(null);
    const fim = useRef(null);
    const carregando = useRef(null);
    const requisicao = useRef(null);
    const montado = useRef(false);
    const interagiu = useRef(false);
    // Histórico de mensagens nas setas (como no shell): posição atual (null = digitando) e o rascunho guardado ao começar a navegar.
    const posicao = useRef(null);
    const rascunho = useRef('');

    useEffect(() => {
        montado.current = true;
        return () => { montado.current = false; requisicao.current?.abort(); };
    }, []);

    // A roda do mouse fora da coluna do chat (laterais, topo, rodapé) também rola a conversa: a tela só tem o chat para rolar.
    // Dentro de #historico o navegador já cuida; zoom (Ctrl) e campos de texto ficam como estão.
    useEffect(() => {
        const aoRolar = (e) => {
            const historico = document.getElementById('historico');
            if (!historico || e.ctrlKey || e.defaultPrevented || historico.contains(e.target) || e.target.closest?.('textarea, input, select, [role="dialog"]')) return;
            historico.scrollTop += e.deltaMode === 1 ? e.deltaY * 16 : e.deltaY;
        };
        window.addEventListener('wheel', aoRolar, { passive: true });
        return () => window.removeEventListener('wheel', aoRolar);
    }, []);

    useEffect(() => {
        if (!interagiu.current) return;
        (carregando.current ?? fim.current)?.scrollIntoView({ block: 'nearest' });
        if (!pensando) campo.current?.focus({ preventScroll: true });
    }, [mensagens, pensando]);

    const adicionar = (texto, classe) => setMensagens((atuais) => [...atuais, { texto, classe }]);

    async function abrirFormulario(tool, id) {
        if (requisicao.current) return;
        const controller = new AbortController();
        requisicao.current = controller;
        interagiu.current = true;
        setPensando(true);
        const timeout = setTimeout(() => controller.abort(), 35000);
        try {
            const resposta = await fetch('/agente/formulario', {
                method: 'POST', credentials: 'same-origin', signal: controller.signal,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
                body: JSON.stringify({ tool, id }),
            });
            if (resposta.status === 401 || resposta.status === 419) { window.location.assign('/login'); return; }
            const dados = await resposta.json().catch(() => null);
            if (!montado.current) return;
            if (!resposta.ok || dados?.tipo !== 'confirmacao_pendente' || typeof dados.proposta !== 'string') {
                throw new Error(dados?.mensagem || 'Não foi possível abrir o formulário. Tente novamente.');
            }
            setMensagens((atuais) => [...atuais, { proposta: dados }]);
        } catch (erro) {
            if (montado.current) adicionar(erro.name === 'AbortError' || erro instanceof TypeError
                ? 'Falha ao abrir o formulário. Tente novamente.' : erro.message, 'msg--erro');
        } finally {
            clearTimeout(timeout);
            requisicao.current = null;
            if (montado.current) setPensando(false);
        }
    }

    // ↑ volta às mensagens anteriores, ↓ avança; passando da mais nova, volta o que estava sendo digitado.
    function navegarHistorico(e) {
        if ((e.key !== 'ArrowUp' && e.key !== 'ArrowDown') || e.nativeEvent.isComposing || e.shiftKey || e.altKey || e.ctrlKey || e.metaKey) return;
        const enviadas = mensagens.filter((m) => m.classe === 'msg--usuario').map((m) => m.texto);
        if (enviadas.length === 0) return;
        e.preventDefault();
        let proxima = posicao.current;
        if (e.key === 'ArrowUp') {
            if (proxima === null) { rascunho.current = texto; proxima = enviadas.length - 1; }
            else proxima = Math.max(0, proxima - 1);
        } else {
            if (proxima === null) return;
            proxima = proxima + 1 >= enviadas.length ? null : proxima + 1;
        }
        posicao.current = proxima;
        setTexto(proxima === null ? rascunho.current : enviadas[proxima]);
        // Cursor no fim do texto, como no shell.
        setTimeout(() => { const c = campo.current; if (c) c.setSelectionRange(c.value.length, c.value.length); }, 0);
    }

    async function enviar(evento) {
        evento.preventDefault();
        const mensagem = texto.trim();
        if (!mensagem || requisicao.current) return;
        const controller = new AbortController();
        requisicao.current = controller;
        interagiu.current = true;
        adicionar(mensagem, 'msg--usuario');
        posicao.current = null;
        setTexto('');
        setPensando(true);
        const timeout = setTimeout(() => controller.abort(), 35000);
        try {
            const resposta = await fetch('/agente/comando', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ mensagem }),
                signal: controller.signal,
            });
            if (resposta.status === 401 || resposta.status === 419) {
                window.location.assign('/login');
                return;
            }
            const dados = await resposta.json().catch(() => null);
            if (!resposta.ok) {
                throw new Error(resposta.status === 422 && typeof dados?.mensagem === 'string'
                    ? dados.mensagem : errosHttp[resposta.status] || 'Não foi possível processar o pedido.');
            }
            if (!dados || typeof dados !== 'object' || Array.isArray(dados)) {
                throw new Error('O servidor retornou uma resposta inválida. Tente novamente.');
            }
            switch (dados.tipo) {
                case 'texto':
                    if (typeof dados.mensagem !== 'string' || !dados.mensagem.trim()) {
                        throw new Error('O servidor retornou uma resposta inválida. Tente novamente.');
                    }
                    adicionar(dados.mensagem, 'msg--agente');
                    break;
                case 'erro':
                case 'negado':
                    throw new Error(typeof dados.mensagem === 'string' ? dados.mensagem : 'Não foi possível processar o pedido.');
                case 'resultado_leitura':
                    if (!Array.isArray(dados.resultado) || dados.resultado.some((linha) => !linha || typeof linha !== 'object' || Array.isArray(linha))
                        || (dados.colunas != null && (!Array.isArray(dados.colunas) || dados.colunas.some((coluna) => typeof coluna !== 'string')))) {
                        throw new Error('O servidor retornou uma consulta inválida. Tente novamente.');
                    }
                    // A resposta em frase (montada pelo servidor) vem antes da tabela.
                    setMensagens((atuais) => [...atuais, ...(typeof dados.frase === 'string' && dados.frase ? [{ texto: dados.frase, classe: 'msg--agente' }] : []), { consulta: dados }]);
                    break;
                case 'confirmacao_pendente':
                    if (typeof dados.proposta !== 'string' || !dados.proposta) throw new Error('Proposta inválida. Peça novamente.');
                    setMensagens((atuais) => [...atuais, { proposta: dados }]);
                    break;
                default:
                    throw new Error('O servidor retornou uma resposta inválida. Tente novamente.');
            }
            if (typeof dados.aviso === 'string' && dados.aviso) adicionar(`Aviso: ${dados.aviso}`, 'msg--aviso');
        } catch (erro) {
            if (!montado.current) return;
            adicionar(erro.name === 'AbortError' ? errosHttp[504]
                : erro instanceof TypeError ? 'Falha ao contatar o servidor.' : erro.message, 'msg--erro');
            setTexto(mensagem);
        } finally {
            clearTimeout(timeout);
            requisicao.current = null;
            if (montado.current) setPensando(false);
        }
    }

    return (
        <Workspace titulo="Agente">
            {/* O título visível é o do topo ("Sentinel / Agente"); aqui só o h1 para leitor de tela, sem repetir na tela. */}
            <h1 className="sr-only">Agente</h1>
            <div className="command-layout">
                <section className="agent-workspace" aria-label="Conversa com o Sentinel">
                    <div id="historico" role="log" aria-live="polite" aria-relevant="additions">
                        {mensagens.length === 0 && <div className="agent-empty">
                            <h2>Por onde começamos?</h2>
                            <p>Pergunte o que quiser saber ou peça uma ação, do jeito que você falaria.</p>
                            <ol className="agent-passos">
                                <li><b>01</b><strong>Peça</strong><span>Escreva o que precisa no campo abaixo.</span></li>
                                <li><b>02</b><strong>Revise</strong><span>Confira os dados e ajuste o que quiser.</span></li>
                                <li><b>03</b><strong>Confirme</strong><span>Nada é gravado sem a sua confirmação.</span></li>
                            </ol>
                        </div>}
                        {mensagens.map((mensagem, indice) => mensagem.proposta
                            ? <Confirmacao key={indice} proposta={mensagem.proposta} adicionar={adicionar} trava={requisicao} ocupado={pensando} definirOcupado={setPensando} abrirFormulario={abrirFormulario} />
                            : mensagem.consulta ? <TabelaConsulta key={indice} consulta={mensagem.consulta} ocupado={pensando} abrirFormulario={abrirFormulario} adicionar={adicionar}
                                prepararPedido={(pedido) => { if (!requisicao.current) { setTexto(pedido.slice(0, 1000)); campo.current?.focus(); } }} />
                            : ['msg--erro', 'msg--aviso'].includes(mensagem.classe)
                                ? <Aviso key={indice} tipo={mensagem.classe === 'msg--erro' ? 'erro' : 'atencao'} className={`msg ${mensagem.classe}`}>
                                    <span className="msg__speaker">Atenção: </span>{mensagem.texto}
                                </Aviso>
                                : <div key={indice} className={`msg ${mensagem.classe}`}>
                                    <span className="msg__speaker">{mensagem.classe === 'msg--usuario' ? 'Você' : 'Sentinel'}</span>
                                    <span>{mensagem.texto}</span>
                                </div>)}
                        {pensando && <div id="carregando" ref={carregando} className="chat-carregando" role="status">
                            <FarolCarregando /><span className="sr-only">Consultando…</span>
                        </div>}
                    </div>
                    <div className="agent-composer" ref={fim}>
                        <form id="form-comando" onSubmit={enviar} aria-busy={pensando}>
                            <label htmlFor="campo-mensagem" className="sr-only">Seu pedido ao agente</label>
                            <span className="composer-mark" aria-hidden="true">⌘</span>
                            <input ref={campo} type="text" id="campo-mensagem" data-foco placeholder="Peça ou pergunte…" required maxLength={1000} value={texto} autoComplete="off" autoCorrect="off" onChange={(e) => { posicao.current = null; setTexto(e.target.value); }} disabled={pensando} onKeyDown={(e) => { if (e.key === 'Enter' && e.nativeEvent.isComposing) e.preventDefault(); navegarHistorico(e); }} />
                            <button type="submit" id="botao-enviar" aria-label="Enviar" title="Enviar" disabled={pensando}><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true"><path d="M4 12h15m-6-6 6 6-6 6" /></svg></button>
                        </form>
                        <p className="composer-note"><span aria-hidden="true">◇</span> Você revisa. Você decide. Nenhuma gravação sem confirmação.</p>
                    </div>
                </section>
            </div>
        </Workspace>
    );
}
