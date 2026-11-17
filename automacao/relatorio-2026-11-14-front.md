# Relatório do front — 14/11/2026 (INVENTÁRIO → F1 → F2 → F3 → F4)

Branch `automacao-na-madruga`, base `f5f5824` (árvore limpa no início). Nada commitado; nenhum comando git de histórico. Registro detalhado: seção "Sessão de front (14/11, tarde)" em `.claude/decisoes/2026-11-14.md`.

**Suíte: 931/931 → 981/981** (7671 asserções), PHP 8.4.27, pelo `test-runner`, depois das decisões do Lucas de 15/11. Antes delas: 978/978.

**Build:** `npm run build` limpo, com o node do Laragon (`C:\laragon\bin\nodejs\node-v22`, fora do PATH).

**Chamadas reais à API: 0.** Nenhuma migration, nenhum pacote novo.

**Mudou por decisão do Lucas (15/11), e só isso fora do front:**
- a tool `consultar_cobrancas` ganhou o papel `veterinario`;
- a ficha do pet e os Resultados gravam a leitura na auditoria;
- as despesas do `ClinicaSeeder` foram refeitas.

Nenhum service de escrita, PIN, estorno, confirmação ou prompt mudou. Em `app/` há dois arquivos novos, os dois de leitura (seção 6).

## 1. Decisões para auditar (da mais arriscada para a menos)

1. **RBAC das telas = tabela da FASE oito** (decisão do Lucas de 15/11):
   - **Veterinário vê `/cobrancas`.** Para isso, `consultar_cobrancas` ganhou o `veterinario`, então ele também consulta cobranças no chat. Receber, cancelar e estornar não mudaram.
   - **Vendedor não abre `/agenda`** (403, sem item no menu, sem agendamentos na ficha).
   - **Atendente só vê a agenda de banho e tosa**, na tela e na ficha, mesmo pedindo a da clínica.
   - **Ficou de fora:** essas duas restrições valem só nas telas. A tool `consultar_agenda` do chat continua aberta à equipe, porque restringi-la por setor seria regra nova no catálogo. Se quiser o chat igual, é uma tarefa à parte.
   - **Não estava na decisão e ficou como está:** a tabela também diz "veterinário — banho e tosa: —", e cobrança por setor (atendente só petshop, recepção só clínica) segue sem filtro.
2. **A ficha do pet e os Resultados gravam auditoria de leitura** (decisão de 15/11): `ver_ficha_pet` e `ver_resultados`, origem "manual", pelo `AuditoriaService::registrar`, como a `ConsultaAuditoria` já faz. As cinco telas têm teto de 60 acessos/min por usuário.
3. **Seed sem prejuízo de demo** (decisão de 15/11): as 13 despesas caem sempre em dias em que o seed recebe mais do que elas. Nenhum dia, semana ou mês fica negativo; há teste.
   - Em 15/11, novembro: faturamento R$ 1.463,00, despesas R$ 1.021,45, lucro R$ 441,55 (30%).
   - Saíram a folha de R$ 6.800 e o aluguel de R$ 4.500, fora da escala da receita simulada.
   - A clínica continua sem tela para lançar despesa; o cartão diz isso.
4. **Definições dos Resultados** (`ResultadosClinica`, só leitura):
   - faturamento = cobranças e vendas pagas no período, pelo total;
   - estornado = estornos gravados no período;
   - lucro = faturamento − estornos − despesas;
   - ticket médio = faturamento ÷ pagamentos;
   - a receber = cobranças fechadas e vendas em aberto hoje (não segue o período);
   - top itens = os 5 de maior total;
   - períodos: dia, semana (segunda a domingo) e mês. O "ano" da página do Notion ficou de fora.
5. **Tamanho do texto guardado no navegador, por usuário** (`localStorage`), sem migration. Não acompanha a pessoa em outro aparelho. O aumento é por `zoom` (1,125 e 1,25).
6. **Tipografia da bancada subiu no PC também:** corpo 16 px, nada abaixo de 15 px, rótulos mono em 12 px. Antes valia só no celular. Muda a cara das telas do Matheus no projetor: veja `depois-pc-agente-dono.png` contra `antes-pc-agente-dono.png`.
7. **Borda de risco de 2 px em volta do card inteiro** (skill §5). Era 1 px neutro com 3 px só à esquerda.
8. **Login:** contorno de campo, placeholder, subtítulo e rodapé em `#5B6570` (5,9:1). Saíram os `<link>` do Google Fonts; as fontes são as locais.
9. **Sugestões da `/agente` na clínica** trocadas (agenda de hoje, mensagens novas, agendar, registrar venda). O efeito dos textos no modelo não foi medido.
10. **Ficha do pet sem nada do prontuário, nem os metadados** que a recepção poderia ver.
11. **Depois de gravar por um card aberto numa tela nova, a lista não recarrega sozinha:** fica o aviso com o botão "Atualizar a tela".
12. **As páginas novas têm teto próprio de 60 acessos/min por usuário** (`throttle:60,1,telas-clinica`, 429 em HTML), separado do `agente-sem-ia`: navegar não gasta o teto do Revisar. Veio da 2ª passada do reviewer, porque cada acesso grava uma linha de auditoria.

