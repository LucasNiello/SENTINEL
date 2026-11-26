import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import Aviso from './Aviso';

export const TOAST_MINIMO_MS = 6000;

// Toast: fica no mínimo 6 s, pausa com o mouse ou o foco em cima e tem botão de fechar. Erro não fecha sozinho. Um por vez.
// Padrão: no topo da tela. `ancorado`: sai de trás do botão que o disparou, para cima (o pai é position: relative), sem portal.
// `fixo`: não fecha sozinho (ex.: "Preparando o arquivo…" enquanto a ação não terminou). Sem animação com movimento reduzido.
export default function Toast({ tipo = 'info', duracao = TOAST_MINIMO_MS, aoFechar, ancorado = false, fixo = false, children }) {
    const [pausado, setPausado] = useState(false);
    const fechar = useRef(aoFechar);
    fechar.current = aoFechar;

    useEffect(() => {
        if (tipo === 'erro' || fixo || pausado) return undefined;
        const timer = setTimeout(() => fechar.current?.(), Math.max(TOAST_MINIMO_MS, duracao));
        return () => clearTimeout(timer);
    }, [tipo, fixo, pausado, duracao, children]);

    const aviso = <Aviso tipo={tipo} role={tipo === 'erro' ? 'alert' : 'status'}
        onMouseEnter={() => setPausado(true)} onMouseLeave={() => setPausado(false)}
        onFocus={() => setPausado(true)} onBlur={() => setPausado(false)}
        acao={fixo ? null : <button type="button" className="toast__fechar" aria-label="Fechar aviso" onClick={() => fechar.current?.()}>×</button>}>
        {children}
    </Aviso>;

    return ancorado
        ? <div className="toast-ancorado">{aviso}</div>
        : createPortal(<div className="toast-area">{aviso}</div>, document.body);
}
