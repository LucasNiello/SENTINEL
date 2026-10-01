import { smooth, storyPose } from './lighthouse/motion';

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const stage = document.querySelector('[data-stage]');
const state = { progress: 0, confirmation: 0, confirmedAt: 0 };
const loader = document.querySelector('[data-loader]');
let scene = null;
let sceneGeneration = 0;

// Hidden in server HTML. A failed module or WebGL import can never cover the page.
function closeLoader() {
    loader.classList.add('is-ready');
    setTimeout(() => { loader.hidden = true; }, 200);
}
if (!reducedMotion.matches && window.scrollY < 50) {
    loader.hidden = false;
    setTimeout(closeLoader, 900);
}

async function startScene() {
    const generation = ++sceneGeneration;
    scene?.dispose();
    scene = null;
    document.documentElement.classList.toggle('scene-enhanced', !reducedMotion.matches);
    if (reducedMotion.matches) { closeLoader(); return; }
    try {
        const { createLighthouse } = await import('./lighthouse/scene');
        if (generation !== sceneGeneration || reducedMotion.matches) return;
        scene = createLighthouse(stage, state, () => { scene = null; });
    } catch {
        stage.classList.remove('webgl-ready');
    } finally {
        // No artificial minimum wait: HTML and the SVG are already ready.
        closeLoader();
    }
}
reducedMotion.addEventListener('change', startScene);
startScene();

const chapters = [...document.querySelectorAll('[data-chapter]')];
const header = document.querySelector('.site-header');
const workbench = document.querySelector('.workbench');
const transition = document.querySelector('.light-transition');
const captions = ['Observar antes de agir.', 'Encontrar o que importa.', 'A decisão continua sua.', 'Cada ação, um registro.', 'Clareza para seguir.'];
const caption = document.querySelector('[data-scene-caption]');
const markers = [...document.querySelectorAll('.story-progress i')];
const conversation = document.querySelector('.conversation');
const auditEvents = [...document.querySelectorAll('.audit-list li')];
let offsets = [], workbenchTop = 0, transitionTop = 0, transitionHeight = 1, scrollFrame = 0, activeChapter = -1;
function measure() {
    offsets = chapters.map((chapter,index) => index ? chapter.getBoundingClientRect().top + window.scrollY - window.innerHeight * .12 : 0);
    workbenchTop = workbench.getBoundingClientRect().top + window.scrollY;
    transitionTop = transition.getBoundingClientRect().top + window.scrollY;
    transitionHeight = transition.offsetHeight;
    offsets.push(transitionTop + transitionHeight * .25 - window.innerHeight * .25, workbenchTop - window.innerHeight * .65);
    scheduleScroll();
}
function updateScroll() {
    scrollFrame = 0;
    const y = window.scrollY;
    let index = 0;
    while (index < offsets.length - 2 && y >= offsets[index + 1]) index++;
    const span = offsets[index + 1] - offsets[index];
    state.progress = index + Math.max(0, Math.min(1, (y - offsets[index]) / Math.max(1,span)));
    if (index !== activeChapter) {
        activeChapter = index;
        caption.textContent = captions[index] ?? captions[4];
        markers.forEach((marker,i) => marker.classList.toggle('is-active',i===index));
    }
    header.classList.toggle('is-scrolled', y > 24);
    const mobile = window.innerWidth <= 600;
    const light = smooth((state.progress - 3.8) / 1.1);
    header.classList.toggle('is-light', (!mobile && !reducedMotion.matches && light>.68) || y >= workbenchTop - 80);
    stage.style.setProperty('--light-open', reducedMotion.matches || mobile ? 0 : light);
    stage.style.setProperty('--scene-label-opacity', 1-smooth((state.progress-3.3)/.6));
    stage.style.setProperty('--caption-opacity', index===0 ? 1 : 0);
    stage.style.setProperty('--scene-anchor', `${50+storyPose(state.progress).framing*100}%`);
    transition.style.setProperty('--transition-copy', smooth((state.progress-4.15)/.55));
    conversation.classList.toggle('is-lit', !reducedMotion.matches && state.progress>.72 && state.progress<1.55);
    auditEvents.forEach((event,i) => event.classList.toggle('is-lit', state.progress>=2.85+i*.24 && state.progress<3.4+i*.24));
}
function scheduleScroll() { if (!scrollFrame) scrollFrame = requestAnimationFrame(updateScroll); }
window.addEventListener('scroll', scheduleScroll, { passive: true });
window.addEventListener('resize', measure, { passive: true });
const layoutObserver = new ResizeObserver(measure);
layoutObserver.observe(document.querySelector('main'));
document.fonts?.ready.then(measure);
measure();

