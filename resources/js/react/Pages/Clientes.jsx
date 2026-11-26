import { useCallback, useEffect, useRef, useState } from 'react';
import Workspace from '../Layouts/Workspace';
import Confirmacao from '../Components/Confirmacao';
import Aviso from '../Components/ui/Aviso';
import Botao from '../Components/ui/Botao';

const ESPECIES = { cao: 'Cão', gato: 'Gato', ave: 'Ave', roedor: 'Roedor', reptil: 'Réptil', outro: 'Outro' };
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const dataBr = (iso) => (iso ? iso.split('-').reverse().join('/') : null);
const peso = (g) => (g == null ? null : g >= 1000 ? `${(g / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 2 })} kg` : `${g} g`);

async function pedir(url, opcoes = {}) {
    const resposta = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', ...(opcoes.headers ?? {}) }, ...opcoes });
    if (resposta.status === 401 || resposta.status === 419) { window.location.assign('/login'); throw new Error('Sessão expirada.'); }
    const dados = await resposta.json().catch(() => null);
    if (!resposta.ok) throw new Error(dados?.mensagem || (resposta.status === 403 ? 'Você não tem permissão para esta operação.' : 'Não foi possível concluir. Tente novamente.'));
    return dados;
}

// Um pet, recolhido em uma linha; a setinha abre o cadastro dele (editar, observações, arquivar com segurar 2 s, reativar).
function LinhaDoPet({ pet, aberto, alternar, foco, pode, ocupado, abrirCartao }) {
    const id = `pet-${pet.id}`;
    const detalhes = [['Raça', pet.raca], ['Sexo', pet.sexo === 'macho' ? 'Macho' : pet.sexo === 'femea' ? 'Fêmea' : null], ['Nascimento', dataBr(pet.data_nascimento)],
        ['Peso', peso(pet.peso_gramas)], ['Microchip', pet.microchip], ['Alertas', pet.alertas], ['Observações', pet.observacoes],
        ['Arquivado em', dataBr(pet.arquivado_em)]].filter(([, v]) => v);
    return <li id={id} className={`pet-item${pet.arquivado ? ' pet-item--arquivado' : ''}${foco ? ' pet-item--foco' : ''}`}>
        <button type="button" className="pet-linha" aria-expanded={aberto} aria-controls={`${id}-corpo`} onClick={alternar}>
            <span className="pet-seta" aria-hidden="true">▸</span>
            <span className="pet-nome">{pet.nome}</span>
            <span className="pet-especie">{ESPECIES[pet.especie] ?? pet.especie}</span>
            {pet.arquivado && <span className="etiqueta-arquivado">Arquivado</span>}
        </button>
        {aberto && <div id={`${id}-corpo`} className="pet-corpo">
            <dl className="pet-dados">{detalhes.map(([r, v]) => <div key={r}><dt>{r}</dt><dd>{v}</dd></div>)}</dl>
            <div className="pet-acoes">
                {!pet.arquivado && pode.atualizar_pet && <Botao variante="secundario" disabled={ocupado} onClick={() => abrirCartao('atualizar_pet', { id: pet.id })}>Editar pet</Botao>}
                {!pet.arquivado && pode.arquivar_pet && <Botao variante="fantasma" disabled={ocupado} onClick={() => abrirCartao('arquivar_pet', { id: pet.id })}>Arquivar pet</Botao>}
                {pet.arquivado && pode.reativar_pet && <Botao variante="secundario" disabled={ocupado} onClick={() => abrirCartao('reativar_pet', { id: pet.id })}>Reativar pet</Botao>}
            </div>
        </div>}
    </li>;
}

