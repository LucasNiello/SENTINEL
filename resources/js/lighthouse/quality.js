export function sceneQuality(width = window.innerWidth) {
    if (width <= 600) return { name: 'low', dpr: 1, segments: 24, antialias: false, fps: 30 };
    if (width <= 1100) return { name: 'medium', dpr: 1.5, segments: 32, antialias: true, fps: 30 };
    return { name: 'high', dpr: 2, segments: 64, antialias: true, fps: 45 };
}