const revealObserver = new IntersectionObserver(entries => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            if (!reducedMotion.matches) entry.target.classList.add('is-entering');
            revealObserver.unobserve(entry.target);
        }
    });
}, { threshold: .2 });
document.querySelectorAll('.demo').forEach(demo => revealObserver.observe(demo));

// Local demonstration only: no fetch, form submission, API or persisted data.
const hold = document.querySelector('[data-hold]');
const cancel = document.querySelector('[data-cancel]');
const status = document.querySelector('#hold-status');
const label = hold.querySelector('.hold-label');
let holdStart = null, holdFrame = 0, confirmed = false, input = null;
function reset(message = 'Soltou antes do fim. Nada foi gravado.') {
    cancelAnimationFrame(holdFrame);
    holdStart = null; input = null; confirmed = false;
    state.confirmation = 0;
    state.confirmedAt = 0;
    hold.style.setProperty('--hold', 0);
    hold.classList.remove('is-holding', 'is-confirmed');
    hold.removeAttribute('aria-disabled');
    label.textContent = 'Segure para confirmar';
    status.textContent = message;
}
function release() { if (holdStart !== null && !confirmed) reset(); }
function advance(now) {
    if (holdStart === null) return;
    const progress = Math.min(1, (now - holdStart) / 1000);
    state.confirmation = progress;
    hold.style.setProperty('--hold', progress);
    if (progress < 1) { holdFrame = requestAnimationFrame(advance); return; }
    confirmed = true; holdStart = null; input = null;
    state.confirmedAt = performance.now();
    hold.classList.remove('is-holding');
    hold.classList.add('is-confirmed');
    hold.setAttribute('aria-disabled', 'true');
    label.textContent = 'Confirmado';
    status.textContent = 'Confirmado. Demonstração concluída; nenhum dado real foi gravado.';
}
function begin(source) {
    if (confirmed || holdStart !== null) return;
    input = source;
    holdStart = performance.now();
    hold.classList.add('is-holding');
    status.textContent = 'Segurando… Você decide se continua.';
    holdFrame = requestAnimationFrame(advance);
}
hold.addEventListener('pointerdown', event => {
    if (!event.isPrimary || event.button !== 0) return;
    hold.focus({ preventScroll: true });
    begin(`pointer:${event.pointerId}`);
});
hold.addEventListener('pointerleave', () => { if (input?.startsWith('pointer:')) release(); });
window.addEventListener('pointerup', event => { if (input === `pointer:${event.pointerId}`) release(); });
window.addEventListener('pointercancel', event => { if (input === `pointer:${event.pointerId}`) release(); });
hold.addEventListener('keydown', event => {
    if (event.key === ' ' || event.key === 'Enter') {
        event.preventDefault();
        if (!event.repeat) begin(event.key);
    } else if (event.key === 'Escape') release();
});
hold.addEventListener('keyup', event => {
    if (event.key === ' ' || event.key === 'Enter') event.preventDefault();
    if (input === event.key) release();
});
hold.addEventListener('blur', release);
hold.addEventListener('contextmenu', event => event.preventDefault());
window.addEventListener('blur', release);
document.addEventListener('visibilitychange', () => { if (document.hidden) release(); });
cancel.addEventListener('click', () => reset('Cancelado. Nada foi gravado. Você pode experimentar novamente.'));
document.querySelector('[data-hold-controls]').hidden = false;

window.addEventListener('pagehide', () => { sceneGeneration++; scene?.dispose(); scene = null; release(); });
window.addEventListener('pageshow', event => { if (event.persisted) { measure(); startScene(); } });
