import { useEffect, useRef, useState } from 'react';
import Botao from './ui/Botao';
import Toast from './ui/Toast';

// Todo retorno da exportação sai num toast só, ancorado no botão (sobe de trás dele). Sem texto escondido embaixo
// e sem repetir a mensagem no chat. "Preparando…" fica até terminar; o erro não fecha sozinho.
export default function ExportarConsulta({ consulta, ocupado }) {
    const [exportando, setExportando] = useState(false);
    const [toast, setToast] = useState(null);
    const requisicao = useRef(null);
    const montado = useRef(false);
    useEffect(() => {
        montado.current = true;
        return () => { montado.current = false; requisicao.current?.abort(); };
    }, []);

    async function exportar() {
        if (ocupado || requisicao.current) return;
        const controller = new AbortController();
        requisicao.current = controller;
        setExportando(true);
        setToast({ tipo: 'info', texto: 'Preparando o arquivo…', fixo: true });
        const timeout = setTimeout(() => controller.abort(), 35000);
        try {
            const resposta = await fetch('/agente/exportar', {
                method: 'POST', credentials: 'same-origin', signal: controller.signal,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
                body: JSON.stringify({ consulta }),
            });
            if (resposta.status === 401 || resposta.status === 419) { window.location.assign('/login'); return; }
            if (!resposta.ok) {
                const dados = await resposta.json().catch(() => null);
                throw new Error(typeof dados?.mensagem === 'string' ? dados.mensagem : 'Não consegui exportar esta tabela agora.');
            }
            if (!resposta.headers.get('Content-Type')?.toLowerCase().startsWith('text/csv')) {
                throw new Error('O servidor não retornou um arquivo CSV válido. Tente novamente.');
            }
            const arquivo = await resposta.blob();
            if (!montado.current) return;
            const nome = resposta.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/)?.[1] || 'consulta.csv';
            const url = URL.createObjectURL(arquivo);
            const link = document.createElement('a');
            try {
                link.href = url;
                link.download = nome;
                document.body.appendChild(link);
                link.click();
                setToast({ tipo: 'sucesso', texto: `Download iniciado: ${nome}` });
            } finally {
                link.remove();
                // Dar ao navegador tempo para consumir o blob antes de liberá-lo.
                setTimeout(() => URL.revokeObjectURL(url), 1000);
            }
        } catch (erro) {
            if (!montado.current) return;
            const mensagem = erro.name === 'AbortError' ? 'A exportação demorou demais. Tente novamente.'
                : erro instanceof TypeError ? 'Falha ao contatar o servidor. Tente novamente.' : erro.message;
            setToast({ tipo: 'erro', texto: mensagem });
        } finally {
            clearTimeout(timeout);
            requisicao.current = null;
            if (montado.current) setExportando(false);
        }
    }

    return <div className="exportar" style={{ minWidth: 0, maxWidth: '100%', overflowWrap: 'anywhere' }} aria-busy={exportando}>
        <Botao variante="secundario" className="btn-exportar" disabled={ocupado || exportando} onClick={exportar}>{exportando ? 'Exportando…' : 'Exportar CSV'}</Botao>
        {toast && <Toast ancorado tipo={toast.tipo} fixo={!!toast.fixo} aoFechar={() => setToast(null)}>{toast.texto}</Toast>}
    </div>;
}
