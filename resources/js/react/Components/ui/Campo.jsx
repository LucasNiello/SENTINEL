// Campo: rótulo, "*obrigatório" em vermelho, borda vermelha no erro e a mensagem curta embaixo. O erro aparece só aqui;
// o resumo do topo (ResumoErros) só conta e leva até ele. O input vem como filho e recebe o id.
export default function Campo({ id, rotulo, obrigatorio = false, erro, info, hidden, className = '', children }) {
    return <div className={`campo${erro ? ' campo--erro' : ''} ${className}`.trim()} hidden={hidden} style={{ minWidth: 0 }}>
        <label htmlFor={id}>{rotulo}{obrigatorio && <span className="campo-obrigatorio"> *obrigatório</span>}</label>
        {children}
        <div id={`${id}-info`}>
            {info}
            {erro && <p className="campo-erro">{erro}</p>}
        </div>
    </div>;
}
