import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import Workspace from '../Layouts/Workspace';
import Aviso from '../Components/ui/Aviso';
import Botao from '../Components/ui/Botao';
import { csrf, pedir } from '../Components/usarCartao';

const TIPOS = { consulta: 'Consulta', retorno: 'Retorno', vacinacao: 'Vacinação', exame: 'Exame', cirurgia: 'Cirurgia', internacao: 'Internação', emergencia: 'Emergência' };
const CAMPOS = [['anamnese', 'Anamnese', 'Queixa do tutor e histórico do animal.'], ['exame_fisico', 'Exame físico', 'Achados do exame.'],
    ['diagnostico', 'Diagnóstico', 'Diagnóstico ou suspeita.'], ['conduta', 'Conduta', 'Tratamento, prescrição e orientações.']];
const v = (x) => (x ? x : '—');

const enviar = (url, corpo) => pedir(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify(corpo) });

// CRMV e senha: só nos inputs (refs). Nunca em estado, props, URL ou armazenamento. A senha é limpa logo no envio; o CRMV não é segredo (aparece no avatar).
function Conferencia({ rotulo, textoSegurar, ocupado, aoConfirmar, aoCancelar, children }) {
    const crmv = useRef(null);
    const senha = useRef(null);
    const confirmar = async () => {
        const dados = { crmv: crmv.current?.value ?? '', senha: senha.current?.value ?? '' };
        if (senha.current) senha.current.value = '';
        await aoConfirmar(dados);
    };
    return <div className="prontuario-conferencia" role="group" aria-label={rotulo}>
        {children}
        <div className="campo"><label htmlFor="conf-crmv">Seu CRMV</label><input id="conf-crmv" ref={crmv} type="text" autoComplete="off" maxLength={40} /></div>
        <div className="campo"><label htmlFor="conf-senha">Sua senha de login</label><input id="conf-senha" ref={senha} type="password" autoComplete="current-password" maxLength={255} /></div>
        <p className="campo-info">Segure o botão por 2 segundos. Se soltar antes, nada é feito.</p>
        <div className="painel__acoes">
            <Botao variante="primario" segurar disabled={ocupado} onClick={confirmar}>{textoSegurar}</Botao>
            <Botao variante="fantasma" disabled={ocupado} onClick={aoCancelar}>Voltar</Botao>
        </div>
    </div>;
}

