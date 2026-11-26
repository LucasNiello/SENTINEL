import Aviso from './Aviso';
import Botao from './Botao';

// Aviso antes de uma ação em lote: a pergunta com a contagem e o verbo real ("Tem certeza que quer cancelar 3 agendamentos?")
// e o botão de segurar 2 s. Um componente só para cancelar, estornar, conciliar ou excluir; quem usa passa o texto.
export default function AvisoConfirmacao({ pergunta, rotulo, ocupado, aoConfirmar, aoVoltar, aoSoltarCedo }) {
    return <Aviso tipo="atencao" className="aviso-confirmacao" role="group" aria-label="Confirmar ação em lote">
        <p className="aviso-confirmacao__pergunta">{pergunta}</p>
        <p className="aviso-confirmacao__dica">Segure o botão por 2 segundos. Se soltar antes, nada é feito.</p>
        <div className="aviso-confirmacao__acoes">
            <Botao variante="perigo" segurar disabled={ocupado} onClick={aoConfirmar} aoSoltarCedo={aoSoltarCedo}>{rotulo}</Botao>
            <Botao variante="fantasma" disabled={ocupado} onClick={aoVoltar}>Voltar</Botao>
        </div>
    </Aviso>;
}
