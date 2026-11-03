# Redesign UI — área operacional do SENTINEL

## Base

29/10/2026. Branch malaman. Commit inicial cfcb5cf40860bc318cb76d16b00fcaaa53269994, igual a origin/malaman após fetch; checkout inicialmente limpo. A homologação funcional anterior foi preservada.

## Diagnóstico visual anterior

Conteúdo limitado a 720 px, formulário do agente solto numa página sem estado inicial, tipografia declarada sem webfonts internas, navegação horizontal sem continuidade com o login, tabelas pouco hierarquizadas e CSS inline duplicado em quatro views. Não foram tratados ajustes puramente visuais como bugs funcionais.

## Direção visual

A vitrine impressiona uma vez; a bancada é feita para um dia inteiro de uso. Sidebar noite de 244 px com o componente SVG existente do farol; superfícies creme e papel, azul reservado a foco/ações, estados sóbrios, espaço baseado em 4/8 px, Sora para títulos, IBM Plex Sans para corpo e IBM Plex Mono para metadados. Fontes OFL locais com licenças, sem dependência de Google Fonts no workspace. Cabeçalho contextual, conteúdo até 1456 px com gutters responsivos. Sem dashboard fictício, partículas, WebGL ou dependências novas.

## Arquivos alterados

- resources/views/agente.blade.php
- resources/views/layouts/app.blade.php
- resources/views/partials/bancada.blade.php
- resources/views/telas/auditoria.blade.php
- resources/views/telas/consulta.blade.php
- tests/Feature/CascaTest.php
- tests/Feature/FormularioAgenteTest.php
- tests/Feature/TelasTest.php
- vite.config.js
- resources/css/workspace.css
- resources/fonts/OFL-ibmplexmono.txt
- resources/fonts/OFL-ibmplexsans.txt
- resources/fonts/OFL-sora.txt
- resources/fonts/ibm-plex-mono-400.woff2
- resources/fonts/ibm-plex-mono-500.woff2
- resources/fonts/ibm-plex-sans-400-600.woff2
- resources/fonts/sora-300-600.woff2
- resources/js/workspace.js
- tests/Feature/WorkspaceTest.php
- RELATORIO_REDESIGN_UI_SENTINEL.md (este relatório).

## Componentes criados/alterados

workspace.css é a fonte visual compartilhada: tokens/base, shell, sidebar/topbar, headers, superfícies, botões, campos, tabelas, badges, agente, confirmação, auditoria, erros, responsive e reduced motion. workspace.js contém somente drawer acessível e preenchimento das sugestões; Vite tem entradas separadas, sem Tailwind Preflight ou imports 3D. CSS inline duplicado foi removido. IDs/classes de negócio e endpoints existentes foram mantidos.

## Agente

Painel de conversa com estado inicial, exemplos que apenas preenchem o campo (nunca enviam), composer ancorado, metadados Você/Sentinel, resultados amplos com rolagem interna, mensagens e loading discreto. Propostas têm accent lateral de 3 px e badge de risco. Revisar/Editar/Cancelar e o preenchimento do hold preservam a lógica homologada. Popup de erro mantém foco, fecha com Escape e retorna ao alvo da página; sucesso usa status não modal.

## Cadastros

Cabeçalho contextual, filtros existentes agrupados em toolbar, Novo com prioridade, resultados com contagem, tabela de maior densidade e edição secundária. Formulários de duas colunas no desktop e uma no mobile. Nenhuma consulta, campo ou regra de escrita alterados.

## Lixeira

Mesma linguagem visual com o texto funcional existente, distinção das ações definitivas e cards de restauração/reautenticação. Regras de prazo, papel, vínculos e autorização administrativa preservadas.

## Auditoria

Filtro em grid, ações juntas, tabela com badges textuais, paginação e detalhe em superfície separada. Timestamps não quebram entre linhas; em espaços estreitos, a tabela rola no contêiner. Labels de filtros, máscaras, exportação CSV, parâmetros e consultas permanecem existentes. Nenhuma migração ou serviço alterados.

## Erros

401/403/404/419/429/500/503 usam a identidade compartilhada. Layout conserva obtenção de usuário e CSRF protegida por rescue. Sete views reais renderizadas pelo Laravel foram verificadas visualmente com respostas interceptadas apenas no navegador QA, sem adicionar rotas ao app. Regressão automatizada confirma renderização com obtenção de usuário falhando; testes existentes cobrem status reais e ausência de dados técnicos.

## Responsividade

36 combinações aprovadas: quatro telas em 360×800, 390×844, 430×932, 768×1024, 820×1180, 1024×768, 1366×768, 1440×900 e 1920×1080. Nenhum overflow horizontal global; tabelas têm região de rolagem interna focável. Drawer abaixo de 1024 px com inert no fundo, Escape, foco inicial/previsível e retorno ao acionador. Cards/filtros empilham no mobile. Composer visível e utilizável nos tamanhos testados.

## Acessibilidade

