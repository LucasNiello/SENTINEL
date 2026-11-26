import { router } from '@inertiajs/react';
import { useState } from 'react';
import Workspace from '../Layouts/Workspace';
import Confirmacao from '../Components/Confirmacao';
import Aviso from '../Components/ui/Aviso';
import Botao from '../Components/ui/Botao';
import usarCartao from '../Components/usarCartao';

const ESPECIES = { cao: 'Cão', gato: 'Gato', ave: 'Ave', roedor: 'Roedor', reptil: 'Réptil', outro: 'Outro' };
const v = (x) => (x ? x : '—');

// Ficha do tutor (só leitura). O cadastro continua nos cards: "Editar o cadastro" abre o card de revisão.
export default function Tutor({ tutor, pets, podeEditar }) {
    const { cartao, ocupado, setOcupado, erro, trava, abrir } = usarCartao();
    const [aviso, setAviso] = useState('');
    const aoMensagem = (texto) => { setAviso(texto); router.reload(); };

    return <Workspace titulo="Ficha do tutor">
        <header className="page-header">
            <p className="eyebrow">Ficha do tutor</p>
            <h1>{tutor.nome}</h1>
            <p className="intro">Contato do tutor e os pets dele. Para alterar o cadastro, use "Editar o cadastro".</p>
        </header>
        <p className="acoes-topo">
            <a className="botao" href={`/clientes?cliente=${tutor.id}`}><span aria-hidden="true">←</span> Clientes</a>
            {podeEditar && <Botao variante="secundario" disabled={ocupado} onClick={() => abrir('atualizar_tutor', { id: tutor.id })}>Editar o cadastro</Botao>}
        </p>
        {erro && <Aviso tipo="erro" role="alert">{erro}</Aviso>}
        {aviso && <Aviso tipo="info" role="status">{aviso}</Aviso>}
        {cartao && <Confirmacao key={cartao.proposta} proposta={cartao} adicionar={aoMensagem} trava={trava} ocupado={ocupado} definirOcupado={setOcupado}
            abrirFormulario={(tool, id) => abrir(tool, { id })} />}

        <div className="ficha-grade">
            <section className="bloco" aria-labelledby="titulo-contato">
                <h2 id="titulo-contato">Contato</h2>
                <dl className="ficha">
                    <dt>Telefone</dt><dd className="mono">{v(tutor.telefone)}</dd>
                    <dt>E-mail</dt><dd>{v(tutor.email)}</dd>
                    <dt>Endereço</dt><dd>{v(tutor.endereco)}</dd>
                </dl>
            </section>
        </div>

        {pets !== null && <section className="bloco" aria-labelledby="titulo-pets">
            <h2 id="titulo-pets">Pets</h2>
            {pets.length === 0 ? <p className="vazio">Nenhum pet cadastrado.</p>
                : <ul className="pets-do-tutor" role="list">{pets.map((p) => <li key={p.id}>
                    <dl className="ficha">
                        <dt>Nome</dt><dd><a href={`/pets/${p.id}`}>{p.nome}</a></dd>
                        <dt>Espécie</dt><dd>{ESPECIES[p.especie] ?? p.especie}</dd>
                        <dt>Raça</dt><dd>{v(p.raca)}</dd>
                    </dl>
                </li>)}</ul>}
        </section>}
    </Workspace>;
}
