import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import Workspace from '../Layouts/Workspace';
import Aviso from '../Components/ui/Aviso';
import Botao from '../Components/ui/Botao';
import { csrf } from '../Components/usarCartao';

const SETORES = [['', 'Nenhum'], ['clinica', 'Clínica'], ['petshop', 'Petshop']];

// Tudo é conferido de novo nas rotas JSON (dono, Administrador, tenant, senha); aqui só se monta a tela.
// O PIN gerado e a senha ficam só no estado deste componente: nada vai para props, armazenamento do navegador ou outra resposta.
async function enviar(url, metodo, corpo) {
    const resp = await fetch(url, {
        method: metodo, credentials: 'same-origin', cache: 'no-store',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        ...(corpo === undefined ? {} : { body: JSON.stringify(corpo) }),
    }).catch(() => null);
    if (resp && (resp.status === 401 || resp.status === 419)) { window.location.assign('/login'); }
    const dados = resp ? await resp.json().catch(() => ({})) : {};
    return { ok: !!resp && resp.ok, dados };
}

function ChaveConta({ u, aoMudar }) {
    const ativo = u.ativo !== false;
    const [ocupado, setOcupado] = useState(false);
    return <button type="button" className="chave-conta" role="switch" aria-checked={ativo} disabled={ocupado} aria-label={`Conta de ${u.nome}: ${ativo ? 'ativa' : 'inativa'}`}
        onClick={async () => { setOcupado(true); await aoMudar(!ativo); setOcupado(false); }}>
        <span className="chave-conta__trilho" aria-hidden="true"><span className="chave-conta__bola" /></span>
        <span>{ativo ? 'Ativo' : 'Inativo'}</span>
    </button>;
}

function Linha({ u, ehDono, ehAdmin, eu, rotulosPapeis, mostrar, recarregar, abrirGerar }) {
    const [setor, setSetor] = useState(u.supervisor_setor || '');
    const [ocupado, setOcupado] = useState(false);
    const ativo = u.ativo !== false;
    useEffect(() => setSetor(u.supervisor_setor || ''), [u.supervisor_setor]);

    async function salvarSetor() {
        setOcupado(true);
        const { ok, dados } = await enviar(`/supervisao/supervisores/${u.id}`, 'POST', { setor: setor === '' ? null : setor });
        setOcupado(false);
        // A frase vem do servidor (ex.: "Sem mudança: o usuário já estava nesse setor.").
        mostrar(ok ? `${u.nome}: ${dados.mensagem || 'Supervisor atualizado.'}` : (dados.mensagem || 'Não consegui salvar. Nada foi gravado.'), ok ? 'sucesso' : 'erro');
        if (ok) recarregar();
    }
    async function revogar() {
        setOcupado(true);
        const { ok, dados } = await enviar(`/supervisao/pins/${u.id}/revogar`, 'POST', {});
        setOcupado(false);
        mostrar(ok ? `${dados.mensagem || 'PIN revogado.'} A pessoa não autoriza até ganhar outro.` : (dados.mensagem || 'Não consegui revogar o PIN. Nada foi gravado.'), ok ? 'sucesso' : 'erro');
        if (ok) recarregar();
    }
    async function mudarConta(novo) {
        const { ok, dados } = await enviar(`/supervisao/usuarios/${u.id}/ativo`, 'POST', { ativo: novo });
        mostrar(ok ? (dados.mensagem || 'Conta atualizada.') : (dados.mensagem || 'Não consegui alterar a conta. Nada foi gravado.'), ok ? 'sucesso' : 'erro');
        // Recarrega sempre: mostra o estado real do servidor (se falhou, o interruptor volta ao que era).
        recarregar();
    }

    let tdSetor;
    if (u.papel === 'dono') tdSetor = 'Todos (dono)';
    else if (u.papel === 'admin') tdSetor = 'Não autoriza (Administrador)';
    else if (ehDono && ativo) tdSetor = <div className="campo">
        <label className="sr-only" htmlFor={`setor-${u.id}`}>Setor que {u.nome} supervisiona</label>
        <select id={`setor-${u.id}`} value={setor} onChange={(e) => setSetor(e.target.value)}>{SETORES.map(([v, t]) => <option key={v} value={v}>{t}</option>)}</select>
    </div>;
    else tdSetor = u.supervisor_setor === 'clinica' ? 'Clínica' : u.supervisor_setor === 'petshop' ? 'Petshop' : 'Nenhum';

    // O Administrador e o dono veem a equipe toda; o PIN é só de quem pode ter. Conta inativa não recebe PIN nem setor.
    const podePin = ativo && ((ehAdmin && u.pode_pin) || (ehDono && (u.id === eu || (u.papel !== 'dono' && u.papel !== 'admin' && u.supervisor_setor))));

    return <tr className={ativo ? undefined : 'linha-inativa'}>
        <td>{u.nome}</td>
        <td>{rotulosPapeis[u.papel] || u.papel}</td>
        <td>{tdSetor}</td>
        <td>{u.pode_pin ? (u.tem_pin ? 'Gerado' : 'Sem PIN') : '—'}</td>
        <td>{(!ehAdmin || !u.pode_ativar)
            ? <span className={`estado-conta${ativo ? '' : ' estado-conta--inativo'}`}>{ativo ? 'Ativo' : 'Inativo'}</span>
            : <ChaveConta u={u} aoMudar={mudarConta} />}</td>
        <td><div className="supervisao-acoes">
            {ehDono && ativo && u.papel !== 'dono' && u.papel !== 'admin' && <Botao variante="secundario" disabled={ocupado} aria-label={`Salvar o setor de ${u.nome}`} onClick={salvarSetor}>Salvar setor</Botao>}
            {podePin && <Botao variante="secundario" aria-label={`Gerar novo PIN para ${u.nome}`} onClick={() => abrirGerar(u)}>Gerar novo PIN</Botao>}
            {podePin && u.tem_pin && <Botao variante="perigo" disabled={ocupado} aria-label={`Revogar o PIN de ${u.nome}`} onClick={revogar}>Revogar PIN</Botao>}
        </div></td>
    </tr>;
}

