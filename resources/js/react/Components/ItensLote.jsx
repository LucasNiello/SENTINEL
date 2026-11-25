export default function ItensLote({ itens = [] }) {
    if (!itens.length) return null;
    return <div className="tabela-rolagem card-lote" tabIndex={0} role="region" aria-label="Itens do lote" style={{ maxWidth: '100%' }}>
        <table>
            <caption className="sr-only">Plano do lote</caption>
            <thead><tr>{['Nº', 'Descrição', 'Data', 'Valor', 'Status'].map((titulo) => <th key={titulo} scope="col">{titulo}</th>)}</tr></thead>
            <tbody>{itens.map((item, indice) => <tr key={indice} className={item.erro ? 'item-erro' : undefined}>
                <td className="num">#{item.id}</td>
                <td>{item.descricao ?? '—'}</td>
                <td className="num">{item.data ?? '—'}</td>
                <td className="num">{item.valor ?? '—'}</td>
                <td>{item.antes} → {item.depois}{item.erro && <span className="item-erro-texto">✕ {item.erro}</span>}</td>
            </tr>)}</tbody>
        </table>
    </div>;
}
