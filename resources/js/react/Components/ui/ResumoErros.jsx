import Aviso from './Aviso';

// Uma linha no topo ("2 campos precisam de ajuste") que leva ao primeiro campo com erro dentro de `container`.
export default function ResumoErros({ quantidade, container }) {
    if (!quantidade) return null;
    const irParaOPrimeiro = () => {
        // Campo digitável primeiro; na ficha de agenda o erro de horário fica no calendário (sem input), então rola até ele.
        const alvo = container?.current?.querySelector('[aria-invalid="true"]') || container?.current?.querySelector('.campo-erro');
        if (!alvo) return;
        alvo.scrollIntoView({ block: 'center' });
        if (alvo.matches('input, select, textarea')) alvo.focus({ preventScroll: true });
    };
    return <Aviso tipo="erro" role="alert" className="resumo-erros">
        <span>{quantidade === 1 ? '1 campo precisa de ajuste. Nada foi gravado.' : `${quantidade} campos precisam de ajuste. Nada foi gravado.`}</span>
        <button type="button" onClick={irParaOPrimeiro}>Ir para o {quantidade === 1 ? 'campo' : 'primeiro'}</button>
    </Aviso>;
}
