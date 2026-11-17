/*
 * Sentinel — service worker mínimo (T3, 14/11). Só existe para o navegador oferecer "instalar" (PWA).
 * Faz cache APENAS do shell estático (ícones, manifest, CSS/JS/fontes do build). Nunca guarda página, JSON,
 * login, /agente, /supervisao nem nenhum dado: tudo isso vai sempre à rede, sem fallback (sem modo offline).
 */
const CACHE = 'sentinel-shell-v1';
const SHELL = ['/manifest.webmanifest', '/icons/sentinel-192.png', '/icons/sentinel-512.png', '/favicon-sentinel.svg'];

self.addEventListener('install', (evento) => {
    evento.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (evento) => {
    evento.waitUntil(caches.keys()
        .then((chaves) => Promise.all(chaves.filter((chave) => chave !== CACHE).map((chave) => caches.delete(chave))))
        .then(() => self.clients.claim()));
});

// Estático cacheável: só GET, mesma origem, e só estes caminhos. Qualquer outra coisa (inclusive /agente/*) não passa aqui.
function ehShellEstatico(requisicao) {
    if (requisicao.method !== 'GET') return false;
    const url = new URL(requisicao.url);
    if (url.origin !== self.location.origin || url.search !== '') return false;
    return SHELL.includes(url.pathname) || /^\/build\/assets\/[\w.-]+\.(css|js|woff2?)$/.test(url.pathname);
}

self.addEventListener('fetch', (evento) => {
    if (!ehShellEstatico(evento.request)) return; // rede direta, sem cache e sem resposta inventada
    evento.respondWith(caches.match(evento.request).then((guardado) => guardado || fetch(evento.request).then((resposta) => {
        if (resposta.ok && resposta.type === 'basic') {
            const copia = resposta.clone();
            caches.open(CACHE).then((cache) => cache.put(evento.request, copia));
        }
        return resposta;
    })));
});
