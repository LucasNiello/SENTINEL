import * as THREE from 'three';
import { createLighthouseModel, createLighting } from './model';
import { sceneQuality } from './quality';

export const TOWER_SECONDS_PER_TURN = 84;
export const LANTERN_SECONDS_PER_TURN = 15;
const TAU = Math.PI * 2;

// Separate world-space rotations: the lantern does not inherit the tower's speed.
export function loginAngles(seconds) {
    const tower = TAU * (seconds % TOWER_SECONDS_PER_TURN) / TOWER_SECONDS_PER_TURN;
    const lantern = .9 + TAU * (seconds % LANTERN_SECONDS_PER_TURN) / LANTERN_SECONDS_PER_TURN;
    return { tower, lantern: lantern - tower };
}

export function mountLoginLighthouse() {
    const stage = document.querySelector('#art-stage');
    const host = document.querySelector('#stage-canvas');
    const panel = document.querySelector('.visual');
    const status = document.querySelector('#model-status');
    if (!stage || !host || !panel || !status) return;
    const reduced = matchMedia('(prefers-reduced-motion: reduce)');
    const compact = matchMedia('(max-width: 900px)');
    let instance;
    let failed = false;

    function stop(message) {
        instance?.dispose();
        instance = undefined;
        panel.classList.remove('is-3d-ready');
        status.textContent = message;
    }

    function start() {
        if (compact.matches) { stop('Visual 3D desativado em telas compactas.'); return; }
        if (reduced.matches) { stop('Imagem estática por preferência de movimento reduzido.'); return; }
        if (instance || failed) return;

        let renderer, scene, model, observer, resizer;
        let frame = 0, previous = 0, lastRender = 0, elapsed = 0;
        let visible = true, disposed = false;
        const canvas = document.createElement('canvas');
        const camera = new THREE.PerspectiveCamera(32, 1, .1, 70);
        const dispose = () => {
            if (disposed) return;
            disposed = true;
            cancelAnimationFrame(frame);
            observer?.disconnect();
            resizer?.disconnect();
            document.removeEventListener('visibilitychange', sync);
            canvas.removeEventListener('webglcontextlost', contextLost);
            const geometries = new Set(), materials = new Set();
            scene?.traverse(object => {
                if (object.geometry) geometries.add(object.geometry);
                if (object.material) {
                    for (const material of Array.isArray(object.material) ? object.material : [object.material]) materials.add(material);
                }
            });
            geometries.forEach(item => item.dispose());
            materials.forEach(item => item.dispose());
            model?.textures.forEach(item => item.dispose());
            renderer?.dispose();
            canvas.remove();
        };
        const fallback = () => {
            failed = true;
            dispose();
            stop('Farol ilustrativo exibido. Gráfico 3D indisponível.');
        };
        function contextLost(event) { event.preventDefault(); fallback(); }
        function resize() {
            if (disposed) return;
            const width = stage.clientWidth, height = stage.clientHeight;
            if (!width || !height) return;
            const quality = sceneQuality();
            renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, quality.dpr));
            renderer.setSize(width, height, false);
            camera.aspect = width / height;
            camera.position.set(8.1, 7.3, 15.5);
            camera.lookAt(0, 2.82, 0);
            camera.setViewOffset(width, height, -width * .245, 0, width, height);
            camera.updateProjectionMatrix();
            try { renderer.render(scene, camera); } catch { fallback(); }
        }
        function draw(now) {
            frame = 0;
            if (disposed || !visible || document.hidden) return;
            frame = requestAnimationFrame(draw);
            // Accumulate active time only; returning to a hidden tab never jumps.
            elapsed += Math.max(0, (now - previous) / 1000);
            previous = now;
            if (now - lastRender < 1000 / sceneQuality().fps) return;
            lastRender = now;
            const angles = loginAngles(elapsed);
            model.root.rotation.y = angles.tower;
            model.rotating.rotation.y = angles.lantern;
            try { renderer.render(scene, camera); } catch { fallback(); }
        }
        function sync() {
            cancelAnimationFrame(frame);
            frame = 0;
            if (!disposed && visible && !document.hidden) {
                previous = performance.now();
                frame = requestAnimationFrame(draw);
            }
        }

        try {
            const quality = sceneQuality();
            const context = canvas.getContext('webgl2', { alpha: true, antialias: quality.antialias, powerPreference: 'low-power' });
            if (!context) { fallback(); return; }
            renderer = new THREE.WebGLRenderer({ canvas, context, alpha: true, antialias: quality.antialias });
            renderer.setClearColor('#0B1119', 0);
            renderer.toneMapping = THREE.ACESFilmicToneMapping;
            renderer.toneMappingExposure = 1.16;
            canvas.setAttribute('aria-hidden', 'true');
            scene = new THREE.Scene();
            model = createLighthouseModel(quality);
            model.root.position.set(0, -.08, 0);
            model.root.scale.setScalar(1.08);
            model.rotating.rotation.y = .9;
            model.beam.materials[0].uniforms.strength.value = .16;
            model.beam.materials[1].uniforms.strength.value = .085;
            model.lampLight.intensity = 2.7;
            scene.add(model.root);
            createLighting(scene);
            host.appendChild(canvas);
            instance = { dispose };
            canvas.addEventListener('webglcontextlost', contextLost);
            observer = new IntersectionObserver(([entry]) => { visible = entry.isIntersecting; sync(); });
            resizer = new ResizeObserver(resize);
            observer.observe(panel);
            resizer.observe(stage);
            document.addEventListener('visibilitychange', sync);
            resize();
            if (disposed) return;
            panel.classList.add('is-3d-ready');
            status.textContent = 'Farol 3D com rotação automática da estrutura e da luz.';
            sync();
        } catch { fallback(); }
    }

    reduced.addEventListener('change', start);
    compact.addEventListener('change', start);
    window.addEventListener('pagehide', () => stop('Visual 3D pausado.'));
    window.addEventListener('pageshow', start);
    start();
}