export default function Clientes({ clienteInicial = null, petInicial = null, pode = {} }) {
    const [busca, setBusca] = useState('');
    const [lista, setLista] = useState(null);
    const [erro, setErro] = useState('');
    const [clienteId, setClienteId] = useState(clienteInicial);
    const [cliente, setCliente] = useState(null);
    const [abertos, setAbertos] = useState(() => new Set(petInicial ? [petInicial] : []));
    const [foco, setFoco] = useState(petInicial);
    const [cartao, setCartao] = useState(null);
    const [aviso, setAviso] = useState('');
    const [mostrarArq, setMostrarArq] = useState(false);
    const [ocupado, setOcupado] = useState(false);
    const trava = useRef(null);
    const atual = useRef(clienteInicial);
    const montado = useRef(true);
    const rolar = useRef(petInicial);
    const topoDetalhe = useRef(null);
    useEffect(() => { montado.current = true; return () => { montado.current = false; }; }, []);

    const carregarLista = useCallback(async (texto, sinal) => {
        try {
            const dados = await pedir(`/clientes/dados?busca=${encodeURIComponent(texto)}`, { signal: sinal });
            if (montado.current) { setLista(dados.clientes); setErro(''); }
        } catch (e) { if (e.name !== 'AbortError' && montado.current) setErro(e.message); }
    }, []);
    // Digitar filtra com uma pequena espera; a primeira carga é na hora.
    useEffect(() => {
        const controller = new AbortController();
        const espera = setTimeout(() => carregarLista(busca, controller.signal), lista === null ? 0 : 300);
        return () => { clearTimeout(espera); controller.abort(); };
    }, [busca, carregarLista]);

    const carregarCliente = useCallback(async (id) => {
        try {
            const dados = await pedir(`/clientes/dados?cliente=${id}`);
            // Resposta velha (clicou noutro cliente depois) é descartada.
            if (montado.current && atual.current === id) { setCliente(dados.cliente); setErro(''); }
        } catch (e) { if (montado.current) { setErro(e.message); setCliente(null); } }
    }, []);
    useEffect(() => { atual.current = clienteId; if (clienteId) carregarCliente(clienteId); else setCliente(null); }, [clienteId, carregarCliente]);

    // Buscar pelo pet: ao abrir o dono, a página rola até o pet e o realça por instantes.
    useEffect(() => {
        if (!cliente || !rolar.current) return undefined;
        const petAlvo = rolar.current;
        rolar.current = null;
        if (cliente.pets.some((p) => p.id === petAlvo && p.arquivado)) setMostrarArq(true);
        let fim;
        const quadro = requestAnimationFrame(() => {
            const alvo = document.getElementById(`pet-${petAlvo}`);
            if (!alvo) return;
            const reduzido = matchMedia('(prefers-reduced-motion: reduce)').matches;
            alvo.scrollIntoView({ block: 'center', behavior: reduzido ? 'auto' : 'smooth' });
            alvo.querySelector('button')?.focus({ preventScroll: true });
            fim = setTimeout(() => setFoco(null), 2500);
        });
        return () => { cancelAnimationFrame(quadro); clearTimeout(fim); };
    }, [cliente]);

    function abrirCliente(id, petId = null) {
        rolar.current = petId;
        setFoco(petId);
        setAbertos(petId ? new Set([petId]) : new Set());
        setCartao(null);
        setAviso('');
        setClienteId(id);
        history.replaceState(null, '', `/clientes?cliente=${id}${petId ? `&pet=${petId}` : ''}`);
        if (id === clienteId) carregarCliente(id);
        else setCliente(null);
        setTimeout(() => topoDetalhe.current?.scrollIntoView({ block: 'start' }), 0);
    }

    async function abrirCartao(tool, { id, tutor_id } = {}) {
        if (ocupado || trava.current) return;
        const controller = new AbortController();
        trava.current = controller;
        setOcupado(true);
        setAviso('');
        try {
            const dados = await pedir('/agente/formulario', { method: 'POST', signal: controller.signal,
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify({ tool, id, tutor_id }) });
            if (!montado.current) return;
            if (dados?.tipo !== 'confirmacao_pendente' || typeof dados.proposta !== 'string') throw new Error('Não foi possível abrir o formulário. Tente novamente.');
            setCartao(dados);
        } catch (e) { if (montado.current) setErro(e.name === 'AbortError' ? 'Demorou demais. Tente novamente.' : e.message); }
        finally { trava.current = null; if (montado.current) setOcupado(false); }
    }

    // O card avisa o desfecho por esta função: depois de gravar (ou cancelar), a lista e o cadastro abertos são lidos de novo.
    const aoMensagem = (texto) => {
        setAviso(texto);
        carregarLista(busca);
        if (clienteId) carregarCliente(clienteId);
    };

    const ativos = cliente?.pets.filter((p) => !p.arquivado) ?? [];
    const arquivados = cliente?.pets.filter((p) => p.arquivado) ?? [];
    const alternar = (id) => setAbertos((atual) => { const novo = new Set(atual); if (novo.has(id)) novo.delete(id); else novo.add(id); return novo; });
    const pets = (itens) => <ul className="pet-pilha">{itens.map((p) => <LinhaDoPet key={p.id} pet={p} aberto={abertos.has(p.id)} alternar={() => alternar(p.id)}
        foco={foco === p.id} pode={pode} ocupado={ocupado} abrirCartao={abrirCartao} />)}</ul>;

    return <Workspace titulo="Clientes">
        <header className="page-header">
            <p className="eyebrow">Organização</p>
            <h1>Clientes</h1>
            <p className="intro">Tutores e seus pets em um só lugar. Busque pelo nome do cliente, do pet ou pelo microchip; toda gravação passa pelo card de revisão.</p>
        </header>
        <div className="clientes">
            <section className="clientes-lista" aria-label="Lista de clientes">
                <div className="clientes-topo">
                    <label htmlFor="busca-cliente">Buscar por cliente ou por pet</label>
                    {pode.cadastrar_cliente_com_pets && <Botao variante="primario" disabled={ocupado} onClick={() => { setClienteId(null); history.replaceState(null, '', '/clientes'); abrirCartao('cadastrar_cliente_com_pets'); }}>Novo cliente</Botao>}
                </div>
                <input id="busca-cliente" type="search" className="clientes-busca" maxLength={60} value={busca} placeholder="Nome do cliente, do pet ou microchip" onChange={(e) => setBusca(e.target.value)} />
                <p className="campo-info" role="status">{lista === null ? 'Carregando…' : lista.length === 0 ? 'Nenhum cliente encontrado.' : `${lista.length} cliente(s)${lista.length >= 30 ? ' (mostrando os 30 primeiros: refine a busca)' : ''}.`}</p>
                <ul className="clientes-itens">
                    {(lista ?? []).map((c) => {
                        const achados = c.pets.filter((p) => p.achado);
                        return <li key={c.id}><button type="button" className="cliente-item" aria-current={c.id === clienteId ? 'true' : undefined}
                            onClick={() => abrirCliente(c.id, achados[0]?.id ?? null)}>
                            <strong>{c.nome}</strong>
                            {c.telefone && <span className="cliente-sub">{c.telefone}</span>}
                            <span className="cliente-sub">{c.pets.length === 0 ? 'Sem pets' : c.pets.map((p) => p.nome + (p.arquivado ? ' (arquivado)' : '')).join(', ')}</span>
                            {achados.length > 0 && <span className="cliente-achado">Pet encontrado: {achados.map((p) => p.nome).join(', ')}</span>}
                        </button></li>;
                    })}
                </ul>
            </section>
            <section className="clientes-detalhe" aria-label="Cadastro do cliente" ref={topoDetalhe}>
                {erro && <Aviso tipo="erro" role="alert">{erro}</Aviso>}
                {aviso && <Aviso tipo="info" role="status">{aviso}</Aviso>}
                {cartao && <Confirmacao key={cartao.proposta} proposta={cartao} adicionar={aoMensagem} trava={trava} ocupado={ocupado} definirOcupado={setOcupado}
                    abrirFormulario={(tool, id) => abrirCartao(tool, { id })} />}
                {!cliente && !cartao && <p className="campo-info">Escolha um cliente na lista ou busque pelo nome do pet.</p>}
                {cliente && <article className="cliente-ficha">
                    <header className="cliente-cabeca">
                        <h2>{cliente.nome}</h2>
                        <div className="pet-acoes">
                            {pode.atualizar_tutor && <Botao variante="secundario" disabled={ocupado} onClick={() => abrirCartao('atualizar_tutor', { id: cliente.id })}>Editar cliente</Botao>}
                            {pode.criar_pet && <Botao variante="primario" disabled={ocupado} onClick={() => abrirCartao('criar_pet', { tutor_id: cliente.id })}>Adicionar pet</Botao>}
                        </div>
                    </header>
                    <dl className="pet-dados">{[['Documento', cliente.documento], ['E-mail', cliente.email], ['Telefone', cliente.telefone], ['Endereço', cliente.endereco]]
                        .filter(([, v]) => v).map(([r, v]) => <div key={r}><dt>{r}</dt><dd>{v}</dd></div>)}</dl>
                    <h3>Pets ({ativos.length})</h3>
                    {ativos.length === 0 ? <p className="campo-info">Este cliente não tem pets ativos.</p> : pets(ativos)}
                    {arquivados.length > 0 && <details className="pets-arquivados" open={mostrarArq} onToggle={(e) => setMostrarArq(e.currentTarget.open)}>
                        <summary>Arquivados ({arquivados.length})</summary>
                        {pets(arquivados)}
                    </details>}
                </article>}
            </section>
        </div>
    </Workspace>;
}
