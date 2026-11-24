// Run with node tests/browser/ChatConfirmacao.mjs. PLAYWRIGHT_MODULE may point
// to an already installed Playwright module; no installation is required.
import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE ? pathToFileURL(process.env.PLAYWRIGHT_MODULE).href : 'playwright');
const server = await createServer({ configFile: false, plugins: [react(), tailwindcss()], server: { host: '127.0.0.1', port: 0 }, appType: 'custom' });
const initialPage = { component: 'Agente', url: '/agente', version: null, props: {
    usuario: { id: 1, nome: 'QA', papel: 'operador', rotuloPapel: 'Operador', iniciais: 'QA' },
    menu: [], appNome: 'Sentinel', historico: [], errors: {},
} };
server.middlewares.use('/__chat-test', async (_req, res) => {
    const html = await server.transformIndexHtml('/__chat-test', `<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="qa"></head><body class="workspace"><div id="app"></div><script type="module">
        import React from 'react'; import { createRoot } from 'react-dom/client';
        import { createInertiaApp } from '@inertiajs/react';
        import Agente from '/resources/js/react/Pages/Agente.jsx';
        import '/resources/css/app.css'; import '/resources/css/workspace.css';
        createInertiaApp({ page: ${JSON.stringify(initialPage)}, resolve: () => Agente,
            setup({el, App, props}) { createRoot(el).render(React.createElement(App, props)); } });
    </script></body></html>`);
    res.setHeader('Content-Type', 'text/html'); res.end(html);
});
await server.listen();
const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
const page = await browser.newPage({ viewport: { width: 360, height: 740 } });
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
const calls = [];
let failure = null;
let release = null;
let delayNext = false;
const proposal = { tipo: 'confirmacao_pendente', proposta: '11111111-1111-4111-8111-111111111111', risco: 'escrita',
    argumentos: { nome: 'Cliente QA' }, formulario: { titulo: 'Cadastrar cliente', campos: [{ nome: 'nome', rotulo: 'Nome', valor: 'Cliente QA' }] } };
