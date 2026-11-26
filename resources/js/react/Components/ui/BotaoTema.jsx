import { useState } from 'react';
import { escolherTema, temaAtual } from '../../tema';

const SOL = 'M12 4V2m0 20v-2m8-8h2M2 12h2m13.66-5.66 1.41-1.41M4.93 19.07l1.41-1.41m0-11.32L4.93 4.93m14.14 14.14-1.41-1.41M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10z';
const LUA = 'M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z';

// Alterna claro/escuro. O rótulo diz para onde vai; aria-pressed diz se o escuro está ligado.
export default function BotaoTema() {
    const [tema, setTema] = useState(temaAtual);
    const escuro = tema === 'escuro';
    const alternar = () => {
        const novo = escuro ? 'claro' : 'escuro';
        escolherTema(novo);
        setTema(novo);
    };
    return <button type="button" className="botao-tema" aria-pressed={escuro} onClick={alternar}>
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d={escuro ? SOL : LUA} /></svg>
        <span className="botao-tema__texto">Tema escuro</span>
    </button>;
}
