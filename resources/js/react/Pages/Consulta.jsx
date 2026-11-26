import { useEffect, useRef, useState } from 'react';
import Workspace from '../Layouts/Workspace';
import Confirmacao from '../Components/Confirmacao';
import TabelaConsulta from '../Components/TabelaConsulta';
import Aviso from '../Components/ui/Aviso';
import Botao from '../Components/ui/Botao';
import CaixaCarregando from '../Components/ui/CaixaCarregando';
import usarCartao, { csrf, pedir } from '../Components/usarCartao';

// Cadastros e Lixeira (mesma tela). Filtros, RBAC e tenant são conferidos de novo no /agente/consultar; toda gravação passa pelo card.
export default function Consulta({ titulo, intro, entidades, cadastroInicial = null, podeArquivarPet = false, podeReativarPet = false }) {
    const chaves = Object.keys(entidades);
    const [chave, setChave] = useState(cadastroInicial && entidades[cadastroInicial] ? cadastroInicial : chaves[0]);
    const entidade = entidades[chave];
    const [filtros, setFiltros] = useState({});
    const [consulta, setConsulta] = useState(null);
    const [erro, setErro] = useState('');
    const [aviso, setAviso] = useState('');
    const [carregando, setCarregando] = useState(false);
    const { cartao, abrindo, ocupado, setOcupado, erro: erroCartao, trava, abrir } = usarCartao();
    const ultimo = useRef(0);
    const filtrosRef = useRef(filtros);
    filtrosRef.current = filtros;

    async function buscar(mensagemDepois = '') {
        const numero = ++ultimo.current;
        const enviar = Object.fromEntries(Object.entries(filtrosRef.current).filter(([, v]) => String(v).trim() !== '').map(([k, v]) => [k, String(v).trim()]));
        setCarregando(true);
        setErro('');
        try {
            const dados = await pedir('/agente/consultar', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: JSON.stringify({ tool: entidade.consultar, filtros: enviar }) });
            if (numero !== ultimo.current) return;
            if (dados?.tipo !== 'resultado_leitura' || !Array.isArray(dados.resultado)) throw new Error(dados?.mensagem || 'Não consegui fazer a consulta. Tente de novo.');
            setConsulta(dados);
            setAviso([mensagemDepois, typeof dados.aviso === 'string' ? dados.aviso : ''].filter(Boolean).join(' '));
        } catch (e) { if (numero === ultimo.current) { setConsulta(null); setErro(e.message); } }
        finally { if (numero === ultimo.current) setCarregando(false); }
    }

    // Trocar de cadastro zera os filtros e busca de novo; a primeira carga também.
    useEffect(() => { setFiltros({}); filtrosRef.current = {}; setConsulta(null); buscar(); }, [chave]);

    // Depois de gravar (ou cancelar) o card, a lista se atualiza sozinha com os filtros atuais.
    const aoMensagem = (texto) => buscar(texto);

    const linkDaLinha = (linha) => chave === 'pets' ? { href: `/pets/${linha.id}`, rotulo: 'Ver ficha' } : chave === 'tutores' ? { href: `/tutores/${linha.id}`, rotulo: 'Ver ficha' } : null;
    const acoesExtras = (linha) => {
        const extras = [];
        if (chave === 'pets') {
            if (linha.inativado_em) { if (podeReativarPet) extras.push({ tool: 'reativar_pet', rotulo: 'Reativar' }); }
            else if (podeArquivarPet) extras.push({ tool: 'arquivar_pet', rotulo: 'Arquivar' });
        }
        if (entidade.excluir) extras.push({ tool: entidade.excluir, rotulo: 'Excluir', perigo: true });
        return extras;
    };

    return <Workspace titulo={titulo}>
        <header className="page-header">
            <p className="eyebrow">{titulo === 'Lixeira' ? 'Gestão de registros' : 'Organização'}</p>
            <h1>{titulo}</h1>
            <p className="intro">{intro}</p>
        </header>

        <form id="form-consulta" className="painel" aria-label="Filtros" aria-busy={carregando} onSubmit={(e) => { e.preventDefault(); buscar(); }}>
            {chaves.length > 1 && <div className="campo">
                <label htmlFor="entidade">Cadastro</label>
                <select id="entidade" value={chave} onChange={(e) => setChave(e.target.value)}>
                    {chaves.map((k) => <option key={k} value={k}>{entidades[k].rotulo}</option>)}
                </select>
            </div>}
            <div id="filtros" className="filtros">
                {entidade.filtros.map((f) => <div className="campo" key={`${chave}-${f.campo}`}>
                    <label htmlFor={`filtro-${f.campo}`}>{f.rotulo}</label>
                    {f.tipo === 'lista'
                        ? <select id={`filtro-${f.campo}`} name={f.campo} value={filtros[f.campo] ?? ''} onChange={(e) => setFiltros({ ...filtros, [f.campo]: e.target.value })}>
                            <option value="">Todos</option>
                            {Object.entries(f.opcoes).map(([v, t]) => <option key={v} value={v}>{t}</option>)}
                        </select>
                        : <input id={`filtro-${f.campo}`} name={f.campo} type={f.tipo === 'data' ? 'date' : 'text'} maxLength={f.tipo === 'texto' ? 100 : undefined}
                            value={filtros[f.campo] ?? ''} onChange={(e) => setFiltros({ ...filtros, [f.campo]: e.target.value })} />}
                </div>)}
            </div>
            <div className="painel__acoes">
                <Botao type="submit" variante="primario" disabled={carregando}>Buscar</Botao>
                {entidade.criar && <Botao variante="secundario" disabled={ocupado} onClick={() => abrir(entidade.criar)}>Novo</Botao>}
            </div>
        </form>

        {carregando && <CaixaCarregando texto="Consultando…" />}
        {erro && <Aviso tipo="erro" role="alert">{erro}</Aviso>}
        {erroCartao && <Aviso tipo="erro" role="alert">{erroCartao}</Aviso>}
        {abrindo && <CaixaCarregando texto="Abrindo o formulário…" />}
        {cartao && <Confirmacao key={cartao.proposta} proposta={cartao} adicionar={aoMensagem} trava={trava} ocupado={ocupado} definirOcupado={setOcupado}
            abrirFormulario={(tool, id) => abrir(tool, { id })} />}
        <div id="historico" aria-live="polite">
            {consulta && <TabelaConsulta key={`${chave}-${consulta.exportar ?? ''}-${consulta.resultado.length}`} consulta={consulta} ocupado={ocupado} abrirFormulario={(tool, id) => abrir(tool, { id })}
                adicionar={(texto) => setAviso(texto)} prepararPedido={() => {}} linkDaLinha={linkDaLinha} acoesExtras={acoesExtras} />}
            {aviso && <Aviso tipo="info" role="status">{aviso}</Aviso>}
        </div>
    </Workspace>;
}