## 2. Telas por fase

Capturas em `automacao/capturas-front/`, PC 1366×768 e celular 360×780, Edge headless. Servidor de capturas em SQLite no scratchpad; `.env` e MySQL intocados. Nas 41 capturas finais: 0 erros no console, 0 rolagem horizontal.

### F1 — base
- CSS de `public/css/sentinel-extra.css` e `sentinel-login-extra.css` levado para `resources/css/workspace.css` e `login.css`; `<link>` e arquivos removidos.
- Menu por papel (entrou com as rotas da F2): Agente, Agenda, Cadastros, Cobranças, Vendas, Resultados, Lixeira, Auditoria, Supervisão, cada item pela regra da rota.
- Capturas: `antes-*` (15). O visual da F1 é o mesmo de antes.

### F2 — telas novas (só leitura)

| Tela | Rota | Quem abre | Ações (abrem o card existente) | Capturas |
|---|---|---|---|---|
| Agenda (dia/semana; tudo, clínica, banho e tosa) | `/agenda` | recepção, veterinário, dono; atendente só banho e tosa | Agendar atendimento; Mudar status | `depois-*-agenda-dia`, `-agenda-semana`, `-agenda-card-agendar`, `depois-1511-*-agenda-atendente` |
| Cobranças (total, estornado, líquido) | `/cobrancas` | atendente, recepção, veterinário (só ver), dono | Receber, Cancelar, Estornar | `depois-*-cobrancas`, `-cobrancas-card-receber`, `depois-1511-*-cobrancas-veterinario` |
| Vendas (total, estornado, líquido) | `/vendas` | equipe | Estornar (atendente, recepção, dono) | `depois-*-vendas`, `-vendas-vendedor` |
| Ficha do pet | `/pets/{id}` | equipe | Editar o cadastro | `depois-*-ficha-pet`, `-cadastros-pets` |
| Resultados | `/resultados` | dono | — | `depois-1511-*-resultados-mes`, `-resultados-mes-anterior`, `-resultados-semana` (seed novo) |

- A ficha abre por Cadastros › Pets › "Ver ficha". A agenda não tem link para a ficha: a tool não devolve o id do pet.
- Fora da clínica as telas dão 404; o Admin da clínica vai para `/supervisao`.
- Testes: `tests/Feature/Clinica/TelasDeLeituraDaClinicaTest.php` (40 casos): papel por tela, sem login, outro tenant, prontuário e CPF fora da ficha, números dos Resultados, limites de semana e mês, estorno contado uma vez, leitura auditada, menu.

### F3 — revisão visual
- Card com borda de risco de 2 px; login com contraste AA e fontes locais; tipografia mínima; sugestões da clínica; placeholder "Peça ou pergunte…".
- Conferido sem mudança: `/supervisao` (código uma vez, "Sem PIN"/"Gerado" em texto), caixa de entrada, segurar só nas destrutivas, Cancelar neutro.
- Capturas: `depois-*-login`, `-card-estorno`, `-caixa-de-entrada`, `-supervisao-admin`, `-supervisao-dono`, `-agente-dono`.

### F4 — celular instalado e acessibilidade
- Tamanho do texto em 3 níveis na lateral (A, A+, A++): `depois-*-texto-maior-agente`, `-texto-maior-cobrancas`, `-texto-maior-menu`.
- Tabelas das telas novas viram um bloco por linha no celular; menu em gaveta; a lateral rola por dentro.
- Medido nas capturas finais: nenhum alvo de toque abaixo de 44 px e nenhum texto abaixo de 15 px na bancada (rótulos em caixa alta e mono em 12 px). A exceção é o login no PC (seção 4).
- PWA (`pwa-conferencia.json`): manifest sem erros; service worker ativo; o cache só tinha o shell estático depois de passar por `/agente`, `/agente/consultar` e as telas novas. `manifest.webmanifest` e `sw.js` não mudaram.
- Testes: `tests/Feature/FrontAcessibilidadeTest.php` (5).

## 3. Contraste das combinações usadas (WCAG AA pede 4,5:1 no texto e 3:1 no contorno)

