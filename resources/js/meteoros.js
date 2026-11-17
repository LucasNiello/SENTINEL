// Céu de meteoros (port do Meteors do Lumenite UI, Web Animations API). Usado na vitrine e no login.
const semente = (i, s = 1) => Math.abs(Math.sin(i * 78.233 * s + s * 1.7));
const QUANTIDADE = 22;
const ANGULO_GRAUS = 55;

export function montarCeuMeteoros(ceuMeteoros) {
    if (!ceuMeteoros || !Element.prototype.animate) return;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    function criarMeteoros() {
        if (reducedMotion.matches || ceuMeteoros.childElementCount) return;
        const radianos = ANGULO_GRAUS * Math.PI / 180;
        const rotacao = `rotate(${ANGULO_GRAUS}deg)`;
        for (let i = 0; i < QUANTIDADE; i++) {
            const distancia = 480 + semente(i, 0.7) * 380;
            const atraso = semente(i, 3.1) * 6;
            const duracao = 2.2 + semente(i, 1.3) * 2.4;
            const pausa = 1.5 + semente(i, 4.4) * 3.5;
            const dx = Math.cos(radianos) * distancia;
            const dy = Math.sin(radianos) * distancia;
            const meteoro = document.createElement('div');
            meteoro.className = 'meteoro';
            meteoro.style.left = `${-10 + semente(i, 1.7) * 115}%`;
            meteoro.style.top = `${-20 + semente(i, 2.3) * 55}%`;
            meteoro.style.width = `${55 + Math.round(semente(i, 2.9) * 70)}px`;
            meteoro.append(document.createElement('span'));
            ceuMeteoros.append(meteoro);
            const total = duracao + pausa;
            const p = duracao / total;
            const inicio = `translate(0px, 0px) ${rotacao}`;
            const fim = `translate(${dx}px, ${dy}px) ${rotacao}`;
            meteoro.animate([
                { offset: 0, transform: inicio, opacity: 0 },
                { offset: p * 0.15, opacity: 1 },
                { offset: p * 0.85, opacity: 1 },
                { offset: p, transform: fim, opacity: 0 },
                { offset: 1, transform: fim, opacity: 0 },
            ], { duration: total * 1000, delay: atraso * 1000, iterations: Infinity, easing: 'linear' });
        }
    }

    reducedMotion.addEventListener('change', criarMeteoros);
    criarMeteoros();
}
