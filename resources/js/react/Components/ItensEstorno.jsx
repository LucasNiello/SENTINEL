import { useId } from 'react';

export const quantidadesIniciais = (itens = []) => Object.fromEntries(itens.map((item) => [item.item_id, String(item.quantidade || 0)]));
export const coletarLinhas = (itens, quantidades) => itens.flatMap((item) => {
    const quantidade = String(quantidades[item.item_id] ?? '').trim();
    return quantidade === '' || quantidade === '0' ? [] : [{ item_id: Number(item.item_id), quantidade }];
});

export default function ItensEstorno({ itens, quantidades, alterar, bloqueado }) {
    const id = useId();
    return <fieldset className="card-campos" disabled={bloqueado} style={{ minWidth: 0 }}>
        <div className="campo estorno-itens" style={{ minWidth: 0 }}>
            <p className="estorno-itens__titulo" id={id}>Quanto estornar de cada item (0 = não estorna)</p>
            <div className="tabela-rolagem" tabIndex={0} role="region" aria-label="Itens do estorno">
                <table aria-labelledby={id}>
                    <thead><tr>{['Item', 'Preço', 'Lançado', 'Já estornado', 'Estornar agora'].map((titulo) => <th key={titulo} scope="col">{titulo}</th>)}</tr></thead>
                    <tbody>{itens.map((item) => <tr key={item.item_id}>
                        <td>{item.descricao}<span className="estorno-itens__origem">{item.origem === 'Prontuário' ? 'Prontuário: só o valor volta, o estoque não' : 'Avulso'}</span></td>
                        <td className="num">{item.preco}{item.liquido_restante != null && <span className="estorno-itens__resta">Líquido restante: {item.liquido_restante}</span>}</td><td className="num">{item.lancada}</td><td className="num">{item.estornada}</td>
                        <td><input type="text" inputMode="numeric" value={quantidades[item.item_id] ?? ''} disabled={item.restante < 1}
                            aria-label={`Estornar quantos de ${item.descricao} (restam ${item.restante})`} onChange={(e) => alterar(item.item_id, e.target.value)} />
                            <span className="estorno-itens__resta">restam {item.restante}</span></td>
                    </tr>)}</tbody>
                </table>
            </div>
        </div>
    </fieldset>;
}
