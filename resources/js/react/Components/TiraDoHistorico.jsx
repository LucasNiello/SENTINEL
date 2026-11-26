// A "tira" que o card finalizado deixa na conversa, refeita a partir do histórico quando a página é recarregada (UX: nada some
// como se tivesse dado erro). Só título, desfecho e hora; nenhum dado do card. Mesmo visual de Confirmacao (card-tira).
const DESFECHOS = {
    confirmada: { tipo: 'sucesso', texto: 'Confirmado' },
    cancelada: { tipo: 'neutro', texto: 'Cancelado' },
    substituida: { tipo: 'neutro', texto: 'Substituído por novo pedido' },
    expirada: { tipo: 'neutro', texto: 'Expirado' },
    falhou: { tipo: 'erro', texto: 'Não executado' },
    pendente: { tipo: 'neutro', texto: 'Não finalizado. Peça de novo para gerar outra' },
};
const MARCAS = { sucesso: '✓', erro: '!', neutro: '–' };

export default function TiraDoHistorico({ tira }) {
    const d = tira.tipo === 'consulta'
        ? { tipo: 'neutro', titulo: `Consulta: ${tira.linhas} ${tira.linhas === 1 ? 'registro' : 'registros'}`, texto: 'os dados não ficam guardados; peça de novo para ver' }
        : { ...(DESFECHOS[tira.desfecho] ?? DESFECHOS.pendente), titulo: tira.titulo };
    return <section className={`card-tira card-tira--${d.tipo}`} aria-label={tira.tipo === 'consulta' ? 'Consulta anterior' : 'Confirmação de ação anterior'}>
        <span className="card-tira__marca" aria-hidden="true">{MARCAS[d.tipo]}</span>
        <span className="card-tira__texto">{d.titulo} · {d.texto}</span>
        {tira.hora && <span className="card-tira__hora">{tira.hora}</span>}
    </section>;
}
