// Use the already installed Playwright package, optionally via PLAYWRIGHT_MODULE.
import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE ? pathToFileURL(process.env.PLAYWRIGHT_MODULE).href : 'playwright');
const server = await createServer({ configFile: false, plugins: [react()], server: { host: '127.0.0.1', port: 0 }, appType: 'custom' });
const initial = { component: 'Agente', url: '/agente', version: null, props: { usuario: { id: 1, nome: 'QA', iniciais: 'QA', papel: 'operador', rotuloPapel: 'Operador' }, menu: [], appNome: 'Sentinel', errors: {}, historico: [{ role: 'assistant', content: 'Histórico anterior preservado.' }] } };
server.middlewares.use('/__consultas', async (_req, res) => {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    res.end(await server.transformIndexHtml('/__consultas', `<!doctype html><html lang="pt-BR"><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="qa"><script type="module" src="/resources/js/react/app.jsx"></script><link rel="stylesheet" href="/resources/css/workspace.css"></head><body class="workspace"><script type="application/json" data-page="app">${JSON.stringify(initial)}</script><div id="app"></div></body></html>`));
});
await server.listen();
const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
const page = await browser.newPage({ viewport: { width: 360, height: 740 } });
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
const long = 'Dado integral '.repeat(35) + 'FIM';
const result = { tipo: 'resultado_leitura', colunas: ['id', 'nome', 'saldo', 'ativo', 'observação'], mensagem: 'Consulta concluída.', aviso: 'Dados do tenant atual.', editar: 'atualizar_cliente', exportar: 'nao-utilizar', resultado: [
    { id: 7, nome: 'Cliente QA', saldo: 0, ativo: false, observação: long, destaque: 'aviso', pedido: 'Confira o cliente QA' },
    { id: 8, nome: '<script>alert(1)</script>', saldo: 23.45, ativo: true, observação: 'linha 1\nlinha 2', acao: { tool: 'restaurar_cliente', rotulo: 'Restaurar' } },
    { id: 9, nome: 'Última linha', saldo: null, ativo: false, observação: 'Final' },
] };
let payload = result;
let status = 200;
let release;
const calls = [];
await page.route('**/agente/*', async (route) => {
    const action = route.request().url().split('/').at(-1);
    calls.push({ action, body: route.request().postDataJSON() });
    if (action === 'formulario') {
        await route.fulfill({ json: { tipo: 'confirmacao_pendente', proposta: '11111111-1111-4111-8111-111111111111', formulario: { titulo: 'Formulário recebido', campos: [] }, risco: 'escrita' } }); return;
    }
    await new Promise((resolve) => { release = resolve; });
    await route.fulfill({ status, json: payload });
});
async function until(check) { for (let i = 0; i < 100; i++) { if (await check()) return; await new Promise((r) => setTimeout(r, 50)); } throw new Error('Condition timed out'); }
async function send(text) {
    release = null;
    await page.locator('#campo-mensagem').fill(text);
    const count = calls.length;
    await page.locator('#botao-enviar').evaluate((b) => { b.click(); b.click(); });
    await until(() => !!release);
    assert.equal(calls.length, count + 1);
    assert.equal(await page.locator('#carregando').isVisible(), true);
    assert.equal(await page.locator('#campo-mensagem').isDisabled(), true);
    release();
    await until(async () => !await page.locator('#botao-enviar').isDisabled());
    assert.equal(await page.locator('#carregando').isVisible(), false);
}
try {
    await page.goto(`http://127.0.0.1:${server.httpServer.address().port}/__consultas`);
    await send('Consulte os clientes');
    const table = page.getByRole('table').first();
    assert.deepEqual(await table.locator('thead th').allTextContents(), [...result.colunas, 'Ação']);
    assert.equal(await table.locator('tbody tr').count(), 3);
    for (let r = 0; r < result.resultado.length; r++) {
        const cells = table.locator('tbody tr').nth(r).locator('td');
        for (let c = 0; c < result.colunas.length; c++) assert.equal(await cells.nth(c).textContent(), String(result.resultado[r][result.colunas[c]] ?? ''));
    }
    assert.equal(await table.locator('tbody tr').first().getAttribute('class'), 'linha--aviso');
    assert.equal(await table.locator('script').count(), 0);
    assert.match(await page.locator('#historico').innerText(), /Histórico anterior preservado/);
    assert.match(await page.locator('#historico').innerText(), /Consulta concluída/);
    assert.match(await page.locator('#historico').innerText(), /Dados do tenant atual/);
    assert.equal(await page.getByRole('button', { name: /Exportar/ }).count(), 1);
    for (const viewport of [{ width: 360, height: 740 }, { width: 1440, height: 900 }]) {
        await page.setViewportSize(viewport);
        await until(async () => await page.evaluate(() => document.documentElement.scrollWidth === innerWidth));
        const region = page.getByRole('region', { name: 'Resultado da consulta' }).first();
        const dimensions = await region.evaluate((el) => ({ width: el.clientWidth, scroll: el.scrollWidth, overflow: getComputedStyle(el).overflowX }));
        assert.equal(dimensions.overflow, 'auto');
        if (viewport.width === 360) assert.ok(dimensions.scroll > dimensions.width);
        await region.evaluate((el) => { el.scrollLeft = el.scrollWidth; });
        assert.equal(await table.locator('tbody tr').first().locator('td').nth(4).textContent(), long);
        await region.evaluate((el) => { el.scrollLeft = 0; });
        if (process.env.CHAT_SCREENSHOT_PREFIX) await page.screenshot({ path: `${process.env.CHAT_SCREENSHOT_PREFIX}-${viewport.width}.png`, fullPage: true, animations: 'disabled' });
    }
    const count = calls.length;
    await page.getByRole('button', { name: 'Pedir ao agente o que a mensagem 7 pede' }).click();
    assert.equal(await page.locator('#campo-mensagem').inputValue(), result.resultado[0].pedido);
    assert.equal(calls.length, count, 'request shortcut only fills input');
    await page.getByRole('button', { name: 'Editar registro #7', exact: true }).click();
    await page.getByText('Formulário recebido', { exact: true }).waitFor();
    assert.deepEqual(calls.at(-1), { action: 'formulario', body: { tool: 'atualizar_cliente', id: 7 } });
    await until(async () => !await page.locator('#botao-enviar').isDisabled());
    await page.getByRole('button', { name: 'Restaurar registro #8', exact: true }).click();
    await until(() => calls.at(-1).body.tool === 'restaurar_cliente');
    await until(async () => !await page.locator('#botao-enviar').isDisabled());
    payload = { tipo: 'resultado_leitura', colunas: ['id', 'nome'], resultado: [] }; await send('Consulta vazia');
    assert.match(await page.locator('#historico').innerText(), /Nenhum registro encontrado/);
    status = 503; payload = { tipo: 'erro', mensagem: 'Falha de consulta' }; await send('Consulta com falha');
    assert.equal(await page.locator('#campo-mensagem').inputValue(), 'Consulta com falha');
    assert.equal(await page.locator('#historico .msg--erro').count(), 1);
    assert.equal(await page.getByRole('table').count(), 1, 'existing result survives error and empty result');
    status = 200; payload = { tipo: 'resultado_leitura', resultado: [{ primeira: 'A' }, { primeira: 'B', segunda: 'C' }] }; await send('Consulta recuperada');
    assert.deepEqual(await page.getByRole('table').last().locator('thead th').allTextContents(), ['primeira', 'segunda']);
    assert.equal(await page.getByRole('table').count(), 2);
    assert.equal(await page.locator('#historico .msg--usuario').count(), 4);
    assert.deepEqual(errors, []);
    console.log('PASS: tabelas integrais, colunas/linhas, ações, carregamento, vazio, erro, recuperação, histórico, 360x740 e desktop.');
} finally { await browser.close(); await server.close(); }
