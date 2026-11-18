// Símbolo do farol (mesmo SVG de resources/views/components/lighthouse.blade.php).
export default function Farol({ className = '' }) {
    return (
        <svg className={`lighthouse-symbol ${className}`.trim()} viewBox="0 0 120 120" fill="none" aria-hidden="true" focusable="false">
            <g fill="currentColor"><path d="M50 22 60 13 70 22ZM52 22h16v12H52zM47 34h26v4H47zM52.5 38h15L73 100H47ZM40 100h40v5H40z" /></g>
            <path className="lighthouse-window" d="M55 25.5h10v5H55z" />
            <path className="lighthouse-beam" d="m68 28 40-9v18Z" fill="currentColor" opacity=".35" />
        </svg>
    );
}
