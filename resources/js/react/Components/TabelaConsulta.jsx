import { useEffect, useRef, useState } from 'react';
import ExportarConsulta from './ExportarConsulta';
import AvisoConfirmacao from './ui/AvisoConfirmacao';
import Botao from './ui/Botao';
import Toast from './ui/Toast';
import Aviso from './ui/Aviso';

// Data/hora ISO em UTC (ex.: 2019-03-10T03:00:00.000000Z) vira texto brasileiro; à meia-noite local só a data.
const ISO_UTC = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/;
function dataBr(texto) {
    const d = new Date(texto);
    if (Number.isNaN(d.getTime())) return texto;
    const fuso = { timeZone: 'America/Sao_Paulo' };
    const hora = d.toLocaleTimeString('pt-BR', { ...fuso, hour: '2-digit', minute: '2-digit', hour12: false });
    const dia = d.toLocaleDateString('pt-BR', fuso);
    return hora === '00:00' ? dia : `${dia} ${hora}`;
}
const textoCelula = (valor) => valor == null ? '' : typeof valor === 'object' ? JSON.stringify(valor) : (typeof valor === 'string' && ISO_UTC.test(valor)) ? dataBr(valor) : String(valor);

// Texto de cada ação em lote, pela URL que o servidor manda em `selecao`. Ação nova = uma linha aqui.
const ACOES_EM_LOTE = {
    '/agente/agenda/cancelar-lote': { verbo: 'cancelar', singular: 'agendamento', plural: 'agendamentos', botao: 'Cancelar', feito: 'cancelado' },
    // 26/11: remarcar em lote (o servidor propõe o novo horário de cada linha; "novo_horario" é uma coluna da tabela). Não é destrutivo: botão
    // primário e a linha não fica riscada.
    '/agente/agenda/remarcar-lote': { verbo: 'remarcar', singular: 'agendamento', plural: 'agendamentos', botao: 'Remarcar', feito: 'remarcado', variante: 'primario', semRisco: true, rotuloBotao: 'Remarcar selecionados' },
};
const INCERTO = 'Não consegui confirmar o resultado. A ação pode ter sido processada; confira a situação antes de tentar de novo.';
const SOLTOU_CEDO ='Você soltou antes da hora, então não fiz nada. Se quiser, é só segurar de novo.';

