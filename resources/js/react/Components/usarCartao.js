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
    // `abrindo`: só enquanto o formulário é buscado (diferente de `ocupado`, que também vale no Confirmar). Alimenta a CaixaCarregando.
    const [abrindo, setAbrindo] = useState(false);
    const trava = useRef(null);
    // 27/11: de onde o usuário clicou (linha e posição da página), para o card abrir À VISTA e a tela voltar à linha quando ele terminar.
    const origem = useRef(null);
    const soltarLinha = () => { origem.current?.linha?.classList.remove('linha-em-edicao'); };
    const reduzido = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    useEffect(() => {
        const voltar = () => {
            const o = origem.current;
            if (!o) return;
            origem.current = null;
            soltarLinha();
            // A lista recarrega depois de gravar: espera um instante e volta à linha (ou à posição, se a linha mudou de lugar ou saiu da lista).
            setTimeout(() => {
                const comportamento = reduzido() ? 'auto' : 'smooth';
                if (o.linha?.isConnected) o.linha.scrollIntoView({ block: 'center', behavior: comportamento });
                else window.scrollTo({ top: o.y, behavior: comportamento });
            }, 250);
        };
        window.addEventListener('sentinel:cartao-encerrado', voltar);
        return () => { window.removeEventListener('sentinel:cartao-encerrado', voltar); soltarLinha(); };
    }, []);
    const montado = useRef(true);
    useEffect(() => { montado.current = true; return () => { montado.current = false; }; }, []);

    async function abrir(tool, { id, tutor_id } = {}) {
        if (trava.current) return;
        const controller = new AbortController();
        trava.current = controller;
        setOcupado(true);
        setAbrindo(true);
        soltarLinha();
        const clicado = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        origem.current = { y: window.scrollY, linha: clicado?.closest?.('tr') ?? null };
        origem.current.linha?.classList.add('linha-em-edicao');
        setCartao(null);
        setErro('');
        try {
            const dados = await pedir('/agente/formulario', { method: 'POST', signal: controller.signal,
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify({ tool, id, tutor_id }) });
            if (!montado.current) return;
            if (dados?.tipo !== 'confirmacao_pendente' || typeof dados.proposta !== 'string') throw new Error('Não foi possível abrir o formulário. Tente novamente.');
            setCartao(dados);
            // O card abre no topo da página e a linha clicada pode estar lá embaixo: rola até ele (sem animação para quem pede menos movimento).
            requestAnimationFrame(() => { const el = document.querySelector('[data-cartao-aberto]'); el?.scrollIntoView({ block: 'start', behavior: reduzido() ? 'auto' : 'smooth' }); el?.querySelector('h2, h3, [tabindex]')?.focus?.({ preventScroll: true }); });
        } catch (e) { if (montado.current) setErro(e.name === 'AbortError' ? 'Demorou demais. Tente novamente.' : e.message); }
        finally { trava.current = null; if (montado.current) { setOcupado(false); setAbrindo(false); } }
    }

    return { cartao, setCartao, abrindo, ocupado, setOcupado, erro, setErro, trava, abrir };
}
