// Backend simulado; somente credenciais sintéticas, nunca credenciais locais.
import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE ? pathToFileURL(process.env.PLAYWRIGHT_MODULE).href : 'playwright');
const server = await createServer({ configFile: false, plugins: [react()], server: { host: '127.0.0.1', port: 0 }, appType: 'custom' });
const initial = { component: 'Agente', url: '/agente', version: null, props: { usuario: { id: 1, nome: 'QA', iniciais: 'QA', papel: 'operador', rotuloPapel: 'Operador' }, menu: [], appNome: 'Sentinel', errors: {}, historico: [] } };
server.middlewares.use('/__reauth', async (_req, res) => {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    res.end(await server.transformIndexHtml('/__reauth', `<!doctype html><html lang="pt-BR"><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="qa"><script type="module" src="/resources/js/react/app.jsx"></script><link rel="stylesheet" href="/resources/css/workspace.css"></head><body class="workspace"><script type="application/json" data-page="app">${JSON.stringify(initial)}</script><div id="app"></div></body></html>`));
});
await server.listen();
const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
const page = await browser.newPage({ viewport: { width: 360, height: 740 } });
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
const proposta = '11111111-1111-4111-8111-111111111111';
const token = '22222222-2222-4222-8222-222222222222';
const secret = 'somente-fixture-qa';
let formulario, modo, release, confirms = 0, reviews = 0, validBody = false;
const fixture = () => ({ tipo: 'confirmacao_pendente', proposta, risco: 'escrita', formulario });
await page.route('**/login', (route) => route.fulfill({ contentType: 'text/html', body: '<h1>Login</h1>' }));
await page.route('**/agente/*', async (route) => {
    const acao = route.request().url().split('/').at(-1);
    const body = route.request().postDataJSON();
    if (acao !== 'confirmar') assert.ok(!JSON.stringify(body).includes(secret), 'segredo fora de requisições auxiliares');
    if (acao === 'comando') return route.fulfill({ json: fixture() });
    if (acao === 'revisar') {
        reviews++;
        if (modo === 'exigir-na-revisao') formulario = { ...formulario, reautenticar: true, tipo_autorizacao: 'crmv' };
        return route.fulfill({ json: { tipo: 'revisada', proposta, token, formulario } });
    }
    if (acao === 'editar') return route.fulfill({ json: { tipo: 'rascunho', proposta } });
    if (acao === 'cancelar') return route.fulfill({ json: { tipo: 'cancelado', mensagem: 'Proposta cancelada.' } });
    assert.equal(acao, 'confirmar');
    confirms++;
    validBody = body.token === token && body.senha_admin === secret
        && body.email_admin === (formulario.tipo_autorizacao === 'crmv' ? 'CRMV-QA' : 'qa@example.test')
        && Object.keys(body).sort().join(',') === 'email_admin,senha_admin,token';
    await new Promise((resolve) => { release = resolve; });
    if (modo === 'rede') return route.abort('failed');
    if (modo === 'sessao') return route.fulfill({ status: 419, json: { message: 'Sessão expirada.' } });
    if (modo === 'incorreta') return route.fulfill({ status: 403, json: { tipo: 'erro', codigo: 'AI_REAUTH_FAILED', mensagem: 'Credencial inválida. Nada foi gravado.' } });
    if (modo === 'recusa') return route.fulfill({ status: 403, json: { tipo: 'negado', codigo: 'AI_FORBIDDEN', mensagem: 'Seu perfil não permite esta ação.' } });
    if (modo === 'limite') return route.fulfill({ status: 429, json: { tipo: 'erro', codigo: 'AI_REAUTH_RATE_LIMIT', mensagem: 'Limite de tentativas atingido.' } });
    return route.fulfill({ json: { tipo: 'resultado_escrita', mensagem: 'Ação autorizada concluída.' } });
});
async function until(check) { for (let i = 0; i < 100; i++) { if (await check()) return; await new Promise((r) => setTimeout(r, 50)); } throw new Error('Condição não concluída'); }
const senha = () => page.locator('input[type=password]');
const ident = () => page.locator('.card-autorizacao input').first();
async function preencher() { await ident().fill(formulario.tipo_autorizacao === 'crmv' ? 'CRMV-QA' : 'qa@example.test'); await senha().fill(secret); }
async function abrir(tipo = 'admin', scenario = 'sucesso', exigida = true) {
    formulario = { titulo: 'Ação sensível QA', campos: [], reautenticar: exigida, tipo_autorizacao: tipo };
    modo = scenario; release = null;
    await page.goto(`${server.resolvedUrls.local[0]}__reauth`);
    await page.locator('#campo-mensagem').fill('Preparar ação QA');
    await page.locator('#botao-enviar').click();
    await page.getByRole('button', { name: 'Revisar', exact: true }).waitFor();
}
async function confirmar() {
    const antes = confirms;
    await page.getByRole('button', { name: 'Confirmar', exact: true }).evaluate((b) => { b.click(); b.click(); });
    await until(() => !!release);
    assert.equal(confirms, antes + 1);
    assert.ok(validBody, 'contrato de confirmação com credencial');
    assert.equal(await senha().inputValue(), '');
    assert.equal(await ident().inputValue(), '');
    assert.equal(await page.getByRole('button', { name: 'Confirmar', exact: true }).isDisabled(), true);
    release();
}
async function semVazamento() {
    assert.ok(!(await page.locator('#historico').innerText()).includes(secret));
    assert.ok(!(await page.content()).includes(secret));
    assert.ok(!(await page.evaluate(() => JSON.stringify([localStorage, sessionStorage]))).includes(secret));
    assert.ok(!page.url().includes(secret));
}
try {
    for (const tipo of ['admin', 'crmv', 'estorno']) {
        await abrir(tipo);
        assert.equal(await page.getByRole('button', { name: 'Confirmar', exact: true }).isDisabled(), true);
        const antes = reviews;
        await page.getByRole('button', { name: 'Revisar', exact: true }).click();
        assert.equal(reviews, antes, 'credenciais obrigatórias');
        await preencher();
        await page.getByRole('button', { name: 'Revisar', exact: true }).click();
        await until(async () => !await page.getByRole('button', { name: 'Confirmar', exact: true }).isDisabled());
        assert.equal(release, null, 'revisão nunca confirma automaticamente');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await semVazamento(); await confirmar();
        await page.getByText('Ação autorizada concluída.', { exact: true }).first().waitFor();
        assert.equal(await senha().count(), 0); await semVazamento();
    }
    for (const scenario of ['incorreta', 'recusa', 'limite', 'rede', 'sessao']) {
        await abrir('admin', scenario); await preencher();
        await page.getByRole('button', { name: 'Revisar', exact: true }).click();
        await until(async () => !await page.getByRole('button', { name: 'Confirmar', exact: true }).isDisabled());
        await confirmar();
        if (scenario === 'sessao') { await page.waitForURL('**/login'); continue; }
        await page.getByRole('alert').waitFor(); await semVazamento();
        if (scenario === 'rede') {
            assert.equal(await senha().inputValue(), '');
            assert.equal(await page.getByRole('button', { name: 'Confirmar', exact: true }).isDisabled(), true);
            modo = 'sucesso'; release = null; await preencher();
            await page.getByRole('button', { name: 'Revisar', exact: true }).click();
            await until(async () => !await page.getByRole('button', { name: 'Confirmar', exact: true }).isDisabled());
            await confirmar(); await page.getByText('Ação autorizada concluída.', { exact: true }).first().waitFor();
        } else assert.equal(await page.getByRole('button', { name: 'Confirmar', exact: true }).count(), 0);
    }
    await abrir('admin', 'exigir-na-revisao', false);
    assert.equal(await senha().count(), 0);
    await page.getByRole('button', { name: 'Revisar', exact: true }).click();
    await senha().waitFor();
    const antes = confirms;
    await page.getByRole('button', { name: 'Confirmar', exact: true }).click();
    assert.equal(confirms, antes, 'exigência nova da revisão também bloqueia sem credenciais');
    await preencher();
    await page.getByRole('button', { name: 'Editar', exact: true }).click();
    await until(async () => !await page.getByRole('button', { name: 'Revisar', exact: true }).isDisabled());
    assert.equal(await senha().inputValue(), '');
    await preencher();
    await page.evaluate(() => { window.campoDescartadoQA = document.querySelector('input[type=password]'); });
    await page.getByRole('button', { name: 'Cancelar', exact: true }).click();
    await until(async () => await senha().count() === 0);
    assert.equal(await page.evaluate(() => window.campoDescartadoQA.value), '');
    await semVazamento();
    assert.deepEqual(errors, []);
    console.log('PASS: autorização admin/CRMV/PIN, revisão explícita, recusa, credencial incorreta, limite, comunicação/recuperação, sessão expirada, duplicação, limpeza, histórico e 360x740.');
} finally { await browser.close(); await server.close(); }
