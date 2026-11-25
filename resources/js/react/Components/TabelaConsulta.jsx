const textoCelula = (valor) => valor == null ? '' : typeof valor === 'object' ? JSON.stringify(valor) : String(valor);

export default function TabelaConsulta({ consulta, ocupado, abrirFormulario, prepararPedido }) {
    const linhas = consulta.resultado;
    // Sem cabeçalhos explícitos, usar apenas chaves realmente recebidas.
    const colunas = consulta.colunas?.length ? consulta.colunas
        : [...new Set(linhas.flatMap((linha) => Object.keys(linha)))].filter((nome) => !['acao', 'destaque'].includes(nome));
    const temAcoes = !!consulta.editar || linhas.some((linha) => linha.acao?.tool || typeof linha.pedido === 'string');

    return <>
        {consulta.mensagem && <div className="msg msg--agente"><span className="msg__speaker">Sentinel</span><span>{consulta.mensagem}</span></div>}
        {!linhas.length ? <div className="msg msg--agente"><span className="msg__speaker">Sentinel</span><span>Nenhum registro encontrado.</span></div> : <>
            <div className="tabela-resumo">{linhas.length} registro(s) encontrado(s)</div>
            <div className="tabela-rolagem" tabIndex={0} role="region" aria-label="Resultado da consulta">
                <table>
                    <caption className="sr-only">Resultado da consulta</caption>
                    <thead><tr>{colunas.map((coluna) => <th key={coluna} scope="col">{coluna}</th>)}{temAcoes && <th scope="col">Ação</th>}</tr></thead>
                    <tbody>{linhas.map((linha, indice) => {
                        const tool = linha.acao?.tool || consulta.editar;
                        const rotulo = linha.acao?.rotulo || 'Editar';
                        return <tr key={indice} className={linha.destaque === 'aviso' ? 'linha--aviso' : undefined}>
                            {colunas.map((coluna) => <td key={coluna} style={{ whiteSpace: 'pre-wrap' }}>{textoCelula(linha[coluna])}</td>)}
                            {temAcoes && <td>
                                {tool && <button type="button" className={`btn-editar-linha${tool.startsWith('excluir_definitivo_') ? ' btn-danger' : ''}`} disabled={ocupado} aria-label={`${rotulo} registro #${linha.id}`} onClick={() => abrirFormulario(tool, linha.id)}>{rotulo}</button>}
                                {typeof linha.pedido === 'string' && <button type="button" className="btn-editar-linha" disabled={ocupado} aria-label={`Pedir ao agente o que a mensagem ${linha.id} pede`} onClick={() => prepararPedido(linha.pedido)}>Pedir ao agente</button>}
                            </td>}
                        </tr>;
                    })}</tbody>
                </table>
            </div>
        </>}
    </>;
}
