// Tema claro/escuro do front React (25/11). A escolha fica no navegador (por aparelho); na primeira visita vale o
// prefers-color-scheme do sistema. Sem armazenamento (aba anônima, bloqueio), o tema vale só nesta página.
const CHAVE = 'sentinel:tema';

function lerEscolha() {
    try {
        const valor = localStorage.getItem(CHAVE);
        return valor === 'claro' || valor === 'escuro' ? valor : null;
    } catch (e) {
        return null;
    }
}

function doSistema() {
    try {
        return matchMedia('(prefers-color-scheme: dark)').matches ? 'escuro' : 'claro';
    } catch (e) {
        return 'claro';
    }
}

export function temaAtual() {
    return document.documentElement.dataset.tema === 'escuro' ? 'escuro' : 'claro';
}

export function aplicarTema(tema) {
    document.documentElement.dataset.tema = tema;
    // A barra do navegador no celular acompanha o fundo.
    const fundo = getComputedStyle(document.documentElement).getPropertyValue('--cor-fundo').trim();
    if (fundo) document.querySelector('meta[name="theme-color"]')?.setAttribute('content', fundo);
}

export function escolherTema(tema) {
    aplicarTema(tema);
    try { localStorage.setItem(CHAVE, tema); } catch (e) { /* sem armazenamento: vale só nesta página */ }
}

// Antes do primeiro desenho do React (o <head> é do Blade, que fica congelado; o piscar curto no primeiro carregamento é conhecido).
export function iniciarTema() {
    aplicarTema(lerEscolha() ?? doSistema());
}
