import { useEffect, useState } from 'react';
import Botao from './ui/Botao';

export const edicaoInicial = (editor) => editor ? {
    versao: editor.versao,
    linhas: editor.linhas.map(({ item_id, quantidade }) => ({ item_id, quantidade: String(quantidade), remover: false, motivo: '' })),
    novos: [],
    desconto_tipo: 'reais',
    desconto_valor: ((editor.financeiro?.desconto_centavos ?? 0) / 100).toFixed(2).replace('.', ','),
    desconto_motivo: editor.desconto_motivo ?? '',
} : null;

export default function ItensCobranca({ proposta, editor, valor, alterar, bloqueado }) {
    const [previa, setPrevia] = useState(editor.financeiro);
    const [erroPrevia, setErroPrevia] = useState('');
    const [calculando, setCalculando] = useState(false);
    useEffect(() => {
        if (!editor.financeiro || bloqueado) return;
        const controller = new AbortController();
        setCalculando(true);
        setErroPrevia('');
        const timer = setTimeout(async () => {
            try {
                const response = await fetch('/agente/revisar?previa=1', { method: 'POST', credentials: 'same-origin', signal: controller.signal,
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
                    body: JSON.stringify({ proposta, valores: valor }) });
                const data = await response.json();
                const financeiro = data.formulario?.edicao_cobranca?.financeiro;
                if (!response.ok || !financeiro) throw new Error(data.formulario?.edicao_cobranca?.erro_previa || data.formulario?.erro || data.mensagem || 'Confira os campos para calcular a prévia.');
                if (!controller.signal.aborted) setPrevia(financeiro);
            } catch (e) {
                if (!controller.signal.aborted) { setPrevia(null); setErroPrevia(e.message); }
            } finally {
                if (!controller.signal.aborted) setCalculando(false);
            }
        }, 350);
        return () => { clearTimeout(timer); controller.abort(); };
    }, [valor, proposta, bloqueado, editor.financeiro]);
    const reais = cents => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(cents / 100);
    const linha = (indice, dados) => alterar({ ...valor, linhas: valor.linhas.map((l, i) => i === indice ? { ...l, ...dados } : l) });
    const novo = (indice, dados) => alterar({ ...valor, novos: valor.novos.map((l, i) => i === indice ? { ...l, ...dados } : l) });
    return <fieldset className="card-campos" disabled={bloqueado} style={{ minWidth: 0 }}>
        <legend>Itens da cobrança</legend>
        <label className="campo">Tipo de desconto<select aria-label="Tipo de desconto" value={valor.desconto_tipo} onChange={e => alterar({ ...valor, desconto_tipo: e.target.value })}>
            <option value="reais">R$</option><option value="percentual">%</option>
        </select></label>
        <label className="campo">Desconto geral<input inputMode="decimal" required maxLength={15} pattern="[0-9]+([,.][0-9]{1,2})?" value={valor.desconto_valor} onChange={e => alterar({ ...valor, desconto_valor: e.target.value })} /></label>
        <label className="campo">Motivo do desconto<input maxLength={200} required={/[1-9]/.test(valor.desconto_valor)} value={valor.desconto_motivo} onChange={e => alterar({ ...valor, desconto_motivo: e.target.value })} /></label>
        <p>Até 10% do subtotal. Acima disso, solicite autorização do supervisor. Percentuais com fração de centavo arredondam para baixo.</p>
        <div aria-live="polite" aria-label="Prévia financeira">
            {calculando ? <p>Atualizando prévia…</p> : erroPrevia ? <p>{erroPrevia}</p> : previa && <>
                <p>Valor cheio: {reais(previa.subtotal_centavos)}</p><p>Desconto: {reais(previa.desconto_centavos)}</p><p>Total líquido: {reais(previa.total_centavos)}</p>
            </>}
        </div>
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