| Texto / contorno | Fundo | Contraste |
|---|---|---|
| tinta `#1D232A` | creme `#FFFCEF` / papel `#FFFEFA` | 15,4 / 15,7 |
| tinta-2 `#5B6570` (texto secundário, contorno de campo) | creme / papel / creme-alt `#F4F0DE` | 5,8 / 5,9 / 5,2 |
| aço `#2E5B85` (links, selo "Confirmado", destaque) | creme / papel / creme-alt | 6,9 / 7,1 / 6,2 |
| papel (texto de botão) | aço / tinta | 7,1 / 15,7 |
| sucesso `#2F6B4F` | sucesso-faixa `#E4EFE6` | 5,3 |
| aviso `#8A5A12` | aviso-faixa `#F6EBD0` / papel | 5,0 / 5,9 |
| erro `#A3412E` | erro-faixa `#F5E0D9` / papel | 4,9 / 6,2 |
| gelo `#A8C8E6` / texto claro `#E6ECF2` / mudo `#8A97A5` (lateral) | noite `#0A0E13` | 11,1 / 16,3 / 6,5 |
| login: secundário `#5B6570` | campo `#FFFEFA` | 5,9 |

Antes, no login: contorno do campo 1,6:1; placeholder 3,2:1; rodapé 3,1:1; subtítulo 4,4:1.

Todo estado nas telas novas tem dois canais: o selo traz o texto ("Agendado", "A receber", "Paga", "Cancelada") além da cor; o prejuízo troca o rótulo ("Prejuízo") e o sinal, além da cor.

## 4. O que ficou para o Matheus

- **Login no PC, painel escuro:** a marca tem 36 px de altura (alvo de toque) e há 5 textos entre 11 e 14 px. Não mexi no painel do farol 3D.
- **Vitrine (`welcome.blade.php`):** ainda busca fontes no Google. Fora da lista da F3.
- **Tabelas do chat e dos Cadastros:** o cabeçalho mostra o nome cru da coluna (`recebida_em`, `forma_pagamento`, `endereco`). As telas novas já usam rótulos em português.
- **Sucesso depois de confirmar:** aparece como mensagem comum mais o popup "✔ Confirmado", sem a faixa de sucesso da skill §7.
- **Card:** o bloco de aviso encosta na lista de dados logo abaixo (falta um respiro).
- **Texto no nível A++ em 360 px:** o placeholder do chat volta a cortar a última letra.
- **Conferir a tipografia maior** nas telas dele (decisão 6).
- **Bancada com gradientes e animação de hover** (skill §8 e §10), anteriores a esta sessão: fundo do `body`, lateral, item ativo do menu, cabeçalho do chat, compositor e o `translateY` das sugestões (`workspace.css`). Achado da 2ª passada do reviewer; não mexi.

## 5. Pendências do Lucas

1. Auditar as decisões da seção 1, principalmente a 1, a 2 e a 3.
2. Conferir no Edge ou Chrome que `http://localhost:8000` oferece "Instalar" (DevTools → Application → Manifest). Em headless o botão não aparece; só conferi o manifest e o service worker.
3. Abrir no celular de verdade: as capturas são de emulação a 360 px.
4. A migration de ontem (`2026_11_14_100000_create_estornos_table`) precisa ter rodado no Laragon: `/cobrancas`, `/vendas`, a ficha e os Resultados leem a tabela `estornos`.
5. As despesas novas do seed só aparecem num banco recriado. O `ClinicaSeeder` não grava nada se o tenant 3 já tem tutores. Recriar (`migrate:fresh` + seeders com a DEMO_SENHA) apaga os dados locais, então não rodei no Laragon: fica com você. As datas do seed são relativas ao dia em que ele roda; para a banca, o melhor é semear perto do dia 25/11.
6. Reviewer: ver a seção 7. As decisões de 15/11 não tiveram passada própria.

## 6. Arquivos e commits sugeridos (um por fase)

**F1** — `refactor(front): CSS estatico de public/css vai para o build do Vite (workspace.css, login.css)`
- `resources/css/workspace.css`, `resources/css/login.css` (só os blocos movidos), `resources/views/layouts/app.blade.php`, `resources/views/login.blade.php`, `public/css/*` (removidos), `tests/Feature/PwaTest.php`, `documentacao/PENDENCIAS_DOCUMENTACAO_1.3.md`

**F2** — `feat(clinica): telas de leitura — agenda, cobrancas, vendas, ficha do pet e Resultados do dono`
- `app/Http/Controllers/ClinicaTelasController.php`, `app/Services/ResultadosClinica.php`, `routes/web.php`
- `resources/views/clinica/*`, `resources/views/layouts/app.blade.php` (menu), `resources/views/partials/bancada.blade.php` e `resources/views/telas/consulta.blade.php` ("Ver ficha"), `resources/css/workspace.css` (bloco "TELAS DA CLÍNICA")
- `tests/Feature/Clinica/TelasDeLeituraDaClinicaTest.php`

