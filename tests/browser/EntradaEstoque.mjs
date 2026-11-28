// Isolated React test: mocked HTTP only. Uses an existing PLAYWRIGHT_MODULE / CHROME_PATH.
import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE ? pathToFileURL(process.env.PLAYWRIGHT_MODULE).href : 'playwright');
const server = await createServer({ configFile: false, plugins: [react(), tailwindcss()], server: { host: '127.0.0.1', port: 0 }, appType: 'custom' });
server.middlewares.use('/__fe-test', async (req, res) => {
    const component = req.url.includes('chat') ? 'Agente' : 'Consulta';
    const initialPage = { component, url: '/cadastros', version: null, props: {
        usuario: { id: 1, nome: 'QA', papel: 'dono', rotuloPapel: 'Dono', iniciais: 'QA' },
        menu: [], appNome: 'Sentinel', historico: [], errors: {}, titulo: 'Cadastros',
        cadastroInicial: 'catalogo', podeDarEntradaEstoque: !req.url.includes('negado'),
        entidades: { catalogo: { rotulo: 'Catálogo', consultar: 'consultar_catalogo', filtros: [] } },
    } };
    const html = await server.transformIndexHtml('/__fe-test', `<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="qa"></head><body class="workspace"><div id="app"></div><script type="module">
        import React from 'react'; import { createRoot } from 'react-dom/client';
        import { createInertiaApp } from '@inertiajs/react';
        import Page from '/resources/js/react/Pages/${component}.jsx';
        import '/resources/css/app.css'; import '/resources/css/workspace.css';
        createInertiaApp({ page: ${JSON.stringify(initialPage)}, resolve: () => Page,
            setup({el, App, props}) { createRoot(el).render(React.createElement(App, props)); } });
    </script></body></html>`);
    res.setHeader('Content-Type', 'text/html'); res.end(html);
});
await server.listen();
const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
const page = await browser.newPage({ viewport: { width: 360, height: 740 } });
page.setDefaultTimeout(15000);
const errors = []; page.on('pageerror', e => errors.push(e.message));
const calls = [];
let saldo = -5;
const card = () => page.getByRole('region', { name: 'Confirmação de ação' }).last();
const proposal = { tipo: 'confirmacao_pendente', proposta: '11111111-1111-4111-8111-111111111111',
    tool: 'dar_entrada_estoque', risco: 'escrita', passo_unico: true, segurar: false,
    formulario: { titulo: 'Entrada de estoque', campos: [
        { nome: 'quantidade', rotulo: 'Quantidade', tipo: 'numero', inteiro: true, min: 1, max: 100000, obrigatorio: true },
        { nome: 'motivo', rotulo: 'Motivo', max: 200 },
    ], resumo: [{ rotulo: 'Produto', valor: 'Ração' }, { rotulo: 'Saldo atual', valor: -5 }] } };
await page.route('**/agente/*', async route => {
    const action = route.request().url().split('/').at(-1);
    const body = route.request().postDataJSON(); calls.push({ action, body });
    if (action === 'confirmar') saldo += Number(body.valores.quantidade);
    const responses = { comando: proposal, formulario: proposal,
        consultar: { tipo: 'resultado_leitura', colunas: ['id', 'nome_comercial', 'estoque'], resultado: [
            { id: 1, nome_comercial: 'Ração', estoque: saldo, tipo: 'produto', controla_estoque: true },
            { id: 2, nome_comercial: 'Serviço', estoque: 0, tipo: 'servico', controla_estoque: true },
            { id: 3, nome_comercial: 'Sem controle', estoque: 0, tipo: 'produto', controla_estoque: false },
        ] }, confirmar: { tipo: 'resultado_escrita', mensagem: 'Entrada de estoque registrada' } };
    assert.ok(responses[action], `unexpected request ${action}`);
    await route.fulfill({ json: responses[action] });
});
const url = `http://127.0.0.1:${server.httpServer.address().port}/__fe-test`;
async function noOverflow() {
    assert.deepEqual(await page.evaluate(() => ({ width: innerWidth, height: innerHeight, scroll: document.documentElement.scrollWidth })),
        { width: 360, height: 740, scroll: 360 });
}
try {
    await page.goto(url);
    await page.getByRole('button', { name: 'Entrada de estoque registro #1', exact: true }).waitFor();
    assert.equal(await page.getByRole('button', { name: /Entrada de estoque registro/ }).count(), 1, 'eligible products only');
    await noOverflow();
    await page.getByRole('button', { name: 'Entrada de estoque registro #1', exact: true }).click();
    await card().waitFor();
    assert.deepEqual(calls.find(c => c.action === 'formulario').body, { tool: 'dar_entrada_estoque', id: 1 });
    for (const chat of [false, true]) {
        if (chat) {
            await page.goto(`${url}?chat`);
            await page.locator('#campo-mensagem').fill('Dar entrada de 20 unidades de ração');
            await page.locator('#botao-enviar').click(); await card().waitFor();
        }
        assert.equal(await card().getByRole('button', { name: 'Revisar', exact: true }).count(), 0);
        const before = calls.filter(c => c.action === 'confirmar').length;
        await card().locator('[name="quantidade"]').fill('20');
        await card().locator('[name="motivo"]').fill('Compra');
        await noOverflow();
        assert.equal(calls.filter(c => c.action === 'confirmar').length, before, 'no automatic entry');
        await card().getByRole('button', { name: 'Confirmar', exact: true }).evaluate(b => { b.click(); b.click(); });
        await card().getByText(/· Confirmado$/).waitFor();
        assert.equal(calls.filter(c => c.action === 'confirmar').length, before + 1);
        const sent = calls.filter(c => c.action === 'confirmar').at(-1).body;
        assert.deepEqual(sent, { proposta: proposal.proposta, valores: { quantidade: '20', motivo: 'Compra' } });
        await noOverflow();
    }
    assert.equal(calls.filter(c => c.action === 'revisar').length, 0);
    await page.goto(`${url}?negado`);
    await page.getByText('Sem controle', { exact: true }).waitFor();
    assert.equal(await page.getByRole('button', { name: /Entrada de estoque registro/ }).count(), 0);
    assert.deepEqual(errors, []);
    console.log('PASS: catalog eligibility/permission, chat, one-step confirmation, duplicate clicks and 360x740 without overflow.');
} finally { await browser.close(); await server.close(); }
