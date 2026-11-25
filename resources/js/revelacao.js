// Cortina de carregamento: cobre a página até fontes e recursos carregarem e abre ao meio
// (metade de cima sobe, a de baixo desce). Inspirado no Split Reveal da Animata, sem React.
const ESPERA_MS = 240;      // pausa depois de tudo pronto
const SUMIR_MS = 280;       // o progresso some antes de abrir
const ABRIR_MS = 400;       // mesma duração do CSS (.revelacao[data-fase=abrir])
const LIMITE_MS = 3000;     // nunca segura a página mais que isso, mesmo se algo travar

export function revelarPagina(raiz) {
    if (!raiz) return;
    if (document.documentElement.classList.contains('sem-cortina')) { raiz.remove(); return; }
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) { raiz.remove(); return; }

    const contagem = raiz.querySelector('[data-revelacao-contagem]');
    const doisDigitos = n => String(n).padStart(2, '0');
    const tarefas = [
        document.fonts?.ready ?? Promise.resolve(),
        new Promise(pronto => (document.readyState === 'complete' ? pronto() : window.addEventListener('load', pronto, { once: true }))),
    ];
    let feitas = 0;
    const marcar = () => {
        feitas++;
        raiz.style.setProperty('--progresso', feitas / tarefas.length);
        contagem.textContent = `${doisDigitos(feitas)} / ${doisDigitos(tarefas.length)}`;
    };
    contagem.textContent = `00 / ${doisDigitos(tarefas.length)}`;

    const todas = Promise.all(tarefas.map(tarefa => Promise.resolve(tarefa).catch(() => {}).then(marcar)));
    const limite = new Promise(pronto => setTimeout(pronto, LIMITE_MS));

    Promise.race([todas, limite]).then(() => {
        setTimeout(() => {
            raiz.dataset.fase = 'sumir';
            setTimeout(() => {
                raiz.dataset.fase = 'abrir';
                setTimeout(() => raiz.remove(), ABRIR_MS + 50);
            }, SUMIR_MS);
        }, ESPERA_MS);
    });
}
