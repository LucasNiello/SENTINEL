// Farol 2D de carregamento: o facho de luz cresce para a esquerda, diminui, cresce para a direita, diminui, e repete (1,5 s o ciclo todo).
// O desenho é o mesmo do Farol da marca; a animação está em tema.css (.farol-carregando). Sem texto: quem usa põe o rótulo para leitor de tela.
export default function FarolCarregando({ className = '' }) {
    return <svg className={`farol-carregando ${className}`.trim()} viewBox="0 0 120 120" fill="none" aria-hidden="true" focusable="false">
        <g fill="currentColor"><path d="M50 22 60 13 70 22ZM52 22h16v12H52zM47 34h26v4H47zM52.5 38h15L73 100H47ZM40 100h40v5H40z" /></g>
        <path className="lighthouse-window" d="M55 25.5h10v5H55z" />
        <path className="farol-facho farol-facho--esq" d="m52 28-80-18v36Z" fill="currentColor" />
        <path className="farol-facho farol-facho--dir" d="m68 28 80-18v36Z" fill="currentColor" />
    </svg>;
}