export default function Prontuario({ prontuario, pet, agendamento, tipos, permissoes }) {
    const novo = prontuario === null;
    const editavel = permissoes.editar;
    const [form, setForm] = useState(() => ({
        tipo: prontuario?.tipo ?? 'consulta', anamnese: prontuario?.anamnese ?? '', exame_fisico: prontuario?.exame_fisico ?? '',
        diagnostico: prontuario?.diagnostico ?? '', conduta: prontuario?.conduta ?? '', entrada_em: prontuario?.entrada_em ?? '', alta_em: prontuario?.alta_em ?? '',
    }));
    // Versão salva: assinar só é permitido sem alterações pendentes (o servidor assina o que está gravado, não o que está na tela).
    const [base, setBase] = useState(form);
    const sujo = !novo && JSON.stringify(form) !== JSON.stringify(base);
    const [aviso, setAviso] = useState(null);
    const [ocupado, setOcupado] = useState(false);
    const [assinando, setAssinando] = useState(false);
    const [adendando, setAdendando] = useState(false);
    const [textoAdendo, setTextoAdendo] = useState('');

    const muda = (campo) => (e) => setForm({ ...form, [campo]: e.target.value });
    const mostrar = (tipo, texto) => setAviso({ tipo, texto });

    async function acao(fn, aoSucesso) {
        setOcupado(true);
        setAviso(null);
        try { aoSucesso(await fn()); } catch (e) { mostrar('erro', e.message); } finally { setOcupado(false); }
    }

    const salvar = () => acao(
        () => (novo ? enviar('/prontuarios', { ...form, pet_id: pet.id, agendamento_id: agendamento?.id ?? null }) : enviar(`/prontuarios/${prontuario.id}`, form)),
        (r) => { if (novo && r.url) window.location.assign(r.url); else { setBase(form); mostrar('sucesso', r.mensagem); } },
    );
    const assinar = (dados) => acao(() => enviar(`/prontuarios/${prontuario.id}/assinar`, dados),
        (r) => { setAssinando(false); mostrar('sucesso', r.mensagem); router.reload(); });
    const adendar = (dados) => acao(() => enviar(`/prontuarios/${prontuario.id}/adendos`, { ...dados, texto: textoAdendo }),
        (r) => { setAdendando(false); setTextoAdendo(''); mostrar('sucesso', r.mensagem); router.reload(); });

    return <Workspace titulo="Prontuário">
        <header className="page-header">
            <p className="eyebrow">Atendimento</p>
            <h1>{novo ? 'Novo prontuário' : 'Prontuário'} · {pet.nome}</h1>
            <p className="intro">
                {pet.tutor ? `Tutor: ${pet.tutor}. ` : ''}{agendamento ? `Atendimento de ${agendamento.horario}${agendamento.servico ? ` (${agendamento.servico})` : ''}. ` : 'Sem agendamento vinculado. '}
                {pet.arquivado ? 'Este pet está arquivado. ' : ''}
                {!novo && <span className={`status-badge ${prontuario.assinado ? 'status--sucesso' : 'status--expirado'}`}>{prontuario.assinado ? 'Assinado' : 'Rascunho'}</span>}
            </p>
        </header>
        <p className="acoes-topo"><a className="botao" href={`/pets/${pet.id}`}><span aria-hidden="true">←</span> Ficha do pet</a></p>

        {aviso && <Aviso tipo={aviso.tipo} role={aviso.tipo === 'erro' ? 'alert' : 'status'}>{aviso.texto}</Aviso>}

        <form className="prontuario-form" onSubmit={(e) => { e.preventDefault(); if (editavel) salvar(); }}>
            <div className="campo">
                <label htmlFor="p-tipo">Tipo do atendimento</label>
                <select id="p-tipo" value={form.tipo} onChange={muda('tipo')} disabled={!editavel || assinando}>{tipos.map((t) => <option key={t} value={t}>{TIPOS[t] ?? t}</option>)}</select>
            </div>
            {form.tipo === 'internacao' && <div className="prontuario-form__linha">
                <div className="campo"><label htmlFor="p-entrada">Entrada</label><input id="p-entrada" type="datetime-local" value={form.entrada_em} onChange={muda('entrada_em')} disabled={!editavel || assinando} /></div>
                <div className="campo"><label htmlFor="p-alta">Alta</label><input id="p-alta" type="datetime-local" value={form.alta_em} onChange={muda('alta_em')} disabled={!editavel || assinando} /></div>
            </div>}
            {CAMPOS.map(([k, rotulo, dica]) => <div className="campo" key={k}>
                <label htmlFor={`p-${k}`}>{rotulo}</label>
                <textarea id={`p-${k}`} rows={4} maxLength={10000} value={form[k]} onChange={muda(k)} disabled={!editavel || assinando} placeholder={editavel ? dica : ''} />
            </div>)}
            {editavel && <div className="painel__acoes">
                <Botao type="submit" variante="primario" disabled={ocupado || assinando}>{novo ? 'Salvar rascunho' : 'Salvar alterações'}</Botao>
                {!novo && !assinando && permissoes.assinar && <Botao variante="secundario" disabled={ocupado || sujo} onClick={() => setAssinando(true)}>Assinar prontuário…</Botao>}
            </div>}
            {editavel && sujo && <p className="campo-info" role="status">Há alterações não salvas. Salve antes de assinar: o que é assinado é a versão gravada.</p>}
        </form>

        {assinando && permissoes.assinar && <Conferencia rotulo="Assinar o prontuário" textoSegurar="Assinar o prontuário" ocupado={ocupado} aoConfirmar={assinar} aoCancelar={() => setAssinando(false)}>
            <Aviso tipo="atencao" titulo="Assinar é definitivo.">Depois de assinado, o prontuário não pode mais ser alterado: só dá para registrar um adendo. Salve as alterações antes de assinar.</Aviso>
        </Conferencia>}

        {!novo && prontuario.assinado && <section className="bloco" aria-labelledby="titulo-assinatura">
            <h2 id="titulo-assinatura">Assinatura</h2>
            <dl className="ficha"><dt>Assinado por</dt><dd>{v(prontuario.assinado_por)}</dd><dt>Em</dt><dd className="mono">{v(prontuario.assinado_em)}</dd></dl>
        </section>}

        {!novo && prontuario.assinado && <section className="bloco" aria-labelledby="titulo-adendos">
            <h2 id="titulo-adendos">Adendos</h2>
            {prontuario.adendos.length === 0 ? <p className="vazio">Nenhum adendo registrado.</p>
                : <ul className="prontuario__adendos-lista" role="list">{prontuario.adendos.map((a, i) => <li key={i} className="prontuario">
                    <p className="prontuario__nota"><span className="mono">{a.em}</span> · {v(a.autor)}</p>
                    <p className="prontuario__texto">{a.texto}</p>
                </li>)}</ul>}
            {permissoes.adendar && !adendando && <p><Botao variante="secundario" disabled={ocupado} onClick={() => setAdendando(true)}>Registrar adendo…</Botao></p>}
            {permissoes.adendar && adendando && <Conferencia rotulo="Registrar adendo" textoSegurar="Registrar adendo" ocupado={ocupado} aoConfirmar={adendar} aoCancelar={() => setAdendando(false)}>
                <div className="campo"><label htmlFor="p-adendo">Texto do adendo</label>
                    <textarea id="p-adendo" rows={4} maxLength={5000} value={textoAdendo} onChange={(e) => setTextoAdendo(e.target.value)} /></div>
            </Conferencia>}
        </section>}
    </Workspace>;
}
