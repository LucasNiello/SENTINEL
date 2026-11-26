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
const page = await browser.newPage({ viewport: { width: 360, height: 740 }, timezoneId: 'America/Sao_Paulo' });
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
const calls = [];
let failure = null;
let release = null;
let delayNext = false;
const proposal = { tipo: 'confirmacao_pendente', proposta: '11111111-1111-4111-8111-111111111111', risco: 'escrita',
    argumentos: { nome: 'Cliente QA' }, formulario: { titulo: 'Cadastrar cliente', campos: [{ nome: 'nome', rotulo: 'Nome', valor: 'Cliente QA' }] } };
const token = '22222222-2222-4222-8222-222222222222';
let nextToken = token;
let commandResponse = proposal;
let formProposal = proposal;
let reviewWithoutForm = false;
await page.route('**/agente/*', async (route) => {
    const action = route.request().url().split('/').at(-1);
    const body = route.request().postDataJSON();
    calls.push({ action, body });
    if (delayNext) { delayNext = false; await new Promise((resolve) => { release = resolve; }); }
    if (failure?.action === action) {
        const failed = failure; failure = null;
        await route.fulfill({ status: failed.status || 422, json: { tipo: 'erro', codigo: failed.code || 'AI_VALIDATION_ERROR', mensagem: 'Falha simulada', ...(failed.formulario ? { formulario: failed.formulario } : {}) } }); return;
    }
    const responses = { comando: commandResponse, formulario: formProposal, editar: { tipo: 'rascunho', proposta: proposal.proposta },
        revisar: { tipo: 'revisada', token: nextToken, segurar: false, ...(reviewWithoutForm ? {} : { formulario: { ...formProposal.formulario, avisos: ['Revisão validada pelo servidor'] } }) },
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
    assert.equal(await card().getByLabel('Nome', { exact: true }).inputValue(), 'Cliente QA');
    assert.equal(await card().getByRole('button', { name: 'Confirmar', exact: true }).isDisabled(), true);
    await card().getByRole('button', { name: 'Confirmar', exact: true }).evaluate((button) => button.click());
    assert.equal(calls.filter((c) => c.action === 'confirmar').length, 0);
    failure = { action: 'revisar' }; await action('Revisar', 'revisar');
    assert.equal(await card().getByRole('button', { name: 'Confirmar', exact: true }).isDisabled(), true);
    assert.match(await card().getByRole('alert').innerText(), /Falha simulada/);
    await action('Revisar', 'revisar');
    assert.deepEqual(calls.at(-1).body, { proposta: proposal.proposta, valores: { nome: 'Cliente QA' } });
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
    // Parte 3: open the backend-provided action, fill schema fields, handle
    // validation, invalidate the reviewed token and explicitly review again.
    commandResponse = { tipo: 'resultado_leitura', editar: 'atualizar_cliente', resultado: [{ id: 7, nome: 'Cliente para editar' }] };
    const fields = [
        { nome: 'nome', rotulo: 'Nome', tipo: 'texto', obrigatorio: true, max: 255, valor: '' },
        { nome: 'email', rotulo: 'E-mail', tipo: 'email', valor: '' },
        { nome: 'valor', rotulo: 'Valor', tipo: 'numero', min: 0, valor: '10' },
        { nome: 'data', rotulo: 'Data', tipo: 'data', valor: '2026-11-24' },
        { nome: 'tipo', rotulo: 'Tipo', tipo: 'opcoes', valor: 'saida', opcoes: [{ valor: 'saida', rotulo: 'Saída' }, { valor: 'entrada', rotulo: 'Entrada' }] },
        { nome: 'cliente_id', rotulo: 'Cliente', tipo: 'referencia', valor: '7', opcoes: [{ valor: 7, rotulo: 'Cliente QA' }], condicoes: [{ campo: 'tipo', valor: 'entrada', efeito: 'proibido' }, { campo: 'tipo', valor: 'saida', efeito: 'obrigatorio' }] },
        { nome: 'documento', rotulo: 'Documento', tipo: 'texto', obrigatorio: true, sensivel: true, informado: true, valor: '***.***.789-01' },
    ];
    formProposal = { ...proposal, formulario: { titulo: 'Editar cliente', campos: fields } };
    await page.locator('#campo-mensagem').fill('Quero editar o cliente'); await page.locator('#botao-enviar').click();
    const open = page.getByRole('button', { name: 'Editar registro #7', exact: true });
    await open.waitFor();
    failure = { action: 'formulario', status: 503 };
    await open.click();
    await until(() => failure === null); await until(async () => !await open.isDisabled());
    assert.match(await page.locator('#historico .msg--erro').last().innerText(), /Falha simulada/);
    delayNext = true; release = null;
    const beforeOpen = calls.length;
    await open.evaluate((button) => { button.click(); button.click(); });
    await until(() => release !== null);
    assert.equal(calls.length, beforeOpen + 1);
    assert.deepEqual(calls.at(-1), { action: 'formulario', body: { tool: 'atualizar_cliente', id: 7 } });
    release(); await until(async () => await card().getByLabel('Documento').count() > 0 && !await open.isDisabled());
    const beforeInvalid = calls.length;
    await card().getByRole('button', { name: 'Revisar', exact: true }).click();
    assert.equal(calls.length, beforeInvalid, 'required field prevents review');
    assert.equal(await card().getByRole('button', { name: 'Confirmar', exact: true }).isDisabled(), true);
    await card().getByLabel('Nome *obrigatório', { exact: true }).fill('Nome preenchido');
    await card().getByLabel('E-mail', { exact: true }).fill('invalido');
    await card().getByRole('button', { name: 'Revisar', exact: true }).click();
    assert.equal(calls.length, beforeInvalid, 'invalid email prevents review');
    await card().getByLabel('E-mail', { exact: true }).fill('qa@example.test');
    assert.equal(await card().getByLabel('Documento').inputValue(), '', 'never send a mask');
    await card().getByLabel('Tipo', { exact: true }).selectOption('entrada');
    assert.equal(await card().getByLabel('Cliente', { exact: true }).isDisabled(), true);
    await card().getByLabel('Valor', { exact: true }).fill('4.567,89');
    failure = { action: 'revisar', formulario: { ...formProposal.formulario, campos: fields.map((c) => c.nome === 'valor' ? { ...c, erro: 'Use ponto nos centavos.' } : c) } };
    await action('Revisar', 'revisar');
    assert.equal(calls.at(-1).body.valores.cliente_id, '');
    assert.equal('documento' in calls.at(-1).body.valores, false);
    assert.equal(await card().getByLabel('Valor', { exact: true }).inputValue(), '4.567,89');
    assert.equal(await card().getByLabel('Nome *obrigatório', { exact: true }).inputValue(), 'Nome preenchido');
    assert.equal(await card().getByLabel('Valor', { exact: true }).getAttribute('aria-invalid'), 'true');
    await card().getByLabel('Valor', { exact: true }).fill('4567.89');
    reviewWithoutForm = true;
    await action('Revisar', 'revisar');
    assert.equal(await card().getByLabel('Valor', { exact: true }).getAttribute('aria-invalid'), null);
    assert.equal(await card().getByLabel('Nome *obrigatório', { exact: true }).isDisabled(), true);
    failure = { action: 'editar', status: 503 }; await action('Editar', 'editar');
    assert.deepEqual(calls.at(-1).body, { token });
    assert.equal(await card().getByRole('button', { name: 'Confirmar', exact: true }).isDisabled(), true);
    assert.equal(await card().getByLabel('Nome *obrigatório', { exact: true }).isDisabled(), true);
    await action('Editar', 'editar');
    assert.equal(await card().getByLabel('Nome *obrigatório', { exact: true }).isEnabled(), true);
    assert.match(await page.locator('#historico .msg--agente').last().innerText(), /nova revisão/);
    await card().getByLabel('Nome *obrigatório', { exact: true }).fill('Nome editado');
    nextToken = '33333333-3333-4333-8333-333333333333';
    const confirmations = calls.filter((c) => c.action === 'confirmar').length;
    await action('Revisar', 'revisar');
    assert.equal(calls.at(-1).body.valores.nome, 'Nome editado');
    assert.equal(calls.filter((c) => c.action === 'confirmar').length, confirmations);
    if (process.env.CHAT_SCREENSHOT) await page.screenshot({ path: process.env.CHAT_SCREENSHOT, fullPage: true });
    await action('Confirmar', 'confirmar');
    assert.deepEqual(calls.at(-1).body, { token: nextToken });
    assert.equal(await card().getByRole('button').count(), 0);
    // Parte 4: availability is supplied exclusively by the existing GET API.
    await page.clock.setFixedTime(new Date('2026-11-24T12:00:00-03:00'));
    const availabilityCalls = [];
    let availabilityFailure = false;
    await page.route('**/agenda/disponibilidade?*', async (route) => {
        const q = Object.fromEntries(new URL(route.request().url()).searchParams);
        availabilityCalls.push(q);
        if (availabilityFailure) { availabilityFailure = false; await route.fulfill({ status: 503, json: { mensagem: 'Disponibilidade temporariamente indisponível' } }); return; }
        const professionals = [{ id: 8, nome: 'Dra. Ana' }, { id: 9, nome: 'Dr. Bruno' }];
        if (q.mes) {
            const days = Object.fromEntries(Array.from({ length: new Date(Number(q.mes.slice(0, 4)), Number(q.mes.slice(5)), 0).getDate() }, (_, i) => {
                const date = `${q.mes}-${String(i + 1).padStart(2, '0')}`;
                return [date, ['2026-11-24', '2026-11-25', '2026-11-26', '2026-12-02'].includes(date)];
            }));
            await route.fulfill({ json: { tipo: 'disponibilidade', setor: 'clinica', profissionais: professionals, dias: days } });
        } else {
            const available = q.profissional ? [{ id: Number(q.profissional), vagas: 1 }] : [{ id: 8, vagas: 1 }, { id: 9, vagas: 1 }];
            await route.fulfill({ json: { tipo: 'disponibilidade', setor: 'clinica', modelo: 'blocos', mostra_vagas: true, profissionais: professionals,
                horarios: [{ hora: '09:00', inicio: `${q.dia} 09:00`, vagas: 0, profissionais: [] },
                    { hora: '10:00', inicio: `${q.dia} 10:00`, vagas: available.length, profissionais: available }] } });
        }
    });
    const scheduleFields = [
        { nome: 'pet_id', rotulo: 'Pet', tipo: 'referencia', valor: '3', obrigatorio: true, opcoes: [{ valor: 3, rotulo: 'Thor' }] },
        { nome: 'catalogo_item_id', rotulo: 'Serviço', tipo: 'referencia', valor: '20', obrigatorio: true, opcoes: [{ valor: 20, rotulo: 'Consulta' }, { valor: 21, rotulo: 'Retorno' }] },
        { nome: 'profissional_id', rotulo: 'Profissional', tipo: 'referencia', valor: '', obrigatorio: true, opcoes: [{ valor: 8, rotulo: 'Dra. Ana' }, { valor: 9, rotulo: 'Dr. Bruno' }] },
        { nome: 'inicio', rotulo: 'Início', tipo: 'texto', valor: '', obrigatorio: true },
    ];
    formProposal = { ...proposal, formulario: { titulo: 'Agendar atendimento', campos: scheduleFields, ficha: { servico_id: 20, dia: '2026-11-24' } } };
    commandResponse = formProposal;
    await send();
    const calendar = () => card().getByRole('group', { name: 'Calendário de agendamento', exact: true });
    const slotsReady = () => until(async () => await calendar().getByRole('button', { name: '10:00 · 2 vaga(s)', exact: true }).count() > 0);
    await slotsReady();
    assert.equal(await calendar().getByRole('button', { name: '23 de novembro, sem vaga', exact: true }).isDisabled(), true);
    assert.equal(await calendar().getByRole('button', { name: '09:00 · lotado', exact: true }).isDisabled(), true);
    assert.equal(await calendar().getByRole('button', { name: 'Mês anterior' }).isDisabled(), true);
    await calendar().getByRole('button', { name: 'Próximo mês' }).click();
    await until(async () => await calendar().getByRole('button', { name: '2 de dezembro, com vaga' }).count() > 0);
    await calendar().getByRole('button', { name: '2 de dezembro, com vaga' }).click();
    await slotsReady();
    assert.equal(availabilityCalls.at(-1).dia, '2026-12-02');
    availabilityFailure = true;
    await calendar().getByRole('button', { name: 'Mês anterior' }).click();
    await calendar().getByRole('button', { name: 'Tentar novamente' }).waitFor();
    assert.equal(await calendar().locator('.ficha-horario').count(), 0, 'failed request clears stale availability');
    await calendar().getByRole('button', { name: 'Tentar novamente' }).click();
    await until(async () => await calendar().getByRole('button', { name: '25 de novembro, com vaga' }).count() > 0);
    await calendar().getByRole('button', { name: '25 de novembro, com vaga' }).click();
    await slotsReady();
    await calendar().getByLabel('Buscar profissional').fill('Ana');
    assert.equal(await calendar().getByLabel('Profissional', { exact: true }).locator('option').count(), 2);
    await calendar().getByLabel('Buscar profissional').fill('');
    await calendar().getByRole('button', { name: '10:00 · 2 vaga(s)', exact: true }).click();
    const beforeSchedule = calls.filter((c) => c.action === 'confirmar').length;
    failure = { action: 'revisar' };
    await action('Dra. Ana · 1 vaga(s)', 'revisar');
    assert.equal(calls.at(-1).body.valores.inicio, '2026-11-25 10:00');
    assert.equal(calls.at(-1).body.valores.profissional_id, '8');
    assert.match(await calendar().innerText(), /Selecionado: 2026-11-25 10:00/);
    assert.equal(await card().getByRole('button', { name: 'Confirmar', exact: true }).isDisabled(), true);
    await calendar().getByRole('button', { name: '10:00 · 2 vaga(s)', exact: true }).click();
    await action('Dra. Ana · 1 vaga(s)', 'revisar');
    assert.equal(await calendar().getByRole('button', { name: 'Próximo mês' }).isDisabled(), true);
    assert.equal(calls.filter((c) => c.action === 'confirmar').length, beforeSchedule, 'slot selection only reviews');
    await action('Editar', 'editar');
    assert.equal(await card().getByRole('button', { name: 'Confirmar', exact: true }).isDisabled(), true);
    await calendar().getByRole('button', { name: '26 de novembro, com vaga' }).click();
    await slotsReady();
    nextToken = '44444444-4444-4444-8444-444444444444';
    await calendar().getByRole('button', { name: '10:00 · 2 vaga(s)', exact: true }).click();
    await action('Dr. Bruno · 1 vaga(s)', 'revisar');
    assert.equal(calls.at(-1).body.valores.inicio, '2026-11-26 10:00');
    await action('Confirmar', 'confirmar');
    assert.deepEqual(calls.at(-1).body, { token: nextToken });
    formProposal = { ...proposal, formulario: { titulo: 'Remarcar agendamento', campos: scheduleFields.map((f) => ({ ...f, valor: f.nome === 'profissional_id' ? '8' : f.nome === 'inicio' ? '2026-11-24 10:00' : f.valor })), ficha: { servico_id: 20, agendamento_id: 17, dia: '2026-11-24' } } };
    commandResponse = formProposal; await send();
    await until(async () => await calendar().getByRole('button', { name: '10:00 · 1 vaga(s)', exact: true }).count() > 0);
    assert.equal(availabilityCalls.at(-1).ignorar, '17');
    assert.equal(availabilityCalls.at(-1).profissional, '8');
    await card().getByLabel('Serviço *obrigatório', { exact: true }).selectOption('21');
    await slotsReady();
    assert.equal(availabilityCalls.at(-1).servico, '21');
    assert.equal(availabilityCalls.at(-1).profissional, undefined);
    assert.doesNotMatch(await calendar().innerText(), /Selecionado:/);
    await calendar().getByLabel('Profissional', { exact: true }).selectOption('8');
    await until(async () => await calendar().getByRole('button', { name: '10:00 · 1 vaga(s)', exact: true }).count() > 0);
    await calendar().getByRole('button', { name: '25 de novembro, com vaga' }).click();
    await until(async () => await calendar().getByRole('button', { name: '10:00 · 1 vaga(s)', exact: true }).count() > 0);
    await action('10:00 · 1 vaga(s)', 'revisar');
    assert.equal(calls.at(-1).body.valores.inicio, '2026-11-25 10:00');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    if (process.env.CHAT_SCREENSHOT) await card().screenshot({ path: process.env.CHAT_SCREENSHOT });
    await action('Confirmar', 'confirmar');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    assert.deepEqual(errors, []);
    console.log('PASS: Partes 1/2/3/4, calendário, meses, disponibilidade, profissionais, agendamento, remarcação, token, revisão explícita, erros, histórico, duplicação e 360x740.');
} finally { await browser.close(); await server.close(); }