export default function Supervisao({ ehDono, ehAdmin, rotulosPapeis }) {
    const { usuario } = usePage().props;
    const [usuarios, setUsuarios] = useState(null);
    const [aviso, setAviso] = useState(null);
    const [alvo, setAlvo] = useState(null);
    const [pin, setPin] = useState(null);
    const [gerando, setGerando] = useState(false);
    const senha = useRef(null);
    const caixaPin = useRef(null);
    const montado = useRef(true);

    const mostrar = (texto, tipo) => setAviso({ texto, tipo });
    async function carregar() {
        const { ok, dados } = await enviar('/supervisao/supervisores', 'GET');
        if (!montado.current) return;
        if (!ok) { mostrar(dados.mensagem || 'Não consegui carregar a equipe. Tente novamente.', 'erro'); return; }
        setUsuarios(dados.usuarios || []);
    }
    useEffect(() => {
        montado.current = true;
        carregar();
        // Saiu da página sem "Já anotei": o código e a senha não ficam na tela guardada para o Voltar.
        // flushSync: o PIN precisa sair do DOM antes de a página ir para o cache do navegador (Voltar).
        const limpar = () => { flushSync(() => setPin(null)); if (senha.current) senha.current.value = ''; };
        window.addEventListener('pagehide', limpar);
        return () => { montado.current = false; window.removeEventListener('pagehide', limpar); };
    }, []);
    useEffect(() => { if (pin) caixaPin.current?.focus(); }, [pin]);

    function abrirGerar(u) { setPin(null); setAlvo(u); setTimeout(() => senha.current?.focus(), 0); }
    function cancelar() { if (senha.current) senha.current.value = ''; setAlvo(null); }

    async function gerar(e) {
        e.preventDefault();
        if (!alvo || gerando) return;
        setGerando(true);
        const { ok, dados } = await enviar(`/supervisao/pins/${alvo.id}/gerar`, 'POST', { senha_atual: senha.current.value });
        setGerando(false);
        // A senha não fica na tela depois do envio, com acerto ou erro.
        senha.current.value = '';
        if (!ok || typeof dados.pin !== 'string') { mostrar(dados.mensagem || 'Não consegui gerar o PIN. Nada foi gravado.', 'erro'); senha.current.focus(); return; }
        mostrar(dados.mensagem || 'PIN gerado.', 'sucesso');
        setPin({ para: alvo.nome, codigo: dados.pin });
        setAlvo(null);
        carregar();
    }

    return <Workspace titulo="Supervisão">
        <header className="page-header">
            <p className="eyebrow">Gestão da equipe</p>
            <h1>Supervisão</h1>
            <p className="intro">{ehDono
                ? 'Marque quem supervisiona cada setor e gere ou revogue o PIN de autorização (o seu e o dos supervisores). O supervisor do setor e o dono autorizam estornos só com o PIN, que aparece uma vez só, aqui.'
                : 'Gere ou revogue o PIN de autorização do dono e dos supervisores de setor. O código aparece uma vez só, aqui, para você entregar à pessoa.'}</p>
        </header>

        <div className="supervisao-aviso" role="status" aria-live="polite">{aviso && <Aviso tipo={aviso.tipo}>{aviso.texto}</Aviso>}</div>

        {pin && <div className="pin-gerado" role="alert">
            <p>PIN de {pin.para}:</p>
            <p className="pin-gerado__codigo">{pin.codigo}</p>
            <p>Anote e entregue à pessoa agora: este código não aparece de novo. Um código novo invalida o anterior.</p>
            <Botao ref={caixaPin} variante="primario" onClick={() => setPin(null)}>Já anotei</Botao>
        </div>}

        {alvo && <form className="painel" noValidate onSubmit={gerar}>
            <p className="campo-info gerar-pin-para">Gerar um PIN novo para {alvo.nome}. O PIN anterior deixa de valer.</p>
            <div className="campo">
                <label htmlFor="senha-admin">Sua senha de login</label>
                <input type="password" id="senha-admin" ref={senha} autoComplete="current-password" maxLength={255} required />
            </div>
            <div className="painel__acoes">
                <Botao type="submit" variante="primario" disabled={gerando}>Gerar novo PIN</Botao>
                <Botao variante="secundario" onClick={cancelar}>Cancelar</Botao>
            </div>
        </form>}

        <section className="supervisao-secao" aria-labelledby="titulo-supervisores">
            <h2 id="titulo-supervisores">{ehDono ? 'Supervisores de setor' : 'PIN de autorização'}</h2>
            <p>{ehDono
                ? 'Desmarcar ou trocar o setor zera o PIN da pessoa. O dono não é marcado: ele já autoriza em qualquer setor. Gerar PIN pede a sua senha de login.'
                : 'Gerar pede a sua senha de login. Revogar zera o PIN: a pessoa deixa de autorizar até ganhar outro.'}</p>
            {usuarios === null
                ? <div className="carregando" role="status"><span>Consultando…</span><div className="carregando__barra" /></div>
                : <div className="tabela-rolagem" id="tabela-supervisores"><table>
                    <thead><tr><th scope="col">Nome</th><th scope="col">Papel</th><th scope="col">Setor que supervisiona</th><th scope="col">PIN</th><th scope="col">Conta</th><th scope="col">Ações</th></tr></thead>
                    <tbody>{usuarios.map((u) => <Linha key={u.id} u={u} ehDono={ehDono} ehAdmin={ehAdmin} eu={usuario.id} rotulosPapeis={rotulosPapeis}
                        mostrar={mostrar} recarregar={carregar} abrirGerar={abrirGerar} />)}</tbody>
                </table></div>}
        </section>
    </Workspace>;
}
