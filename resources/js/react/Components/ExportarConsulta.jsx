import { useEffect, useRef, useState } from 'react';

export default function ExportarConsulta({ consulta, ocupado, adicionar }) {
    const [exportando, setExportando] = useState(false);
    const [estado, setEstado] = useState('');
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
        setEstado('Preparando o arquivo…');
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
                setEstado(`Download iniciado: ${nome}`);
            } finally {
                link.remove();
                // Dar ao navegador tempo para consumir o blob antes de liberá-lo.
                setTimeout(() => URL.revokeObjectURL(url), 1000);
            }
        } catch (erro) {
            if (!montado.current) return;
            const mensagem = erro.name === 'AbortError' ? 'A exportação demorou demais. Tente novamente.'
                : erro instanceof TypeError ? 'Falha ao contatar o servidor. Tente novamente.' : erro.message;
            setEstado('Exportação não concluída. Você pode tentar novamente.');
            adicionar(mensagem, 'msg--erro');
        } finally {
            clearTimeout(timeout);
            requisicao.current = null;
            if (montado.current) setExportando(false);
        }
    }

    return <div style={{ minWidth: 0, maxWidth: '100%', overflowWrap: 'anywhere' }} aria-busy={exportando}>
        <button type="button" className="btn-exportar" disabled={ocupado || exportando} onClick={exportar}>{exportando ? 'Exportando…' : 'Exportar CSV'}</button>
        <p role="status">{estado}</p>
    </div>;
}
