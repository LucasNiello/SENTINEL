import { useId } from 'react';
import Botao from './Botao';
import Campo from './Campo';
import Combobox from './Combobox';

// Lista repetível do cadastro unificado do cliente (tutor + pets). O servidor manda o molde (formulario.lista.modelo) e o erro de
// cada pet; os valores ficam no card. Linha totalmente em branco é ignorada ao revisar (coletarPets), então não há "required" nativo aqui.
export const petsIniciais = (lista) => (lista?.itens ?? []).map((i) => ({ ...i.valores }));
export const coletarPets = (pets) => pets
    .map((p) => Object.fromEntries(Object.entries(p).filter(([, v]) => String(v ?? '').trim() !== '')))
    .filter((p) => Object.keys(p).length > 0);

export default function ListaDePets({ lista, pets, aoMudar, bloqueado }) {
    const prefixo = useId();
    const modelo = lista.modelo ?? [];
    const trocar = (i, nome, valor) => aoMudar(pets.map((p, j) => (j === i ? { ...p, [nome]: valor } : p)));
    return <fieldset className="lista-pets" disabled={bloqueado}>
        <legend>{lista.rotulo || 'Pets'}</legend>
        {pets.length === 0 && <p className="campo-info">Sem pets: só o cliente será cadastrado.</p>}
        {pets.map((pet, i) => {
            const erros = lista.itens?.[i]?.erros ?? {};
            return <div className="lista-pets__item" key={i} role="group" aria-label={`Pet ${i + 1}`}>
                <div className="lista-pets__topo">
                    <strong>Pet {i + 1}{pet.nome ? ` · ${pet.nome}` : ''}</strong>
                    <Botao variante="fantasma" type="button" onClick={() => aoMudar(pets.filter((_, j) => j !== i))}>Remover este pet</Botao>
                </div>
                <div className="card-campos">
                    {modelo.map((campo) => {
                        const id = `${prefixo}-${i}-${campo.nome}`;
                        const erro = erros[campo.nome];
                        const valor = pet[campo.nome] ?? '';
                        return <Campo key={campo.nome} id={id} rotulo={`${campo.rotulo}`} obrigatorio={!!campo.obrigatorio} erro={erro}>
                            {campo.tipo === 'opcoes'
                                ? <Combobox id={id} name={`pets.${i}.${campo.nome}`} value={valor} opcoes={(campo.opcoes ?? []).map((o) => ({ valor: String(o.valor), rotulo: o.rotulo }))}
                                    aoMudar={(v) => trocar(i, campo.nome, v)} invalido={!!erro} descrito={`${id}-info`} />
                                : <input id={id} name={`pets.${i}.${campo.nome}`} value={valor} aria-invalid={erro ? true : undefined} aria-describedby={`${id}-info`}
                                    type={campo.tipo === 'data' ? 'date' : 'text'} inputMode={campo.tipo === 'numero' ? 'numeric' : undefined}
                                    maxLength={campo.tipo === 'numero' || campo.tipo === 'data' ? undefined : campo.max}
                                    onChange={(e) => trocar(i, campo.nome, e.target.value)} />}
                        </Campo>;
                    })}
                </div>
            </div>;
        })}
        {pets.length < (lista.max ?? 10) && <Botao variante="secundario" type="button" onClick={() => aoMudar([...pets, {}])}>Adicionar pet</Botao>}
    </fieldset>;
}
