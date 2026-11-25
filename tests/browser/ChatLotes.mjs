// Backend simulado; somente credenciais sintéticas, nunca credenciais locais.
import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE ? pathToFileURL(process.env.PLAYWRIGHT_MODULE).href : 'playwright');
const server = await createServer({ configFile: false, plugins: [react()], server: { host: '127.0.0.1', port: 0 }, appType: 'custom' });
const initial = { component: 'Agente', url: '/agente', version: null, props: { usuario: { id: 1, nome: 'QA', iniciais: 'QA', papel: 'operador', rotuloPapel: 'Operador' }, menu: [], appNome: 'Sentinel', errors: {}, historico: [] } };
server.middlewares.use('/__lotes', async (_req, res) => {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    res.end(await server.transformIndexHtml('/__lotes', `<!doctype html><html lang="pt-BR"><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="qa"><script type="module" src="/resources/js/react/app.jsx"></script><link rel="stylesheet" href="/resources/css/workspace.css"></head><body class="workspace"><script type="application/json" data-page="app">${JSON.stringify(initial)}</script><div id="app"></div></body></html>`));
});
await server.listen();
const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
const page = await browser.newPage({ viewport: { width: 360, height: 740 } });
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
const proposta = '11111111-1111-4111-8111-111111111111';
const token = '22222222-2222-4222-8222-222222222222';
const itens = [
    { id: 7, descricao: 'Primeiro lançamento <script>texto</script>', data: '25/11/2026', valor: 'R$ 10,00', antes: 'pendente', depois: 'conciliado', erro: null },
    { id: 8, descricao: 'Segundo lançamento', data: '26/11/2026', valor: 'R$ 20,00', antes: 'pendente', depois: 'conciliado', erro: null },
];
let modo, formulario, release, confirms = 0, reviews = 0, cancels = 0;
await page.route('**/agente/*', async (route) => {
    const action = route.request().url().split('/').at(-1);
    const body = route.request().postDataJSON();
    if (action === 'comando') return route.fulfill({ json: { tipo: 'confirmacao_pendente', proposta, tool: 'conciliar_lancamentos_em_lote', risco: 'escrita', formulario } });
    if (action === 'revisar') {
        reviews++;
        assert.deepEqual(body, { proposta, valores: {} });
        if (modo === 'permissao') return route.fulfill({ status: 403, json: { tipo: 'negado', codigo: 'AI_FORBIDDEN', mensagem: 'Seu perfil não permite esta ação.' } });
        if (modo === 'transitorio') return route.fulfill({ status: 503, json: { tipo: 'erro', mensagem: 'Serviço indisponível.' } });
        if (modo === 'item-invalido') return route.fulfill({ status: 422, json: { tipo: 'erro', mensagem: 'O lote mudou. Nada foi gravado.', formulario: { ...formulario, itens: [itens[0], { ...itens[1], erro: 'O lançamento #8 não está mais pendente.' }] } } });
        return route.fulfill({ json: { tipo: 'revisada', token, formulario } });
    }
    if (action === 'cancelar') {
        cancels++; assert.deepEqual(body, { proposta });
        return route.fulfill({ json: { tipo: 'cancelado', mensagem: 'Lote cancelado.' } });
    }
    assert.equal(action, 'confirmar'); confirms++;
    assert.equal(body.token, token);
    assert.ok(!('ids' in body) && !('valores' in body), 'itens executados vêm exclusivamente da sessão');
    if (formulario.reautenticar) assert.ok(body.email_admin === 'qa@example.test' && body.senha_admin === 'fixture-lote');
    else assert.deepEqual(body, { token });
    await new Promise((resolve) => { release = resolve; });
    if (modo === 'falha-confirmacao') return route.fulfill({ status: 422, json: { tipo: 'erro', mensagem: 'O lançamento #8 não está mais pendente. Nada foi gravado.' } });
    return route.fulfill({ json: { tipo: 'resultado_escrita', mensagem: '2 lançamentos conciliados.' } });
});
async function until(check) { for (let i = 0; i < 100; i++) { if (await check()) return; await new Promise((r) => setTimeout(r, 50)); } throw new Error('Condição não concluída'); }
async function abrir(scenario, reautenticar = false) {
    modo = scenario; release = null;
    formulario = { titulo: 'Conciliar lançamentos em lote', campos: [], itens, reautenticar };
    await page.goto(`${server.resolvedUrls.local[0]}__lotes`);
    await page.locator('#campo-mensagem').fill('Conciliar o período');
    await page.locator('#botao-enviar').click();
    await page.getByRole('table').waitFor();
}
async function revisar() {
    await page.getByRole('button', { name: 'Revisar', exact: true }).click();
    await until(async () => !await page.getByRole('button', { name: 'Confirmar', exact: true }).isDisabled());
}
async function confirmar() {
    const antes = confirms;
    await page.getByRole('button', { name: 'Confirmar', exact: true }).evaluate((b) => { b.click(); b.click(); });
    await until(() => !!release);
    assert.equal(confirms, antes + 1);
    assert.equal(await page.getByRole('button', { name: 'Cancelar', exact: true }).isDisabled(), true);
    release();
}
try {
    await abrir('sucesso');
    assert.deepEqual(await page.locator('th').allTextContents(), ['Nº', 'Descrição', 'Data', 'Valor', 'Status']);
    assert.equal(await page.locator('tbody tr').count(), 2);
    assert.equal(await page.locator('tbody tr').first().locator('td').nth(1).textContent(), itens[0].descricao);
    assert.equal(await page.locator('tbody script').count(), 0);
    assert.equal(await page.locator('tbody tr').first().locator('td').last().textContent(), 'pendente → conciliado');
    assert.equal(await page.getByRole('button', { name: 'Confirmar', exact: true }).isDisabled(), true);
    await revisar();
    assert.equal(confirms, 0, 'confirmar exige ação explícita');
    assert.equal(await page.getByRole('button', { name: 'Editar', exact: true }).count(), 0, 'lote congelado não tem edição');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    assert.equal(await page.getByRole('region', { name: 'Itens do lote' }).evaluate((e) => getComputedStyle(e).overflowX), 'auto');
    await confirmar();
    await page.getByText('2 lançamentos conciliados.', { exact: true }).first().waitFor();
    assert.equal(await page.locator('tbody tr').count(), 2, 'plano permanece no histórico');
    assert.ok((await page.locator('#historico').innerText()).includes('Conciliar o período'));
    await abrir('item-invalido');
    await page.getByRole('button', { name: 'Revisar', exact: true }).click();
    await page.locator('.item-erro-texto').waitFor();
    assert.equal(await page.locator('.item-erro').count(), 1);
    assert.equal(await page.locator('tbody tr').count(), 2);
    assert.equal(await page.getByRole('button', { name: 'Revisar', exact: true }).count(), 0);
    assert.equal(await page.getByRole('button', { name: 'Confirmar', exact: true }).count(), 0);
    await page.getByRole('button', { name: 'Cancelar', exact: true }).click();
    await page.getByText('Lote cancelado.', { exact: true }).first().waitFor();
    assert.equal(cancels, 1);
    await abrir('permissao');
    await page.getByRole('button', { name: 'Revisar', exact: true }).click();
    await page.getByRole('alert').waitFor();
    assert.equal(await page.getByRole('button', { name: 'Confirmar', exact: true }).count(), 0);
    await abrir('transitorio');
    await page.getByRole('button', { name: 'Revisar', exact: true }).click();
    await page.getByRole('alert').waitFor();
    modo = 'sucesso'; await revisar();
    await page.getByRole('button', { name: 'Cancelar', exact: true }).click();
    await page.getByText('Lote cancelado.', { exact: true }).first().waitFor();
    await abrir('falha-confirmacao'); await revisar(); await confirmar();
    await page.getByRole('alert').waitFor();
    assert.ok((await page.getByRole('alert').innerText()).includes('Nada foi gravado'));
    assert.equal(await page.getByRole('button', { name: 'Confirmar', exact: true }).isDisabled(), true);
    assert.equal(await page.locator('tbody tr').count(), 2);
    // Exercita o sinal genérico do contrato, sem criar uma nova regra de autorização para lotes.
    await abrir('sucesso', true);
    const antes = reviews;
    await page.getByRole('button', { name: 'Revisar', exact: true }).click();
    assert.equal(reviews, antes);
    await page.getByLabel('E-mail do administrador', { exact: true }).fill('qa@example.test');
    await page.getByLabel('Senha do administrador', { exact: true }).fill('fixture-lote');
    await revisar(); await confirmar();
    await page.getByText('2 lançamentos conciliados.', { exact: true }).first().waitFor();
    assert.ok(!(await page.locator('#historico').innerText()).includes('fixture-lote'));
    assert.deepEqual(errors, []);
    console.log('PASS: itens/estados, revisão explícita, confirmação única, cancelamento, erro individual, falha atômica, permissões, recuperação, reautenticação, histórico e 360px.');
} finally { await browser.close(); await server.close(); }
