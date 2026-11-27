import { router } from '@inertiajs/react';
import { useState } from 'react';
import Workspace from '../Layouts/Workspace';
import Confirmacao from '../Components/Confirmacao';
import Aviso from '../Components/ui/Aviso';
import Botao from '../Components/ui/Botao';
import CaixaCarregando from '../Components/ui/CaixaCarregando';
import usarCartao from '../Components/usarCartao';

const ESPECIES = { cao: 'Cão', gato: 'Gato', ave: 'Ave', roedor: 'Roedor', reptil: 'Réptil', outro: 'Outro' };
const SEXOS = { macho: 'Macho', femea: 'Fêmea' };
const SELOS_AGENDA = { agendado: ['Agendado', ''], confirmado: ['Confirmado', 'status--info'], realizado: ['Realizado', 'status--sucesso'],
    cancelado: ['Cancelado', 'status--erro'], faltou: ['Faltou', 'status--expirado'] };
const SELOS_CONTA = { aberta: ['Aberta', ''], fechada: ['A receber', 'status--expirado'], paga: ['Paga', 'status--sucesso'], cancelada: ['Cancelada', 'status--erro'] };
const v = (x) => (x ? x : '—');

const Selo = ({ tabela, status }) => <span className={`status-badge ${tabela[status]?.[1] ?? ''}`.trim()}>{tabela[status]?.[0] ?? status}</span>;

const LIMITE = 20; // linhas por seção antes do "Ver todos"
const LIMITE_PRONTUARIOS = 10;

// Seção que abre e fecha (27/11, pedido do Lucas: a ficha ficava infinita). <details> nativo: teclado, leitor de tela e "aberta" de graça.
function Secao({ id, titulo, total, aberta = false, children }) {
    return <details className="secao" open={aberta || undefined}>
        <summary id={id}><span className="secao__titulo">{titulo}</span>{total !== undefined && <span className="secao__conta">{total}</span>}</summary>
        <div className="secao__corpo">{children}</div>
    </details>;
}

const VerTodos = ({ mostrando, total, aoVerTodos }) => total > mostrando
    ? <p className="secao__mais"><Botao variante="fantasma" onClick={aoVerTodos}>Mostrando {mostrando} de {total}. Ver todos</Botao></p> : null;

function Bloco({ id, titulo, vazio, itens, rotulo, aberta = false, rodape = null, children }) {
    return <Secao id={id} titulo={titulo} total={itens.length} aberta={aberta}>
        {itens.length === 0 ? <p className="vazio">{vazio}</p>
            : <><div className="tabela-rolagem tabela-empilha" tabIndex={0} role="region" aria-label={rotulo}><table>{children}</table></div>{rodape}</>}
    </Secao>;
}

// Ficha do pet (só leitura; o prontuário é do veterinário e não aparece aqui).
const TIPOS = { consulta: 'Consulta', retorno: 'Retorno', vacinacao: 'Vacinação', exame: 'Exame', cirurgia: 'Cirurgia', internacao: 'Internação', emergencia: 'Emergência' };
const CAMPOS_CLINICOS = [['anamnese', 'Anamnese'], ['exame_fisico', 'Exame físico'], ['diagnostico', 'Diagnóstico'], ['conduta', 'Conduta']];

