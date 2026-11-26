import FarolCarregando from './FarolCarregando';

// Padrão de carregamento do sistema: uma caixa com o contorno de um card, no lugar onde o conteúdo vai aparecer,
// com o farol (facho varrendo os dois lados). O texto é só para leitor de tela.
export default function CaixaCarregando({ texto = 'Carregando…', className = '' }) {
    return <div className={`caixa-carregando ${className}`.trim()} role="status" aria-live="polite" aria-busy="true">
        <FarolCarregando className="caixa-carregando__farol" />
        <span className="sr-only">{texto}</span>
    </div>;
}
