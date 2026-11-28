// node tests/browser/CobrancaUmPasso.mjs; accepts existing PLAYWRIGHT_MODULE / CHROME_PATH.
// Isolated Vite page and mocked HTTP: never connects to Laravel or a business database.
import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE ? pathToFileURL(process.env.PLAYWRIGHT_MODULE).href : 'playwright');
const server = await createServer({ configFile: false, plugins: [react(), tailwindcss()], server: { host: '127.0.0.1', port: 0 }, appType: 'custom' });
const initialPage = { component: 'Agente', url: '/agente', version: null, props: {
    usuario: { id: 1, nome: 'QA', papel: 'dono', rotuloPapel: 'Dono', iniciais: 'QA' },
    menu: [], appNome: 'Sentinel', historico: [], errors: {},
} };
server.middlewares.use('/__f0-test', async (_req, res) => {
    const html = await server.transformIndexHtml('/__f0-test', `<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="qa"></head><body class="workspace"><div id="app"></div><script type="module">
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
page.setDefaultTimeout(15000);
const errors = [];
page.on('pageerror', e => errors.push(e.message));
const calls = [];
let proposal;
let rejectNext = false;
const card = () => page.getByRole('region', { name: 'Confirmação de ação' }).last();
const payment = { nome: 'forma_pagamento', rotulo: 'Forma de pagamento', tipo: 'opcoes', obrigatorio: true,
    valor: '', opcoes: [{ valor: 'pix', rotulo: 'Pix' }, { valor: 'dinheiro', rotulo: 'Dinheiro' }] };
await page.route('**/agente/*', async route => {
    const action = route.request().url().split('/').at(-1);
    const body = route.request().postDataJSON(); calls.push({ action, body });
    if (action === 'confirmar' && rejectNext) {
        rejectNext = false;
        await route.fulfill({ status: 422, json: { tipo: 'erro', mensagem: 'Confira a forma de pagamento.',
            formulario: { ...proposal.formulario, campos: [{ ...payment, erro: 'Confirme o pagamento.' }] } } }); return;
    }
    const responses = { comando: proposal,
        revisar: { tipo: 'revisada', token: '22222222-2222-4222-8222-222222222222', segurar: true, formulario: proposal.formulario },
        confirmar: { tipo: 'resultado_escrita', mensagem: 'Operação F0 confirmada.' } };
    await route.fulfill({ json: responses[action] });
});
async function settled() { await page.waitForFunction(() => !document.querySelector('#botao-enviar')?.disabled); }
async function noOverflow() {
    assert.deepEqual(await page.evaluate(() => ({ width: innerWidth, height: innerHeight, scroll: document.documentElement.scrollWidth })),
        { width: 360, height: 740, scroll: 360 });
    assert.equal(await card().evaluate(el => el.scrollWidth <= el.clientWidth), true, 'card has no horizontal overflow');
}
try {
    for (const [tool, title, fields] of [
        ['receber_cobranca', 'Receber a cobrança', [payment]],
        ['cobrar_atendimento', 'Cobrar o atendimento', []],
        ['registrar_venda', 'Registrar a venda', [{ ...payment, obrigatorio: false }]],
    ]) {
        proposal = { tipo: 'confirmacao_pendente', proposta: '11111111-1111-4111-8111-111111111111',
            tool, risco: 'escrita', passo_unico: true, segurar: false,
            formulario: { titulo: title, campos: fields, resumo: [{ rotulo: 'Total', valor: 'R$ 150,00' }] } };
        await page.goto(`http://127.0.0.1:${server.httpServer.address().port}/__f0-test`);
        const before = calls.filter(c => c.action === 'confirmar').length;
        await page.locator('#campo-mensagem').fill(title); await page.locator('#botao-enviar').click();
        await card().waitFor(); await settled();
        assert.equal(await card().getByRole('button', { name: 'Revisar', exact: true }).count(), 0);
        assert.equal(calls.filter(c => c.action === 'confirmar').length, before, 'proposal never auto-confirms');
        await noOverflow();
        if (tool === 'receber_cobranca') {
            await card().getByRole('button', { name: 'Confirmar', exact: true }).click();
            assert.equal(calls.filter(c => c.action === 'confirmar').length, before, 'required payment blocks empty submit');
            await card().getByRole('combobox').click();
            await page.getByRole('option', { name: 'Pix', exact: true }).click();
            rejectNext = true;
            await card().getByRole('button', { name: 'Confirmar', exact: true }).click();
            await card().getByRole('alert').waitFor(); await settled(); await noOverflow();
            assert.equal(await card().getByRole('button', { name: 'Revisar', exact: true }).count(), 0);
        }
        const count = calls.filter(c => c.action === 'confirmar').length;
        await card().getByRole('button', { name: 'Confirmar', exact: true }).evaluate(b => { b.click(); b.click(); });
        await card().getByText(/· Confirmado$/).waitFor(); await settled();
        assert.equal(calls.filter(c => c.action === 'confirmar').length, count + 1, 'double click sends once');
        assert.equal(calls.at(-1).body.proposta, proposal.proposta);
        assert.equal(calls.at(-1).body.token, undefined);
        if (tool === 'receber_cobranca') assert.equal(calls.at(-1).body.valores.forma_pagamento, 'pix');
        assert.equal(await page.getByText('Operação F0 confirmada.', { exact: true }).count(), 1, 'result appears in history');
        await noOverflow();
    }
    assert.equal(calls.filter(c => c.action === 'revisar').length, 0);
    // Destructive cards keep the separate review and two-second hold.
    for (const tool of ['cancelar_cobranca', 'estornar_cobranca', 'estornar_venda']) {
        proposal = { ...proposal, tool, passo_unico: false, segurar: true, risco: 'exclusao', formulario: { titulo: tool, campos: [] } };
        await page.goto(`http://127.0.0.1:${server.httpServer.address().port}/__f0-test`);
        await page.locator('#campo-mensagem').fill(tool); await page.locator('#botao-enviar').click();
        await card().getByRole('button', { name: 'Revisar', exact: true }).waitFor(); await settled();
        const confirm = card().getByRole('button', { name: 'Confirmar', exact: true });
        assert.equal(await confirm.isDisabled(), true);
        await card().getByRole('button', { name: 'Revisar', exact: true }).click(); await settled();
        const before = calls.filter(c => c.action === 'confirmar').length;
        await confirm.click();
        assert.equal(calls.filter(c => c.action === 'confirmar').length, before, 'short click cannot confirm destructive action');
        await noOverflow();
        await confirm.hover(); await page.mouse.down(); await page.waitForTimeout(2100); await page.mouse.up();
        await card().getByText(/· Confirmado$/).waitFor();
        assert.equal(calls.filter(c => c.action === 'confirmar').length, before + 1);
        assert.ok(calls.at(-1).body.token);
    }
    assert.deepEqual(errors, []);
    console.log('PASS: F0, explicit confirmation, payment, 422 retry, duplicate clicks, history, destructive review/hold and 360x740 without overflow.');
} catch (error) {
    console.error({ tool: proposal.tool, calls, card: await card().innerText(), errors });
    throw error;
} finally { await browser.close(); await server.close(); }
