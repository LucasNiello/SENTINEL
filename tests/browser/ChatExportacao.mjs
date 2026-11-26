// Uses existing Playwright; PLAYWRIGHT_MODULE and CHROME_PATH may select bundled tools.
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE ? pathToFileURL(process.env.PLAYWRIGHT_MODULE).href : 'playwright');
const server = await createServer({ configFile: false, plugins: [react()], server: { host: '127.0.0.1', port: 0 }, appType: 'custom' });
const initial = { component: 'Agente', url: '/agente', version: null, props: { usuario: { id: 1, nome: 'QA', iniciais: 'QA', papel: 'operador', rotuloPapel: 'Operador' }, menu: [], appNome: 'Sentinel', errors: {}, historico: [{ role: 'assistant', content: 'Histórico preservado.' }] } };
server.middlewares.use('/__exportacao', async (_req, res) => {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    res.end(await server.transformIndexHtml('/__exportacao', `<!doctype html><html lang="pt-BR"><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="qa"><script type="module" src="/resources/js/react/app.jsx"></script><link rel="stylesheet" href="/resources/css/workspace.css"></head><body class="workspace"><script type="application/json" data-page="app">${JSON.stringify(initial)}</script><div id="app"></div></body></html>`));
});
await server.listen();
const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
const page = await browser.newPage({ viewport: { width: 360, height: 740 }, acceptDownloads: true });
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
let downloads = 0;
page.on('download', () => downloads++);
const id = '11111111-1111-4111-8111-111111111111';
const fileName = 'clientes-2026-11-24-120000.csv';
const bytes = Buffer.from('\uFEFFid;nome;documento\n7;José da Silva;***.***.789-01\n', 'utf8');
let result = { tipo: 'resultado_leitura', colunas: ['id', 'nome'], resultado: [{ id: 7, nome: 'José da Silva' }] };
let status = 200;
let networkFailure = false;
let invalidContent = false;
let release = null;
const requests = [];
await page.route('**/agente/comando', (route) => route.fulfill({ json: result }));
await page.route('**/login', (route) => route.fulfill({ contentType: 'text/html', body: '<h1>Login</h1>' }));
await page.route('**/agente/exportar', async (route) => {
    requests.push({ method: route.request().method(), body: route.request().postDataJSON(), headers: route.request().headers() });
    await new Promise((resolve) => { release = resolve; });
    if (networkFailure) { networkFailure = false; await route.abort('failed'); return; }
    if (status !== 200 || invalidContent) {
        await route.fulfill({ status, json: { tipo: 'erro', mensagem: status === 403 ? 'Sem permissão para exportar.' : 'Falha simulada de exportação.' } }); return;
    }
    await route.fulfill({ status: 200, headers: { 'Content-Type': 'text/csv; charset=UTF-8', 'Content-Disposition': `attachment; filename="${fileName}"`, 'Cache-Control': 'no-store' }, body: bytes });
});
async function until(check) { for (let i = 0; i < 100; i++) { if (await check()) return; await new Promise((r) => setTimeout(r, 50)); } throw new Error('Condition timed out'); }
async function send(text) {
    await page.locator('#campo-mensagem').fill(text); await page.locator('#botao-enviar').click();
    await until(async () => !await page.locator('#botao-enviar').isDisabled() && await page.locator('#campo-mensagem').inputValue() === '');
}
async function startExport() {
    release = null;
    const before = requests.length;
    await page.getByRole('button', { name: 'Exportar CSV', exact: true }).evaluate((b) => { b.click(); b.click(); });
    await until(() => !!release);
    assert.equal(requests.length, before + 1, 'duplicate click blocked synchronously');
    assert.equal(await page.getByRole('button', { name: 'Exportando…', exact: true }).isDisabled(), true);
    assert.match(await page.locator('.toast-ancorado').innerText(), /Preparando o arquivo/);
    assert.equal(requests.at(-1).method, 'POST');
    assert.deepEqual(requests.at(-1).body, { consulta: id });
    assert.equal(requests.at(-1).headers['x-csrf-token'], 'qa');
    assert.equal(requests.at(-1).headers.accept, 'application/json');
    release();
    await until(async () => await page.getByRole('button', { name: 'Exportar CSV', exact: true }).isEnabled());
}
try {
    await page.goto(`http://127.0.0.1:${server.httpServer.address().port}/__exportacao`);
    await send('Consulta sem exportação');
    await page.getByRole('table').waitFor();
    assert.equal(await page.getByRole('button', { name: 'Exportar CSV', exact: true }).count(), 0);
    result = { ...result, exportar: id }; await send('Consulta exportável');
    await page.getByRole('button', { name: 'Exportar CSV', exact: true }).waitFor();
    const downloaded = page.waitForEvent('download');
    await startExport();
    const file = await downloaded;
    assert.equal(file.suggestedFilename(), fileName);
    assert.deepEqual(await readFile(await file.path()), bytes, 'byte-for-byte: BOM, accents, separators and masked data');
    assert.match(await page.locator('.toast-ancorado').innerText(), /Download iniciado: clientes-2026-11-24-120000.csv/);
    assert.equal(downloads, 1);
    for (const code of [403, 500]) {
        status = code; await startExport();
        assert.match(await page.locator('.toast-ancorado .aviso--erro').innerText(), code === 403 ? /Sem permissão/ : /Falha simulada/);
        assert.equal(downloads, 1);
    }
    status = 200; invalidContent = true; await startExport();
    assert.match(await page.locator('.toast-ancorado .aviso--erro').innerText(), /CSV válido/);
    invalidContent = false; networkFailure = true; await startExport();
    assert.match(await page.locator('.toast-ancorado .aviso--erro').innerText(), /Falha ao contatar/);
    const recovered = page.waitForEvent('download'); await startExport();
    assert.deepEqual(await readFile(await (await recovered).path()), bytes);
    assert.equal(downloads, 2);
    assert.equal(await page.getByRole('table').count(), 2);
    assert.match(await page.locator('#historico').innerText(), /Histórico preservado/);
    result = { ...result, resultado: [] }; await send('Consulta vazia');
    await until(async () => (await page.locator('#historico').innerText()).includes('Nenhum registro encontrado'));
    assert.equal(await page.getByRole('button', { name: 'Exportar CSV', exact: true }).count(), 1, 'empty result does not add export button');
    for (const viewport of [{ width: 360, height: 740 }, { width: 1440, height: 900 }]) {
        await page.setViewportSize(viewport);
        await until(async () => await page.evaluate(() => document.documentElement.scrollWidth === innerWidth));
        if (process.env.CHAT_SCREENSHOT_PREFIX) await page.screenshot({ path: `${process.env.CHAT_SCREENSHOT_PREFIX}-${viewport.width}.png`, fullPage: true, animations: 'disabled' });
    }
    assert.deepEqual(errors, []);
    status = 419; release = null;
    await page.getByRole('button', { name: 'Exportar CSV', exact: true }).click(); await until(() => !!release); release();
    await page.waitForURL('**/login'); assert.equal(downloads, 2);
    console.log('PASS: CSV/download bytes e nome, permissões, erros, recuperação, duplicação, histórico, sessão expirada, 360x740 e desktop.');
} finally { await browser.close(); await server.close(); }
