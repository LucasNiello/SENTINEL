import Workspace from '../Layouts/Workspace';

// Mesmo formato do servidor (ResultadosClinica::reais): "R$ 1.234,56", com sinal de menos tipográfico.
const reais = (c) => `${c < 0 ? '−' : ''}R$ ${Math.floor(Math.abs(c) / 100).toLocaleString('pt-BR')},${String(Math.abs(c) % 100).padStart(2, '0')}`;

export default function Resultados({ r, periodo, periodos, dia, rotuloPeriodo, nomes, anterior, proximo, hoje }) {
    const link = (troca) => `/resultados?${new URLSearchParams({ periodo, dia, ...troca })}`;
    // Barra de proporção por setor (largura = parte do faturamento); o número está sempre ao lado.
    const parte = (valor) => (r.faturamento > 0 ? Math.round((valor * 100) / r.faturamento) : 0);

    return <Workspace titulo="Resultados">
        <header className="page-header">
            <p className="eyebrow">Resultados</p>
            <h1>Resultados da clínica e do petshop</h1>
            <p className="intro">Faturamento do que foi pago no período, menos estornos e despesas. Valores simulados: não há caixa, banco nem imposto.</p>
        </header>

        <nav className="barra-periodo" aria-label="Período dos resultados">
            <div className="segmentos" role="group" aria-label="Período">
                {Object.entries(periodos).map(([chave, rotulo]) => <a key={chave} href={link({ periodo: chave })} aria-current={periodo === chave ? 'true' : undefined}>{rotulo}</a>)}
            </div>
            <div className="passos">
                <a className="botao" href={link({ dia: anterior })}><span aria-hidden="true">←</span> {nomes[0]}</a>
                <a className="botao" href={link({ dia: hoje })}>Hoje</a>
                <a className="botao" href={link({ dia: proximo })}>{nomes[1]} <span aria-hidden="true">→</span></a>
            </div>
        </nav>

        <p className="periodo-atual">Período: <strong>{rotuloPeriodo}</strong></p>

        <dl className="kpis" aria-label="Resumo do período">
            <div className="kpi"><dt>Faturamento</dt><dd>{reais(r.faturamento)}</dd><p>{r.pagamentos} pagamento(s)</p></div>
            <div className="kpi"><dt>Estornado</dt><dd>{reais(r.estornado)}</dd><p>Líquido: {reais(r.liquido)}</p></div>
            <div className="kpi"><dt>Despesas</dt><dd>{reais(r.despesas)}</dd><p>Lançamentos de despesa do cadastro inicial: a clínica ainda não tem tela para lançar despesa.</p></div>
            <div className={`kpi kpi--destaque${r.lucro < 0 ? ' kpi--negativo' : ''}`}>
                <dt>{r.lucro < 0 ? 'Prejuízo' : 'Lucro'}</dt><dd>{reais(r.lucro)}</dd><p>Faturamento − estornos − despesas</p>
            </div>
            <div className="kpi"><dt>Ticket médio</dt><dd>{reais(r.ticket_medio)}</dd><p>Faturamento ÷ pagamentos</p></div>
            <div className="kpi"><dt>A receber hoje</dt><dd>{reais(r.a_receber.total)}</dd><p>{r.a_receber.cobrancas} cobrança(s) e {r.a_receber.vendas} venda(s) em aberto</p></div>
        </dl>

        <div className="blocos">
            <section className="bloco" aria-labelledby="titulo-setores">
                <h2 id="titulo-setores">Por setor</h2>
                <div className="tabela-rolagem tabela-empilha resultados-mobile" tabIndex={0} role="region" aria-label="Resultados por setor">
                    <table>
                        <thead><tr><th scope="col">Setor</th><th scope="col" className="num">Faturamento</th><th scope="col">Parte</th><th scope="col" className="num">Estornado</th>
                            <th scope="col" className="num">Líquido</th><th scope="col" className="num">Ticket médio</th></tr></thead>
                        <tbody>{r.setores.map((s) => <tr key={s.rotulo}>
                            <td data-rotulo="Setor">{s.rotulo}</td>
                            <td data-rotulo="Faturamento" className="num">{reais(s.faturamento)}</td>
                            <td data-rotulo="Parte"><span className="proporcao"><span className="proporcao__barra" style={{ width: `${parte(s.faturamento)}%` }} /></span> <span className="mono">{parte(s.faturamento)}%</span></td>
                            <td data-rotulo="Estornado" className="num">{reais(s.estornado)}</td>
                            <td data-rotulo="Líquido" className="num">{reais(s.liquido)}</td>
                            <td data-rotulo="Ticket médio" className="num">{reais(s.ticket_medio)}</td>
                        </tr>)}</tbody>
                    </table>
                </div>
            </section>

            <section className="bloco" aria-labelledby="titulo-top">
                <h2 id="titulo-top">Itens que mais faturaram</h2>
                {r.top_itens.length === 0 ? <p className="vazio">Nenhum pagamento no período.</p>
                    : <div className="tabela-rolagem tabela-empilha resultados-mobile" tabIndex={0} role="region" aria-label="Itens que mais faturaram">
                        <table>
                            <thead><tr><th scope="col">Item</th><th scope="col" className="num">Quantidade</th><th scope="col" className="num">Total</th></tr></thead>
                            <tbody>{r.top_itens.map((i) => <tr key={i.descricao}>
                                <td data-rotulo="Item">{i.descricao}</td><td data-rotulo="Quantidade" className="num">{i.quantidade}</td><td data-rotulo="Total" className="num">{reais(i.total)}</td>
                            </tr>)}</tbody>
                        </table>
                    </div>}
            </section>
        </div>
    </Workspace>;
}
