import Botao from './ui/Botao';

export const edicaoInicial = (editor) => editor ? {
    versao: editor.versao,
    linhas: editor.linhas.map(({ item_id, quantidade }) => ({ item_id, quantidade: String(quantidade), remover: false, motivo: '' })),
    novos: [],
} : null;

export default function ItensCobranca({ editor, valor, alterar, bloqueado }) {
    const linha = (indice, dados) => alterar({ ...valor, linhas: valor.linhas.map((l, i) => i === indice ? { ...l, ...dados } : l) });
    const novo = (indice, dados) => alterar({ ...valor, novos: valor.novos.map((l, i) => i === indice ? { ...l, ...dados } : l) });
    return <fieldset className="card-campos" disabled={bloqueado} style={{ minWidth: 0 }}>
        <legend>Itens da cobrança</legend>
        <p>Os valores serão recalculados pelo servidor ao confirmar. Exclusão clínica não devolve estoque nem altera o prontuário.</p>
        {editor.linhas.map((item, i) => <div className="campo" key={item.item_id} style={{ minWidth: 0 }}>
            <strong>{item.descricao}</strong>
            {item.clinico && <span>Prontuário — quantidade consumida preservada</span>}
            <label>Quantidade de {item.descricao}
                <input type="number" min="1" max="100000" step="1" required disabled={item.clinico || valor.linhas[i].remover}
                    value={valor.linhas[i].quantidade} onChange={e => linha(i, { quantidade: e.target.value })} />
            </label>
            <label><input type="checkbox" checked={valor.linhas[i].remover} onChange={e => linha(i, { remover: e.target.checked })} /> Excluir financeiramente {item.descricao}</label>
            {valor.linhas[i].remover && <label>Motivo da exclusão de {item.descricao}
                <input required maxLength={200} value={valor.linhas[i].motivo} onChange={e => linha(i, { motivo: e.target.value })} />
            </label>}
        </div>)}
        {valor.novos.map((item, i) => <div className="campo" key={i} style={{ minWidth: 0 }}>
            <label>Item novo {i + 1}<select aria-label={`Item novo ${i + 1}`} required value={item.catalogo_item_id} onChange={e => novo(i, { catalogo_item_id: e.target.value })}>
                <option value="">Escolha no catálogo</option>
                {editor.catalogo.map(p => <option key={p.id} value={p.id}>{p.nome_comercial}</option>)}
            </select></label>
            <label>Quantidade nova {i + 1}<input type="number" min="1" max="100000" step="1" required value={item.quantidade} onChange={e => novo(i, { quantidade: e.target.value })} /></label>
            <Botao variante="fantasma" onClick={() => alterar({ ...valor, novos: valor.novos.filter((_, j) => i !== j) })}>Retirar inclusão {i + 1}</Botao>
        </div>)}
        <Botao variante="secundario" disabled={bloqueado || valor.novos.length >= 20} onClick={() => alterar({ ...valor, novos: [...valor.novos, { catalogo_item_id: '', quantidade: '1' }] })}>Adicionar item</Botao>
    </fieldset>;
}