// linkDaLinha(linha) → { href, rotulo } e acoesExtras(linha) → [{ tool, rotulo, perigo }] são opcionais (telas Cadastros/Lixeira); o chat não usa.
export default function TabelaConsulta({ consulta, ocupado, abrirFormulario, prepararPedido, adicionar, linkDaLinha, acoesExtras }) {
    const linhas = consulta.resultado;
    const selecao = consulta.selecao && typeof consulta.selecao.url === 'string' ? consulta.selecao : null;
    const acao = selecao ? (ACOES_EM_LOTE[selecao.url] ?? { verbo: 'aplicar a ação em', singular: 'registro', plural: 'registros', botao: selecao.rotulo || 'Confirmar', feito: 'feito' }) : null;
    // Sem cabeçalhos explícitos, usar apenas chaves realmente recebidas.
    const colunas = consulta.colunas?.length ? consulta.colunas
        : [...new Set(linhas.flatMap((linha) => Object.keys(linha)))].filter((nome) => !['acao', 'destaque', 'bloqueado'].includes(nome));
    const temAcoes = !!consulta.editar || linhas.some((linha) => linha.acao?.tool || typeof linha.pedido === 'string' || linkDaLinha?.(linha) || acoesExtras?.(linha)?.length);

    // Seleção em lote (25/11): nada é gravado ao marcar. O envio leva só os ids marcados; o servidor só aceita os da lista.
    const [marcados, setMarcados] = useState(() => new Set());
    const [perguntando, setPerguntando] = useState(false);
    const [enviando, setEnviando] = useState(false);
    const [feitos, setFeitos] = useState(() => new Set());
    const [motivos, setMotivos] = useState({});
    const [final, setFinal] = useState(null); // a mensagem do servidor depois de gravar (fica na tela; o aviso passageiro some)
    const [toast, setToast] = useState(null);
    const montado = useRef(true);
    useEffect(() => () => { montado.current = false; }, []);
    const [invalida, setInvalida] = useState(false);
    const barra = useRef(null);
    const concluido = feitos.size > 0;
    const livres = selecao ? linhas.filter((l) => l.bloqueado !== true && !feitos.has(l.id)) : [];
    const todos = livres.length > 0 && livres.every((l) => marcados.has(l.id));
    const travado = ocupado || enviando || concluido || invalida;
    const n = marcados.size;

    // O botão que tinha o foco sai do DOM ao abrir/fechar a pergunta: o foco vai para a barra, para não cair no body.
    // Só quando a pergunta abre ou fecha pelos botões da própria barra (não ao marcar um checkbox, para não tirar o teclado da tabela).
    const moverFoco = useRef(false);
    useEffect(() => { if (moverFoco.current) { moverFoco.current = false; barra.current?.focus({ preventScroll: true }); } }, [perguntando]);
    const tabela = useRef(null);
    // A barra sai do DOM (sucesso ou lista inválida): o foco volta para a tabela, não cai no body.
    useEffect(() => { if (concluido || invalida) tabela.current?.focus({ preventScroll: true }); }, [concluido, invalida]);
    const mostrarPergunta = (v) => { moverFoco.current = true; setPerguntando(v); };
    const alternar = (id) => {
        const novo = new Set(marcados);
        if (novo.has(id)) novo.delete(id); else novo.add(id);
        setMarcados(novo);
        // A pergunta mostra a contagem: mudou a marcação, a pergunta volta a ser feita do zero.
        setPerguntando(false);
    };
    const alternarTodos = () => { setMarcados(todos ? new Set() : new Set(livres.map((l) => l.id))); setPerguntando(false); };

    async function enviar() {
        if (!n || enviando) return;
        const ids = [...marcados];
        setEnviando(true);
        try {
            const resposta = await fetch(selecao.url, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
                body: JSON.stringify({ ids }),
            });
            if (resposta.status === 401 || resposta.status === 419) { window.location.assign('/login'); return; }
            const dados = await resposta.json().catch(() => null);
            if (!montado.current) return;
            // Resposta que não dá para ler, ou 5xx: pode ter gravado antes de falhar. Nunca dizer "não alterei nada".
            if (!dados || resposta.status >= 500) {
                setToast({ tipo: 'erro', texto: INCERTO });
                return;
            }
            if (resposta.ok && dados.tipo === 'resultado_escrita') {
                setFeitos(new Set(ids));
                setFinal(dados.mensagem || null);
                setMarcados(new Set());
                setPerguntando(false);
                setToast({ tipo: 'sucesso', texto: dados.mensagem || 'Feito.' });
                return;
            }
            // Recusa (tudo ou nada): nada foi gravado. O item que barrou fica apontado na própria linha e desmarcado.
            const erros = dados.erros && typeof dados.erros === 'object' ? dados.erros : {};
            setMotivos(Object.fromEntries(Object.entries(erros).map(([id, motivo]) => [String(id), String(motivo)])));
            setMarcados((atual) => new Set([...atual].filter((id) => !erros[String(id)])));
            setPerguntando(false);
            // Lista que não vale mais (outra consulta, outro usuário): marcar de novo não adianta; é pedir de novo no chat.
            if (['AI_BATCH_LIST_EXPIRED', 'AI_BATCH_IDS_NOT_LISTED'].includes(dados.codigo)) setInvalida(true);
            setToast({ tipo: 'erro', texto: dados.mensagem || 'Não consegui fazer agora, e não alterei nada. Tenta de novo?' });
        } catch {
            // Falha de rede: a gravação pode ter acontecido antes da queda.
            if (montado.current) setToast({ tipo: 'erro', texto: INCERTO });
        } finally {
            if (montado.current) setEnviando(false);
        }
    }

    const celula = (linha, coluna) => {
        if (feitos.has(linha.id) && coluna === 'status') return acao.feito;
        // Remarcar: a linha mostra o horário NOVO e "Remarcado" (senão a tabela parece não ter mudado).
        if (acao?.semRisco && feitos.has(linha.id)) { if (coluna === 'horario') return linha.novo_horario; if (coluna === 'observacao') return 'Remarcado'; }
        if (coluna === 'observacao' && (feitos.has(linha.id) || motivos[String(linha.id)])) return motivos[String(linha.id)] ?? '';
        return textoCelula(linha[coluna]);
    };
    const destacar = Array.isArray(consulta.destacar) ? consulta.destacar : [];
    // A linha que o pedido apontou entra à vista (e pisca uma vez, pelo CSS).
    useEffect(() => { if (destacar.length) tabela.current?.querySelector('.linha-destaque')?.scrollIntoView({ block: 'nearest' }); }, []);
    const classeLinha = (linha) => [
        destacar.includes(linha.id) ? 'linha-destaque' : '',
        linha.destaque === 'aviso' || motivos[String(linha.id)] ? 'linha--aviso' : '',
        selecao && feitos.has(linha.id) && !acao.semRisco ? 'linha-cancelada' : '',
        selecao && linha.bloqueado === true ? 'linha-bloqueada' : '',
    ].filter(Boolean).join(' ') || undefined;
    const quantos = (q) => `${q} ${q === 1 ? acao.singular : acao.plural}`;

    return <>
        {consulta.mensagem && <div className="msg msg--agente"><span className="msg__speaker">Sentinel</span><span>{consulta.mensagem}</span></div>}
        {!linhas.length ? (consulta.mensagem ? null : <div className="msg msg--agente"><span className="msg__speaker">Sentinel</span><span>Nenhum registro encontrado.</span></div>) : <>
            <div className="tabela-resumo">{linhas.length} registro(s) encontrado(s)</div>
            <div ref={tabela} className="tabela-rolagem" tabIndex={0} role="region" aria-label="Resultado da consulta">
                <table>
                    <caption className="sr-only">Resultado da consulta</caption>
                    <thead><tr>
                        {selecao && <th scope="col" className="selecao-celula"><label>
                            <input type="checkbox" checked={todos} disabled={travado || !livres.length} onChange={alternarTodos}
                                aria-label={`Selecionar todos os ${acao.plural} que podem ser marcados`} />
                        </label></th>}
                        {colunas.map((coluna) => <th key={coluna} scope="col">{coluna}</th>)}{temAcoes && <th scope="col">Ação</th>}
                    </tr></thead>
                    <tbody>{linhas.map((linha, indice) => {
                        const tool = linha.acao?.tool || consulta.editar;
                        const rotulo = linha.acao?.rotulo || 'Editar';
                        const bloqueada = linha.bloqueado === true;
                        return <tr key={indice} className={classeLinha(linha)}>
                            {selecao && <td className="selecao-celula"><label>
                                <input type="checkbox" checked={marcados.has(linha.id)} disabled={travado || bloqueada || feitos.has(linha.id)} onChange={() => alternar(linha.id)}
                                    aria-label={`${bloqueada ? 'Não pode ser marcado' : 'Selecionar'}: ${[linha.pet, linha.horario, `${acao.singular} ${linha.id}`].filter(Boolean).join(', ')}`} />
                            </label></td>}
                            {colunas.map((coluna) => <td key={coluna} style={{ whiteSpace: 'pre-wrap' }}>{celula(linha, coluna)}</td>)}
                            {temAcoes && <td>
                                {tool && <button type="button" className={`btn-editar-linha${tool.startsWith('excluir_definitivo_') ? ' btn-danger' : ''}`} disabled={ocupado} aria-label={`${rotulo} registro #${linha.id}`} onClick={() => abrirFormulario(tool, linha.id)}>{rotulo}</button>}
                                {(() => { const l = Number.isInteger(linha.id) ? linkDaLinha?.(linha) : null; return l ? <a className="btn-editar-linha" href={l.href}>{l.rotulo}</a> : null; })()}
                                {(acoesExtras?.(linha) ?? []).map((a) => <button key={a.tool} type="button" className={`btn-editar-linha${a.perigo ? ' btn-danger' : ''}`} disabled={ocupado} aria-label={`${a.rotulo} registro #${linha.id}`} onClick={() => abrirFormulario(a.tool, linha.id)}>{a.rotulo}</button>)}
                                {typeof linha.pedido === 'string' && <button type="button" className="btn-editar-linha" disabled={ocupado} aria-label={`Pedir ao agente o que a mensagem ${linha.id} pede`} onClick={() => prepararPedido(linha.pedido)}>Pedir ao agente</button>}
                            </td>}
                        </tr>;
                    })}</tbody>
                </table>
            </div>
            {selecao && concluido && final && <Aviso tipo="sucesso" role="status">{final}</Aviso>}
            {selecao && invalida && <Aviso tipo="atencao">Essa lista não vale mais. Peça de novo no chat e marque os agendamentos.</Aviso>}
            {selecao && !concluido && !invalida && n > 0 && <div ref={barra} tabIndex={-1} className="selecao-barra" role="region" aria-label="Ação com os marcados">
                {perguntando
                    ? <AvisoConfirmacao pergunta={`Tem certeza que quer ${acao.verbo} ${quantos(n)}?`} rotulo={`${acao.botao} ${quantos(n)}`}
                        ocupado={ocupado || enviando} aoConfirmar={enviar} aoVoltar={() => mostrarPergunta(false)}
                        aoSoltarCedo={() => setToast({ tipo: 'atencao', texto: SOLTOU_CEDO })} />
                    : <>
                        <span className="selecao-barra__conta" role="status">{n} {n === 1 ? 'selecionado' : 'selecionados'}</span>
                        <Botao variante={acao.variante ?? 'perigo'} disabled={ocupado || enviando} onClick={() => mostrarPergunta(true)}>{acao.rotuloBotao ?? `${acao.botao} ${acao.plural}`}</Botao>
                    </>}
            </div>}
            {typeof consulta.exportar === 'string' && consulta.exportar && <ExportarConsulta consulta={consulta.exportar} ocupado={ocupado} adicionar={adicionar} />}
            {toast && <Toast tipo={toast.tipo} aoFechar={() => setToast(null)}>{toast.texto}</Toast>}
        </>}
    </>;
}
