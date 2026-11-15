// Somente navegação e affordances de apresentação. Operações pertencem aos scripts existentes.
const sidebar = document.getElementById('workspace-sidebar');
const toggle = document.querySelector('.workspace-menu-toggle');
const backdrop = document.querySelector('[data-menu-backdrop]');
const content = document.querySelector('.workspace-main');
const mobile = matchMedia('(max-width: 1023px)');
let returnFocus = null;
function closeMenu(restore = true) {
    document.body.classList.remove('nav-open');
    backdrop.hidden = true;
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
    content.inert = false;
    sidebar.inert = mobile.matches && !!toggle;
    if (restore && returnFocus?.isConnected) returnFocus.focus();
}
function openMenu() {
    if (!mobile.matches || !toggle) return;
    returnFocus = document.activeElement;
    sidebar.inert = false;
    document.body.classList.add('nav-open');
    backdrop.hidden = false;
    toggle.setAttribute('aria-expanded', 'true');
    content.inert = true;
    sidebar.querySelector('[data-close-menu]').focus();
}
if (toggle) {
    toggle.addEventListener('click', openMenu);
    sidebar.querySelector('[data-close-menu]').addEventListener('click', () => closeMenu());
    backdrop.addEventListener('click', () => closeMenu());
    mobile.addEventListener('change', () => closeMenu(false));
    closeMenu(false);
    document.addEventListener('keydown', e => {
        if (!document.body.classList.contains('nav-open')) return;
        if (e.key === 'Escape') { e.preventDefault(); closeMenu(); }
        if (e.key === 'Tab') {
            const items = [...sidebar.querySelectorAll('a[href],button:not(:disabled)')].filter(x => x.getClientRects().length);
            const first = items[0], last = items.at(-1);
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    });
}
document.querySelectorAll('[data-suggestion]').forEach(button => {
    button.addEventListener('click', () => {
        const field = document.getElementById('campo-mensagem');
        if (!field || field.disabled) return;
        field.value = button.dataset.suggestion;
        field.focus(); // Preenche; nunca envia nem confirma uma operação.
    });
});
// F4 (14/11): tamanho do texto em 3 níveis, guardado no navegador por usuário (sem migration). O <head> já aplicou a
// escolha antes de pintar; aqui os botões trocam, gravam e dizem qual está ativo (aria-pressed + contorno).
const botoesTexto = [...document.querySelectorAll('.texto-tamanho [data-texto]')];
if (botoesTexto.length) {
    const raiz = document.documentElement;
    const chave = 'sentinel:texto:' + (raiz.dataset.usuario || '0');
    const marcar = () => botoesTexto.forEach(b => b.setAttribute('aria-pressed', String(b.dataset.texto === (raiz.dataset.texto || '1'))));
    botoesTexto.forEach(botao => botao.addEventListener('click', () => {
        const nivel = botao.dataset.texto;
        if (nivel === '1') delete raiz.dataset.texto; else raiz.dataset.texto = nivel;
        try { nivel === '1' ? localStorage.removeItem(chave) : localStorage.setItem(chave, nivel); } catch (e) { /* sem armazenamento: vale só nesta página */ }
        marcar();
    }));
    marcar();
}