**F3** — `fix(front): borda de risco de 2 px no card, login com contraste AA e fontes locais, tipografia minima e sugestoes da clinica`
- `resources/css/workspace.css`, `resources/css/login.css`, `resources/views/login.blade.php`, `resources/views/agente.blade.php`

**F4** — `feat(front): tamanho do texto em 3 niveis (preferencia no navegador por usuario) e lateral que rola por dentro`
- `resources/views/layouts/app.blade.php`, `resources/js/workspace.js`, `resources/css/workspace.css`, `tests/Feature/FrontAcessibilidadeTest.php`

**Decisões de 15/11** — `feat(clinica): telas seguem o RBAC da FASE oito (vet ve cobrancas; agenda sem vendedor, atendente so banho e tosa), ficha e Resultados auditados, seed sem prejuizo`
- `app/Services/Agente/CatalogoDaClinica.php` (só os papéis de `consultar_cobrancas`), `app/Http/Controllers/ClinicaTelasController.php`, `app/Http/Controllers/TelasController.php` (filtro da auditoria), `routes/web.php` (tetos)
- `resources/views/clinica/agenda.blade.php`, `resources/views/layouts/app.blade.php` (menu do vendedor), `resources/css/workspace.css` (`.setor-fixo`)
- `database/seeders/ClinicaSeeder.php` (despesas)
- `tests/Feature/Clinica/TelasDeLeituraDaClinicaTest.php`, `tests/Feature/Clinica/ClinicaSeederTest.php`

**Docs** — `docs: decisoes, relatorio e capturas do front (14 e 15/11)`
- `.claude/decisoes/2026-11-14.md`, `automacao/RETOMADA.md`, `automacao/relatorio-2026-11-14-front.md`, `automacao/capturas-front/`

`workspace.css` e `layouts/app.blade.php` têm mudanças das quatro fases no mesmo arquivo: para separar os commits, use `git add -p`, ou junte F3 e F4 num commit só.

## 7. Revisão (reviewer)

- **1ª passada (F2):** 0 bloqueadores, 3 importantes, 3 cosméticos.
  - Feitos: 4 testes novos (estorno contado uma vez, limites de período, leitura auditada, CPF fora da ficha); bloco do tutor segue `consultar_tutores`; aviso das 100 linhas; `/pets/{id}` com até 18 dígitos.
  - Para você: auditoria da ficha e dos Resultados; despesas só do seed; "Total listado" soma canceladas.
- **2ª passada (F2 corrigida + F3/F4):** 0 bloqueadores, 1 importante, 5 cosméticos. As correções da 1ª passada foram confirmadas. Nenhuma mudança em regra de negócio, RBAC, PIN, estorno, confirmação, auditoria ou prompt (antes das decisões de 15/11).

| # | Achado | Situação |
|---|---|---|
| Imp. 1 | Agenda, cobranças e vendas gravam 1 linha de auditoria por acesso, sem teto | Feito: `throttle:60,1,telas-clinica`, depois estendido à ficha e aos Resultados; teste do teto |
| Cosm. 2 | Agenda corta em 200 horários sem avisar | Feito: aviso na tela; teste |
| Cosm. 3 | Hex soltos no `login.css` (3 fora dos tokens da skill) | Feito: variáveis no `:root` com os valores da skill |
| Cosm. 4 | Selos e notas abaixo de 15 px sem caixa alta | Feito: selos e resumo em caixa alta 12 px; notas em 15 px |
| Cosm. 5 | Popup e card no nível A++ em 360 px | Conferido: botões visíveis (`depois-360-texto-maior-popup.png`) |
| Cosm. 6 | Gradientes e `translateY` antigos na bancada | Para o Matheus (seção 4) |

- **Decisões do Lucas (15/11), aplicadas depois da 2ª passada:** seção 1, itens 1 a 3. Não houve 3ª passada do reviewer sobre elas. Elas mexem em permissão (a tool `consultar_cobrancas`) e em auditoria, e a regra do projeto pede 2 passadas nesse caso. Se quiser, é o próximo passo.
- **Testes das decisões:** `test_atendente_ve_so_a_agenda_de_banho_e_tosa_na_tela_e_na_ficha`, `test_veterinario_ve_as_cobrancas_mas_nao_recebe_cancela_nem_estorna`, `test_ficha_e_resultados_gravam_a_leitura_na_auditoria` (em `TelasDeLeituraDaClinicaTest`) e `test_resultados_do_seed_nao_mostram_prejuizo_em_nenhum_periodo` (em `ClinicaSeederTest`).
