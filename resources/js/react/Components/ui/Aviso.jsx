// Aviso: o padrão único de mensagem do sistema (info, sucesso, atenção, erro). Texto curto, ícone e a cor do estado.
const ICONES = {
    info: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zm0 8v5m0-8.5h.01',
    sucesso: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zm-4 9 3 3 5-6',
    atencao: 'M12 4 2.5 20h19L12 4zm0 6v4.5m0 3h.01',
    erro: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zm-3 6 6 6m0-6-6 6',
};

export default function Aviso({ tipo = 'info', titulo, acao, className = '', children, ...resto }) {
    const variante = ICONES[tipo] ? tipo : 'info';
    return <div className={`aviso aviso--${variante} ${className}`.trim()} {...resto}>
        <svg className="aviso__icone" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d={ICONES[variante]} /></svg>
        <div className="aviso__texto">{titulo && <strong>{titulo}</strong>}{children}</div>
        {acao}
    </div>;
}