// Prontuário (só leitura): veterinário e dono. Edição e assinatura ficam fora desta tela. O autor do adendo é o nome gravado na assinatura.
function Prontuarios({ itens }) {
    const [todos, setTodos] = useState(false);
    const visiveis = todos ? itens : itens.slice(0, LIMITE_PRONTUARIOS);
    // Cada prontuário é uma linha recolhida (tipo, data, veterinário, situação); o mais recente já vem aberto.
    return <Secao id="titulo-prontuario" titulo="Prontuário" total={itens.length} aberta>
        {itens.length === 0 ? <p className="vazio">Nenhum prontuário registrado para este pet.</p>
            : <><ul className="prontuarios" role="list">{visiveis.map((p, i) => <li key={p.id} className="prontuario">
                <details open={i === 0 || undefined}>
                    <summary className="prontuario__cabeca">
                        <strong>{TIPOS[p.tipo] ?? p.tipo}</strong>
                        <span className="mono">{p.data}</span>
                        <span>{p.veterinario ? `Vet. ${p.veterinario}` : ''}</span>
                        <span className={`status-badge ${p.assinado ? 'status--sucesso' : 'status--expirado'}`}>{p.assinado ? 'Assinado' : 'Em rascunho'}</span>
                        <a className="botao prontuario__abrir" href={`/prontuarios/${p.id}`} onClick={(e) => e.stopPropagation()}>{p.editavel ? 'Continuar' : 'Abrir'}</a>
                    </summary>
                    {(p.entrada_em || p.alta_em) && <p className="prontuario__nota">{p.entrada_em && `Entrada: ${p.entrada_em}`}{p.entrada_em && p.alta_em && ' · '}{p.alta_em && `Alta: ${p.alta_em}`}</p>}
                    <dl className="ficha">{CAMPOS_CLINICOS.filter(([k]) => p[k]).map(([k, r]) => <div key={k} style={{ display: 'contents' }}><dt>{r}</dt><dd className="prontuario__texto">{p[k]}</dd></div>)}</dl>
                    {p.assinado && p.assinado_por && <p className="prontuario__nota">Assinado por {p.assinado_por} em {p.assinado_em}.</p>}
                    {p.adendos.length > 0 && <div className="prontuario__adendos"><h3>Adendos</h3>
                        <ul role="list">{p.adendos.map((a, j) => <li key={j}><span className="mono">{a.em}</span> · {a.autor ?? ''}<p className="prontuario__texto">{a.texto}</p></li>)}</ul></div>}
                </details>
            </li>)}</ul>
            {!todos && <VerTodos mostrando={LIMITE_PRONTUARIOS} total={itens.length} aoVerTodos={() => setTodos(true)} />}</>}
    </Secao>;
}

