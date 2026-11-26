import { useId } from 'react';
import Campo from './ui/Campo';
import Combobox from './ui/Combobox';

export const valoresIniciais = (campos) => Object.fromEntries(campos.map((c) => [c.nome, c.sensivel ? '' : String(c.valor ?? '')]));

export function condicoesCampo(campo, valores) {
    const condicoes = (campo.condicoes ?? []).filter((c) => String(valores[c.campo] ?? '') === String(c.valor));
    return { proibido: condicoes.some((c) => c.efeito === 'proibido'),
        obrigatorio: campo.obrigatorio || condicoes.some((c) => c.efeito === 'obrigatorio') };
}

export function coletarValores(campos, valores) {
    return Object.fromEntries(campos.flatMap((campo) => {
        const valor = valores[campo.nome] ?? '';
        if (condicoesCampo(campo, valores).proibido) return [[campo.nome, '']];
        return campo.sensivel && valor === '' ? [] : [[campo.nome, valor]];
    }));
}

export default function CamposProposta({ campos, valores, alterar, bloqueado }) {
    const prefixo = useId();
    return <fieldset className="card-campos" disabled={bloqueado}>
        {campos.map((campo) => {
            const { proibido, obrigatorio } = condicoesCampo(campo, valores);
            const id = `${prefixo}-${campo.nome}`;
            const props = { id, name: campo.nome, value: valores[campo.nome] ?? '', disabled: proibido,
                required: !!obrigatorio && !(campo.sensivel && campo.informado),
                'aria-invalid': campo.erro ? true : undefined, 'aria-describedby': `${id}-info`,
                onChange: (e) => alterar(campo.nome, e.target.value) };
            // O erro fica só no campo (sem role="alert": o resumo do card é o único alerta e leva até aqui).
            return <Campo key={campo.nome} id={id} rotulo={campo.rotulo || campo.nome} obrigatorio={!!obrigatorio} erro={campo.erro} hidden={proibido}
                info={<>
                    {campo.sensivel && <p className="campo-info">{campo.informado ? `Informado${campo.valor ? `: ${campo.valor}` : ''}. Deixe em branco para manter.` : 'Não informado.'}</p>}
                    {campo.tipo === 'numero' && <p className="campo-info">{campo.inteiro ? 'Informe um número inteiro.' : 'Use ponto nos centavos (ex.: 4567.89).'}{campo.min != null && ` Mínimo: ${campo.min}.`}{campo.max != null && ` Máximo: ${campo.max}.`}</p>}
                </>}>
                {['opcoes', 'referencia'].includes(campo.tipo)
                    ? <Combobox id={id} name={campo.nome} value={valores[campo.nome] ?? ''} opcoes={(campo.opcoes ?? []).map((o) => ({ valor: String(o.valor), rotulo: o.rotulo }))}
                        aoMudar={(valor) => alterar(campo.nome, valor)} required={props.required} disabled={proibido} invalido={!!campo.erro} descrito={props['aria-describedby']} />
                    : <input {...props} type={{ email: 'email', data: 'date' }[campo.tipo] || 'text'}
                        inputMode={campo.tipo === 'numero' ? campo.inteiro ? 'numeric' : 'decimal' : undefined}
                        minLength={campo.tipo === 'numero' ? undefined : campo.min}
                        maxLength={campo.tipo === 'numero' ? undefined : campo.max} />}
            </Campo>;
        })}
    </fieldset>;
}
