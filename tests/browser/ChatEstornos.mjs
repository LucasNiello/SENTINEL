// Backend simulado; somente credenciais sintéticas, nunca credenciais locais.
import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';
import { createServer } from 'vite';
import react from '@vitejs/plugin-react';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE ? pathToFileURL(process.env.PLAYWRIGHT_MODULE).href : 'playwright');
const server = await createServer({ configFile: false, plugins: [react()], server: { host: '127.0.0.1', port: 0 }, appType: 'custom' });
const initial = { component: 'Agente', url: '/agente', version: null, props: { usuario: { id: 1, nome: 'QA', iniciais: 'QA', papel: 'operador', rotuloPapel: 'Operador' }, menu: [], appNome: 'Sentinel', errors: {}, historico: [] } };
server.middlewares.use('/__estornos', async (_req, res) => {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    res.end(await server.transformIndexHtml('/__estornos', `<!doctype html><html lang="pt-BR"><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="qa"><script type="module" src="/resources/js/react/app.jsx"></script><link rel="stylesheet" href="/resources/css/workspace.css"></head><body class="workspace"><script type="application/json" data-page="app">${JSON.stringify(initial)}</script><div id="app"></div></body></html>`));
});
await server.listen();
const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
const page = await browser.newPage({ viewport: { width: 360, height: 740 } });
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
const proposta = '11111111-1111-4111-8111-111111111111';
const token = '22222222-2222-4222-8222-222222222222';
const itens = [
    { item_id: 1, descricao: 'Consulta', origem: 'Prontuário', preco: 'R$ 150,00', liquido_restante: 'R$ 135,00', lancada: 1, estornada: 0, restante: 1, quantidade: 1 },
    { item_id: 2, descricao: 'Medicamento', origem: 'Avulso', preco: 'R$ 35,00', liquido_restante: 'R$ 63,00', lancada: 2, estornada: 0, restante: 2, quantidade: 2 },
    { item_id: 3, descricao: 'Esgotado', origem: 'Avulso', preco: 'R$ 10,00', lancada: 1, estornada: 1, restante: 0, quantidade: 0 },
];
const campos = (modo = 'tudo', motivo = 'Cobrado a mais') => [
    { nome: 'modo', rotulo: 'Modo', tipo: 'opcoes', obrigatorio: true, valor: modo, opcoes: ['tudo', 'tudo_menos_prontuario', 'itens'].map((valor) => ({ valor, rotulo: valor })) },
    { nome: 'motivo', rotulo: 'Motivo', tipo: 'texto', obrigatorio: true, valor: motivo },
];
let scenario, formulario, release, confirms = 0, reviews = 0, ultimoPedido;
await page.route('**/login', (route) => route.fulfill({ contentType: 'text/html', body: '<h1>Login</h1>' }));
await page.route('**/agente/*', async (route) => {
    const action = route.request().url().split('/').at(-1);
    const body = route.request().postDataJSON();
    if (action === 'comando') return route.fulfill({ json: { tipo: 'confirmacao_pendente', proposta, tool: 'estornar_cobranca', risco: 'exclusao', formulario } });
    if (action === 'revisar') {
        reviews++; ultimoPedido = body;
        assert.ok(!JSON.stringify(body).includes('fixture-pin'));
        assert.deepEqual(Object.keys(body.valores).sort(), ['linhas', 'modo', 'motivo']);
        for (const linha of body.valores.linhas) assert.deepEqual(Object.keys(linha).sort(), ['item_id', 'quantidade']);
        if (scenario === 'validacao') return route.fulfill({ status: 422, json: { tipo: 'erro', mensagem: 'Quantidade inválida.', formulario: { ...formulario, campos: campos(body.valores.modo, body.valores.motivo), erro: 'Medicamento: restam 2.', itens_estorno: itens.map((item) => ({ ...item, quantidade: body.valores.linhas.find((l) => l.item_id === item.item_id)?.quantidade ?? 0 })) } } });
        formulario = { ...formulario, campos: campos('itens', body.valores.motivo), resumo: [{ rotulo: 'Valor do estorno', valor: 'R$ 35,00' }], itens_estorno: itens.map((item) => ({ ...item, quantidade: item.item_id === 2 ? 1 : 0 })) };
        return route.fulfill({ json: { tipo: 'revisada', token, segurar: true, formulario } });
    }
    if (action === 'editar') { assert.deepEqual(body, { token }); return route.fulfill({ json: { tipo: 'rascunho', proposta } }); }
    if (action === 'cancelar') return route.fulfill({ json: { tipo: 'cancelado', mensagem: 'Proposta cancelada.' } });
    assert.equal(action, 'confirmar'); confirms++;
    assert.ok(body.token === token && body.email_admin === 'qa@example.test' && body.senha_admin === 'fixture-pin');
    assert.deepEqual(Object.keys(body).sort(), ['email_admin', 'senha_admin', 'token']);
    await new Promise((resolve) => { release = resolve; });
    if (scenario === 'rede') return route.abort('failed');
    if (scenario === 'sessao') return route.fulfill({ status: 419, json: {} });
    if (scenario === 'pin') return route.fulfill({ status: 403, json: { tipo: 'erro', codigo: 'AI_REAUTH_FAILED', mensagem: 'E-mail ou PIN de quem autoriza inválidos. Nada foi gravado.' } });
    if (scenario === 'recusa') return route.fulfill({ status: 403, json: { tipo: 'negado', codigo: 'AI_FORBIDDEN', mensagem: 'Seu perfil não permite esta ação.' } });
    if (scenario === 'mudou') return route.fulfill({ status: 422, json: { tipo: 'erro', mensagem: 'O plano não está mais disponível. Nada foi gravado.' } });
    return route.fulfill({ json: { tipo: 'resultado_escrita', mensagem: 'Estorno realizado (simulado).' } });
});
async function until(check) { for (let i = 0; i < 100; i++) { if (await check()) return; await new Promise((r) => setTimeout(r, 50)); } throw new Error('Condição não concluída'); }
const quantidade = (nome) => page.getByRole('textbox', { name: new RegExp(`Estornar quantos de ${nome}`) });
async function credenciais() { await page.getByLabel('E-mail de quem autoriza').fill('qa@example.test'); await page.getByLabel('PIN de quem autoriza').fill('fixture-pin'); }
async function abrir(modo = 'sucesso') {
    scenario = modo; release = null;
    formulario = { titulo: 'Estornar cobrança #7', campos: campos(), itens_estorno: itens, resumo: [{ rotulo: 'Valor do estorno', valor: 'R$ 220,00' }], avisos: ['O valor é calculado pelo servidor.'], reautenticar: true, tipo_autorizacao: 'estorno' };
    await page.goto(`${server.resolvedUrls.local[0]}__estornos`);
    await page.locator('#campo-mensagem').fill('Estornar a cobrança'); await page.locator('#botao-enviar').click();
    await quantidade('Medicamento').waitFor();
}
async function revisar() { await page.getByRole('button', { name: 'Revisar', exact: true }).click(); await until(async () => !await page.getByRole('button', { name: 'Confirmar', exact: true }).isDisabled()); }
// 25/11: estorno é destrutivo (o servidor manda segurar: true). O clique sozinho não confirma; é preciso segurar 2 s.
async function confirmar() {
    const antes = confirms;
    const botao = page.getByRole('button', { name: 'Confirmar', exact: true });
    await botao.evaluate((b) => { b.click(); b.click(); });
    await new Promise((r) => setTimeout(r, 300)); assert.equal(confirms, antes, 'clique não confirma ação de segurar');
    const caixa = await botao.boundingBox();
    await page.mouse.move(caixa.x + caixa.width / 2, caixa.y + caixa.height / 2);
    await page.mouse.down(); await new Promise((r) => setTimeout(r, 2300)); await page.mouse.up();
    await until(() => !!release); assert.equal(confirms, antes + 1);
    assert.equal(await page.getByLabel('PIN de quem autoriza').inputValue(), ''); release();
}
try {
    await abrir();
    assert.equal(await quantidade('Esgotado').isDisabled(), true);
    assert.equal(await page.locator('tbody tr').count(), 3);
    await page.getByText('Líquido restante: R$ 135,00', { exact: true }).waitFor();
    await page.getByRole('combobox', { name: 'Modo' }).click();
    await page.getByRole('option', { name: 'tudo_menos_prontuario', exact: true }).click();
    assert.equal(await quantidade('Consulta').inputValue(), '0');
    assert.equal(await quantidade('Medicamento').inputValue(), '2');
    await page.getByRole('combobox', { name: 'Modo' }).click();
    await page.getByRole('option', { name: 'tudo', exact: true }).click();
    assert.equal(await quantidade('Consulta').inputValue(), '1');
    await quantidade('Consulta').fill('0'); await quantidade('Medicamento').fill('1');
    assert.equal(await page.getByLabel('Modo', { exact: false }).inputValue(), 'itens');
    await credenciais();
    const antes = reviews;
    await page.getByLabel('Motivo', { exact: false }).fill('');
    await page.getByRole('button', { name: 'Revisar', exact: true }).click(); assert.equal(reviews, antes);
    await page.getByLabel('Motivo', { exact: false }).fill('Justificativa QA');
    await revisar();
    assert.deepEqual(ultimoPedido.valores.linhas, [{ item_id: 2, quantidade: '1' }]);
    assert.equal(confirms, 0);
    assert.ok((await page.locator('.card-registro').innerText()).includes('R$ 35,00'));
    assert.equal(await quantidade('Medicamento').isDisabled(), true);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    await page.getByRole('button', { name: 'Editar', exact: true }).click();
    await until(async () => !await quantidade('Medicamento').isDisabled());
    assert.equal(await page.getByRole('button', { name: 'Confirmar', exact: true }).isDisabled(), true);
    assert.equal(await page.getByLabel('Modo', { exact: false }).inputValue(), 'itens');
    assert.equal(await quantidade('Medicamento').inputValue(), '1');
    await credenciais(); await revisar(); await confirmar();
    await page.getByText('Estorno realizado (simulado).', { exact: true }).first().waitFor();
    assert.ok((await page.locator('#historico').innerText()).includes('Estornar a cobrança'));
    assert.ok(!(await page.locator('#historico').innerText()).includes('fixture-pin'));
    await abrir('validacao'); await quantidade('Medicamento').fill('3'); await credenciais();
    await page.getByRole('button', { name: 'Revisar', exact: true }).click();
    await page.getByText('Quantidade inválida.', { exact: true }).waitFor();
    assert.equal(await quantidade('Medicamento').inputValue(), '3');
    assert.equal(await page.getByRole('button', { name: 'Confirmar', exact: true }).isDisabled(), true);
    scenario = 'sucesso'; await quantidade('Consulta').fill('0'); await quantidade('Medicamento').fill('1'); await revisar();
    await page.getByRole('button', { name: 'Cancelar', exact: true }).click(); await page.getByText('Proposta cancelada.', { exact: true }).first().waitFor();
    for (const falha of ['pin', 'recusa', 'rede', 'mudou', 'sessao']) {
        await abrir(falha); await credenciais(); await revisar(); await confirmar();
        if (falha === 'sessao') { await page.waitForURL('**/login'); continue; }
        await page.getByRole('alert').waitFor();
        assert.ok(!(await page.locator('#historico').innerText()).includes('fixture-pin'));
        if (['pin', 'recusa'].includes(falha)) assert.equal(await page.getByRole('button', { name: 'Confirmar', exact: true }).count(), 0);
        else assert.equal(await page.getByRole('button', { name: 'Confirmar', exact: true }).isDisabled(), true);
    }
    assert.deepEqual(errors, []);
    console.log('PASS: estornos simulados, modos, quantidades, justificativa, valores do servidor, revisão/edição/token, PIN, recusa, falhas, sessão expirada, duplicação, histórico e 360x740.');
} finally { await browser.close(); await server.close(); }