Skip link, foco visível, labels, aria-live, aria-current, aria-expanded/controls, alvos de ações >=44 px, navegação por Tab/Shift+Tab/Enter/Space/Escape e prefers-reduced-motion verificados. Dez pares de contraste conferidos pelo cálculo de luminância WCAG: texto/creme 15.4:1; secundario/creme 5.77:1; gelo/noite 11.12:1; secundario/sidebar 6.5:1; botao azul 7.05:1; sucesso 5.33:1; aviso 4.99:1; erro 4.94:1; borda-campo 5.88:1; foco 6.92:1. Textos de estados passam 4,5:1; bordas/foco passam 3:1. Borda funcional permanece #5B6570. Cores não substituem texto.

## Screenshots

Evidências externas, não versionadas: C:/Temp/SENTINEL_UI_REDESIGN/20261029_210506/before (40 capturas) e C:/Temp/SENTINEL_UI_REDESIGN/20261029_210506/after (57 capturas, incluindo estados, formulários, papéis e erros). Comparação de mesmas telas/viewports realizada. Exemplos: after/agente_1440.png, after/agente_360.png, after/auditoria_1366.png, after/auditoria-detalhe.png, after/menu-mobile.png, after/formulario-novo.png e after/erro-403.png. Nenhuma senha foi capturada. CSVs e logs QA permanecem externos em logs/.

## Testes

462 testes, 4253 assertions, zero failures/errors, 31500 ms. Baseline: 457/4197. Cinco testes novos em WorkspaceTest cobrem matriz de menu por papel (3 casos), sugestões sem envio/contratos do composer e sete erros sem obter usuário. Três arquivos de testes existentes foram adaptados à extração de CSS e ao novo markup da navegação, mantendo verificações funcionais. Seis falhas iniciais eram expectativas literais do HTML/CSS antigo; foram corrigidas sem alterar regras do produto.

Browser real Edge/Playwright, com UI e Azure reais: consulta, cadastro pelo agente, cadastro manual, Revisar, Editar, hold curto de 400 ms sem gravação, hold completo por mouse/teclado, edição, exportação, exclusão lógica/restauração de fixture com reautenticação, expiração sem gravação, filtros/paginação/detalhe/CSV, popup e drawer. Leitura/operador/admin mostraram apenas menus autorizados; backend manteve 403 apropriado; login/logout conferidos. Duas fixtures isoladas foram criadas pela UI: clientes QA IDs 18 e 19, preservados para inspeção. Nenhum dado importante foi alterado. Hashes temporários dos usuários QA de leitura/operador foram restaurados; admin local não foi modificado.

Uma captura inicial do estado vazio ocorreu durante loading por espera insuficiente do coletor (registro externo mantido). O reteste passou ao aguardar a resposta e o indicador oculto; não houve correção de código para esse evento de medição.

## Build

npm run build OK. Bundle workspace: CSS 21,52 kB (gzip 5,09 kB), JS 1,42 kB (gzip 0,61 kB); quatro fontes locais totalizam 108.960 bytes. Network das 36 combinações não baixou Three.js/GLB/landing; as três famílias foram confirmadas carregadas. Vitrine/login/farol e seus JS/CSS permanecem byte a byte iguais aos fontes da base; após build, 3D desktop ativo e layouts públicos desktop/mobile preservados. Mobile do login mantém o comportamento responsivo original.

## Auditorias

composer check-platform-reqs OK; composer audit 0 vulnerabilidades; npm audit 0 vulnerabilidades; 16 migrations aplicadas e 0 pendentes; git diff --check OK. Nenhuma dependência, lock, versão de PHP, migration, Azure ou configuração de segurança alterada.

## Regressões

Homologação funcional preservada: RBAC/tenant, intenção do agente, confirmação humana, revisão/hold/expiração, RF03/lote_id, reautenticação, auditoria, lixeira e modo manual continuam cobertos pela suíte. Não foi encontrada regressão funcional após o redesign. Nenhuma divergência funcional nova precisou ser aberta no Notion.

## Git

Destino exclusivo origin/malaman. Um único commit final da rodada será criado após a inspeção do staging. O hash do commit que versiona este relatório e a confirmação real de envio serão registrados no Notion e no chat após push/fetch; não se inclui hash autorreferente no próprio documento. Segredos, .env, temporários, screenshots e backups ficam fora do staging.

## Notion

Estrutura existente recuperada por busca: raiz SENTINEL e Homologação 574e359. Não houve criação de outra raiz ou banco. Página Redesign UI — Área Operacional recebe diagnóstico, resultados e evidências; Homologação recebe adendo sem apagar histórico. IDs finais e commit/push são registrados no fechamento.

## Conclusão

Redesign visual/UX aprovado no escopo testado: Agente, Cadastros, Lixeira, Auditoria, estados, erros, desktop/tablet/mobile e teclado. Vitrine e login preservados. Área autenticada conecta a identidade existente ao trabalho operacional sem alterar regras homologadas.