export default function Pet({ pet, tutor, verTutor, agendamentos, cobrancas, vendas, prontuarios = null, podeAtender = false, podeEditar }) {
    const { cartao, abrindo, ocupado, setOcupado, erro, trava, abrir } = usarCartao();
    const [aviso, setAviso] = useState('');
    const [tudo, setTudo] = useState({}); // seções que o usuário abriu por inteiro ("Ver todos")
    const lim = (k, a) => (tudo[k] ? a : a.slice(0, LIMITE));
    const mais = (k, a) => (tudo[k] ? null : <VerTodos mostrando={LIMITE} total={a.length} aoVerTodos={() => setTudo((t) => ({ ...t, [k]: true }))} />);
    const aoMensagem = (texto) => { setAviso(texto); router.reload(); };

    return <Workspace titulo="Ficha do pet">
        <header className="page-header">
            <p className="eyebrow">Ficha do pet</p>
            <h1>{pet.nome}</h1>
            <p className="intro">{prontuarios !== null
                ? 'Cadastro, alertas de atendimento, prontuário, agenda e contas do pet. O prontuário aparece só para veterinário e dono.'
                : 'Cadastro, alertas de atendimento, agenda e contas do pet. O prontuário não aparece aqui: ele é do veterinário.'}</p>
        </header>
        <p className="acoes-topo">
            {podeAtender && <a className="botao botao--primario" href={`/prontuarios/novo?pet=${pet.id}`}>Novo prontuário</a>}
            <a className="botao" href={tutor ? `/clientes?cliente=${tutor.id}&pet=${pet.id}` : '/cadastros'}><span aria-hidden="true">←</span> {tutor ? 'Clientes' : 'Cadastros'}</a>
            {podeEditar && <Botao variante="secundario" disabled={ocupado} onClick={() => abrir('atualizar_pet', { id: pet.id })}>Editar o cadastro</Botao>}
        </p>
        {erro && <Aviso tipo="erro" role="alert">{erro}</Aviso>}
        {aviso && <Aviso tipo="info" role="status">{aviso}</Aviso>}
        {abrindo && <CaixaCarregando texto="Abrindo o formulário…" />}
        {cartao && <Confirmacao key={cartao.proposta} proposta={cartao} adicionar={aoMensagem} trava={trava} ocupado={ocupado} definirOcupado={setOcupado}
            abrirFormulario={(tool, id) => abrir(tool, { id })} />}

        <div className="ficha-grade ficha-do-pet">
            {verTutor && <section className="bloco" aria-labelledby="titulo-tutor">
                <h2 id="titulo-tutor">Tutor</h2>
                {tutor ? <dl className="ficha">
                    <dt>Nome</dt><dd><a href={`/tutores/${tutor.id}`}>{tutor.nome}</a></dd>
                    <dt>Telefone</dt><dd className="mono">{v(tutor.telefone)}</dd>
                    <dt>E-mail</dt><dd>{v(tutor.email)}</dd>
                </dl> : <p className="vazio">Tutor não encontrado.</p>}
            </section>}
            <section className="bloco" aria-labelledby="titulo-dados">
                <h2 id="titulo-dados">Dados</h2>
                <dl className="ficha">
                    <dt>Espécie</dt><dd>{ESPECIES[pet.especie] ?? pet.especie}</dd>
                    <dt>Raça</dt><dd>{v(pet.raca)}</dd>
                    <dt>Sexo</dt><dd>{SEXOS[pet.sexo] ?? '—'}</dd>
                    <dt>Nascimento</dt><dd>{v(pet.nascimento)}</dd>
                    <dt>Peso</dt><dd>{v(pet.peso)}</dd>
                    <dt>Microchip</dt><dd className="mono">{v(pet.microchip)}</dd>
                </dl>
            </section>
        </div>

        <section className="bloco" aria-labelledby="titulo-alertas">
            <h2 id="titulo-alertas">Alertas de atendimento</h2>
            {pet.alertas ? <Aviso tipo="atencao" titulo="Atenção:">{pet.alertas}</Aviso> : <p className="vazio">Nenhum alerta no cadastro.</p>}
        </section>

        {prontuarios !== null && <Prontuarios itens={prontuarios} />}

        {agendamentos !== null && <Bloco id="titulo-agenda" titulo="Agendamentos" vazio="Nenhum agendamento." itens={agendamentos} rotulo="Agendamentos do pet" aberta={prontuarios === null} rodape={mais("ag", agendamentos)}>
            <thead><tr><th scope="col">Horário</th><th scope="col">Serviço</th><th scope="col">Profissional</th><th scope="col">Setor</th><th scope="col">Situação</th></tr></thead>
            <tbody>{lim("ag", agendamentos).map((a) => <tr key={a.id}>
                <td data-rotulo="Horário">{a.horario}</td><td data-rotulo="Serviço">{a.servico}</td><td data-rotulo="Profissional">{a.profissional}</td>
                <td data-rotulo="Setor">{a.setor === 'clinica' ? 'Clínica' : 'Banho e tosa'}</td>
                <td data-rotulo="Situação"><Selo tabela={SELOS_AGENDA} status={a.status} /></td>
            </tr>)}</tbody>
        </Bloco>}

        {cobrancas !== null && <Bloco id="titulo-cobrancas" titulo="Cobranças" vazio="Nenhuma cobrança." itens={cobrancas} rotulo="Cobranças do pet" rodape={mais("co", cobrancas)}>
            <thead><tr><th scope="col">Nº</th><th scope="col">Serviço</th><th scope="col">Atendimento</th><th scope="col" className="num">Total</th>
                <th scope="col" className="num">Estornado</th><th scope="col" className="num">Líquido</th><th scope="col">Situação</th></tr></thead>
            <tbody>{lim("co", cobrancas).map((c) => <tr key={c.id}>
                <td data-rotulo="Nº">{c.id}</td><td data-rotulo="Serviço">{c.servico}</td><td data-rotulo="Atendimento">{c.atendimento}</td>
                <td data-rotulo="Total" className="num">{c.total}</td><td data-rotulo="Estornado" className="num">{c.estornado}</td>
                <td data-rotulo="Líquido" className="num">{v(c.liquido)}</td>
                <td data-rotulo="Situação"><Selo tabela={SELOS_CONTA} status={c.status} /></td>
            </tr>)}</tbody>
        </Bloco>}

        {vendas !== null && <Bloco id="titulo-vendas" titulo="Vendas ligadas ao pet" vazio="Nenhuma venda." itens={vendas} rotulo="Vendas ligadas ao pet" rodape={mais("ve", vendas)}>
            <thead><tr><th scope="col">Nº</th><th scope="col">Data</th><th scope="col">Setor</th><th scope="col" className="num">Total</th>
                <th scope="col" className="num">Estornado</th><th scope="col" className="num">Líquido</th><th scope="col">Situação</th></tr></thead>
            <tbody>{lim("ve", vendas).map((x) => <tr key={x.id}>
                <td data-rotulo="Nº">{x.id}</td><td data-rotulo="Data">{v(x.data)}</td><td data-rotulo="Setor">{x.setor === 'clinica' ? 'Clínica' : 'Petshop'}</td>
                <td data-rotulo="Total" className="num">{x.total}</td><td data-rotulo="Estornado" className="num">{x.estornado}</td>
                <td data-rotulo="Líquido" className="num">{v(x.liquido)}</td>
                <td data-rotulo="Situação"><Selo tabela={SELOS_CONTA} status={x.status} /></td>
            </tr>)}</tbody>
        </Bloco>}
    </Workspace>;
}
