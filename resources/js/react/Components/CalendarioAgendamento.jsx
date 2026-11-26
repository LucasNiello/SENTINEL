import { useEffect, useId, useRef, useState } from 'react';

const meses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const data = (s) => { const [a, m, d] = s.split('-').map(Number); return new Date(a, m - 1, d); };
const extenso = (s) => `${data(s).getDate()} de ${meses[data(s).getMonth()]}`;

export default function CalendarioAgendamento({ ficha, valores, bloqueado, selecionar, erros = [] }) {
    const prefixo = useId();
    const [hoje] = useState(() => iso(new Date()));
    const [dia, setDia] = useState(() => ficha.dia && ficha.dia >= hoje ? ficha.dia : hoje);
    const [mes, setMes] = useState(() => dia.slice(0, 7));
    const [profissional, setProfissional] = useState(() => String(valores.profissional_id ?? ''));
    const [busca, setBusca] = useState('');
    const [disponibilidade, setDisponibilidade] = useState(null);
    const [erro, setErro] = useState('');
    const [tentativa, setTentativa] = useState(0);
    const [quem, setQuem] = useState(null);
    const servico = String(valores.catalogo_item_id || ficha.servico_id || '');
    const servicoAnterior = useRef(servico);
    const chave = JSON.stringify([servico, profissional, mes, dia, ficha.agendamento_id, tentativa]);
    const carregando = !!servico && disponibilidade?.chave !== chave && !erro;

    useEffect(() => {
        const controller = new AbortController();
        let ativo = true;
        setErro('');
        setQuem(null);
        setDisponibilidade(null);
        if (servicoAnterior.current !== servico) {
            servicoAnterior.current = servico;
            // Um profissional do serviço anterior pode pertencer a outro setor.
            if (profissional) {
                setProfissional('');
                return () => { ativo = false; controller.abort(); };
            }
        }
        if (!servico) return () => { ativo = false; controller.abort(); };
        const timeout = setTimeout(() => controller.abort(), 15000);
        async function buscar(parametros) {
            const query = new URLSearchParams(Object.entries({ servico, profissional, ignorar: ficha.agendamento_id || '', ...parametros }).filter(([, v]) => v !== ''));
            const resposta = await fetch(`/agenda/disponibilidade?${query}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal });
            if (resposta.status === 401 || resposta.status === 419) { window.location.assign('/login'); throw new Error('Sessão expirada.'); }
            const dados = await resposta.json().catch(() => null);
            if (!resposta.ok || !dados || dados.tipo !== 'disponibilidade') throw new Error(dados?.mensagem || 'Não consegui carregar os horários.');
            return dados;
        }
        // Carregar o mês primeiro permite descartar um profissional de outro setor.
        (async () => {
            try {
                const mensal = await buscar({ mes });
                if (!ativo) return;
                if (profissional && !(mensal.profissionais ?? []).some((p) => String(p.id) === profissional)) {
                    setProfissional('');
                    return;
                }
                const diario = await buscar({ dia });
                if (ativo) setDisponibilidade({ chave, mensal, diario });
            } catch (falha) {
                if (ativo) setErro(falha.name === 'AbortError' || falha instanceof TypeError ? 'Não foi possível carregar a disponibilidade. Tente novamente.' : falha.message);
            } finally { clearTimeout(timeout); }
        })();
        return () => { ativo = false; clearTimeout(timeout); controller.abort(); };
    }, [chave]);

    const mensal = disponibilidade?.chave === chave ? disponibilidade.mensal : {};
    const diario = disponibilidade?.chave === chave ? disponibilidade.diario : {};
    const profissionais = mensal?.profissionais ?? [];
    const dias = mensal?.dias ?? {};
    const horarios = diario?.horarios ?? [];
    const comVagas = diario?.modelo === 'blocos' && diario.mostra_vagas;
    const nome = (id) => profissionais.find((p) => String(p.id) === String(id))?.nome || `#${id}`;
    const [ano, numeroMes] = mes.split('-').map(Number);
    const vazios = (new Date(ano, numeroMes - 1, 1).getDay() + 6) % 7;
    const amanhaData = data(hoje); amanhaData.setDate(amanhaData.getDate() + 1);
    const amanha = iso(amanhaData);
    const escolherDia = (novo) => { setErro(''); setDia(novo); setMes(novo.slice(0, 7)); setQuem(null); };
    const andarMes = (passo) => { setErro(''); setMes(iso(new Date(ano, numeroMes - 1 + passo, 1)).slice(0, 7)); setQuem(null); };
    const confirmarEscolha = (p, horario) => {
        if (bloqueado || carregando || erro || !dias[dia] || horario.vagas < 1) return;
        setQuem(null);
        selecionar({ profissional_id: String(p), inicio: horario.inicio });
    };
    const escolherHorario = (horario) => {
        const livres = (horario.profissionais ?? []).filter((p) => p.vagas > 0);
        if (profissional) confirmarEscolha(profissional, horario);
        else if (livres.length === 1) confirmarEscolha(livres[0].id, horario);
        else setQuem({ ...horario, profissionais: livres });
    };
    return <fieldset className="ficha-agenda" disabled={bloqueado} aria-label="Calendário de agendamento" aria-busy={carregando} style={{ border: 0, padding: 0, margin: 0 }}>
        <fieldset className="ficha-passo">
            <legend>1. Profissional</legend>
            <label className="ficha-rotulo" htmlFor={`${prefixo}-busca`}>Buscar profissional</label>
            <input id={`${prefixo}-busca`} className="ficha-busca" type="search" maxLength={60} value={busca} onChange={(e) => setBusca(e.target.value)} />
            <label className="ficha-rotulo" htmlFor={`${prefixo}-profissional`}>Profissional</label>
            <select id={`${prefixo}-profissional`} className="ficha-profissional" value={profissional} disabled={carregando || !!erro} onChange={(e) => { setErro(''); setProfissional(e.target.value); }}>
                <option value="">{mensal?.setor === 'petshop' ? 'Qualquer atendente' : 'Qualquer veterinário'}</option>
                {profissionais.filter((p) => p.nome.toLowerCase().includes(busca.trim().toLowerCase()) || String(p.id) === profissional).map((p) => <option key={p.id} value={p.id}>{p.nome}</option>)}
            </select>
        </fieldset>
        <fieldset className="ficha-passo">
            <legend>2. Dia</legend>
            <div className="ficha-dias-rapidos">{[[hoje, 'Hoje'], [amanha, 'Amanhã']].map(([d, label]) => <button key={d} type="button" disabled={carregando || (d in dias && !dias[d])} onClick={() => escolherDia(d)}>{label}</button>)}</div>
            <div className="ficha-mes">
                <div className="ficha-mes-topo"><button type="button" aria-label="Mês anterior" disabled={mes <= hoje.slice(0, 7)} onClick={() => andarMes(-1)}>‹</button><strong className="ficha-mes-titulo" aria-live="polite">{meses[numeroMes - 1]} de {ano}</strong><button type="button" aria-label="Próximo mês" onClick={() => andarMes(1)}>›</button></div>
                <div className="ficha-semana" aria-hidden="true">{['seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'].map((s) => <span key={s}>{s}</span>)}</div>
                <div className="ficha-mes-grade">
                    {Array.from({ length: vazios }, (_, i) => <span key={`vazio-${i}`} />)}
                    {Object.keys(dias).sort().map((d) => <button key={d} type="button" className="ficha-dia" disabled={!dias[d] || carregando} aria-pressed={d === dia} aria-label={`${extenso(d)}, ${dias[d] ? 'com' : 'sem'} vaga`} onClick={() => escolherDia(d)}>{data(d).getDate()}</button>)}
                </div>
            </div>
        </fieldset>
        <fieldset className="ficha-passo">
            <legend>3. Horário</legend>
            <p className="ficha-estado" role="status">{!servico ? 'Escolha o serviço para ver os horários.' : carregando ? 'Carregando disponibilidade…' : erro || `${extenso(dia)}: ${horarios.filter((h) => h.vagas > 0).length} horário(s) livre(s). Clicar num horário já revisa.`}</p>
            {erro && <button type="button" onClick={() => { setErro(''); setTentativa((n) => n + 1); }}>Tentar novamente</button>}
            {/* Erro de horário/profissional: só aqui (o resumo do card é o alerta). Não repete a mensagem do estado acima. */}
            {erros.filter((e) => e && e !== erro).map((e, i) => <p key={i} className="campo-erro">{e}</p>)}
            <div className="ficha-horarios">{horarios.map((h) => <button key={h.inicio} type="button" className="ficha-horario mono" disabled={carregando || !dias[dia] || h.vagas < 1} aria-current={valores.inicio === h.inicio && (!profissional || valores.profissional_id === profissional) ? 'true' : undefined} onClick={() => escolherHorario(h)}>
                {h.hora}{comVagas ? ` · ${h.vagas < 1 ? 'lotado' : `${h.vagas} vaga(s)`}` : diario.modelo === 'blocos' ? ` · ${h.vagas < 1 ? 'ocupado' : 'livre'}` : ''}
            </button>)}</div>
            {quem && <div className="ficha-quem"><p>Quem atende às {quem.hora}?</p><div className="ficha-quem-lista">{quem.profissionais.map((p) => <button key={p.id} type="button" className="ficha-horario" onClick={() => confirmarEscolha(p.id, quem)}>{nome(p.id)}{comVagas ? ` · ${p.vagas} vaga(s)` : ''}</button>)}</div></div>}
            {valores.inicio && <p className="ficha-estado">Selecionado: {valores.inicio} · {nome(valores.profissional_id)}. {bloqueado ? 'Use Editar para alterar.' : ''}</p>}
        </fieldset>
    </fieldset>;
}
