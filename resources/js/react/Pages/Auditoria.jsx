import { useEffect, useRef, useState } from 'react';
import Workspace from '../Layouts/Workspace';
import Aviso from '../Components/ui/Aviso';
import Botao from '../Components/ui/Botao';

// Os dados vêm do /auditoria/registros (só admin, só o tenant do usuário, mascarados no servidor). Nada do registro vira HTML:
// o React escapa tudo que é texto.
const RESULTADOS = { sucesso: 'Sucesso', erro: 'Erro', recusado: 'Recusado', negado: 'Negado', cancelado: 'Cancelado', expirado: 'Expirado', revisao_com_erro: 'Revisão com erro' };
const ORIGENS = { agente: 'Agente', formulario: 'Formulário', manual: 'Manual' };
const ENTIDADES = { Lancamento: 'Lançamento', Cliente: 'Cliente', Fornecedor: 'Fornecedor', Funcionario: 'Funcionário', NotaFiscal: 'Nota fiscal', CategoriaLancamento: 'Categoria', AuditLog: 'Auditoria', Lixeira: 'Lixeira' };
const legivel = (t) => (t ? String(t).charAt(0).toUpperCase() + String(t).slice(1).replaceAll('_', ' ') : '');
const registro = (r) => (r.entidade_id ? `${ENTIDADES[r.entidade_tipo] || legivel(r.entidade_tipo)} #${r.entidade_id}` : '');
const FILTRO_VAZIO = { de: '', ate: '', usuario: '', tool: '', resultado: '', grupo: '' };

async function buscarJson(url) {
    const resp = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).catch(() => null);
    if (!resp) throw new Error('Falha ao contatar o servidor.');
    if (resp.status === 401 || resp.status === 419) { window.location.assign('/login'); return null; }
    const dados = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(dados.mensagem || 'Não consegui consultar a auditoria agora. Tente de novo.');
    return dados;
}

// Proposto (o que a IA ou o formulário pediu) × confirmado (o que foi executado) × anterior (o "antes").
function Valores({ titulo, valores }) {
    const vazio = valores === null || valores === undefined || (typeof valores === 'object' && !Object.keys(valores).length);
    return <div>
        <h3>{titulo}</h3>
        {vazio ? <p>Nada registrado.</p>
            : typeof valores !== 'object' ? <p>{String(valores)}</p>
                : <div className="tabela-rolagem"><table><tbody>{Object.entries(valores).map(([campo, valor]) => <tr key={campo}>
                    <td>{legivel(campo)}</td><td>{valor !== null && typeof valor === 'object' ? JSON.stringify(valor) : valor}</td>
                </tr>)}</tbody></table></div>}
    </div>;
}

