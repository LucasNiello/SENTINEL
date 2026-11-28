// Isolated Vite/React fixture with mocked requests; no real billing or database access.
import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE ? pathToFileURL(process.env.PLAYWRIGHT_MODULE).href : 'playwright');
const server = await createServer({ configFile: false, plugins: [react(), tailwindcss()], server: { host: '127.0.0.1', port: 0 }, appType: 'custom' });
server.middlewares.use('/__f2-test', async (req, res) => {
    const component = req.url.includes('chat') ? 'Agente' : 'Financeiro';
    const initialPage = { component, url: '/cobrancas', version: null, props: {
        usuario: { id: 1, nome: 'QA', papel: 'dono', rotuloPapel: 'Dono', iniciais: 'QA' },
        menu: [], appNome: 'Sentinel', historico: [], errors: {}, titulo: 'Cobranças', rota: '/cobrancas',
        status: '', setor: '', opcoesStatus: {}, colunas: { id: 'ID', status: 'Situação', bruto: 'Valor cheio', desconto: 'Desconto', total: 'Total líquido' },
        resumo: { total: 'R$ 350,00', estornado: 'R$ 0,00', liquido: 'R$ 0,00', registros: 3 },
        linhas: [{ id: 1, status: 'aberta', bruto: 'R$ 350,00', desconto: 'R$ 0,00', total: 'R$ 350,00' }, { id: 2, status: 'a receber', bruto: 'R$ 350,00', desconto: 'R$ 0,00', total: 'R$ 350,00' }, { id: 3, status: 'paga', bruto: 'R$ 350,00', desconto: 'R$ 0,00', total: 'R$ 350,00' }],
        acoes: { aberta: [{ tool: 'editar_cobranca', rotulo: 'Editar' }], 'a receber': [{ tool: 'editar_cobranca', rotulo: 'Editar' }] },
    } };
    const html = await server.transformIndexHtml('/__f2-test', `<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="qa"></head><body class="workspace"><div id="app"></div><script type="module">
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
const card = () => page.getByRole('region', { name: 'Confirmação de ação' }).last();
const editor = { financeiro: { subtotal_centavos: 35000, desconto_centavos: 0, total_centavos: 35000 }, versao: 'a'.repeat(64), linhas: [
    { item_id: 1, descricao: 'Consulta', quantidade: 1, clinico: false },
    { item_id: 2, descricao: 'Vacina aplicada', quantidade: 2, clinico: true },
], catalogo: [{ id: 3, nome_comercial: 'Produto avulso' }] };
const proposal = { tipo: 'confirmacao_pendente', proposta: '11111111-1111-4111-8111-111111111111', tool: 'editar_cobranca',
    risco: 'escrita', passo_unico: true, segurar: false, formulario: { titulo: 'Editar cobrança #1', campos: [],
        resumo: [{ rotulo: 'Total atual', valor: 'R$ 350,00' }], edicao_cobranca: editor } };
let failNext = true;
await page.route('**/agente/*', async route => {
    const action = new URL(route.request().url()).pathname.split('/').at(-1);
    const body = route.request().postDataJSON(); calls.push({ action, body });
    if (action === 'revisar') {
        assert.equal(new URL(route.request().url()).searchParams.get('previa'), '1');
        await route.fulfill({ json: { tipo: 'previa', formulario: { edicao_cobranca: { ...editor,
            financeiro: { subtotal_centavos: 35000, desconto_centavos: 3500, total_centavos: 31500 } } } } }); return;
    }
    if (action === 'confirmar' && failNext) {
        failNext = false;
        await route.fulfill({ status: 422, json: { tipo: 'erro', mensagem: 'Confira os itens.', formulario: proposal.formulario } }); return;
    }
    assert.ok(['comando', 'formulario', 'confirmar'].includes(action));
    await route.fulfill({ json: action === 'confirmar' ? { tipo: 'resultado_escrita', mensagem: 'Cobrança editada' } : proposal });
});
const url = `http://127.0.0.1:${server.httpServer.address().port}/__f2-test`;
async function noOverflow() {
    assert.deepEqual(await page.evaluate(() => ({ width: innerWidth, height: innerHeight, scroll: document.documentElement.scrollWidth })),
        { width: 360, height: 740, scroll: 360 });
}
try {
    await page.goto(url);
    await page.getByRole('button', { name: 'Editar o registro 1', exact: true }).waitFor();
    assert.equal(await page.getByRole('button', { name: /^Editar o registro/ }).count(), 2);
    await page.getByRole('button', { name: 'Editar o registro 1', exact: true }).click();
    await card().waitFor(); await noOverflow();
    await card().getByLabel('Tipo de desconto', { exact: true }).selectOption('percentual');
    await card().getByLabel('Desconto geral', { exact: true }).fill('10');
    await card().getByRole('button', { name: 'Confirmar', exact: true }).click();
    assert.equal(calls.filter(c => c.action === 'confirmar').length, 0);
    await card().getByLabel('Motivo do desconto', { exact: true }).fill('Acordo comercial');
    await card().getByLabel('Prévia financeira').getByText(/Total líquido:.*315,00/).waitFor();
    await noOverflow();
    assert.deepEqual(calls.find(c => c.action === 'formulario').body, { tool: 'editar_cobranca', id: 1 });
    await page.goto(`${url}?chat`);
    await page.locator('#campo-mensagem').fill('Editar cobrança 1'); await page.locator('#botao-enviar').click(); await card().waitFor();
    assert.equal(await card().getByRole('button', { name: 'Revisar', exact: true }).count(), 0);
    assert.equal(await card().getByLabel('Quantidade de Vacina aplicada', { exact: true }).isDisabled(), true);
    await card().getByLabel('Desconto geral', { exact: true }).fill('35,00');
    await card().getByLabel('Motivo do desconto', { exact: true }).fill('Acordo comercial');
    await card().getByLabel('Quantidade de Consulta', { exact: true }).fill('3');
    await card().getByLabel('Excluir financeiramente Vacina aplicada', { exact: true }).check();
    await card().getByRole('button', { name: 'Confirmar', exact: true }).click();
    assert.equal(calls.filter(c => c.action === 'confirmar').length, 0, 'reason required');
    await card().getByLabel('Motivo da exclusão de Vacina aplicada', { exact: true }).fill('Isenção financeira');
    await card().getByRole('button', { name: 'Adicionar item', exact: true }).click();
    await card().getByLabel('Item novo 1', { exact: true }).selectOption('3');
    await card().getByLabel('Quantidade nova 1', { exact: true }).fill('2');
    await noOverflow();
    await card().getByRole('button', { name: 'Confirmar', exact: true }).click();
    await card().getByRole('alert').waitFor();
    assert.equal(await card().getByLabel('Quantidade de Consulta', { exact: true }).inputValue(), '3');
    await page.waitForFunction(() => !document.querySelector('#botao-enviar').disabled);
    await card().getByRole('button', { name: 'Confirmar', exact: true }).evaluate(b => { b.click(); b.click(); });
    await card().getByText(/· Confirmado$/).waitFor();
    assert.equal(calls.filter(c => c.action === 'confirmar').length, 2, 'one failed attempt and one confirmation');
    const sent = calls.findLast(c => c.action === 'confirmar').body.valores;
    assert.equal(sent.desconto_tipo, 'reais');
    assert.equal(sent.desconto_valor, '35,00');
    assert.equal(sent.desconto_motivo, 'Acordo comercial');
    assert.equal(sent.linhas[0].quantidade, '3');
    assert.deepEqual(sent.linhas[1], { item_id: 2, quantidade: '2', remover: true, motivo: 'Isenção financeira' });
    assert.deepEqual(sent.novos, [{ catalogo_item_id: '3', quantidade: '2' }]);
    assert.equal(sent.versao, editor.versao);
    await noOverflow(); assert.deepEqual(errors, []);
    console.log('PASS: discount R$/%, required reason, server preview, financial editor and chat, 422 retry, single confirmation, 360x740 without overflow or page errors.');
} finally { await browser.close(); await server.close(); }
