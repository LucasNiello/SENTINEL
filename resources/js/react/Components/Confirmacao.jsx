import { useEffect, useRef, useState } from 'react';
import CamposProposta, { coletarValores, valoresIniciais } from './CamposProposta';
import CalendarioAgendamento from './CalendarioAgendamento';
import Reautenticacao from './Reautenticacao';
import ItensLote from './ItensLote';
import ItensEstorno, { coletarLinhas, quantidadesIniciais } from './ItensEstorno';
import Aviso from './ui/Aviso';
import Botao from './ui/Botao';
import ResumoErros from './ui/ResumoErros';
import Toast from './ui/Toast';

const exibir = (valor) => valor == null || valor === '' ? 'Não informado' : typeof valor === 'object' ? JSON.stringify(valor) : String(valor);
// O Blade ignora o clique nos 400 ms depois de o Confirmar aparecer porque ali ele surge no lugar do Revisar. Aqui os dois
// botões ficam em posições diferentes; o duplo envio já é barrado pela trava da requisição.
const horaAgora = () => new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
const SOLTOU_CEDO = 'Você soltou antes da hora, então não fiz nada. Se quiser, é só segurar de novo.';

export default function Confirmacao({ proposta, adicionar, trava, ocupado, definirOcupado, abrirFormulario }) {
    const [dados, setDados] = useState(proposta);
    const [token, setToken] = useState(null);
    const [valores, setValores] = useState(() => valoresIniciais(proposta.formulario?.campos ?? []));
    const [quantidades, setQuantidades] = useState(() => quantidadesIniciais(proposta.formulario?.itens_estorno));
    const [edicaoPendente, setEdicaoPendente] = useState(false);
    const tokenEdicao = useRef(null);
    const form = useRef(null);
    const autorizacao = useRef(null);
    const [processando, setProcessando] = useState(false);
    const [erro, setErro] = useState('');
    const [encerrado, setEncerrado] = useState(false);
    const [fechamento, setFechamento] = useState(null);
    const [erroSeq, setErroSeq] = useState(0);
    const avisoErro = useRef(null);
    const [etapa, setEtapa] = useState('Confira a proposta e clique em Revisar. Nada foi gravado.');
    const [toast, setToast] = useState(null);
    const montado = useRef(true);
    const cartao = useRef(null);
    const loteComErro = (dados.formulario?.itens ?? []).some((item) => item.erro);
    useEffect(() => { montado.current = true; return () => { montado.current = false; }; }, []);
    // Segurar 2 s só nas ações destrutivas (decisão B): o servidor diz em `segurar`, calculado com os valores revisados.
    // Sem a resposta, o padrão seguro é segurar (igual ao Blade).
    const precisaSegurar = dados.segurar !== false;

    async function executar(acao, alteracoes = null, { silencioso = false } = {}) {
        if (trava.current || encerrado || (acao === 'confirmar' && !token)
            || (loteComErro && acao !== 'cancelar')
            || (acao === 'revisar' && (token || edicaoPendente))
            || (acao === 'editar' && !token && !tokenEdicao.current)) return false;
        const atuais = alteracoes ? { ...valores, ...alteracoes } : valores;
        if (alteracoes) setValores(atuais);
        if (acao === 'revisar' && !form.current?.reportValidity()) return false;
        if (['revisar', 'confirmar'].includes(acao) && dados.formulario?.reautenticar
            && !autorizacao.current?.validar()) return false;
        const controller = new AbortController();
        trava.current = controller;
        setProcessando(true);
        definirOcupado(true);
        setErro('');
        const corpo = acao === 'revisar' ? { proposta: proposta.proposta, valores: coletarValores(dados.formulario?.campos ?? [], atuais) }
            : acao === 'confirmar' ? { token } : acao === 'editar' ? { token: token || tokenEdicao.current } : { proposta: proposta.proposta };
        if (acao === 'revisar' && dados.formulario?.itens_estorno) {
            corpo.valores.linhas = coletarLinhas(dados.formulario.itens_estorno, quantidades);
        }
        if (acao === 'confirmar' && dados.formulario?.reautenticar) Object.assign(corpo, autorizacao.current.consumir());
        if (['editar', 'cancelar'].includes(acao)) autorizacao.current?.limpar();
        if (acao === 'editar') {
            tokenEdicao.current = corpo.token;
            setToken(null);
            setEdicaoPendente(true);
        }
        // Uma tentativa de confirmação pode consumir o token mesmo quando falha.
        if (acao === 'confirmar') setToken(null);
        const timeout = setTimeout(() => controller.abort(), 35000);
        let propostaEncerrada = false;
        try {
            const pedido = fetch(`/agente/${acao}`, {
                method: 'POST', credentials: 'same-origin', signal: controller.signal,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
                body: JSON.stringify(corpo),
            });
            delete corpo.email_admin;
            delete corpo.senha_admin;
            const resposta = await pedido;
            if (resposta.status === 401 || resposta.status === 419) {
                window.location.assign('/login');
                return false;
            }
            const resultado = await resposta.json().catch(() => null);
            if (!montado.current) return false;
            if (!resposta.ok || !resultado || ['erro', 'negado'].includes(resultado.tipo)) {
                if (acao === 'revisar' && resultado?.formulario) {
                    setDados((anterior) => ({ ...anterior, formulario: resultado.formulario }));
                    setValores((anteriores) => ({ ...valoresIniciais(resultado.formulario.campos ?? []), ...anteriores }));
                    if (resultado.formulario.itens_estorno) {
                        setValores(valoresIniciais(resultado.formulario.campos ?? []));
                        setQuantidades(quantidadesIniciais(resultado.formulario.itens_estorno));
                    }
                    if ((resultado.formulario.itens ?? []).some((item) => item.erro)) {
                        setToken(null);
                        setEtapa('Peça de novo para montar outro plano, ou clique em Cancelar.');
                    }
                }
                if (['AI_PROPOSAL_EXPIRED', 'AI_PROPOSAL_UNAVAILABLE', 'AI_CONFIRMATION_INVALID', 'AI_CONFIRMATION_USED',
                    'AI_REAUTH_REQUIRED', 'AI_REAUTH_FAILED', 'AI_REAUTH_RATE_LIMIT'].includes(resultado?.codigo) || resultado?.tipo === 'negado') {
                    propostaEncerrada = true;
                    setEncerrado(true);
                    setFechamento({ tipo: 'erro', texto: 'Encerrado sem gravar', hora: horaAgora() });
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
                if (resultado.formulario?.itens_estorno) {
                    setValores(valoresIniciais(resultado.formulario.campos ?? []));
                    setQuantidades(quantidadesIniciais(resultado.formulario.itens_estorno));
                }
                setEtapa(resultado.segurar === false
                    ? 'Revisão concluída. Confira os dados da proposta e clique em Confirmar para executar. Nada foi gravado ainda.'
                    : 'Dados conferidos. Segure Confirmar por 2 segundos. Se soltar antes, nada é gravado.');
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
                setFechamento(acao === 'confirmar' ? { tipo: 'sucesso', texto: 'Confirmado', hora: horaAgora() }
                    : { tipo: 'neutro', texto: resultado.tipo === 'expirado' ? 'Encerrado' : 'Cancelado', hora: horaAgora() });
                setToken(null);
                setEtapa(resultado.mensagem);
                if (!silencioso) adicionar(resultado.mensagem, 'msg--agente');
            }
            return true;
        } catch (falha) {
            if (!montado.current) return false;
            const mensagem = falha.name === 'AbortError' || falha instanceof TypeError
                ? 'Não foi possível obter a resposta. A ação pode ter sido processada; confira a situação antes de tentar novamente.' : falha.message;
            setErro(mensagem);
            setErroSeq((n) => n + 1);
            if (acao === 'confirmar') setEtapa(propostaEncerrada
                ? 'Proposta encerrada. Faça um novo pedido para tentar novamente.'
                : 'Confirmação não concluída nesta tela. É necessário revisar novamente antes de confirmar.');
            return false;
        } finally {
            delete corpo.email_admin;
            delete corpo.senha_admin;
            clearTimeout(timeout);
            trava.current = null;
            if (montado.current) { setProcessando(false); definirOcupado(false); }
        }
    }

    const formulario = dados.formulario ?? {};
    const campos = formulario.campos ?? [];
    const risco = ['leitura', 'escrita', 'exclusao'].includes(dados.risco) ? dados.risco : 'exclusao';
    const bloqueado = ocupado || !!token || edicaoPendente || encerrado;
    function alterarCampo(nome, valor) {
        if (bloqueado) return;
        setValores((anteriores) => ({ ...anteriores, [nome]: valor,
            ...(formulario.ficha && nome === 'catalogo_item_id' ? { profissional_id: '', inicio: '' } : {}) }));
        if (nome === 'modo' && formulario.itens_estorno && valor !== 'itens') {
            setQuantidades(Object.fromEntries(formulario.itens_estorno.map((item) => [item.item_id,
                String(valor === 'tudo_menos_prontuario' && item.origem === 'Prontuário' ? 0 : item.restante)])));
        }
    }
    // Atalho da ficha (Remarcar, Trocar serviço, Mudar status): esta proposta sai sem gravar e a outra ficha abre, sem IA.
    async function abrirOutra(tool) {
        if (!abrirFormulario || ocupado || !formulario.ficha?.agendamento_id) return;
        if (await executar('cancelar', null, { silencioso: true })) abrirFormulario(tool, formulario.ficha.agendamento_id);
    }
    // O erro aparece num lugar só: no campo (com o resumo no topo do card) ou, se não é de campo, numa linha do card.
    const errosDeCampo = campos.filter((c) => c.erro).length;
    const mensagemErro = erro || formulario.erro || '';
    const aguardando = !!token && !encerrado;
    // Erro: o chat volta ao TOPO do card e o foco vai para o aviso (senão ele fica escondido e ninguém percebe).
    useEffect(() => {
        if (!erroSeq && !formulario.erro) return;
        cartao.current?.scrollIntoView({ block: 'start' });
        avisoErro.current?.focus({ preventScroll: true });
    }, [erroSeq, formulario.erro]);
    // Card finalizado: vira uma tira pequena (1 linha, no máximo meia largura), para o chat não ficar com um buraco.
    if (encerrado && fechamento) {
        const marca = { sucesso: '✓', erro: '!', neutro: '–' }[fechamento.tipo];
        return <>
            <section ref={cartao} className={`card-tira card-tira--${fechamento.tipo}`} aria-label="Confirmação de ação">
                <span className="card-tira__marca" aria-hidden="true">{marca}</span>
                <span className="card-tira__texto">{formulario.titulo || 'Ação proposta'} · {fechamento.texto}</span>
                <span className="card-tira__hora">{fechamento.hora}</span>
            </section>
            {fechamento.tipo === 'erro' && mensagemErro && <div ref={avisoErro} tabIndex={-1} className="card-erro"><Aviso tipo="erro" role="alert">{mensagemErro}</Aviso></div>}
        </>;
    }
    return <section ref={cartao} className={`card-confirmacao card--risco-${risco}${aguardando ? ' card--aguardando' : ''}${encerrado ? ' card--encerrado' : ''}`}
        aria-label="Confirmação de ação" aria-busy={processando} style={{ overflowWrap: 'anywhere' }}>
        <div className="card-overline"><span>AÇÃO PROPOSTA</span><span className="card-risco">{risco}</span></div>
        <strong className="card-titulo">{formulario.titulo || 'Confirmar a ação'}</strong>
        {dados.mensagem && <p>{dados.mensagem}</p>}
        {mensagemErro && <div ref={avisoErro} tabIndex={-1} className="card-erro">{errosDeCampo > 0
            ? <ResumoErros quantidade={errosDeCampo} container={cartao} />
            : <Aviso tipo="erro" role="alert">{mensagemErro}</Aviso>}</div>}
        <dl className="card-registro" style={{ gridTemplateColumns: 'minmax(0, 1fr) minmax(0, 2fr)' }} hidden={!(formulario.resumo ?? []).length && (campos.length > 0 || !Object.keys(dados.argumentos ?? {}).length)}>
            {(formulario.resumo ?? []).map((item, i) => <div key={`resumo-${i}`} style={{ display: 'contents' }}><dt>{item.rotulo}</dt><dd>{exibir(item.valor)}</dd></div>)}
            {(campos.length ? [] : Object.entries(dados.argumentos ?? {})).map(([nome, valor], i) =>
                <div key={i} style={{ display: 'contents' }}><dt>{nome}</dt><dd>{exibir(valor)}</dd></div>)}
        </dl>
        {(formulario.avisos ?? []).map((aviso, i) => <Aviso key={i} tipo="atencao" className="msg msg--aviso">{exibir(aviso)}</Aviso>)}
        <ItensLote itens={formulario.itens} />
        {formulario.ficha && abrirFormulario && !encerrado && (formulario.ficha.atalhos ?? []).length > 0 && <div className="ficha-atalhos">
            <span>Prefere outra ação com este agendamento?</span>
            {formulario.ficha.atalhos.map((atalho) => <Botao key={atalho.tool} variante="fantasma" disabled={ocupado} onClick={() => abrirOutra(atalho.tool)}>{atalho.rotulo}</Botao>)}
        </div>}
        <form ref={form} className="card-form" onSubmit={(evento) => { evento.preventDefault(); executar('revisar'); }}>
            <CamposProposta campos={formulario.ficha ? campos.filter((c) => !['profissional_id', 'inicio'].includes(c.nome)) : campos} valores={valores} bloqueado={ocupado || !!token || edicaoPendente || encerrado}
                alterar={alterarCampo} />
            {formulario.itens_estorno && <ItensEstorno itens={formulario.itens_estorno} quantidades={quantidades} bloqueado={bloqueado}
                alterar={(id, quantidade) => { if (!bloqueado) { setQuantidades((anteriores) => ({ ...anteriores, [id]: quantidade })); setValores((anteriores) => ({ ...anteriores, modo: 'itens' })); } }} />}
            {formulario.ficha && <CalendarioAgendamento ficha={formulario.ficha} valores={valores} bloqueado={ocupado || !!token || edicaoPendente || encerrado}
                selecionar={(selecao) => executar('revisar', selecao)} erros={campos.filter((c) => ['inicio', 'profissional_id'].includes(c.nome)).map((c) => c.erro)} />}
        </form>
        {formulario.reautenticar && !encerrado && <Reautenticacao key={formulario.tipo_autorizacao || 'admin'}
            ref={autorizacao} tipo={formulario.tipo_autorizacao} bloqueado={ocupado || edicaoPendente} />}
        <p className="card-etapa" role="status">{processando ? 'Processando…' : etapa}</p>
        {!encerrado && <div className="acoes">
            {!token && !edicaoPendente && !loteComErro && <Botao variante="primario" className="btn-revisar" disabled={ocupado} onClick={() => executar('revisar')}>Revisar</Botao>}
            {(token || edicaoPendente) && !loteComErro && (!(formulario.itens?.length) || campos.length > 0 || formulario.reautenticar) && <Botao variante="secundario" className="btn-editar" disabled={ocupado} onClick={() => executar('editar')}>Editar</Botao>}
            {!loteComErro && <Botao variante={token && precisaSegurar ? 'perigo' : token ? 'primario' : 'secundario'} className="btn-confirmar" disabled={ocupado || !token}
                segurar={!!token && precisaSegurar} aoSoltarCedo={() => setToast({ tipo: 'atencao', texto: SOLTOU_CEDO })}
                onClick={() => executar('confirmar')}>Confirmar</Botao>}
            <Botao variante="fantasma" className="btn-cancelar" disabled={ocupado} onClick={() => executar('cancelar')}>Cancelar</Botao>
        </div>}
        {toast && <Toast tipo={toast.tipo} aoFechar={() => setToast(null)}>{toast.texto}</Toast>}
    </section>;
}