export default function Auditoria({ usuarios, resultados, tools }) {
    const [filtros, setFiltros] = useState(FILTRO_VAZIO);
    const [dados, setDados] = useState(null);
    const [erro, setErro] = useState('');
    const [carregando, setCarregando] = useState(false);
    const [detalhe, setDetalhe] = useState(null);
    const [abrindo, setAbrindo] = useState(null);
    const quadro = useRef(null);
    const ultimo = useRef(0);
    const filtrosRef = useRef(filtros);
    filtrosRef.current = filtros;

    const parametros = () => new URLSearchParams(Object.entries(filtrosRef.current).filter(([, v]) => v));

    async function carregar(pagina) {
        const numero = ++ultimo.current;
        const p = parametros();
        p.set('pagina', pagina);
        setCarregando(true);
        setErro('');
        try {
            const r = await buscarJson(`/auditoria/registros?${p}`);
            if (r && numero === ultimo.current) setDados(r);
        } catch (e) { if (numero === ultimo.current) { setDados(null); setErro(e.message); } }
        finally { if (numero === ultimo.current) setCarregando(false); }
    }
    useEffect(() => { carregar(1); }, []);

    async function abrir(id) {
        setAbrindo(id);
        try {
            const r = await buscarJson(`/auditoria/registros/${encodeURIComponent(id)}`);
            if (r) { setDetalhe({ r }); setTimeout(() => quadro.current?.focus(), 0); }
        } catch (e) { setDetalhe({ erro: e.message }); }
        finally { setAbrindo(null); }
    }

    const campo = (nome) => ({ id: `filtro-${nome}`, name: nome, value: filtros[nome], onChange: (e) => setFiltros({ ...filtros, [nome]: e.target.value }) });
    const r = detalhe?.r;

    return <Workspace titulo="Auditoria">
        <header className="page-header">
            <p className="eyebrow">Rastreabilidade</p>
            <h1>Auditoria</h1>
            <p className="intro">Veja quem pediu, o que foi executado e o resultado. Dados pessoais aparecem mascarados.</p>
        </header>

        <form className="painel" aria-label="Filtros da auditoria" aria-busy={carregando} onSubmit={(e) => { e.preventDefault(); setDetalhe(null); carregar(1); }}>
            <div className="campo"><label htmlFor="filtro-de">De</label><input type="date" {...campo('de')} /></div>
            <div className="campo"><label htmlFor="filtro-ate">Até</label><input type="date" {...campo('ate')} /></div>
            <div className="campo"><label htmlFor="filtro-usuario">Usuário</label>
                <select {...campo('usuario')}><option value="">Todos</option>{usuarios.map((u) => <option key={u.id} value={u.id}>{u.nome}</option>)}</select></div>
            <div className="campo"><label htmlFor="filtro-tool">Ação</label>
                <select {...campo('tool')}><option value="">Todas</option>{tools.map((t) => <option key={t} value={t}>{legivel(t)}</option>)}</select></div>
            <div className="campo"><label htmlFor="filtro-resultado">Resultado</label>
                <select {...campo('resultado')}><option value="">Todos</option>{resultados.map((x) => <option key={x} value={x}>{legivel(x)}</option>)}</select></div>
            <div className="campo"><label htmlFor="filtro-grupo">Mostrar</label>
                <select {...campo('grupo')}><option value="">Tudo</option><option value="desfechos">Só desfechos</option><option value="revisoes">Só revisões com erro</option></select></div>
            <div className="painel__acoes">
                <Botao type="submit" variante="primario" disabled={carregando}>Filtrar</Botao>
                <Botao variante="secundario" onClick={() => window.location.assign(`/auditoria/exportar?${parametros()}`)}>Exportar CSV</Botao>
            </div>
        </form>

        {carregando && <div className="carregando" role="status"><span>Consultando…</span><div className="carregando__barra" /></div>}
        {erro && <Aviso tipo="erro" role="alert">{erro}</Aviso>}

        <div aria-live="polite">
            {dados && (dados.registros.length === 0 ? <p className="empty-state">Nenhum registro encontrado.</p>
                : <div className="tabela-rolagem" tabIndex={0} role="region" aria-label="Registros de auditoria"><table>
                    <thead><tr>{['Data', 'Usuário', 'Papel', 'Ação', 'Registro', 'Resultado', 'Origem', 'Detalhe'].map((t) => <th key={t} scope="col">{t}</th>)}</tr></thead>
                    <tbody>{dados.registros.map((x) => <tr key={x.id}>
                        <td>{x.data}</td><td>{x.usuario}</td><td>{legivel(x.papel)}</td><td>{legivel(x.tool)}</td><td>{registro(x)}</td>
                        <td><span className={`status-badge status--${Object.hasOwn(RESULTADOS, x.resultado) ? x.resultado : 'neutro'}`}>{RESULTADOS[x.resultado] || legivel(x.resultado)}</span></td>
                        <td>{ORIGENS[x.origem] || legivel(x.origem)}</td>
                        <td><Botao variante="fantasma" className="btn-ver" disabled={abrindo === x.id} aria-label={`Ver o registro ${x.id}`} onClick={() => abrir(x.id)}>Ver</Botao></td>
                    </tr>)}</tbody>
                </table></div>)}
        </div>
        {dados && dados.paginas > 1 && <div className="paginacao">
            <Botao variante="secundario" disabled={dados.pagina <= 1 || carregando} onClick={() => carregar(dados.pagina - 1)}>Anterior</Botao>
            <span>Página {dados.pagina} de {dados.paginas} ({dados.total} registros)</span>
            <Botao variante="secundario" disabled={dados.pagina >= dados.paginas || carregando} onClick={() => carregar(dados.pagina + 1)}>Próxima</Botao>
        </div>}
        <div className="sr-only" aria-live="assertive">{dados ? `${dados.total} registro(s)` : ''}</div>

        {detalhe && <section ref={quadro} className="detalhe" tabIndex={-1} aria-labelledby="detalhe-titulo">
            {detalhe.erro ? <Aviso tipo="erro" role="alert">{detalhe.erro}</Aviso> : <>
                <h2 id="detalhe-titulo">Registro {r.id}</h2>
                <dl>{[['Data', r.data], ['Usuário', r.usuario], ['Papel', legivel(r.papel)], ['Ação', legivel(r.tool)], ['Registro', registro(r) || '—'],
                    ['Resultado', RESULTADOS[r.resultado] || legivel(r.resultado)], ['Origem', ORIGENS[r.origem] || legivel(r.origem) || '—'],
                    ['Mensagem', r.mensagem || '—'], ['Autorizado por', r.autorizado_por || '—'],
                    ['Tempo da IA', r.tempo_ia_ms !== null && r.tempo_ia_ms !== undefined ? `${r.tempo_ia_ms} ms` : '—']]
                    .map(([rotulo, valor]) => <div key={rotulo} style={{ display: 'contents' }}><dt>{rotulo}</dt><dd>{valor ?? '—'}</dd></div>)}</dl>
                <Valores titulo="Proposto" valores={r.proposto} />
                <Valores titulo="Confirmado" valores={r.confirmado} />
                <Valores titulo="Anterior" valores={r.anterior} />
            </>}
        </section>}
    </Workspace>;
}
