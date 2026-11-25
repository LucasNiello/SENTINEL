import { useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import Workspace from '../Layouts/Workspace';
import Farol from '../Components/Farol';
import Confirmacao from '../Components/Confirmacao';
import TabelaConsulta from '../Components/TabelaConsulta';

const errosHttp = {
    403: 'Você não tem permissão para esta operação.',
    422: 'Confira os dados do pedido.',
    429: 'Muitos pedidos. Aguarde antes de tentar novamente.',
    502: 'O serviço de IA retornou uma resposta inesperada. Tente novamente.',
    503: 'O serviço de IA está indisponível no momento. Tente novamente.',
    504: 'O serviço de IA demorou demais para responder. Tente novamente.',
};

export default function Agente({ historico = [] }) {
    const { usuario } = usePage().props;
    const [mensagens, setMensagens] = useState(() => historico
        .filter((m) => ['user', 'assistant'].includes(m.role) && typeof m.content === 'string')
        .map((m) => ({ texto: m.content, classe: m.role === 'user' ? 'msg--usuario' : 'msg--agente' })));
    const [texto, setTexto] = useState('');
    const [pensando, setPensando] = useState(false);
    const campo = useRef(null);
    const fim = useRef(null);
    const requisicao = useRef(null);
    const montado = useRef(false);
    const interagiu = useRef(false);

    useEffect(() => {
        montado.current = true;
        return () => { montado.current = false; requisicao.current?.abort(); };
    }, []);

    useEffect(() => {
        if (!interagiu.current) return;
        fim.current?.scrollIntoView({ block: 'nearest' });
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

    async function enviar(evento) {
        evento.preventDefault();
        const mensagem = texto.trim();
        if (!mensagem || requisicao.current) return;
        const controller = new AbortController();
        requisicao.current = controller;
        interagiu.current = true;
        adicionar(mensagem, 'msg--usuario');
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
                    setMensagens((atuais) => [...atuais, { consulta: dados }]);
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
            <header className="page-header agent-hero">
                <p className="eyebrow">Workspace / Inteligência assistida</p>
                <h1>Converse com seus dados.<br /><span>Decida com contexto.</span></h1>
                <p className="intro">Consulte informações e prepare operações em linguagem natural.</p>
                <div className="agent-orbit" aria-hidden="true"><span /><Farol /></div>
            </header>
            <div className="command-layout">
                <section className="agent-workspace" aria-label="Conversa com o Sentinel">
                    <header className="deck-header"><div><span className="eyebrow">SENTINEL AGENT</span><p>Consulta e operações assistidas</p></div><span className="deck-badge">REVISÃO HUMANA</span></header>
                    <div id="historico" role="log" aria-live="polite" aria-relevant="additions">
                        {mensagens.length === 0 && <div className="agent-empty">
                            <p className="eyebrow">SEU PRÓXIMO MOVIMENTO</p>
                            <h2>Por onde começamos?</h2>
                            <p>Uma pergunta abre caminhos. Uma ação começa pela sua revisão.</p>
                        </div>}
                        {mensagens.map((mensagem, indice) => mensagem.proposta
                            ? <Confirmacao key={indice} proposta={mensagem.proposta} adicionar={adicionar} trava={requisicao} ocupado={pensando} definirOcupado={setPensando} />
                            : mensagem.consulta ? <TabelaConsulta key={indice} consulta={mensagem.consulta} ocupado={pensando} abrirFormulario={abrirFormulario} adicionar={adicionar}
                                prepararPedido={(pedido) => { if (!requisicao.current) { setTexto(pedido.slice(0, 1000)); campo.current?.focus(); } }} />
                            : <div key={indice} className={`msg ${mensagem.classe}`}>
                            <span className="msg__speaker">{mensagem.classe === 'msg--usuario' ? 'Você' : mensagem.classe === 'msg--erro' ? 'Atenção' : 'Sentinel'}</span>
                            <span>{mensagem.texto}</span>
                        </div>)}
                    </div>
                    <div id="carregando" className="carregando" hidden={!pensando} role="status">
                        <span>Consultando…</span><div className="carregando__barra" />
                    </div>
                    <div className="agent-composer" ref={fim}>
                        <form id="form-comando" onSubmit={enviar} aria-busy={pensando}>
                            <label htmlFor="campo-mensagem" className="sr-only">Seu pedido ao agente</label>
                            <span className="composer-mark" aria-hidden="true">⌘</span>
                            <input ref={campo} type="text" id="campo-mensagem" data-foco placeholder="Peça ou pergunte…" required maxLength={1000} value={texto} onChange={(e) => setTexto(e.target.value)} disabled={pensando} onKeyDown={(e) => { if (e.key === 'Enter' && e.nativeEvent.isComposing) e.preventDefault(); }} />
                            <kbd className="composer-key" aria-hidden="true">ENTER ↵</kbd>
                            <button type="submit" id="botao-enviar" disabled={pensando}>Enviar <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true"><path d="M4 12h15m-6-6 6 6-6 6" /></svg></button>
                        </form>
                        <p className="composer-note"><span aria-hidden="true">◇</span> Você revisa. Você decide. Nenhuma gravação sem confirmação.</p>
                    </div>
                </section>
                <aside className="context-rail" aria-label="Contexto da sessão">
                    <section className="session-context">
                        <p className="eyebrow">CONTEXTO ATUAL</p><h2>Seu espaço de decisão.</h2>
                        <dl><dt>CONTA</dt><dd>{usuario?.nome}</dd><dt>PERFIL</dt><dd>{usuario?.rotuloPapel}</dd><dt>MÓDULO</dt><dd>Agente</dd></dl>
                    </section>
                    <section className="decision-flow" aria-label="Fluxo de decisão">
                        <p className="eyebrow">DO PEDIDO À DECISÃO</p>
                        <ol><li><span>01</span><div><h3>Peça</h3><p>Descreva o que precisa em linguagem natural.</p></div></li><li><span>02</span><div><h3>Revise</h3><p>Confira os dados e ajuste antes de prosseguir.</p></div></li><li><span>03</span><div><h3>Confirme</h3><p>Uma gravação exige sua confirmação deliberada.</p></div></li></ol>
                    </section>
                    <p className="context-protection"><span aria-hidden="true">◇</span> Dados isolados por organização.<br />Operações registradas em auditoria.</p>
                </aside>
            </div>
        </Workspace>
    );
}
