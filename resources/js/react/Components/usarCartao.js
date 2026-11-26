import { useEffect, useRef, useState } from 'react';

// Abre o card (Revisar/Confirmar) de uma ação da clínica a partir de uma tela, sem passar pelo chat.
// O servidor continua decidindo tudo: /agente/formulario confere papel, tenant e monta a proposta.
export const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

export async function pedir(url, opcoes = {}) {
    const resposta = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', ...(opcoes.headers ?? {}) }, ...opcoes });
    if (resposta.status === 401 || resposta.status === 419) { window.location.assign('/login'); throw new Error('Sessão expirada.'); }
    const dados = await resposta.json().catch(() => null);
    if (!resposta.ok) throw new Error(dados?.mensagem || (resposta.status === 403 ? 'Você não tem permissão para esta operação.' : 'Não foi possível concluir. Tente novamente.'));
    return dados;
}

export default function usarCartao() {
    const [cartao, setCartao] = useState(null);
    const [ocupado, setOcupado] = useState(false);
    const [erro, setErro] = useState('');
    const trava = useRef(null);
    const montado = useRef(true);
    useEffect(() => { montado.current = true; return () => { montado.current = false; }; }, []);

    async function abrir(tool, { id, tutor_id } = {}) {
        if (trava.current) return;
        const controller = new AbortController();
        trava.current = controller;
        setOcupado(true);
        setErro('');
        try {
            const dados = await pedir('/agente/formulario', { method: 'POST', signal: controller.signal,
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify({ tool, id, tutor_id }) });
            if (!montado.current) return;
            if (dados?.tipo !== 'confirmacao_pendente' || typeof dados.proposta !== 'string') throw new Error('Não foi possível abrir o formulário. Tente novamente.');
            setCartao(dados);
        } catch (e) { if (montado.current) setErro(e.name === 'AbortError' ? 'Demorou demais. Tente novamente.' : e.message); }
        finally { trava.current = null; if (montado.current) setOcupado(false); }
    }

    return { cartao, setCartao, ocupado, setOcupado, erro, setErro, trava, abrir };
}
