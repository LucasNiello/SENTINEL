import { forwardRef, useEffect, useRef, useState } from 'react';

// Mesmo tempo do Blade (partials/bancada: DURACAO_HOLD_MS).
export const DURACAO_SEGURAR_MS = 2000;

/**
 * Botão do sistema: primario (azul da marca), secundario, perigo e fantasma.
 * Com `segurar`, o clique não executa: é preciso manter pressionado (mouse, toque, Enter ou Espaço) por 2 s; a barra
 * enche e o rótulo mostra o percentual. Soltar antes zera e chama `aoSoltarCedo` (nada é enviado). Espelha o Blade.
 */
export default forwardRef(function Botao({ variante = 'secundario', segurar = false, aoSoltarCedo, onClick, disabled,
    className = '', children, type = 'button', ...resto }, ref) {
    const [progresso, setProgresso] = useState(0);
    const segurando = useRef(false);
    const inicio = useRef(0);
    const quadro = useRef(null);
    const acao = useRef(onClick);
    acao.current = onClick;

    const parar = (cedo) => {
        if (!segurando.current) return;
        segurando.current = false;
        cancelAnimationFrame(quadro.current);
        setProgresso(0);
        if (cedo) aoSoltarCedo?.();
    };
    const passo = () => {
        if (!segurando.current) return;
        const pct = Math.min(100, ((performance.now() - inicio.current) / DURACAO_SEGURAR_MS) * 100);
        setProgresso(pct);
        if (pct >= 100) {
            segurando.current = false;
            setProgresso(0);
            acao.current?.();
            return;
        }
        quadro.current = requestAnimationFrame(passo);
    };
    const iniciar = () => {
        if (!segurar || disabled || segurando.current) return;
        segurando.current = true;
        inicio.current = performance.now();
        passo();
    };

    useEffect(() => () => cancelAnimationFrame(quadro.current), []);
    useEffect(() => { if (disabled || !segurar) parar(false); }, [disabled, segurar]);

    const eventos = segurar ? {
        onPointerDown: (e) => { if (e.pointerType !== 'mouse' || e.button === 0) iniciar(); },
        onPointerUp: () => parar(true),
        onPointerLeave: () => parar(true),
        onPointerCancel: () => parar(true),
        onBlur: () => parar(true),
        onKeyDown: (e) => {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            e.preventDefault();
            if (!e.repeat) iniciar();
        },
        onKeyUp: (e) => { if (e.key === 'Enter' || e.key === ' ') parar(true); },
        // O clique (inclusive o que o navegador gera ao soltar) nunca executa uma ação de segurar.
        onClick: (e) => e.preventDefault(),
        onContextMenu: (e) => e.preventDefault(),
    } : {
        onClick,
        // Sem segurar: a repetição automática de uma tecla segurada não dispara de novo.
        onKeyDown: (e) => { if (e.repeat && (e.key === 'Enter' || e.key === ' ')) e.preventDefault(); },
    };

    return <button ref={ref} type={type} disabled={disabled} className={`botao botao--${variante} ${className}`.trim()}
        style={segurar ? { touchAction: 'none', userSelect: 'none', WebkitUserSelect: 'none' } : undefined} {...resto} {...eventos}>
        {segurar && <span className="botao__barra" style={{ width: `${progresso}%` }} aria-hidden="true" />}
        <span className="botao__rotulo">{progresso > 0 ? <><small>confirmando</small>{Math.floor(progresso)}%</> : children}</span>
    </button>;
});
