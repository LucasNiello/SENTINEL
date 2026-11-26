import { useForm, usePage } from '@inertiajs/react';
import Workspace from '../Layouts/Workspace';
import Aviso from '../Components/ui/Aviso';
import Botao from '../Components/ui/Botao';

// Painel do dono. A aba Clínica é a única que grava: POST /painel/clinica (o servidor valida, audita e redireciona de volta).
function FormularioClinica({ valores, maxVagas }) {
    const { errors, flash } = usePage().props;
    const form = useForm({ abre: valores.abre ?? '', fecha: valores.fecha ?? '', intervalo_inicio: valores.intervalo_inicio ?? '', intervalo_fim: valores.intervalo_fim ?? '', vagas: valores.vagas ?? '' });
    const campo = (nome, rotulo, extra = {}) => <div className="campo">
        <label htmlFor={nome}>{rotulo}</label>
        <input id={nome} name={nome} value={form.data[nome]} onChange={(e) => form.setData(nome, e.target.value)} {...extra} />
    </div>;

    return <>
        {flash?.ok && <Aviso tipo="sucesso" role="status">{flash.ok}</Aviso>}
        {errors?.clinica && <Aviso tipo="erro" role="alert">{errors.clinica}</Aviso>}
        <p>A agenda da clínica e do banho e tosa usa estes horários. {valores.personalizada ? 'Valores definidos pelo dono.' : 'Ainda não alterado: valem os valores padrão do sistema.'}</p>
        <form onSubmit={(e) => { e.preventDefault(); form.post('/painel/clinica', { preserveScroll: true }); }}>
            <div className="painel-campos">
                {campo('abre', 'Abre às', { type: 'time', required: true })}
                {campo('fecha', 'Fecha às', { type: 'time', required: true })}
                {campo('intervalo_inicio', 'Início do intervalo', { type: 'time' })}
                {campo('intervalo_fim', 'Fim do intervalo', { type: 'time' })}
                {campo('vagas', 'Vagas por bloco (por veterinário)', { type: 'number', min: 1, max: maxVagas, step: 1, required: true })}
            </div>
            <p>Deixe o intervalo em branco se a clínica não para no meio do dia. Os agendamentos que já existem não mudam.</p>
            <Botao type="submit" variante="primario" disabled={form.processing}>Salvar configuração</Botao>
        </form>
    </>;
}

export default function Painel({ abas, aba, indicadores, valores, maxVagas, funcionarios, tutores, total, servicos }) {
    return <Workspace titulo="Painel do Dono">
        <div className="painel-dono">
            <header className="page-header">
                <p className="eyebrow">Gestão da clínica</p>
                <h1>Painel do Dono</h1>
            </header>
            <nav aria-label="Seções do Painel do Dono">
                {Object.entries(abas).map(([chave, nome]) => <a key={chave} href={`/painel?aba=${chave}`} aria-current={aba === chave ? 'page' : undefined}>{nome}</a>)}
            </nav>
            <section aria-labelledby="painel-titulo">
                <h2 id="painel-titulo">{abas[aba]}</h2>
                {aba === 'dashboard' && <div className="painel-cards">
                    {indicadores.map((c) => <a key={c.titulo} className="painel-card" href={c.link}>
                        <header><svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7"><path d={c.icone} /></svg></header>
                        <h3>{c.titulo}</h3>
                        <p>{c.numero}</p>
                        {c.nota && <small>{c.nota}</small>}
                    </a>)}
                </div>}
                {aba === 'clinica' && <FormularioClinica valores={valores} maxVagas={maxVagas} />}
                {aba === 'funcionarios' && <>
                    <p><a className="botao" href="/supervisao">Supervisores e PINs</a></p>
                    <ul className="painel-lista">
                        {funcionarios.length === 0 ? <li>Nenhum funcionário cadastrado.</li> : funcionarios.map((f) => <li key={`${f.nome}-${f.papel}`}>
                            <strong>{f.nome}</strong> · {f.papel}{f.supervisor ? ` · supervisor de ${f.supervisor === 'clinica' ? 'clínica' : 'petshop'}` : ''}
                        </li>)}
                    </ul>
                </>}
                {aba === 'clientes' && <>
                    <p>Tutores e seus pets ({total} no total). <a href="/clientes">Abrir clientes</a></p>
                    <ul className="painel-lista">
                        {tutores.length === 0 ? <li>Nenhum tutor cadastrado.</li> : tutores.map((t) => <li key={t.id}>
                            <a href={`/tutores/${t.id}`}><strong>{t.nome}</strong></a>
                            {t.pets.length > 0 && <><br />{t.pets.map((p, i) => <span key={p.id}><a href={`/pets/${p.id}`}>{p.nome}</a>{p.especie ? ` (${p.especie})` : ''}{i < t.pets.length - 1 ? ' · ' : ''}</span>)}</>}
                        </li>)}
                    </ul>
                </>}
                {aba === 'petshop' && <>
                    <p><a className="botao" href="/cadastros">Abrir o catálogo</a> <a className="botao" href="/agenda">Agenda do banho e tosa</a></p>
                    <ul className="painel-lista">
                        {servicos.length === 0 ? <li>Nenhum item do petshop no catálogo.</li> : servicos.map((s) => <li key={s.nome}>
                            <strong>{s.nome}</strong> · {s.preco}{s.duracao ? ` · ${s.duracao} minutos` : ''}
                        </li>)}
                    </ul>
                </>}
            </section>
        </div>
    </Workspace>;
}
