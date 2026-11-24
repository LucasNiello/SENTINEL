import { useEffect, useRef, useState } from 'react';
import CamposProposta, { coletarValores, valoresIniciais } from './CamposProposta';
import CalendarioAgendamento from './CalendarioAgendamento';

const exibir = (valor) => valor == null || valor === '' ? 'Não informado' : typeof valor === 'object' ? JSON.stringify(valor) : String(valor);

export default function Confirmacao({ proposta, adicionar, trava, ocupado, definirOcupado }) {
    const [dados, setDados] = useState(proposta);
    const [token, setToken] = useState(null);
    const [valores, setValores] = useState(() => valoresIniciais(proposta.formulario?.campos ?? []));
    const [edicaoPendente, setEdicaoPendente] = useState(false);
    const tokenEdicao = useRef(null);
    const form = useRef(null);
    const [processando, setProcessando] = useState(false);
    const [erro, setErro] = useState('');
    const [encerrado, setEncerrado] = useState(false);
    const [etapa, setEtapa] = useState('Confira a proposta e clique em Revisar. Nada foi gravado.');
    const montado = useRef(true);
    useEffect(() => { montado.current = true; return () => { montado.current = false; }; }, []);

    async function executar(acao, alteracoes = null) {
        if (trava.current || encerrado || (acao === 'confirmar' && !token)
            || (acao === 'revisar' && (token || edicaoPendente))
            || (acao === 'editar' && !token && !tokenEdicao.current)) return;
        const atuais = alteracoes ? { ...valores, ...alteracoes } : valores;
        if (alteracoes) setValores(atuais);
        if (acao === 'revisar' && !form.current?.reportValidity()) return;
        const controller = new AbortController();
        trava.current = controller;
        setProcessando(true);
        definirOcupado(true);
        setErro('');
        const corpo = acao === 'revisar' ? { proposta: proposta.proposta, valores: coletarValores(dados.formulario?.campos ?? [], atuais) }
            : acao === 'confirmar' ? { token } : acao === 'editar' ? { token: token || tokenEdicao.current } : { proposta: proposta.proposta };
        if (acao === 'editar') {
            tokenEdicao.current = corpo.token;
            setToken(null);
            setEdicaoPendente(true);
        }
        // Uma tentativa de confirmação pode consumir o token mesmo quando falha.
        if (acao === 'confirmar') setToken(null);
        const timeout = setTimeout(() => controller.abort(), 35000);
        try {
            const resposta = await fetch(`/agente/${acao}`, {
                method: 'POST', credentials: 'same-origin', signal: controller.signal,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
                body: JSON.stringify(corpo),
            });
            if (resposta.status === 401 || resposta.status === 419) {
                window.location.assign('/login');
                return;
            }
            const resultado = await resposta.json().catch(() => null);
            if (!montado.current) return;
            if (!resposta.ok || !resultado || ['erro', 'negado'].includes(resultado.tipo)) {
                if (acao === 'revisar' && resultado?.formulario) {
                    setDados((anterior) => ({ ...anterior, formulario: resultado.formulario }));
                    setValores((anteriores) => ({ ...valoresIniciais(resultado.formulario.campos ?? []), ...anteriores }));
                }
                if (['AI_PROPOSAL_EXPIRED', 'AI_PROPOSAL_UNAVAILABLE', 'AI_CONFIRMATION_INVALID', 'AI_CONFIRMATION_USED'].includes(resultado?.codigo) || resultado?.tipo === 'negado') {
                    setEncerrado(true);
                    setToken(null);
                }
                throw new Error(resultado?.mensagem || 'Não foi possível processar a ação. Tente novamente.');
            }
            if (acao === 'revisar') {
                if (resultado.tipo !== 'revisada' || typeof resultado.token !== 'string' || !resultado.token) {
                    throw new Error('A revisão não retornou uma confirmação válida. Nada foi confirmado.');
                }
                setDados((anterior) => {
                    const formulario = resultado.formulario ?? anterior.formulario;
                    return { ...anterior, ...resultado, formulario: formulario ? { ...formulario,
                        campos: (formulario.campos ?? []).map(({ erro: _erro, ...campo }) => campo) } : formulario };
                });
                setToken(resultado.token);
                setEtapa('Revisão concluída. Confira os dados da proposta e clique em Confirmar para executar. Nada foi gravado ainda.');
            } else if (acao === 'editar') {
                if (resultado.tipo !== 'rascunho' || resultado.proposta !== proposta.proposta) throw new Error('Não foi possível reabrir a proposta. Tente Editar novamente.');
                tokenEdicao.current = null;
                setEdicaoPendente(false);
                setEtapa('Proposta aberta para edição. Revise novamente antes de confirmar.');
                adicionar('Proposta reaberta para edição. A confirmação exige uma nova revisão.', 'msg--agente');
            } else {
                const tipos = acao === 'confirmar' ? ['resultado_escrita'] : ['cancelado', 'expirado'];
                if (!tipos.includes(resultado.tipo) || typeof resultado.mensagem !== 'string') {
                    throw new Error('Resposta inesperada. Confira a situação da proposta antes de tentar novamente.');
                }
                setEncerrado(true);
                setToken(null);
                setEtapa(resultado.mensagem);
                adicionar(resultado.mensagem, 'msg--agente');
            }
        } catch (falha) {
            if (!montado.current) return;
            const mensagem = falha.name === 'AbortError' || falha instanceof TypeError
                ? 'Não foi possível obter a resposta. A ação pode ter sido processada; confira a situação antes de tentar novamente.' : falha.message;
            setErro(mensagem);
            if (acao === 'confirmar') setEtapa('Confirmação não concluída nesta tela. É necessário revisar novamente antes de confirmar.');
        } finally {
            clearTimeout(timeout);
            trava.current = null;
            if (montado.current) { setProcessando(false); definirOcupado(false); }
        }
    }

    const formulario = dados.formulario ?? {};
    const campos = formulario.campos ?? [];
    const risco = ['leitura', 'escrita', 'exclusao'].includes(dados.risco) ? dados.risco : 'exclusao';
    return <section className={`card-confirmacao card--risco-${risco}`} aria-label="Confirmação de ação" aria-busy={processando} style={{ overflowWrap: 'anywhere' }}>
        <div className="card-overline"><span>AÇÃO PROPOSTA</span><span className="card-risco">{risco}</span></div>
        <strong className="card-titulo">{formulario.titulo || 'Confirmar a ação'}</strong>
        {dados.mensagem && <p>{dados.mensagem}</p>}
        <dl className="card-registro" style={{ gridTemplateColumns: 'minmax(0, 1fr) minmax(0, 2fr)' }}>
            {(formulario.resumo ?? []).map((item, i) => <div key={`resumo-${i}`} style={{ display: 'contents' }}><dt>{item.rotulo}</dt><dd>{exibir(item.valor)}</dd></div>)}
            {(campos.length ? [] : Object.entries(dados.argumentos ?? {})).map(([nome, valor], i) =>
                <div key={i} style={{ display: 'contents' }}><dt>{nome}</dt><dd>{exibir(valor)}</dd></div>)}
        </dl>
        <form ref={form} className="card-form" onSubmit={(evento) => { evento.preventDefault(); executar('revisar'); }}>
            <CamposProposta campos={formulario.ficha ? campos.filter((c) => !['profissional_id', 'inicio'].includes(c.nome)) : campos} valores={valores} bloqueado={ocupado || !!token || edicaoPendente || encerrado}
                alterar={(nome, valor) => { if (!token && !edicaoPendente && !ocupado && !encerrado) setValores((anteriores) => ({ ...anteriores, [nome]: valor, ...(formulario.ficha && nome === 'catalogo_item_id' ? { profissional_id: '', inicio: '' } : {}) })); }} />
            {formulario.ficha && <CalendarioAgendamento ficha={formulario.ficha} valores={valores} bloqueado={ocupado || !!token || edicaoPendente || encerrado}
                selecionar={(selecao) => executar('revisar', selecao)} erros={[formulario.erro, ...campos.filter((c) => ['inicio', 'profissional_id'].includes(c.nome)).map((c) => c.erro)]} />}
        </form>
        {(formulario.avisos ?? []).map((aviso, i) => <p key={i} className="msg msg--aviso">{exibir(aviso)}</p>)}
        <p className="card-etapa" role="status">{processando ? 'Processando…' : etapa}</p>
        {erro && <p className="msg msg--erro" role="alert">{erro}</p>}
        {!encerrado && <div className="acoes">
            {!token && !edicaoPendente && <button type="button" className="btn-revisar" disabled={ocupado} onClick={() => executar('revisar')}>Revisar</button>}
            {(token || edicaoPendente) && <button type="button" className="btn-editar" disabled={ocupado} onClick={() => executar('editar')}>Editar</button>}
            <button type="button" className="btn-confirmar" disabled={ocupado || !token} onClick={() => executar('confirmar')}>Confirmar</button>
            <button type="button" className="btn-cancelar" disabled={ocupado} onClick={() => executar('cancelar')}>Cancelar</button>
        </div>}
    </section>;
}
