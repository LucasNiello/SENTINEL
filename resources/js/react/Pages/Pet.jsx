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

function Bloco({ id, titulo, vazio, itens, rotulo, children }) {
    return <section className="bloco" aria-labelledby={id}>
        <h2 id={id}>{titulo}</h2>
        {itens.length === 0 ? <p className="vazio">{vazio}</p>
            : <div className="tabela-rolagem tabela-empilha" tabIndex={0} role="region" aria-label={rotulo}><table>{children}</table></div>}
    </section>;
}

// Ficha do pet (só leitura; o prontuário é do veterinário e não aparece aqui).
export default function Pet({ pet, tutor, verTutor, agendamentos, cobrancas, vendas, podeEditar }) {
    const { cartao, abrindo, ocupado, setOcupado, erro, trava, abrir } = usarCartao();
    const [aviso, setAviso] = useState('');
    const aoMensagem = (texto) => { setAviso(texto); router.reload(); };

    return <Workspace titulo="Ficha do pet">
        <header className="page-header">
            <p className="eyebrow">Ficha do pet</p>
            <h1>{pet.nome}</h1>
            <p className="intro">Cadastro, alertas de atendimento, agenda e contas do pet. O prontuário não aparece aqui: ele é do veterinário.</p>
        </header>
        <p className="acoes-topo">
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

        {agendamentos !== null && <Bloco id="titulo-agenda" titulo="Agendamentos" vazio="Nenhum agendamento." itens={agendamentos} rotulo="Agendamentos do pet">
            <thead><tr><th scope="col">Horário</th><th scope="col">Serviço</th><th scope="col">Profissional</th><th scope="col">Setor</th><th scope="col">Situação</th></tr></thead>
            <tbody>{agendamentos.map((a) => <tr key={a.id}>
                <td data-rotulo="Horário">{a.horario}</td><td data-rotulo="Serviço">{a.servico}</td><td data-rotulo="Profissional">{a.profissional}</td>
                <td data-rotulo="Setor">{a.setor === 'clinica' ? 'Clínica' : 'Banho e tosa'}</td>
                <td data-rotulo="Situação"><Selo tabela={SELOS_AGENDA} status={a.status} /></td>
            </tr>)}</tbody>
        </Bloco>}

        {cobrancas !== null && <Bloco id="titulo-cobrancas" titulo="Cobranças" vazio="Nenhuma cobrança." itens={cobrancas} rotulo="Cobranças do pet">
            <thead><tr><th scope="col">Nº</th><th scope="col">Serviço</th><th scope="col">Atendimento</th><th scope="col" className="num">Total</th>
                <th scope="col" className="num">Estornado</th><th scope="col" className="num">Líquido</th><th scope="col">Situação</th></tr></thead>
            <tbody>{cobrancas.map((c) => <tr key={c.id}>
                <td data-rotulo="Nº">{c.id}</td><td data-rotulo="Serviço">{c.servico}</td><td data-rotulo="Atendimento">{c.atendimento}</td>
                <td data-rotulo="Total" className="num">{c.total}</td><td data-rotulo="Estornado" className="num">{c.estornado}</td>
                <td data-rotulo="Líquido" className="num">{v(c.liquido)}</td>
                <td data-rotulo="Situação"><Selo tabela={SELOS_CONTA} status={c.status} /></td>
            </tr>)}</tbody>
        </Bloco>}

        {vendas !== null && <Bloco id="titulo-vendas" titulo="Vendas ligadas ao pet" vazio="Nenhuma venda." itens={vendas} rotulo="Vendas ligadas ao pet">
            <thead><tr><th scope="col">Nº</th><th scope="col">Data</th><th scope="col">Setor</th><th scope="col" className="num">Total</th>
                <th scope="col" className="num">Estornado</th><th scope="col" className="num">Líquido</th><th scope="col">Situação</th></tr></thead>
            <tbody>{vendas.map((x) => <tr key={x.id}>
                <td data-rotulo="Nº">{x.id}</td><td data-rotulo="Data">{v(x.data)}</td><td data-rotulo="Setor">{x.setor === 'clinica' ? 'Clínica' : 'Petshop'}</td>
                <td data-rotulo="Total" className="num">{x.total}</td><td data-rotulo="Estornado" className="num">{x.estornado}</td>
                <td data-rotulo="Líquido" className="num">{v(x.liquido)}</td>
                <td data-rotulo="Situação"><Selo tabela={SELOS_CONTA} status={x.status} /></td>
            </tr>)}</tbody>
        </Bloco>}
    </Workspace>;
}