const token = '22222222-2222-4222-8222-222222222222';
await page.route('**/agente/*', async (route) => {
    const action = route.request().url().split('/').at(-1);
    const body = route.request().postDataJSON();
    calls.push({ action, body });
    if (delayNext) { delayNext = false; await new Promise((resolve) => { release = resolve; }); }
    if (failure?.action === action) {
        const failed = failure; failure = null;
        await route.fulfill({ status: failed.status || 422, json: { tipo: 'erro', codigo: failed.code || 'AI_VALIDATION_ERROR', mensagem: 'Falha simulada' } }); return;
    }
    const responses = { comando: proposal, revisar: { tipo: 'revisada', token, formulario: { ...proposal.formulario, avisos: ['Revisão validada pelo servidor'] } },
        confirmar: { tipo: 'resultado_escrita', mensagem: 'Cliente cadastrado.' }, cancelar: { tipo: 'cancelado', mensagem: 'Cancelado. Nada foi gravado.' } };
    await route.fulfill({ json: responses[action] });
});
const card = () => page.getByRole('region', { name: 'Confirmação de ação' }).last();
async function until(check) { for (let i = 0; i < 100; i++) { if (await check()) return; await new Promise((r) => setTimeout(r, 50)); } throw new Error('Condition timed out'); }
async function send() {
    await page.locator('#campo-mensagem').fill('Cadastre Cliente QA');
    await page.locator('#botao-enviar').click();
    await until(async () => await card().getByRole('button', { name: 'Revisar', exact: true }).count() > 0 && !await page.locator('#botao-enviar').isDisabled());
}
async function action(name, endpoint) {
    delayNext = true; release = null;
    const before = calls.length;
    await card().getByRole('button', { name, exact: true }).evaluate((button) => { button.click(); button.click(); });
    await until(() => release !== null);
    assert.equal(calls.length, before + 1, 'double click sends only once');
    assert.equal(calls.at(-1).action, endpoint);
    assert.equal(await page.locator('#botao-enviar').isDisabled(), true);
    assert.equal(await page.locator('#carregando').isVisible(), true);
    assert.equal(await card().getByRole('button', { name: 'Cancelar', exact: true }).isDisabled(), true);
    release();
    await until(async () => !await page.locator('#botao-enviar').isDisabled());
    assert.equal(await page.locator('#carregando').isVisible(), false);
}
try {
    await page.goto(`http://127.0.0.1:${server.httpServer.address().port}/__chat-test`);
    await send();
    assert.match(await card().innerText(), /Cliente QA/);
    assert.equal(await card().getByRole('button', { name: 'Confirmar', exact: true }).isDisabled(), true);
    await card().getByRole('button', { name: 'Confirmar', exact: true }).evaluate((button) => button.click());
    assert.equal(calls.filter((c) => c.action === 'confirmar').length, 0);
    failure = { action: 'revisar' }; await action('Revisar', 'revisar');
    assert.equal(await card().getByRole('button', { name: 'Confirmar', exact: true }).isDisabled(), true);
    assert.match(await card().getByRole('alert').innerText(), /Falha simulada/);
    await action('Revisar', 'revisar');
    assert.deepEqual(calls.at(-1).body, { proposta: proposal.proposta, valores: {} });
    assert.match(await card().innerText(), /Revisão validada pelo servidor/);
    assert.equal(calls.filter((c) => c.action === 'confirmar').length, 0, 'review never auto-confirms');
    const dimensions = await page.evaluate(() => ({ width: innerWidth, height: innerHeight, scroll: document.documentElement.scrollWidth }));
    assert.deepEqual(dimensions, { width: 360, height: 740, scroll: 360 });
    if (process.env.CHAT_SCREENSHOT) await page.screenshot({ path: process.env.CHAT_SCREENSHOT, fullPage: true });
    await action('Confirmar', 'confirmar');
    assert.deepEqual(calls.at(-1).body, { token });
    assert.equal(await card().getByRole('button').count(), 0);
    assert.match(await page.locator('#historico .msg--agente').last().innerText(), /Cliente cadastrado/);
    await send(); failure = { action: 'cancelar', status: 503 }; await action('Cancelar', 'cancelar');
    assert.match(await card().getByRole('alert').innerText(), /Falha simulada/);
    assert.equal(await card().getByRole('button', { name: 'Cancelar', exact: true }).isEnabled(), true);
    await action('Cancelar', 'cancelar');
    assert.deepEqual(calls.at(-1).body, { proposta: proposal.proposta });
    assert.equal(await card().getByRole('button').count(), 0);
    assert.match(await page.locator('#historico .msg--agente').last().innerText(), /Cancelado/);
    await send(); await action('Revisar', 'revisar');
    failure = { action: 'confirmar' }; await action('Confirmar', 'confirmar');
    assert.equal(await card().getByRole('button', { name: 'Confirmar', exact: true }).isDisabled(), true);
    assert.match(await card().getByRole('alert').innerText(), /Falha simulada/);
    failure = { action: 'revisar', code: 'AI_PROPOSAL_UNAVAILABLE' }; await action('Revisar', 'revisar');
    assert.equal(await card().getByRole('button').count(), 0);
    assert.equal(await page.locator('#historico .msg--usuario').count(), 3);
    await send(); await action('Revisar', 'revisar');
    failure = { action: 'cancelar', status: 503 }; await action('Cancelar', 'cancelar');
    assert.equal(await card().getByRole('button', { name: 'Confirmar', exact: true }).isEnabled(), true);
    await action('Cancelar', 'cancelar');
    assert.equal(await card().getByRole('button').count(), 0);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    assert.deepEqual(errors, []);
    console.log('PASS: proposta, revisão, token, ação explícita, confirmação, cancelamento, erros, histórico, cliques duplicados, Parte 1 e 360x740.');
} finally { await browser.close(); await server.close(); }
