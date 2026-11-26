import { router } from '@inertiajs/react';
import { useState } from 'react';
import Workspace from '../Layouts/Workspace';
import Confirmacao from '../Components/Confirmacao';
import Aviso from '../Components/ui/Aviso';
import Botao from '../Components/ui/Botao';
import usarCartao from '../Components/usarCartao';

// Cobranças e Vendas: a mesma tela com colunas e ações diferentes (o servidor manda as duas coisas).
const SELOS = { aberta: ['Aberta', ''], 'a receber': ['A receber', 'status--expirado'], paga: ['Paga', 'status--sucesso'], cancelada: ['Cancelada', 'status--erro'] };
const PAGAMENTOS = { dinheiro: 'Dinheiro', pix: 'Pix', cartao: 'Cartão' };
const NUMERICAS = ['total', 'estornado', 'liquido'];
const vazio = (v) => v === '' || v === null || v === undefined;

export default function Financeiro({ titulo, rota, intro, erro, linhas, colunas, resumo, status, setor, opcoesStatus, acoes }) {
    const { cartao, setCartao, ocupado, setOcupado, erro: erroCartao, trava, abrir } = usarCartao();
    const [aviso, setAviso] = useState('');
    const aoMensagem = (texto) => { setAviso(texto); router.reload(); };
    const acoesDe = (s) => Object.values(acoes?.[s] ?? {});
    const temAcao = linhas.some((l) => acoesDe(l.status).length > 0);
    const chaves = Object.keys(colunas);

    return <Workspace titulo={titulo}>
        <header className="page-header">
            <p className="eyebrow">Financeiro</p>
            <h1>{titulo}</h1>
            <p className="intro">{intro}</p>
        </header>

        <form className="painel" method="GET" action={rota} aria-label="Filtros">
            <div className="campo">
                <label htmlFor="status">Situação</label>
                <select id="status" name="status" defaultValue={status}>
                    <option value="">Todas</option>
                    {Object.entries(opcoesStatus).map(([v, r]) => <option key={v} value={v}>{r}</option>)}
                </select>
            </div>
            <div className="campo">
                <label htmlFor="setor">Setor</label>
                <select id="setor" name="setor" defaultValue={setor}>
                    <option value="">Todos</option>
                    <option value="clinica">Clínica</option>
                    <option value="petshop">Petshop</option>
                </select>
            </div>
            <div className="painel__acoes"><button type="submit" className="botao-principal">Filtrar</button></div>
        </form>

        {erro && <Aviso tipo="erro" role="alert">{erro}</Aviso>}
        {erroCartao && <Aviso tipo="erro" role="alert">{erroCartao}</Aviso>}
        {aviso && <Aviso tipo="info" role="status">{aviso}</Aviso>}
        {cartao && <Confirmacao key={cartao.proposta} proposta={cartao} adicionar={aoMensagem} trava={trava} ocupado={ocupado} definirOcupado={setOcupado}
            abrirFormulario={(tool, id) => abrir(tool, { id })} />}

        <dl className="kpis kpis--3" aria-label="Soma do que está listado">
            <div className="kpi"><dt>Total listado</dt><dd>{resumo.total}</dd></div>
            <div className="kpi"><dt>Estornado</dt><dd>{resumo.estornado}</dd></div>
            <div className="kpi kpi--destaque"><dt>Líquido</dt><dd>{resumo.liquido}</dd><p>Só do que foi pago.</p></div>
        </dl>

        <p className="tabela-resumo"><span>{resumo.registros} registro(s)</span></p>
        {resumo.registros >= 100 && <Aviso tipo="atencao">A lista mostra só os 100 registros mais novos. Use os filtros para ver os demais; a soma acima é só do que está listado.</Aviso>}

        {linhas.length === 0
            ? (!erro && <p className="vazio">Nenhum registro com estes filtros.</p>)
            : <div className="tabela-rolagem tabela-empilha financeiro-mobile" tabIndex={0} role="region" aria-label={titulo}>
                <table>
                    <thead><tr>
                        {chaves.map((k) => <th key={k} scope="col" className={NUMERICAS.includes(k) ? 'num' : undefined}>{colunas[k]}</th>)}
                        {temAcao && <th scope="col">Ação</th>}
                    </tr></thead>
                    <tbody>{linhas.map((l) => <tr key={l.id}>
                        {chaves.map((k) => <td key={k} data-rotulo={colunas[k]} className={NUMERICAS.includes(k) ? 'num' : undefined}>
                            {k === 'status'
                                ? <span className={`status-badge ${SELOS[l.status]?.[1] ?? ''}`.trim()}>{SELOS[l.status]?.[0] ?? l.status}</span>
                                : k === 'forma_pagamento' ? (PAGAMENTOS[l[k] ?? ''] ?? '—')
                                    : vazio(l[k]) ? '—' : l[k]}
                        </td>)}
                        {temAcao && <td data-rotulo="Ação">{acoesDe(l.status).map((a) => <Botao key={a.tool} variante="fantasma" className="btn-editar-linha" disabled={ocupado}
                            aria-label={`${a.rotulo} o registro ${l.id}`} onClick={() => abrir(a.tool, { id: l.id })}>{a.rotulo}</Botao>)}</td>}
                    </tr>)}</tbody>
                </table>
            </div>}
    </Workspace>;
}
