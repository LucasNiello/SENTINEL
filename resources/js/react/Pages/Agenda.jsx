import { router } from '@inertiajs/react';
import { useState } from 'react';
import Workspace from '../Layouts/Workspace';
import Confirmacao from '../Components/Confirmacao';
import Aviso from '../Components/ui/Aviso';
import Botao from '../Components/ui/Botao';
import CaixaCarregando from '../Components/ui/CaixaCarregando';
import usarCartao from '../Components/usarCartao';

// Estado com dois canais: a cor do selo e o texto dele.
const SELOS = { agendado: ['Agendado', ''], confirmado: ['Confirmado', 'status--info'], realizado: ['Realizado', 'status--sucesso'],
    cancelado: ['Cancelado', 'status--erro'], faltou: ['Faltou', 'status--expirado'] };
const abre = (s) => ['agendado', 'confirmado'].includes(s);

function Selo({ status }) {
    const [texto, classe] = SELOS[status] ?? [status, ''];
    return <span className={`status-badge ${classe}`.trim()}>{texto}</span>;
}

export default function Agenda({ erro, dias, visao, dia, setor, soUmSetor, anterior, proximo, hoje, total, noTeto, podeMudarStatus, podeAgendar, temAtender = false }) {
    const { cartao, setCartao, abrindo, ocupado, setOcupado, erro: erroCartao, trava, abrir } = usarCartao();
    const [aviso, setAviso] = useState('');

    // Links de período: mantêm visão, dia e setor; a página inteira é lida de novo no servidor.
    const link = (troca) => {
        const q = new URLSearchParams(Object.entries({ visao, dia, setor, ...troca }).filter(([, v]) => v));
        return `/agenda?${q}`;
    };
    const semana = visao === 'semana';
    const aoMensagem = (texto) => { setAviso(texto); router.reload(); };
    // Atendimento do veterinário: abre o prontuário do agendamento (ou o já existente). Só vem em linhas do próprio veterinário.
    const atender = (l) => l.atender && <a className="botao btn-editar-linha" aria-label={`${l.atender.prontuario_id ? 'Abrir o prontuário' : 'Atender'} ${l.pet}, ${l.hora}`}
        href={l.atender.prontuario_id ? `/prontuarios/${l.atender.prontuario_id}` : `/prontuarios/novo?pet=${l.atender.pet_id}&agendamento=${l.id}`}>{l.atender.prontuario_id ? 'Prontuário' : 'Atender'}</a>;
    const mudarStatus = (l) => <Botao variante="fantasma" className="btn-editar-linha" disabled={ocupado} aria-label={`Mudar o status do horário de ${l.pet}, ${l.hora}`}
        onClick={() => abrir('alterar_status_agendamento', { id: l.id })}>Mudar status</Botao>;

    return <Workspace titulo="Agenda">
        <header className="page-header">
            <p className="eyebrow">{soUmSetor ? 'Banho e tosa' : 'Clínica e banho e tosa'}</p>
            <h1>Agenda</h1>
            <p className="intro">{soUmSetor ? 'Horários do banho e tosa.' : 'Horários da clínica e do banho e tosa.'} Agendar e mudar o status abrem o card de revisão; nada é gravado sem confirmar.</p>
        </header>

        <nav className="barra-periodo" aria-label="Período da agenda">
            <div className="segmentos" role="group" aria-label="Visão">
                <a href={link({ visao: 'dia' })} aria-current={!semana ? 'true' : undefined}>Dia</a>
                <a href={link({ visao: 'semana' })} aria-current={semana ? 'true' : undefined}>Semana</a>
            </div>
            {soUmSetor
                ? <p className="setor-fixo">Agenda de <strong>banho e tosa</strong></p>
                : <div className="segmentos" role="group" aria-label="Setor">
                    <a href={link({ setor: null })} aria-current={setor === '' ? 'true' : undefined}>Tudo</a>
                    <a href={link({ setor: 'clinica' })} aria-current={setor === 'clinica' ? 'true' : undefined}>Clínica</a>
                    <a href={link({ setor: 'petshop' })} aria-current={setor === 'petshop' ? 'true' : undefined}>Banho e tosa</a>
                </div>}
            <div className="passos">
                <a className="botao" href={link({ dia: anterior })}><span aria-hidden="true">←</span> {semana ? 'Semana anterior' : 'Dia anterior'}</a>
                <a className="botao" href={link({ dia: hoje })}>Hoje</a>
                <a className="botao" href={link({ dia: proximo })}>{semana ? 'Próxima semana' : 'Próximo dia'} <span aria-hidden="true">→</span></a>
            </div>
            <form className="ir-para" method="GET" action="/agenda">
                <input type="hidden" name="visao" value={visao} />
                {setor !== '' && <input type="hidden" name="setor" value={setor} />}
                <div className="campo">
                    <label htmlFor="dia">Ir para o dia</label>
                    <input type="date" id="dia" name="dia" defaultValue={dia} />
                </div>
                <button type="submit">Ver</button>
            </form>
        </nav>

        {podeAgendar && <p className="acoes-topo"><Botao variante="primario" disabled={ocupado} onClick={() => abrir('agendar_atendimento')}>Agendar atendimento</Botao></p>}

        {erro && <Aviso tipo="erro" role="alert">{erro}</Aviso>}
        {erroCartao && <Aviso tipo="erro" role="alert">{erroCartao}</Aviso>}
        {aviso && <Aviso tipo="info" role="status">{aviso}</Aviso>}
        {abrindo && <CaixaCarregando texto="Abrindo o formulário…" />}
        {cartao && <Confirmacao key={cartao.proposta} proposta={cartao} adicionar={aoMensagem} trava={trava} ocupado={ocupado} definirOcupado={setOcupado}
            abrirFormulario={(tool, id) => abrir(tool, { id })} />}

        <p className="tabela-resumo"><span>{total} horário(s) no período</span></p>
        {noTeto && <Aviso tipo="atencao">A agenda mostra só os 200 primeiros horários do período. Os últimos dias podem estar incompletos: veja um dia de cada vez ou filtre por setor.</Aviso>}

        {dias.map((bloco, i) => <section key={bloco.rotulo} className="dia-agenda" aria-labelledby={`dia-${i}`}>
            <h2 id={`dia-${i}`}>{bloco.semana} <span className="mono">{bloco.rotulo}</span></h2>
            {bloco.linhas.length === 0
                ? <p className="vazio">Nenhum horário neste dia.</p>
                : setor === 'petshop'
                    ? <ol className="agenda-petshop" aria-label="Atendimentos de banho e tosa">
                        {bloco.linhas.map((l) => <li key={l.id}>
                            <h3>{l.hora} · {l.servico}</h3>
                            <dl className="ficha">
                                <dt>Pet</dt><dd>{l.pet}</dd>
                                <dt>Tutor</dt><dd>{l.tutor}</dd>
                                <dt>Profissional</dt><dd>{l.profissional}</dd>
                                <dt>Situação</dt><dd><Selo status={l.status} /></dd>
                            </dl>
                            {atender(l)}
                            {podeMudarStatus && abre(l.status) && mudarStatus(l)}
                        </li>)}
                    </ol>
                    : <div className="tabela-rolagem tabela-empilha agenda-mobile" tabIndex={0} role="region" aria-label={`Agenda de ${bloco.rotulo}`}>
                        <table>
                            <thead><tr><th scope="col">Horário</th><th scope="col">Pet</th><th scope="col">Tutor</th><th scope="col">Serviço</th><th scope="col">Profissional</th>
                                <th scope="col">Setor</th><th scope="col">Situação</th>{(podeMudarStatus || temAtender) && <th scope="col">Ação</th>}</tr></thead>
                            <tbody>{bloco.linhas.map((l) => <tr key={l.id}>
                                <td data-rotulo="Horário">{l.hora}</td>
                                <td data-rotulo="Pet">{l.pet}</td>
                                <td data-rotulo="Tutor">{l.tutor}</td>
                                <td data-rotulo="Serviço">{l.servico}</td>
                                <td data-rotulo="Profissional">{l.profissional}</td>
                                <td data-rotulo="Setor">{l.setor === 'clinica' ? 'Clínica' : 'Banho e tosa'}</td>
                                <td data-rotulo="Situação"><Selo status={l.status} /></td>
                                {(podeMudarStatus || temAtender) && <td data-rotulo="Ação">{atender(l)}{podeMudarStatus && abre(l.status) && mudarStatus(l)}</td>}
                            </tr>)}</tbody>
                        </table>
                    </div>}
        </section>)}
    </Workspace>;
}
