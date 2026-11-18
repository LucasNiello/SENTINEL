import { Head, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Farol from '../Components/Farol';

const MOBILE = '(max-width: 1023px)';

// Espelha resources/views/layouts/app.blade.php e o comportamento de resources/js/workspace.js
// (menu móvel com foco preso, Esc e backdrop; tamanho do texto em 3 níveis guardado no navegador).
// Os links do menu são <a> comuns: as demais telas ainda são Blade e precisam de carga completa da página.
export default function Workspace({ titulo = 'Sentinel', children }) {
    const { usuario, menu, appNome } = usePage().props;
    const temUsuario = !!usuario;

    const sidebar = useRef(null);
    const botaoFechar = useRef(null);
    const retornoFoco = useRef(null);
    const [movel, setMovel] = useState(() => matchMedia(MOBILE).matches);
    const [aberto, setAberto] = useState(false);
    const [nivelTexto, setNivelTexto] = useState(() => document.documentElement.dataset.texto || '1');

    const fechar = (restaurar = true) => {
        setAberto(false);
        if (restaurar && retornoFoco.current?.isConnected) retornoFoco.current.focus();
    };
    const abrir = () => {
        if (!movel || !temUsuario) return;
        retornoFoco.current = document.activeElement;
        setAberto(true);
    };

    useEffect(() => {
        const consulta = matchMedia(MOBILE);
        const aoMudar = () => { setMovel(consulta.matches); setAberto(false); };
        consulta.addEventListener('change', aoMudar);
        return () => consulta.removeEventListener('change', aoMudar);
    }, []);

    useEffect(() => {
        document.body.classList.toggle('nav-open', aberto);
        if (aberto) botaoFechar.current?.focus();
        return () => document.body.classList.remove('nav-open');
    }, [aberto]);

    useEffect(() => {
        if (!aberto) return;
        const aoTeclar = (e) => {
            if (e.key === 'Escape') { e.preventDefault(); fechar(); }
            if (e.key === 'Tab' && sidebar.current) {
                const itens = [...sidebar.current.querySelectorAll('a[href],button:not(:disabled)')].filter((x) => x.getClientRects().length);
                const primeiro = itens[0], ultimo = itens.at(-1);
                if (e.shiftKey && document.activeElement === primeiro) { e.preventDefault(); ultimo.focus(); }
                else if (!e.shiftKey && document.activeElement === ultimo) { e.preventDefault(); primeiro.focus(); }
            }
        };
        document.addEventListener('keydown', aoTeclar);
        return () => document.removeEventListener('keydown', aoTeclar);
    }, [aberto]);

    const trocarTexto = (nivel) => {
        const raiz = document.documentElement;
        if (nivel === '1') delete raiz.dataset.texto; else raiz.dataset.texto = nivel;
        try {
            const chave = 'sentinel:texto:' + usuario.id;
            nivel === '1' ? localStorage.removeItem(chave) : localStorage.setItem(chave, nivel);
        } catch (e) { /* sem armazenamento: vale só nesta página */ }
        setNivelTexto(nivel);
    };

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    return (
        <>
            <Head title={`${titulo} — ${appNome}`} />
            <a className="pular" href="#conteudo">Pular para o conteúdo</a>
            <aside id="workspace-sidebar" ref={sidebar} className="workspace-sidebar" aria-label="Navegação lateral" inert={movel && temUsuario && !aberto}>
                <header className="topo">
                    <a className="marca" href="/agente" aria-label="Sentinel — ir para o agente"><Farol /><span>SENTINEL<small>CONTROL SYSTEM</small></span></a>
                    {temUsuario ? (
                        <button ref={botaoFechar} className="sidebar-close" type="button" aria-label="Fechar menu" onClick={() => fechar()}>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6" /></svg>
                        </button>
                    ) : (
                        <a href="/login" className="guest-access">Entrar</a>
                    )}
                </header>
                {temUsuario && (
                    <>
                        <p className="sidebar-caption">Área de trabalho</p>
                        <nav className="menu" aria-label="Principal">
                            {menu.map((item) => (
                                <a key={item.caminho} href={`/${item.caminho}`} aria-current={item.ativo ? 'page' : undefined}>
                                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d={item.icone} /></svg>
                                    <span>{item.rotulo}</span>
                                </a>
                            ))}
                        </nav>
                        <p className="sidebar-note">Inteligência para agir.<br />Controle para decidir.</p>
                        <div className="texto-tamanho" role="group" aria-labelledby="rotulo-texto">
                            <span id="rotulo-texto">Tamanho do texto</span>
                            <div className="texto-tamanho__botoes">
                                <button type="button" aria-pressed={nivelTexto === '1'} aria-label="Texto normal" onClick={() => trocarTexto('1')}>A</button>
                                <button type="button" aria-pressed={nivelTexto === '2'} aria-label="Texto grande" onClick={() => trocarTexto('2')}>A+</button>
                                <button type="button" aria-pressed={nivelTexto === '3'} aria-label="Texto maior" onClick={() => trocarTexto('3')}>A++</button>
                            </div>
                        </div>
                        <div className="sidebar-account">
                            <span className="account-initials" aria-hidden="true">{usuario.iniciais}</span>
                            <span className="usuario"><span>{usuario.nome}</span><span className="papel-tag"><span className="sr-only">Papel: </span>{usuario.rotuloPapel}</span></span>
                            <form method="POST" action="/logout">
                                <input type="hidden" name="_token" value={csrf} />
                                <button type="submit">Sair <span aria-hidden="true">↗</span></button>
                            </form>
                        </div>
                    </>
                )}
            </aside>
            <div className="workspace-backdrop" hidden={!aberto} onClick={() => fechar()} />
            <div className="workspace-main" inert={aberto}>
                <div className="workspace-topbar">
                    {temUsuario && (
                        <button className="workspace-menu-toggle" type="button" aria-label="Abrir menu" aria-expanded={aberto} aria-controls="workspace-sidebar" onClick={abrir}>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
                        </button>
                    )}
                    <div className="workspace-context"><span>Sentinel</span><span className="context-divider" aria-hidden="true">/</span><span>{titulo}</span></div>
                    {temUsuario && (
                        <span className="workspace-topbar__note"><i aria-hidden="true" /> Sessão autenticada <span>{usuario.rotuloPapel}</span></span>
                    )}
                </div>
                <main id="conteudo" tabIndex={-1}>
                    {children}
                </main>
                <footer className="rodape">Sentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP</footer>
            </div>
        </>
    );
}
