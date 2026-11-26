import { useEffect, useId, useMemo, useRef, useState } from 'react';

// Combobox único: abre a lista, o digitado dentro dela filtra (ignora acento e maiúscula) e só aceita valor da lista.
// O próprio <input> é o campo do formulário: required e setCustomValidity funcionam no reportValidity() do card.
const limpar = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

export default function Combobox({ id, name, value, opcoes, aoMudar, required = false, disabled = false, invalido = false,
    descrito, textoVazio = '— escolha —', placeholder = 'Digite para filtrar' }) {
    const lista = useId();
    const raiz = useRef(null);
    const campo = useRef(null);
    const [aberto, setAberto] = useState(false);
    const [busca, setBusca] = useState('');
    const [indice, setIndice] = useState(0);
    const escolhido = opcoes.find((o) => String(o.valor) === String(value));
    const itens = useMemo(() => {
        const termo = limpar(busca).trim();
        const filtradas = termo ? opcoes.filter((o) => limpar(o.rotulo).includes(termo)) : opcoes;
        return required ? filtradas : [{ valor: '', rotulo: textoVazio, vazio: true }, ...filtradas];
    }, [opcoes, busca, required, textoVazio]);
    const texto = aberto ? busca : (escolhido?.rotulo ?? '');
    // Sem valor da lista, o campo obrigatório não passa (mesmo com texto digitado).
    useEffect(() => {
        campo.current?.setCustomValidity(required && !escolhido ? 'Escolha um item da lista.' : '');
    }, [required, escolhido]);
    useEffect(() => { if (indice >= itens.length) setIndice(0); }, [itens.length, indice]);
    useEffect(() => {
        if (!aberto) return undefined;
        const fora = (e) => { if (!raiz.current?.contains(e.target)) fechar(); };
        document.addEventListener('pointerdown', fora);
        return () => document.removeEventListener('pointerdown', fora);
    }, [aberto]);

    function abrir() {
        if (disabled || aberto) return;
        setBusca('');
        const posicao = itens.findIndex((o) => String(o.valor) === String(value));
        setIndice(Math.max(0, posicao));
        setAberto(true);
    }
    function fechar() { setAberto(false); setBusca(''); }
    function escolher(opcao) {
        if (!opcao) return;
        aoMudar(String(opcao.valor));
        fechar();
    }
    function tecla(e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); if (!aberto) abrir(); else setIndice((i) => Math.min(i + 1, itens.length - 1)); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); if (!aberto) abrir(); else setIndice((i) => Math.max(i - 1, 0)); }
        else if (e.key === 'Home' && aberto) { e.preventDefault(); setIndice(0); }
        else if (e.key === 'End' && aberto) { e.preventDefault(); setIndice(Math.max(0, itens.length - 1)); }
        else if (e.key === 'Enter') { if (aberto) { e.preventDefault(); escolher(itens[indice]); } }
        else if (e.key === 'Escape' && aberto) { e.preventDefault(); e.stopPropagation(); fechar(); }
        else if (e.key === 'Tab') fechar();
    }
    useEffect(() => {
        if (aberto) document.getElementById(`${lista}-${indice}`)?.scrollIntoView({ block: 'nearest' });
    }, [aberto, indice, lista]);

    return <div className="combo" ref={raiz}>
        <input ref={campo} id={id} name={name} type="text" role="combobox" autoComplete="off" className="combo-campo"
            aria-expanded={aberto} aria-controls={lista} aria-autocomplete="list" aria-haspopup="listbox"
            aria-activedescendant={aberto && itens.length ? `${lista}-${indice}` : undefined}
            aria-invalid={invalido ? true : undefined} aria-describedby={descrito}
            required={required} disabled={disabled} value={texto} placeholder={escolhido ? undefined : placeholder}
            onFocus={abrir} onClick={abrir}
            onChange={(e) => { if (!aberto) setAberto(true); setBusca(e.target.value); setIndice(0); }}
            onKeyDown={tecla} />
        <span className="combo-seta" aria-hidden="true">▾</span>
        {aberto && <ul id={lista} role="listbox" className="combo-lista" aria-label="Opções">
            {itens.length === 0 && <li className="combo-vazio" role="presentation">Nada encontrado para “{busca}”.</li>}
            {itens.map((o, i) => <li key={`${o.valor}-${i}`} id={`${lista}-${i}`} role="option" aria-selected={String(o.valor) === String(value)}
                className={`combo-opcao${i === indice ? ' combo-opcao--ativa' : ''}${o.vazio ? ' combo-opcao--vazia' : ''}`}
                onPointerDown={(e) => e.preventDefault()} onClick={() => escolher(o)} onPointerMove={() => setIndice(i)}>{o.rotulo}</li>)}
        </ul>}
    </div>;
}
