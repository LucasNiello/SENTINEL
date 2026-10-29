# Correções e Reteste — SENTINEL 574e359

## Fechamento final — 29/10/2026 (America/Sao_Paulo)

Este adendo é o estado atual; o restante do documento conserva os resultados e decisões da execução anterior, inclusive sua conclusão histórica.

- T0.4: associação documental corrigida para PHP 8.4.27 TS funcionando em CLI, Composer, Artisan e servidor. Resultados e evidências preservados.
- T6.1: **RESOLVIDO POR ALINHAMENTO DE ESPECIFICAÇÃO**. IMPLEMENTAÇÃO: OK; RBAC: OK; ROTEIRO ANTIGO: DESATUALIZADO. Homologação adota a política formal mais restritiva: leitura e operador não excluem; admin exclui logicamente seguindo revisão/hold. Catálogo em AiAgentService.php reserva excluir_* a admin. PermissaoExclusaoLogicaTest + LixeiraTest: 37 testes/238 assertions passaram. Não foi concedida permissão ao operador. CSV mantém resultado anterior em resultado_antes_fechamento e evidência original em obtido.
- Matriz consolidada: 42 OK, 14 CORRIGIDO, 1 ALINHADO; zero bloqueados da matriz anterior. Novo impedimento funcional da bateria final descrito abaixo.
- **RNF04 ESTADO ATUAL = OK quanto ao tempo**: 13 amostras (10 normais + 3 em novos contextos/sessões); percebido mínimo 2224 ms, máximo 3598 ms, média 2760 ms, mediana 2608 ms, P95 3598 ms (nearest-rank), 0 acima de 10000 ms. HTTP mínimo 2194,445 ms, máximo 3540,448 ms, média 2721,771 ms, mediana 2578,741 ms, P95 3540,448 ms. Arquivo TEMPOS_IA_RNF04_FINAL_574e359.csv. Cold-ish significa primeira chamada de uma nova sessão/contexto do navegador, mantendo caches/OPcache do servidor; não equivale a partida fria completa do PHP/Azure. Dez chamadas normais consecutivas; três novas sessões após expirar a janela normal do limitador. Sem alterações de segurança.
- **houve uma violação histórica isolada antes das otimizações/retestes**: T6.1-repro, HTTP 9066,358 ms, percebido 10922 ms. CSV histórico de tempos não alterado.
- **Critério funcional final NÃO passou integralmente**: 12 respostas 403 e 1 resposta 200, na terceira sessão cold-ish (17:21:11.933Z), percebido 2418 ms. A auditoria em 14:21:14 local mostra consultar_clientes/sucesso, em vez de excluir_cliente/negado. A sessão guarda marcador de resultado de leitura. Diagnóstico: seleção não determinística de ferramenta pela IA; RBAC continua negando a ferramenta de exclusão. O corpo HTTP 200 não foi capturado no coletor; a ferramenta foi identificada por auditoria e sessão, sem expor seus identificadores. Nenhuma exclusão executada; cliente QA id 6 segue ativo, deleted_at e arquivado_em nulos.
- Tentativa intermediária 429 (121 ms) por limite de requisições e diagnóstico posterior 403 (2301 ms) preservados separadamente em RNF04_TENTATIVAS_COMPLEMENTARES_574e359.csv; nenhum substitui o HTTP 200. Não houve descarte de outlier.
- Regressão final: 429 testes, 4056 assertions, zero falhas/erros. PHP 8.4.27; check-platform-reqs OK; Composer audit e npm audit com zero vulnerabilidades; npm run build OK; 16 migrations aplicadas, zero pendentes; git diff --check OK. Logs externos em C:/Temp/SENTINEL_FINAL_20261029. Senha QA temporária usada apenas em memória e hash original restaurado após cada execução.
- **SISTEMA APTO PARA HOMOLOGAÇÃO: NÃO**. Impedimento: esperado funcional 403 não atendido em todas as 13 amostras. Commit e push NÃO EXECUTADOS; nenhum staging feito. É necessário resolver a seleção de consulta no pedido de exclusão e repetir a validação funcional, dentro de nova correção específica. Sem novas alterações de código nesta rodada.

---


## Identificação

Data: 28/10/2026, America/Sao_Paulo. Branch: malaman. Commit inicial e final: de8299c31d90a26c3db1f449011ada85c59c507a. A base histórica 574e3597c13f840fc49517c0e83c065c7cc098fd identifica os quatro relatórios anteriores; o checkout já havia avançado antes desta execução. Working tree inicial: apenas os quatro relatórios baseline não rastreados. Nenhum commit, push, merge ou rebase realizado. Os quatro arquivos permanecem byte a byte iguais (SHA256 em baseline_preservation_final.json).

Pasta externa da execução: C:/Temp/SENTINEL_FIX_574e359/20261028_165633. Backups com ACL restrita ao usuário atual e SYSTEM. Não versionados. Evidências e logs referenciados neste documento permanecem nessa pasta.

## Bloqueios originais

Baseline: 1 OK, 6 falhas efetivamente observadas e 50 bloqueados. Não foram importados 50 bugs. PHP 8.3.33 impedia bootstrap; três migrations pendentes; debug ativo e sessão sem criptografia; quatro pacotes npm vulneráveis; destino Notion ainda inexistente. Todos esses bloqueios foram resolvidos. A inexistência de fixtures de setembro foi preparação, não defeito funcional.

## Correções de ambiente

PHP antigo 8.3.33 preservado. Novo PHP oficial 8.4.27-1 TS x64, instalado em C:/laragon/bin/php/php-8.4.27-1-Win32-vs17-x64. Build Thread Safe escolhido porque Apache usa mod_php. CLI, Composer e Artisan executados explicitamente com esse binário. PATH persistente do usuário atualizado; processos anteriores do Windows/Codex ainda podem herdar PATH antigo e precisam reiniciar para absorvê-lo.

SENTINEL validado em http://127.0.0.1:8000 via artisan serve com o mesmo PHP 8.4.27, binário do processo filho conferido. Apache mod_php também atualizado e header confirmou PHP/8.4.27. Servidor principal continua disponível; servidores temporários offline/perfil foram encerrados. Tentativa posterior de reinício de Apache foi rejeitada pela revisão automática; a execução funcional prosseguiu pelo artisan serve já autorizado, sem contornar essa rejeição.

php.ini novo copiou configurações anteriores, ajustou extension_dir e removeu opções obsoletas de session.sid. Extensões curl, fileinfo, mbstring, openssl, pdo_mysql, zip, intl e extensões nativas exigidas conferidas. check-platform-reqs passou. CA bundle oficial curl/Mozilla configurado em curl.cainfo e openssl.cafile; verificação TLS mantida. Nenhum verify=false. Origem/hash do PHP e do CA registrados externamente. PHP oficial: https://downloads.php.net/~windows/releases/php-8.4.27-1-Win32-vs17-x64.zip ; SHA256 53db2047139bdfb8e6c7c7d5860a0a6a2a4f1dcac49fcf18ae610a14d8ccdf5b.

OPcache nativo habilitado posteriormente no mesmo php.ini, inclusive CLI, validate_timestamps=1 e revalidate_freq=0. Medida de bootstrap: 460,7851 → 56,2584 ms. Não introduz cache externo nem muda arquitetura. Backup antes desse ajuste: config/php8.4.before_opcache.ini. Evidências: php_version_final.txt, php_ini_final.txt, php_modules_final.txt, http_php_binary.json, profile.jsonl e where_php_final.txt.

## Banco

Backup integral anterior às mudanças: C:/Temp/SENTINEL_FIX_574e359/20261028_165633/db/sentinel_before.sql, 30.731 bytes, SHA256 0446E053BF69DB0DB4BDAAA2DADCE42DBA82B8089C3EAECC5BC29497744299D5. MySQL local 127.0.0.1:3306/sentinel; senha não impressa. Três migrations revisadas, somente aditivas: 2026_10_19_120000, 2026_10_22_120000 e 2026_10_28_120000. Antes 13 aplicadas; depois 16 aplicadas e zero pendentes. Campos autorizado_por, origem, parametros_propostos, tempo_ia_ms, valores_anteriores e lote_id fisicamente conferidos.

**Incidente do primeiro teste automatizado:** a configuração local em cache e variáveis herdadas prevaleceram sobre phpunit.xml. RefreshDatabase alcançou as tabelas locais. Interrompido o fluxo, o backup integral foi restaurado com sucesso, os perfis e contagens originais conferidos e as três migrations reaplicadas. Não se afirma que o banco nunca foi afetado. Correção: bootstrap de teste força variáveis nos três mecanismos do PHP, isola caminhos de cache por processo, e TestCase recusa qualquer ambiente diferente de testing com SQLite :memory: antes de RefreshDatabase. Regressão específica cobre config local em cache e APP_ENV/DB herdados. CHECKSUM dos sete conjuntos de dados de domínio/usuários antes e depois da última suíte foi idêntico: isolation_checksum_proof.json, same=true. Backups originais preservados.

## Configuração

APP_DEBUG=false e SESSION_ENCRYPT=true efetivamente carregados após optimize. Comparação com .env.before confirma que somente essas duas chaves mudaram; APP_KEY, Azure key, DB_PASSWORD, endpoint e deployment preservados. env_comparison.json guarda apenas nomes e booleanos. Transporte Azure HTTP 200 em 927,3299 ms; chamada mínima sem tools e sem dados pessoais, “Responda apenas OK.”, HTTP 200 em 1651,3408 ms. Sem alteração de credenciais ou desativação TLS.

## Dependências

composer install sincronizou vendor com lock existente. composer audit identificou symfony/mime CVE-2026-45070; atualização mínima compatível para 8.1.10 resolveu. composer.json inalterado. Auditoria final: zero vulnerabilidades.

| Pacote Composer | Antes | Depois | Classe |
|---|---|---|---|
| symfony/mime | v8.1.6 | v8.1.10 | runtime/transitiva |

npm audit anterior: quatro pacotes afetados, dois high e dois critical. Patches/minors compatíveis, sem --force e sem upgrade major; package.json inalterado. Nove resoluções do lock alteradas. npm ci, audit e build finais passaram, zero vulnerabilidades. Principais versões: concurrently 10.0.6, shell-quote 1.12.0, source-map-js 1.2.2, vite 8.3.5.

| Pacote npm/resolução | Antes | Depois | Classe | Relação |
|---|---|---|---|---|
| node_modules/@oxc-project/types | 0.149.0 | 0.155.0 | dev | transitiva |
| node_modules/@rolldown/binding-android-arm-eabi | 1.2.8 | 1.2.15 | dev | transitiva |
| node_modules/@rolldown/binding-android-arm64 | 1.2.8 | 1.2.15 | dev | transitiva |
| node_modules/@rolldown/binding-darwin-arm64 | 1.2.8 | 1.2.15 | dev | transitiva |
| node_modules/@rolldown/binding-darwin-x64 | 1.2.8 | 1.2.15 | dev | transitiva |
| node_modules/@rolldown/binding-freebsd-x64 | 1.2.8 | 1.2.15 | dev | transitiva |
| node_modules/@rolldown/binding-linux-arm-gnueabihf | 1.2.8 | 1.2.15 | dev | transitiva |
| node_modules/@rolldown/binding-linux-arm64-gnu | 1.2.8 | 1.2.15 | dev | transitiva |
| node_modules/@rolldown/binding-linux-arm64-musl | 1.2.8 | 1.2.15 | dev | transitiva |
| node_modules/@rolldown/binding-linux-ppc64-gnu | 1.2.8 | 1.2.15 | dev | transitiva |
| node_modules/@rolldown/binding-linux-s390x-gnu | 1.2.8 | 1.2.15 | dev | transitiva |
| node_modules/@rolldown/binding-linux-x64-gnu | 1.2.8 | 1.2.15 | dev | transitiva |
| node_modules/@rolldown/binding-linux-x64-musl | 1.2.8 | 1.2.15 | dev | transitiva |
| node_modules/@rolldown/binding-openharmony-arm64 | 1.2.8 | 1.2.15 | dev | transitiva |
| node_modules/@rolldown/binding-win32-arm64-msvc | 1.2.8 | 1.2.15 | dev | transitiva |
| node_modules/@rolldown/binding-win32-x64-msvc | 1.2.8 | 1.2.15 | dev | transitiva |
| node_modules/concurrently | 10.0.5 | 10.0.6 | dev | direta |
| node_modules/nanoid | 3.3.18 | 3.3.20 | dev | transitiva |
| node_modules/postcss | 8.5.28 | 8.5.32 | dev | transitiva |
| node_modules/rolldown | 1.2.8 | 1.2.15 | dev | transitiva |
| node_modules/shell-quote | 1.9.0 | 1.12.0 | dev | transitiva |
| node_modules/source-map-js | 1.2.1 | 1.2.2 | dev | transitiva |
| node_modules/vite | 8.2.2 | 8.3.5 | dev | direta |

## Testes automatizados

Primeira execução com ambiente incorreto: 421 testes, 106 passaram, 312 falharam e 3 erros, 1.045 assertions, 80.197 ms. Esses resultados não representam 315 defeitos do produto; houve falhas de ambiente/419 e o incidente de banco descrito acima. Após isolamento: 421/4.024 passaram; com regressão de isolamento 422/4.028; final **429 testes, 4.056 assertions, zero failures, zero errors, zero skipped**, 33.677 ms. Evidência tests_final.txt. composer check-platform-reqs, composer audit, npm audit, npm run build, migrate e optimize finais com exit 0 (final_checks.json).

## Matriz manual

Execução interativa através de navegador real Microsoft Edge/Playwright, com cliques, digitação, revisão, hold, múltiplas sessões e Azure real. T0 reúne verificações de preparação; demais fluxos foram exercitados na UI com comparação SQL, sem substituir por testes unitários. A suíte automatizada é evidência adicional. CSVs entregues em UTF-8 BOM, separador ponto e vírgula. Resultado: **42 OK, 14 CORRIGIDO, 1 FALHOU**, zero bloqueados. O FALHOU é a divergência literal T6.1, detalhada abaixo. “CORRIGIDO” em T0.6 indica preparação QA.

| ID | Anterior | Atual | Correção necessária | Evidência | Notion |
|---|---|---|---|---|---|
| T0.1 | FALHOU | CORRIGIDO | PHP 8.4.27; Composer sincronizado e patches compatíveis das dependências. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/logs/manual_results.json) | [registro](https://app.notion.com/p/407877fb30708107a36dea2e2a140be3) |
| T0.2 | FALHOU | CORRIGIDO | Três migrations aditivas aplicadas após backup. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/logs/manual_results.json) | [registro](https://app.notion.com/p/407877fb307081f09d78e1310983ce33) |
| T0.3 | FALHOU | CORRIGIDO | APP_DEBUG=false; SESSION_ENCRYPT=true; demais valores preservados. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/logs/manual_results.json) | [registro](https://app.notion.com/p/407877fb3070814bab03da27e3095c4c) |
| T0.4 | FALHOU | CORRIGIDO | PHP 8.4.27 TS funcionando em CLI, Composer, Artisan e servidor. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/logs/manual_results.json) | [registro](https://app.notion.com/p/407877fb307081688231ea588b271b5d) |
| T0.5 | OK | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/logs/manual_results.json) | — |
| T0.6 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | CORRIGIDO | Preparação de fixtures QA; não classificada como bug do produto. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/logs/manual_results.json) | — |
| T1.1 | FALHOU | CORRIGIDO | Desbloqueio do ambiente; redirecionamento existente validado. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T1_1_1793217997750.png) | [registro](https://app.notion.com/p/407877fb307081408fa2ede41239f7b0) |
| T1.2 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T1_2_1793218194007.png) | — |
| T1.3 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T1_3_1793218197480.png) | — |
| T1.4 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | CORRIGIDO | Foco no primeiro campo após inserir cartão no DOM; chat mantém foco do cartão. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T1_4_1793220881742.png) | [registro](https://app.notion.com/p/407877fb307081b48141d58256eaecd4) |
| T1.5 | FALHOU | CORRIGIDO | Desbloqueio do ambiente; página própria 404 existente validada. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T1_5_1793218004636.png) | [registro](https://app.notion.com/p/407877fb3070819a9660dc673d2b873d) |
| T1.6 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | CORRIGIDO | Formulário HTML não seguro de sessão expirada responde 419; JSON mantém 401. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T1_6-REPRO_1793219023799.png) | [registro](https://app.notion.com/p/407877fb307081c38803e598a675c098) |
| T2.1 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T2_1_1793218170829.png) | — |
| T2.2 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T2_2_1793218091867.png) | — |
| T2.3 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T2_3_1793218099986.png) | — |
| T2.4 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T2_4_1793218105917.png) | — |
| T2.5 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | CORRIGIDO | Prompt recusa perguntas gerais sem fornecer a resposta; retestes admin, operador e leitura. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T2_5_1793221042265.png) | [registro](https://app.notion.com/p/407877fb30708165b676f0029eae1ba3) |
| T3.1 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T3_1_1793218213286.png) | — |
| T3.2 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T3_2_1793218248334.png) | — |
| T3.3 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T3_3_1793218252096.png) | — |
| T3.4 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T3_4_1793218256265.png) | — |
| T3.5 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | CORRIGIDO | Histórico enviado à IA exclui marcadores internos; contexto atual exige nova tool. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T3_5_1793218389819.png) | [registro](https://app.notion.com/p/407877fb307081f18536ff6e8965ac25) |
| T3.6 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T3_6_1793218415867.png) | — |
| T3.7 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T3_7_1793218866369.png) | — |
| T3.8 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T3_8_1793218427278.png) | — |
| T4.1 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T4_1_1793218470084.png) | — |
| T4.2 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T4_2_1793218476729.png) | — |
| T4.3 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T4_3_1793218490182.png) | — |
| T4.4 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T4_4_1793221286476.png) | — |
| T5.1 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T5_1_1793218568006.png) | — |
| T5.2 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T5_2_1793218572975.png) | — |
| T5.3 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T5_3_1793218576778.png) | — |
| T5.4 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | CORRIGIDO | Prompt distingue nova conciliação da consulta e do resultado histórico. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T5_4_1793218831136.png) | [registro](https://app.notion.com/p/407877fb307081beb85ed9cdc58be8aa) |
| T5.5 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T5_5_1793219065020.png) | — |
| T5.6 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T5_6_1793219087395.png) | — |
| T5.7 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T5_7_1793218582800.png) | — |
| T6.1 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | FALHOU | Roteiro exige operador; arquitetura reserva exclusão lógica a admin. Regra mantida após análise solicitada pelo usuário. Esperado original não alterado; divergência permanece. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T6_1_1793219258672.png) | [registro](https://app.notion.com/p/407877fb307081889c3ccc0d66edcb77) |
| T6.2 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T6_2_1793219286874.png) | — |
| T6.3 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T6_3_1793219766403.png) | — |
| T6.4 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | CORRIGIDO | Credenciais aparecem somente após Revisar, no estágio final; backend preservado. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T6_4_1793219523172.png) | [registro](https://app.notion.com/p/407877fb3070812f804dc4af03e7fb7d) |
| T6.5 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T6_5_1793219533223.png) | — |
| T6.6 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T6_6_1793219749248.png) | — |
| T6.7 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T6_7_1793220611335.png) | — |
| T7.1 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T7_1_1793219915208.png) | — |
| T7.2 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T7_2_1793219923300.png) | — |
| T7.3 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | CORRIGIDO | CSV de auditoria com filtros, todas as páginas, allowlist e autorização admin. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T7_3_1793219925444.png) | [registro](https://app.notion.com/p/407877fb307081a58060e2c075fbe057) |
| T7.4 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T7_4_1793218199600.png) | — |
| T8.1 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T8_1_1793220139298.png) | — |
| T8.2 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T8_2_1793220604787.png) | — |
| T9.1 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T9_1_1793220506382.png) | — |
| T9.2 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T9_2_1793220512990.png) | — |
| T9.3 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T9_3_1793220522928.png) | — |
| T9.4 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T9_4_1793220538604.png) | — |
| T9.5 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T9_5_1793220731993.png) | — |
| T9.6 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T9_6_1793220955133.png) | — |
| T10.1 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T10_1_1793221131366.png) | — |
| T10.2 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | OK | Não necessária. | [evidência](C:/Temp/SENTINEL_FIX_574e359/20261028_165633/evidencias/T10_2_1793221182094.png) | — |

Os esperados originais, papéis do roteiro e resultados completos estão no RESULTADOS_RETESTE_SENTINEL_574e359.csv. Detalhes efetivamente observados, incluindo papéis usados, aparecem no apêndice abaixo e em manual_results.json. Nenhum esperado foi reescrito para declarar sucesso.

## Falhas funcionais descobertas após desbloqueio

| ID | Sintoma e causa | Arquivos/correção | Regressão e reteste |
|---|---|---|---|
| T1.4 | Novo cartão deixava foco no BODY; chat roubava foco | bancada.blade.php e agente.blade.php: foco após append | UI com Tab/ShiftTab e campo nome nos dois caminhos |
| T1.6 | Formulário com sessão expirada redirecionava ao login | bootstrap/app.php: resposta HTML 419 nos verbos não seguros | SessaoExpiradaFormularioTest e repetição real do logout expirado |
| T2.5 | Modelo respondia conhecimento geral antes de redirecionar | AiAgentService: recusa explícita, inclusive após histórico | Três papéis, reteste final leitura sem responder Paris |
| T3.5 | Após confirmar cadastro, novo pedido ecoava proposta pendente sem cartão | ConversaAgente e AiAgentService: não enviar resumos internos, preservar contexto usuário e resultado, exigir tool atual | ConversaMarcadorTest; novo cartão em Azure real e smoke final cancelado sem insert |
| T5.4 | Novo período herdava “sem pendentes” do histórico | AiAgentService: lembrete de banco atual e tool de lote | Histórico coberto no teste; 26 pendentes agora exibem limite 25 |
| T6.4 | Senha exibida antes de Revisar | bancada.blade.php: fieldset oculto e limpo antes do estágio final | Duas reproduções anteriores; exclusão física QA após revisar e hold com senha |
| T7.3 | Exportação CSV de auditoria inexistente | AuditoriaController, ConsultaAuditoria, routes/web.php e tela: filtros compartilhados, admin, metadados permitidos | ExportarAuditoriaTest: 27 linhas/todas páginas/tenant/sensíveis/validação; download filtrado e completo real |

Expectativa do teste AgenteCorrecoesTest ajustada para conferir exatamente a última mensagem user, pois foi inserido lembrete system confiável antes dela. Nenhuma autorização foi afrouxada. Incidente AUTO-01 e regressão do isolamento detalhados em Banco.

**Decisão T6.1 solicitada pelo usuário:** o roteiro pede exclusão lógica por operador. A documentação formal 4.2/6.1 de Documentacao-Sentinel1.1.docx, o catálogo excluir_* e os serviços reservam todas as exclusões lógicas a admin. Restaurar permite operador com autorização administrativa, com exceções para funcionários; excluir fisicamente exige admin e senha sobre item arquivado. Mesmo reversível, remover um registro altera a visibilidade operacional e contábil. Manter essa separação é coerente com menor privilégio e com o pedido de não enfraquecer RBAC. Operador recebeu 403; admin excluiu logicamente e a restauração foi validada. PermissaoExclusaoLogicaTest fixa os três papéis. O esperado original continua na matriz e a discrepância documental permanece FALHOU; é necessário alinhar formalmente o roteiro à regra analisada antes de aprovação integral.

## RNF04

Todas as 77 amostras estão em TEMPOS_IA_RNF04_RETESTE_574e359.csv, inclusive smoke direto, recusas, limite local 429 e indisponibilidade simulada 503. Nem toda requisição chegou ao Azure. O smoke tem tempo percebido vazio, não zero. timestamp em UTC; papéis e pedidos preservados. HTTP foi medido pelo navegador; percebido desde o início do envio automatizado até o estado utilizável da interface. Essa medida conservadora inclui agendamento da automação; nas repetições de diagnóstico foram instrumentados submit e retorno ao estado utilizável.

Estatísticas em ms; P95 pelo posto ceil(0,95*N), mediana usual:

```json
{
  "amostras": 77,
  "http": {
    "n": 77,
    "min_ms": 516.088,
    "max_ms": 9066.358,
    "media_ms": 2995.173400000387,
    "mediana_ms": 2703.477,
    "p95_ms": 5527.326
  },
  "percebido": {
    "n": 76,
    "min_ms": 900,
    "max_ms": 10922,
    "media_ms": 3761.7236842105262,
    "mediana_ms": 3556,
    "p95_ms": 6174
  },
  "violacoes": [
    {
      "timestamp": "2026-10-28T20:27:27.729Z",
      "teste": "T6.1-repro",
      "papel": "operador",
      "pedido": "exclua o cliente QA574e359 Descartavel",
      "tempo_http_ms": 9066.358,
      "tempo_percebido_ms": 10922,
      "limite_ms": 10000,
      "resultado": "FALHOU RNF04",
      "status": 403
    }
  ],
  "por_status": {
    "200": 61,
    "403": 14,
    "429": 1,
    "503": 1
  }
}
```

**Uma violação histórica mantida:** T6.1-repro, operador, 20:27:27.729Z (17:27 local): HTTP 9.066,358 ms, percebido **10.922 ms**, limite 10.000, resposta 403 correta. Auditoria 38 registrou Azure 2.673 ms; não há decomposição suficiente para atribuir com certeza todo o atraso original. Repetições anteriores ao ajuste: percebido 3309, 2534, 2492 ms. Perfil isolado: bootstrap 460,7851 ms, total 2927,7367 ms; com OPcache bootstrap 56,2584 ms, total 2591,2204 ms. Após ajuste no servidor principal: **2241, 3204 e 2010 ms**. Redução de bootstrap demonstrada; não prova eliminação de toda variação. Nenhuma chamada descartada nem timeout reduzido para esconder resultado. RNF04 permanece FALHOU na série completa e ABERTO/P3 no Notion para nova observação, embora o reteste atual passe. Não classificado como bloqueio externo.

## Segurança

Tenant A comparado com SQL e Tenant B consultado em sessão própria; registros B ausentes em A, salários omitidos para operador. RBAC negativo validado por UI e endpoint, incluindo leitura sem escrita e operador sem exclusão. Senha administrativa continua validada no servidor; cinco erros permitidos e sexto 429 na janela de dez minutos. CSRF e sessão expirada preservados com respostas próprias.

Proposta adulterada após revisão gravou apenas o valor assinado original. Alteração de período em DOM/payload não mudou o lote congelado: novembro conciliado, outubro com 26 pendentes. XSS QA permaneceu texto literal em chat/lista/edição/auditoria; nenhum alerta. Injeção não executou exclusão; contagens permaneceram. Agente: dez requests 200 e décima primeira 429; login cinco tentativas e sexta 429. Sem rotação de segredos sem evidência.

Logs examinados a partir do início da execução; laravel.log a partir do offset 18290. Console contém mensagens esperadas de recursos 403/404/419/422/429/503 durante cenários negativos; não confundir com erro de bootstrap. browser_events.json preserva eventos. O primeiro helper QA, ainda sem CPF obrigatório, causou exceção SQL com um salário fictício no log; corrigido helper para registrar só classe/código, preservada cópia protegida e redigido somente esse valor fictício no log corrente (qa_log_redaction.json). Não é tratado como exposição de credencial real. Resultado da varredura final de valores reais de segredo e senha QA em security_scan.json; backups privados intencionalmente excluídos da varredura de artefatos públicos.

## RF03

Setembro: três lançamentos, total 306,00, mesmo lote_id UUID em três audit logs; estados conciliados após confirmação. Limite 25 validado com 26 pendentes em outubro. Zero pendentes não cria proposta. Mudança antes de Revisar destacou item 37 e impediu seguir; mudança depois de Revisar recusou lote dezembro inteiro, sem gravar 41/42 e com auditoria de recusa. O lote foi proposto por operador; a alteração concorrente do item ocorreu em sessão administrativa separada. Essa diferença do papel da segunda sessão é registrada. Concorrência de edição individual foi exercitada com duas abas do mesmo operador e rejeitou dado obsoleto sem sobrescrever.

## Notion

Integração Notion e skill notion-database-designer usadas. Busca anterior não encontrou estrutura, então criadas raiz SENTINEL, filha Homologação 574e359 e database 🔍 Divergências e Ajustes, com propriedades nativas de Status/Prioridade e demais campos pedidos. Seis falhas reais antigas importadas; cinquenta bloqueados não importados. Dez registros adicionais refletem defeitos, incidente de isolamento, divergência de regra e amostra RNF04. Total 16; quinze retestados e RNF04 aberto para observação. Deduplicação por ID/base/problema. Status atualizado na mesma entrada; detalhes e evidências sem segredos.

Homologação: https://app.notion.com/p/407877fb307081b1841dfc2f59d6b4d8?pvs=204

Database: https://app.notion.com/p/651858e052f04fa794338628023ad05d

- [T0.1](https://app.notion.com/p/407877fb30708107a36dea2e2a140be3)
- [T0.2](https://app.notion.com/p/407877fb307081f09d78e1310983ce33)
- [T0.3](https://app.notion.com/p/407877fb3070814bab03da27e3095c4c)
- [T0.4](https://app.notion.com/p/407877fb307081688231ea588b271b5d)
- [T1.1](https://app.notion.com/p/407877fb307081408fa2ede41239f7b0)
- [T1.5](https://app.notion.com/p/407877fb3070819a9660dc673d2b873d)
- [AUTO-01](https://app.notion.com/p/407877fb307081ebb679e560dd112eb7)
- [T3.5](https://app.notion.com/p/407877fb307081f18536ff6e8965ac25)
- [T1.6](https://app.notion.com/p/407877fb307081c38803e598a675c098)
- [T5.4](https://app.notion.com/p/407877fb307081beb85ed9cdc58be8aa)
- [T6.4](https://app.notion.com/p/407877fb3070812f804dc4af03e7fb7d)
- [T7.3](https://app.notion.com/p/407877fb307081a58060e2c075fbe057)
- [T6.1](https://app.notion.com/p/407877fb307081889c3ccc0d66edcb77)
- [T1.4](https://app.notion.com/p/407877fb307081b48141d58256eaecd4)
- [T2.5](https://app.notion.com/p/407877fb30708165b676f0029eae1ba3)
- [RNF04-01](https://app.notion.com/p/407877fb307081ef8779e8b6863d6861)

## Alterações realizadas

Código e testes: arquivos listados em Git final abaixo; mantém Laravel, Blade, JavaScript e REST Azure existentes. Config local: somente dois valores .env; php.ini, CA, Apache/PATH com backups. Banco: migrations aditivas existentes, restauração do incidente e fixtures QA. Ambiente: PHP 8.4.27 + OPcache, Composer/vendor e npm. Dependências: locks ajustados com patches compatíveis. Sem nova dependência arquitetural.

QA preservado para inspeção: usuários 5–8 (admin, operador, leitura Tenant1; admin Tenant2); clientes 5–9 e 11–17; cliente 10 removido fisicamente em cenário autorizado e auditoria preservada. Cliente 7 na lixeira; 11 arquivado; 6 restaurado. Funcionário 3; lançamentos 8–42 (35); nota fiscal 5 apagada logicamente ligada ao pai 7. Lista nominal/estados no final_fixtures.json, sem senhas/salários. Prefixo QA574e359 em todos os dados criados. Clientes manual online 12, primeira tentativa offline 13 e offline efetivo 14 preservados. Nenhuma alteração intencional em registros originais de domínio após restauração.

Modo offline foi simulado por proxy de conexão recusada apenas no servidor temporário isolado, sem mudar .env/TLS do principal. IA retornou 503 e CRUD manual concluiu. A primeira tentativa de simulação por variável não havia interrompido o transporte; não foi usada como prova de offline. Um falso negativo do helper por leitura antes do fim da confirmação foi corrigido aguardando response; SQL e lista confirmaram gravação. Touch real emulado via CDP emitiu eventos confiáveis: 500 ms não inseriu; 2250 ms inseriu uma vez. Mobile 360px sem overflow global; tabelas têm rolagem interna. CSV baixado foi aberto em visualizador local no navegador, com UTF-8/BOM e separador conferidos; não foi aberto no Excel. Cinco sessões QA encerradas/logout verificado e navegador QA fechado. Senhas temporárias não entregues nem persistidas em relatórios.

## Git final

Branch e HEAD preservados. git diff --check: exit 0. Sem staging, commit ou push. Os três novos relatórios também integram a entrega, além da listagem capturada antes de sua criação.

```text
M README.md
 M app/Http/Controllers/AuditoriaController.php
 M app/Services/AiAgentService.php
 M app/Services/ConsultaAuditoria.php
 M app/Services/ConversaAgente.php
 M bootstrap/app.php
 M composer.lock
 M package-lock.json
 M phpunit.xml
 M resources/views/agente.blade.php
 M resources/views/partials/bancada.blade.php
 M resources/views/telas/auditoria.blade.php
 M routes/web.php
 M tests/Feature/AgenteCorrecoesTest.php
 M tests/TestCase.php
?? NOTION_DIVERGENCIAS_574e359.md
?? RELATORIO_CORRECOES_E_RETESTE_SENTINEL_574e359.md
?? RELATORIO_TESTE_MANUAL_SENTINEL_574e359.md
?? RESULTADOS_RETESTE_SENTINEL_574e359.csv
?? RESULTADOS_TESTES_574e359.csv
?? TEMPOS_IA_RNF04_574e359.csv
?? TEMPOS_IA_RNF04_RETESTE_574e359.csv
?? tests/Feature/ConversaMarcadorTest.php
?? tests/Feature/ExportarAuditoriaTest.php
?? tests/Feature/IsolamentoDoAmbienteTest.php
?? tests/Feature/PermissaoExclusaoLogicaTest.php
?? tests/Feature/SessaoExpiradaFormularioTest.php
?? tests/bootstrap.php

README.md                                    |  12 ++
 app/Http/Controllers/AuditoriaController.php |  26 +++-
 app/Services/AiAgentService.php              |   8 +-
 app/Services/ConsultaAuditoria.php           |  25 ++++
 app/Services/ConversaAgente.php              |  27 +++-
 bootstrap/app.php                            |   7 +
 composer.lock                                |  12 +-
 package-lock.json                            | 202 +++++++++++++++------------
 phpunit.xml                                  |  30 ++--
 resources/views/agente.blade.php             |   2 +-
 resources/views/partials/bancada.blade.php   |  50 ++++---
 resources/views/telas/auditoria.blade.php    |   7 +
 routes/web.php                               |   1 +
 tests/Feature/AgenteCorrecoesTest.php        |   2 +-
 tests/TestCase.php                           |  15 ++
 15 files changed, 281 insertions(+), 145 deletions(-)
```

## Pendências reais

1. Alinhar formalmente T6.1 do roteiro ao RBAC admin, mantendo o resultado literal visível até essa revisão.
2. RNF04: uma amostra histórica excedeu 10 s. Melhoria local comprovada e três repetições aprovadas, mas encerramento integral requer observação adicional. Registro aberto com dados, sem alegação de causa definitiva.

## Conclusão

| Critério | Resultado |
|---|---|
| PHP COMPATÍVEL | SIM |
| BOOTSTRAP LARAVEL | OK |
| MIGRATIONS | OK |
| APP_DEBUG | OK |
| SESSION_ENCRYPT | OK |
| COMPOSER AUDIT | OK |
| NPM AUDIT | OK |
| TESTES AUTOMATIZADOS | OK |
| LOGIN | OK |
| TENANT ISOLATION | OK |
| RBAC | OK |
| AGENTE | OK |
| CADASTRO | OK |
| EDIÇÃO | OK |
| RF03 | OK |
| LIXEIRA | OK segundo política formal; T6.1 divergente |
| AUDITORIA | OK |
| MODO MANUAL | OK |
| SEGURANÇA | OK nos cenários executados |
| RESPONSIVO | OK |
| RNF04 | FALHOU na série histórica; reteste após ajuste OK |
| NOTION | OK |
| SISTEMA APTO PARA HOMOLOGAÇÃO | NÃO para aprovação integral do roteiro enquanto as duas pendências acima permanecerem |

Alterações prontas para revisão. Mensagem única sugerida: fix: corrigir governança, auditoria e isolamento dos testes do Sentinel

## Apêndice — resultados efetivamente observados

Os detalhes abaixo são as capturas finais de cada ID; valores de propostas e tokens já foram redigidos no coletor. As tentativas e retestes extras permanecem em manual_results.json e todas as medições em ai_times.json.

### T0.1 — CORRIGIDO

Esperado original: Dependências instaladas e build concluído sem perda de alterações.

```json
"Composer install e build passaram; auditorias corrigidas sem downgrade."
```

### T0.2 — CORRIGIDO

Esperado original: Schema com migration 16 e audit_logs.lote_id; migrate:status executável.

```json
"16 migrations aplicadas e colunas confirmadas fisicamente."
```

### T0.3 — CORRIGIDO

Esperado original: Azure configurado; APP_DEBUG=false; SESSION_ENCRYPT=true; valores efetivos verificados.

```json
"Valores efetivos debug=false, encrypt=true; endpoint/key/deployment presentes; Azure 200."
```

### T0.4 — CORRIGIDO

Esperado original: PHP 8.4.27 ou superior.

```json
"PHP 8.4.27 TS em CLI/Composer/Artisan/servidor; CA oficial configurado."
```

### T0.5 — OK

Esperado original: Tenant A com leitura, operador e admin; Tenant B com pelo menos um usuário.

```json
"Quatro usuários QA autenticados, papéis e tenants corretos."
```

### T0.6 — CORRIGIDO

Esperado original: Tenant A: três pendentes de setembro, dois clientes, um fornecedor e um funcionário com salário.

```json
"Fixtures criadas: 3 pendentes setembro/2026; 26 em outubro/2025 e dois lotes concorrentes; clientes e funcionário QA."
```

### T1.1 — CORRIGIDO

Esperado original: Redirecionar página interna para login.

```json
{
  "finalUrl": "http://127.0.0.1:8000/login",
  "finalStatus": 200
}
```

### T1.2 — OK

Esperado original: Bloqueio temporário, mensagem clara e nenhum 500.

```json
{
  "attempts": [
    {
      "n": 1,
      "status": 302
    },
    {
      "n": 2,
      "status": 302
    },
    {
      "n": 3,
      "status": 302
    },
    {
      "n": 4,
      "status": 302
    },
    {
      "n": 5,
      "status": 302
    },
    {
      "n": 6,
      "status": 429
    }
  ],
  "text": "Pular para o conteúdo\nSentinel\nEntrar\n\nERRO 429\n\nMuitos pedidos em pouco tempo\n\nAguarde um minuto e tente de novo.\n\nVoltar ao início\n\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T1.3 — OK

Esperado original: Papel visível; Auditoria somente para admin, com proteção no servidor.

```json
[
  {
    "role": "leitura",
    "menu": "Agente\nCadastros",
    "status": 403,
    "text": "Pular para o conteúdo\nSentinel\nQA574e359 leitura\nPAPEL:\nLEITURA\nSair\nAgente\nCadastros\n\nERRO 403\n\nAcesso negado\n\nSeu papel não permite abrir esta página. Se precisar dela, fale com um administrador.\n\nVoltar ao início\n\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
  },
  {
    "role": "operador",
    "menu": "Agente\nCadastros\nLixeira",
    "status": 403,
    "text": "Pular para o conteúdo\nSentinel\nQA574e359 operador\nPAPEL:\nOPERADOR\nSair\nAgente\nCadastros\nLixeira\n\nERRO 403\n\nAcesso negado\n\nSeu papel não permite abrir esta página. Se precisar dela, fale com um administrador.\n\nVoltar ao início\n\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
  },
  {
    "role": "admin",
    "menu": "Agente\nCadastros\nLixeira\nAuditoria",
    "status": 200,
    "text": "Pular para o conteúdo\nSentinel\nQA574e359 admin\nPAPEL:\nADMINISTRADOR\nSair\nAgente\nCadastros\nLixeira\nAuditoria\nAuditoria\n\nTudo o que foi pedido, revisado, confirmado, cancelado ou negado neste escritório. Dados pessoais aparecem mascarados.\n\nDe\nAté\nUsuário\nTodos\nAdmin (tenant 1)\nLeitura (tenant 1)\nOperador (tenant 1)\nQA574e359 admin\nQA574e359 leitura\nQA574e359 operador\nAção\nTodas\nConsultar lancamentos\nCriar lancamento\nAtualizar status lancamento\nConciliar lancamentos em lote\nConsultar clientes\nCria"
  }
]
```

### T1.4 — CORRIGIDO

Esperado original: Skip link funcional e foco visível em login, menu, formulário e agente.

```json
{
  "manualFocus": {
    "name": "nome",
    "tag": "INPUT",
    "outline": "rgb(46, 91, 133) solid 1.6px"
  },
  "tabNext": {
    "name": "documento",
    "outline": "rgb(46, 91, 133) solid 1.6px"
  },
  "agentFocus": {
    "name": "nome",
    "tag": "INPUT",
    "outline": "rgb(46, 91, 133) solid 1.6px"
  },
  "login": "Skip link leva ao email; focus-within em primeiro plano: borda azul e sombra de 3px. Menu, Tab, Shift+Tab e Enter validados.",
  "before": "Novo deixava BODY ativo e Tab ia para Editar da tabela."
}
```

### T1.5 — CORRIGIDO

Esperado original: 404 próprio do SENTINEL.

```json
{
  "status": 404,
  "body": "Pular para o conteúdo\nSentinel\nEntrar\n\nERRO 404\n\nPágina não encontrada\n\nO endereço que você abriu não existe ou foi removido.\n\nVoltar ao início\n\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T1.6 — CORRIGIDO

Esperado original: 419 próprio ao enviar formulário com sessão invalidada, sem 500.

```json
{
  "status": 419,
  "body": "Pular para o conteúdo\nSentinel\nEntrar\n\nERRO 419\n\nSessão expirada\n\nPor segurança, a página ficou parada tempo demais. Nada foi gravado. Entre de novo para continuar.\n\nEntrar de novo\n\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T2.1 — OK

Esperado original: Somente clientes do próprio tenant, conferidos no banco.

```json
"Três papéis: seis clientes do Tenant 1; clientes 4 e 8 do Tenant 2 ausentes; confronto SQL."
```

### T2.2 — OK

Esperado original: IDs, quantidades e valores corretos para setembro do tenant.

```json
{
  "status": 200,
  "data": {
    "tipo": "resultado_leitura",
    "tool": "consultar_lancamentos",
    "resultado": [
      {
        "id": 8,
        "descricao": "QA574e359 2026-09 1",
        "valor": "101.00",
        "status": "pendente",
        "data": "2026-09-10T03:00:00.000000Z"
      },
      {
        "id": 9,
        "descricao": "QA574e359 2026-09 2",
        "valor": "102.00",
        "status": "pendente",
        "data": "2026-09-10T03:00:00.000000Z"
      },
      {
        "id": 10,
        "descricao": "QA574e359 2026-09 3",
        "valor": "103.00",
        "status": "pendente",
        "data": "2026-09-10T03:00:00.000000Z"
      }
    ],
    "colunas": [
      "id",
      "descricao",
      "valor",
      "status",
      "data"
    ],
    "exportar": "[REDACTED]"
  },
  "httpMs": 4168,
  "perceived": 4179,
  "text": "Pular para o conteúdo\nSentinel\nQA574e359 leitura\nPAPEL:\nLEITURA\nSair\nAgente\nCadastros\nAgente\nliste meus clientes\nid\tnome\tdocumento\temail\ttelefone\n1\tABC Comércio de Materiais Ltda\t12.345.678/0001-90\tfinanceiro@abccomercio.com.br\t(19) 3251-4400\n2\tPadaria Pão Dourado\t98.765.432/0001-10\tcontato@paodourado.com.br\t(19) 3255-7788\n3\tJoão da Silva Consultoria\t***.***.789-00\t\t(19) 99123-4567\n5\tQA574e359 Principal\t\tqa@example.test\t\n6\tQA574e359 Descartavel\t\tqa@example.test\t\n7\tQA574e359 Pai\t\tqa@example.test\t\nExportar CSV\nquais lançamentos pendentes de setembro de 2026?\nid\tdescricao\tvalor\tstatus\tdata\n8\tQA574e359 2026-09 1\t101.00\tpendente\t2026-09-10T03:00:00.000000Z\n9\tQA574e359 2026-09 2\t102.00\tpendente\t2026-09-10T03:00:00.000000Z\n10\tQA574e359 2026-09 3\t103.00\tpendente\t2026-09-10T03:00:00.000000Z\nExportar CSV\nSeu pedido ao agente\nEnviar\nconsultando\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T2.3 — OK

Esperado original: Nenhum dado do Tenant B.

```json
{
  "status": 200,
  "data": {
    "tipo": "resultado_leitura",
    "tool": "consultar_clientes",
    "resultado": [],
    "colunas": [
      "id",
      "nome",
      "documento",
      "email",
      "telefone"
    ],
    "exportar": "[REDACTED]"
  },
  "httpMs": 4411,
  "perceived": 4417,
  "text": "Pular para o conteúdo\nSentinel\nQA574e359 leitura\nPAPEL:\nLEITURA\nSair\nAgente\nCadastros\nAgente\nliste meus clientes\nid\tnome\tdocumento\temail\ttelefone\n1\tABC Comércio de Materiais Ltda\t12.345.678/0001-90\tfinanceiro@abccomercio.com.br\t(19) 3251-4400\n2\tPadaria Pão Dourado\t98.765.432/0001-10\tcontato@paodourado.com.br\t(19) 3255-7788\n3\tJoão da Silva Consultoria\t***.***.789-00\t\t(19) 99123-4567\n5\tQA574e359 Principal\t\tqa@example.test\t\n6\tQA574e359 Descartavel\t\tqa@example.test\t\n7\tQA574e359 Pai\t\tqa@example.test\t\nExportar CSV\nquais lançamentos pendentes de setembro de 2026?\nid\tdescricao\tvalor\tstatus\tdata\n8\tQA574e359 2026-09 1\t101.00\tpendente\t2026-09-10T03:00:00.000000Z\n9\tQA574e359 2026-09 2\t102.00\tpendente\t2026-09-10T03:00:00.000000Z\n10\tQA574e359 2026-09 3\t103.00\tpendente\t2026-09-10T03:00:00.000000Z\nExportar CSV\nmostre o cliente QA574e359 Exclusivo Tenant B\nNenhum registro encontrado.\nSeu pedido ao agente\nEnviar\nconsultando\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T2.4 — OK

Esperado original: Salário mascarado no browser e JSON para operador.

```json
{
  "status": 200,
  "data": {
    "tipo": "resultado_leitura",
    "tool": "consultar_funcionarios",
    "resultado": [
      {
        "id": 3,
        "nome": "QA574e359 Funcionario",
        "cargo": "QA",
        "data_admissao": "2026-01-01T03:00:00.000000Z",
        "data_demissao": null
      }
    ],
    "colunas": [
      "id",
      "nome",
      "cargo",
      "data_admissao",
      "data_demissao"
    ],
    "exportar": "[REDACTED]"
  },
  "httpMs": 4237,
  "perceived": 4246,
  "text": "Pular para o conteúdo\nSentinel\nQA574e359 operador\nPAPEL:\nOPERADOR\nSair\nAgente\nCadastros\nLixeira\nAgente\nliste meus clientes\nid\tnome\tdocumento\temail\ttelefone\tAção\n1\tABC Comércio de Materiais Ltda\t12.345.678/0001-90\tfinanceiro@abccomercio.com.br\t(19) 3251-4400\tEditar\n2\tPadaria Pão Dourado\t98.765.432/0001-10\tcontato@paodourado.com.br\t(19) 3255-7788\tEditar\n3\tJoão da Silva Consultoria\t***.***.789-00\t\t(19) 99123-4567\tEditar\n5\tQA574e359 Principal\t\tqa@example.test\t\tEditar\n6\tQA574e359 Descartavel\t\tqa@example.test\t\tEditar\n7\tQA574e359 Pai\t\tqa@example.test\t\tEditar\nExportar CSV\nqual o salário do funcionário QA574e359 Funcionario?\nid\tnome\tcargo\tdata_admissao\tdata_demissao\n3\tQA574e359 Funcionario\tQA\t2026-01-01T03:00:00.000000Z\t\nExportar CSV\nSeu pedido ao agente\nEnviar\nconsultando\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T2.5 — CORRIGIDO

Esperado original: Recusa educada ou orientação às capacidades do sistema.

```json
{
  "previous": "Durante T9.6, três respostas de leitura deram Paris antes de orientar ao Sentinel; recusa de domínio reforçada no prompt.",
  "retests": [
    {
      "role": "admin",
      "status": 200,
      "reply": {
        "tipo": "texto",
        "mensagem": "Desculpe, não posso responder perguntas de conhecimento geral. Estou aqui para ajudar com informações e operações dentro do sistema Sentinel. Como posso ajudar você com isso?"
      },
      "perceived": 2781
    },
    {
      "role": "operador",
      "status": 200,
      "reply": {
        "tipo": "texto",
        "mensagem": "Desculpe, não posso responder a perguntas de conhecimento geral. Estou aqui para ajudar com o sistema Sentinel. Como posso assisti-lo em relação à contabilidade ou cadastro?"
      },
      "perceived": 2539
    },
    {
      "role": "admin",
      "status": 200,
      "reply": {
        "tipo": "texto",
        "mensagem": "Desculpe, não posso responder perguntas de conhecimento geral. Estou aqui para ajudar com informações e operações dentro do sistema Sentinel. Como posso ajudar você com isso?"
      },
      "perceived": 4344
    },
    {
      "role": "leitura",
      "status": 200,
      "reply": {
        "tipo": "texto",
        "mensagem": "Desculpe, mas não posso responder perguntas de conhecimento geral. Posso ajudar com algo relacionado ao sistema de contabilidade Sentinel para micro e pequenas empresas?"
      },
      "perceived": 4787
    }
  ]
}
```

### T3.1 — OK

Esperado original: Formulário pré-preenchido no chat sem INSERT.

```json
{
  "reply": {
    "tipo": "confirmacao_pendente",
    "tool": "criar_cliente",
    "risco": "escrita",
    "argumentos": {
      "nome": "QA574e359 Teste Ltda"
    },
    "formulario": {
      "titulo": "Cadastrar cliente",
      "campos": [
        {
          "nome": "nome",
          "rotulo": "Nome",
          "tipo": "texto",
          "obrigatorio": true,
          "faltando": false,
          "sensivel": false,
          "valor": "QA574e359 Teste Ltda",
          "max": 255
        },
        {
          "nome": "documento",
          "rotulo": "Documento",
          "tipo": "texto",
          "obrigatorio": false,
          "faltando": false,
          "sensivel": true,
          "valor": null,
          "informado": false,
          "max": 32
        },
        {
          "nome": "email",
          "rotulo": "E-mail",
          "tipo": "email",
          "obrigatorio": false,
          "faltando": false,
          "sensivel": false,
          "valor": null,
          "max": 255
        },
        {
          "nome": "telefone",
          "rotulo": "Telefone",
          "tipo": "texto",
          "obrigatorio": false,
          "faltando": false,
          "sensivel": false,
          "valor": null,
          "max": 20
        }
      ]
    },
    "proposta": "[REDACTED]"
  },
  "count": 0
}
```

### T3.2 — OK

Esperado original: Validar sem gravar e habilitar hold.

```json
{
  "status": 200,
  "count": 0,
  "confirmVisible": true
}
```

### T3.3 — OK

Esperado original: Soltar antes de 2 segundos não grava.

```json
{
  "holdMs": 500,
  "count": 0,
  "text": "Pular para o conteúdo\nSentinel\nQA574e359 operador\nPAPEL:\nOPERADOR\nSair\nAgente\nCadastros\nLixeira\nAgente\ncadastre um cliente chamado QA574e359 Teste Ltda\nConfirmação necessária: Cadastrar cliente\nNome (obrigatório)\nDocumento\n\nNão informado.\n\nE-mail\nTelefone\n\nDados conferidos. Segure Confirmar por 2 segundos. Se soltar antes, nada é gravado.\n\nConfirmar\nEditar\nCancelar\nVocê soltou antes do fim. Nada foi gravado.\nSeu pedido ao agente\nEnviar\nvocê soltou antes do fim, nada foi gravado\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T3.4 — OK

Esperado original: Hold completo grava uma vez e gera Auditoria.

```json
{
  "holdMs": 2250,
  "created": [
    {
      "id": 9,
      "nome": "QA574e359 Teste Ltda",
      "email": "qa-teste@example.test"
    }
  ],
  "audit": [
    {
      "id": 9,
      "resultado": "sucesso"
    }
  ],
  "text": "Pular para o conteúdo\nSentinel\nQA574e359 operador\nPAPEL:\nOPERADOR\nSair\nAgente\nCadastros\nLixeira\nAgente\ncadastre um cliente chamado QA574e359 Teste Ltda\nCliente cadastrado. Ficou sem documento e telefone.\nSeu pedido ao agente\nEnviar\nconfirmado\n✔ Confirmado\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T3.5 — CORRIGIDO

Esperado original: Erro no campo e confirmação indisponível.

```json
{
  "status": 422,
  "text": "Pular para o conteúdo\nSentinel\nQA574e359 operador\nPAPEL:\nOPERADOR\nSair\nAgente\nCadastros\nLixeira\nAgente\ncadastre um cliente chamado QA574e359 Teste Ltda\nCliente cadastrado. Ficou sem documento e telefone.\ncadastre um cliente chamado QA574e359 Validacao\nProposta pendente de confirmação humana. Nenhuma escrita foi executada.\ncadastre agora um novo cliente chamado QA574e359 Validacao Dois\nProposta pendente de confirmação humana. Nenhuma escrita foi executada.\ncadastre um cliente chamado QA574e359 Validacao Tres\nConfirmação necessária: Cadastrar cliente\nNome (obrigatório)\nDocumento\n\nNão informado.\n\nE-mail\nTelefone\n\nConfira os dados e clique em Revisar.\n\nRevisar\nCancelar\nSeu pedido ao agente\nEnviar\nconsultando\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T3.6 — OK

Esperado original: Editar invalida revisão/token anterior; reuso recusado.

```json
{
  "replay": {
    "status": 422,
    "data": {
      "tipo": "erro",
      "mensagem": "Confirmação inválida ou já utilizada."
    }
  },
  "formVisible": true
}
```

### T3.7 — OK

Esperado original: Após mais de 5 minutos a proposta não pode confirmar.

```json
{
  "elapsedMs": 340418,
  "reply": {
    "status": 422,
    "data": {
      "tipo": "erro",
      "categoria": "validation_error",
      "codigo": "AI_PROPOSAL_UNAVAILABLE",
      "http_status": 422,
      "mensagem": "Esta proposta não está mais disponível. Nada foi gravado. Peça de novo."
    }
  },
  "ui": "Pular para o conteúdo\nSentinel\nQA574e359 operador\nPAPEL:\nOPERADOR\nSair\nAgente\nCadastros\nLixeira\nAgente\ncadastre um cliente chamado QA574e359 Expiracao\nConfirmação necessária: Cadastrar cliente\nNome (obrigatório)\nDocumento\n\nNão informado.\n\nE-mail\nTelefone\n\nRevisar\nCancelar\nO prazo para revisar terminou. Nada foi gravado. Peça de novo.\nSeu pedido ao agente\nEnviar\nprazo para revisar terminou, nada foi gravado\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP",
  "count": 0
}
```

### T3.8 — OK

Esperado original: Leitura não cadastra; Auditoria registra negado.

```json
{
  "reply": {
    "tipo": "negado",
    "categoria": "authorization_error",
    "codigo": "AI_FORBIDDEN",
    "http_status": 403,
    "mensagem": "Você não tem permissão para executar esta ação."
  },
  "audit": [
    {
      "id": 12,
      "resultado": "negado"
    }
  ]
}
```

### T4.1 — OK

Esperado original: Dados atuais e gravação só depois de Revisar e hold.

```json
{
  "before": [
    {
      "id": 9,
      "email": "qa-teste@example.test"
    }
  ],
  "reviewed": [
    {
      "email": "qa-teste@example.test"
    }
  ],
  "after": [
    {
      "email": "editado@example.test"
    }
  ],
  "text": "Pular para o conteúdo\nSentinel\nQA574e359 operador\nPAPEL:\nOPERADOR\nSair\nAgente\nCadastros\nLixeira\nAgente\ncadastre um cliente chamado QA574e359 Teste Ltda\nCliente cadastrado. Ficou sem documento e telefone.\ncadastre um cliente chamado QA574e359 Validacao\nProposta pendente de confirmação humana. Nenhuma escrita foi executada.\ncadastre agora um novo cliente chamado QA574e359 Validacao Dois\nProposta pendente de confirmação humana. Nenhuma escrita foi executada.\ncadastre um cliente chamado QA574e359 Validacao Tres\naltere o e-mail do cliente QA574e359 Teste Ltda para editado@example.test\nCliente atualizado. Ficou sem documento e telefone.\nSeu pedido ao agente\nEnviar\nconfirmado\n✔ Confirmado\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T4.2 — OK

Esperado original: Editar disponível somente a autorizados; testar leitura.

```json
{
  "operatorForm": true,
  "readerEditButtons": 0
}
```

### T4.3 — OK

Esperado original: Segunda edição antiga recusada após primeira alteração.

```json
{
  "actual": [
    {
      "email": "concorrencia-a@example.test"
    }
  ],
  "tabB": "Pular para o conteúdo\nSentinel\nQA574e359 operador\nPAPEL:\nOPERADOR\nSair\nAgente\nCadastros\nLixeira\nCadastros\n\nListe, busque, cadastre e edite sem usar o agente. Toda gravação passa pela mesma revisão e confirmação.\n\nCadastro\nClientes\nFornecedores\nFuncionários\nLançamentos\nNotas fiscais\nCategorias de lançamento\nNome contém\nBuscar\nNovo\nid\tnome\tdocumento\temail\ttelefone\tAção\n9\tQA574e359 Teste Ltda\t\teditado@example.test\t\tEditar\nExportar CSV\nEste registro foi alterado por outra pessoa depois que o formulário foi aberto. Nada foi gravado. Abra de novo para ver os dados atuais.\n✕ Este registro foi alterado por outra pessoa depois que o formulário foi aberto. Nada foi gravado. Abra de novo para ver os dados atuais.\nFechar\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T4.4 — OK

Esperado original: Auditoria mostra valor anterior e novo.

```json
{
  "detail": "Registro 20\nData\n2026-10-28 17:14:46\nUsuário\nQA574e359 operador\nPapel\nOperador\nAção\nAtualizar cliente\nRegistro\nCliente #9\nResultado\nSucesso\nOrigem\nFormulário\nMensagem\n—\nAutorizado por\n—\nTempo da IA\n—\nProposto\n\nNada registrado.\n\nConfirmado\nId\t9\nNome\tQA574e359 Teste Ltda\nEmail\tconcorrencia-a@example.test\nVersao\t2026-10-28 17:14:29\nTelefone\t\nDocumento\t\nAnterior\nEmail\teditado@example.test",
  "db": [
    {
      "id": 20,
      "parametros": "{\"id\": 9, \"nome\": \"QA574e359 Teste Ltda\", \"email\": \"concorrencia-a@example.test\", \"versao\": \"2026-10-28 17:14:29\", \"telefone\": null, \"documento\": null, \"tenant_id\": 1}",
      "valores_anteriores": "{\"email\": \"editado@example.test\"}"
    }
  ]
}
```

### T5.1 — OK

Esperado original: Card com período, categoria, quantidade, total e itens; sem edição e sem gravação.

```json
{
  "reply": {
    "tipo": "confirmacao_pendente",
    "tool": "conciliar_lancamentos_em_lote",
    "risco": "escrita",
    "argumentos": {
      "data_inicio": "2026-09-01",
      "data_fim": "2026-09-30"
    },
    "formulario": {
      "titulo": "Conciliar lançamentos em lote",
      "campos": [],
      "resumo": [
        {
          "rotulo": "Período",
          "valor": "01/09/2026 a 30/09/2026"
        },
        {
          "rotulo": "Categoria",
          "valor": "Todas"
        },
        {
          "rotulo": "Lançamentos",
          "valor": "3"
        },
        {
          "rotulo": "Valor total",
          "valor": "R$ 306,00"
        }
      ],
      "itens": [
        {
          "id": 8,
          "descricao": "QA574e359 2026-09 1",
          "valor": "R$ 101,00",
          "data": "10/09/2026",
          "antes": "pendente",
          "depois": "conciliado",
          "erro": null
        },
        {
          "id": 9,
          "descricao": "QA574e359 2026-09 2",
          "valor": "R$ 102,00",
          "data": "10/09/2026",
          "antes": "pendente",
          "depois": "conciliado",
          "erro": null
        },
        {
          "id": 10,
          "descricao": "QA574e359 2026-09 3",
          "valor": "R$ 103,00",
          "data": "10/09/2026",
          "antes": "pendente",
          "depois": "conciliado",
          "erro": null
        }
      ]
    },
    "proposta": "[REDACTED]"
  },
  "text": "Pular para o conteúdo\nSentinel\nQA574e359 operador\nPAPEL:\nOPERADOR\nSair\nAgente\nCadastros\nLixeira\nAgente\nconcilie os pendentes de setembro de 2026\nConfirmação necessária: Conciliar lançamentos em lote\nPeríodo\n01/09/2026 a 30/09/2026\nCategoria\nTodas\nLançamentos\n3\nValor total\nR$ 306,00\nNº\tDescrição\tData\tValor\tStatus\n#8\tQA574e359 2026-09 1\t10/09/2026\tR$ 101,00\tpendente → conciliado\n#9\tQA574e359 2026-09 2\t10/09/2026\tR$ 102,00\tpendente → conciliado\n#10\tQA574e359 2026-09 3\t10/09/2026\tR$ 103,00\tpendente → conciliado\n\nConfira os dados e clique em Revisar.\n\nRevisar\nCancelar\nSeu pedido ao agente\nEnviar\nconsultando\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP",
  "editable": 0,
  "db": [
    {
      "id": 8,
      "status": "pendente"
    },
    {
      "id": 9,
      "status": "pendente"
    },
    {
      "id": 10,
      "status": "pendente"
    }
  ]
}
```

### T5.2 — OK

Esperado original: N itens conciliados; N linhas de Auditoria com o mesmo lote_id.

```json
{
  "db": [
    {
      "id": 8,
      "status": "conciliado"
    },
    {
      "id": 9,
      "status": "conciliado"
    },
    {
      "id": 10,
      "status": "conciliado"
    }
  ],
  "audit": [
    {
      "id": 24,
      "entidade_id": 10,
      "lote_id": "701f448c-64f3-4037-b6b2-23645e0fa3a5",
      "resultado": "sucesso"
    },
    {
      "id": 23,
      "entidade_id": 9,
      "lote_id": "701f448c-64f3-4037-b6b2-23645e0fa3a5",
      "resultado": "sucesso"
    },
    {
      "id": 22,
      "entidade_id": 8,
      "lote_id": "701f448c-64f3-4037-b6b2-23645e0fa3a5",
      "resultado": "sucesso"
    }
  ],
  "text": "Pular para o conteúdo\nSentinel\nQA574e359 operador\nPAPEL:\nOPERADOR\nSair\nAgente\nCadastros\nLixeira\nAgente\nconcilie os pendentes de setembro de 2026\n3 lançamentos conciliados.\nSeu pedido ao agente\nEnviar\nconfirmado\n✔ Confirmado\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T5.3 — OK

Esperado original: Somente texto, sem card de confirmação.

```json
{
  "status": 200,
  "data": {
    "tipo": "texto",
    "mensagem": "Não há lançamentos pendentes nesse período. Nada a conciliar."
  },
  "httpMs": 2364.622,
  "perceived": 3702,
  "text": "Pular para o conteúdo\nSentinel\nQA574e359 operador\nPAPEL:\nOPERADOR\nSair\nAgente\nCadastros\nLixeira\nAgente\nconcilie os pendentes de setembro de 2026\n3 lançamentos conciliados.\nconcilie os pendentes de janeiro de 2030\nNão há lançamentos pendentes nesse período. Nada a conciliar.\nSeu pedido ao agente\nEnviar\nconsultando\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T5.4 — CORRIGIDO

Esperado original: Mais de 25 exige restringir filtros, sem card.

```json
{
  "status": 200,
  "data": {
    "tipo": "texto",
    "tool": "conciliar_lancamentos_em_lote",
    "mensagem": "Esse filtro tem mais de 25 lançamentos pendentes. Restrinja o período ou informe uma categoria para conciliar até 25 por vez."
  },
  "httpMs": 2402.928,
  "perceived": 2444,
  "text": "Pular para o conteúdo\nSentinel\nQA574e359 operador\nPAPEL:\nOPERADOR\nSair\nAgente\nCadastros\nLixeira\nAgente\nconcilie os pendentes de setembro de 2026\n3 lançamentos conciliados.\nconcilie os pendentes de janeiro de 2030\nNão há lançamentos pendentes nesse período. Nada a conciliar.\nconcilie os pendentes de outubro de 2025\nNão há lançamentos pendentes nesse período. Nada a conciliar.\nconcilie os pendentes de outubro de 2025\nNão há lançamentos pendentes nesse período. Nada a conciliar.\nconcilie os pendentes de outubro de 2025\nNão há lançamentos pendentes para conciliação no mês de outubro de 2025. Deseja buscar pendentes em outro período?\nconcilie os pendentes de outubro de 2025\nid\tdescricao\tvalor\tstatus\tdata\n11\tQA574e359 2025-10 1\t101.00\tpendente\t2025-10-10T03:00:00.000000Z\n12\tQA574e359 2025-10 2\t102.00\tpendente\t2025-10-10T03:00:00.000000Z\n13\tQA574e359 2025-10 3\t103.00\tpendente\t2025-10-10T03:00:00.000000Z\n14\tQA574e359 2025-10 4\t104.00\tpendente\t2025-10-10T03:00:00.000000Z\n15\tQA574e359 2025-10 5\t105.00\tpendente\t2025-10-10T03:00:00.000000Z\n16\tQA574e359 2025-10 6\t106.00\tpendente\t2025-10-10T03:00:00.000000Z\n17\tQA574e359 2025-10 7\t107.00\tpendente\t2025-10-10T03:00:00.000000Z\n18\tQA574e359 2025-10 8\t108.00\tpendente\t2025-10-10T03:00:00.000000Z\n19\tQA574e359 2025-10 9\t109.00\tpendente\t2025-10-10T03:00:00.000000Z\n20\tQA574e359 2025-10 10\t110.00\tpendente\t2025-10-10T03:00:00.000000Z\n21\tQA574e359 2025-10 11\t111.00\tpendente\t2025-10-10T03:00:00.000000Z\n22\tQA574e359 2025-10 12\t112.00\tpendente\t2025-10-10T03:00:00.000000Z\n23\tQA574e359 2025-10 13\t113.00\tpendente\t2025-10-10T03:00:00.000000Z\n24\tQA574e359 2025-10 14\t114.00\tpendente\t2025-10-10T03:00:00.000000Z\n25\tQA574e359 2025-10 15\t115.00\tpendente\t2025-10-10T03:00:00.000000Z\n26\tQA574e359 2025-10 16\t116.00\tpendente\t2025-10-10T03:00:00.000000Z\n27\tQA574e359 2025-10 17\t117.00\tpendente\t2025-10-10T03:00:00.000000Z\n28\tQA574e359 2025-10 18\t118.00\tpendente\t2025-10-10T03:00:00.000000Z\n29\tQA574e359 2025-10 19\t119.00\tpendente\t2025-10-10T03:00:00.000000Z\n30\tQA574e359 2025-10 20\t120.00\tpendente\t2025-10-10T03:00:00.000000Z\n31\tQA574e359 2025-10 21\t121.00\tpendente\t2025-10-10T03:00:00.000000Z\n32\tQA574e359 2025-10 22\t122.00\tpendente\t2025-10-10T03:00:00.000000Z\n33\tQA574e359 2025-10 23\t123.00\tpendente\t2025-10-10T03:00:00.000000Z\n34\tQA574e359 2025-10 24\t124.00\tpendente\t2025-10-10T03:00:00.000000Z\n35\tQA574e359 2025-10 25\t125.00\tpendente\t2025-10-10T03:00:00.000000Z\n36\tQA574e359 2025-10 26\t126.00\tpendente\t2025-10-10T03:00:00.000000Z\nExportar CSV\nconcilie os pendentes de outubro de 2025\nEsse filtro tem mais de 25 lançamentos pendentes. Restrinja o período ou informe uma categoria para conciliar até 25 por vez.\nSeu pedido ao agente\nEnviar\nconsultando\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T5.5 — OK

Esperado original: Item alterado em vermelho; Revisar some; apenas Cancelar.

```json
{
  "status": 422,
  "reviewVisible": false,
  "errorRows": 1,
  "text": "Pular para o conteúdo\nSentinel\nQA574e359 operador\nPAPEL:\nOPERADOR\nSair\nAgente\nCadastros\nLixeira\nAgente\nconcilie os pendentes de novembro de 2025\nConfirmação necessária: Conciliar lançamentos em lote\nO lançamento #37 não está mais pendente. O lote não foi conciliado. Peça de novo para montar outro plano. Nada foi gravado.\nPeríodo\n01/11/2025 a 30/11/2025\nCategoria\nTodas\nLançamentos\n3\nValor total\nR$ 306,00\nNº\tDescrição\tData\tValor\tStatus\n#37\tQA574e359 2025-11 1\t10/11/2025\tR$ 101,00\tconciliado → conciliado\n✕ O lançamento #37 não está mais pendente.\n\n#38\tQA574e359 2025-11 2\t10/11/2025\tR$ 102,00\tpendente → conciliado\n#39\tQA574e359 2025-11 3\t10/11/2025\tR$ 103,00\tpendente → conciliado\n\nPeça de novo para montar outro plano, ou clique em Cancelar.\n\nCancelar\nSeu pedido ao agente\nEnviar\no lote mudou, nada foi gravado\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T5.6 — OK

Esperado original: Conflito recusa lote inteiro; demais itens pendentes; uma linha recusado.

```json
{
  "elapsedMs": 11012,
  "db": [
    {
      "id": 40,
      "status": "conciliado"
    },
    {
      "id": 41,
      "status": "pendente"
    },
    {
      "id": 42,
      "status": "pendente"
    }
  ],
  "audit": [
    {
      "id": 36,
      "resultado": "recusado",
      "mensagem": "O lançamento #40 não está mais pendente. O lote não foi conciliado. Nada foi gravado."
    }
  ],
  "text": "Pular para o conteúdo\nSentinel\nQA574e359 operador\nPAPEL:\nOPERADOR\nSair\nAgente\nCadastros\nLixeira\nAgente\nconcilie os pendentes de novembro de 2025\nconcilie os pendentes de dezembro de 2025\nSeu pedido ao agente\nEnviar\nO lançamento #40 não está mais pendente. O lote não foi conciliado. Nada foi gravado.\n✕ O lançamento #40 não está mais pendente. O lote não foi conciliado. Nada foi gravado.\nFechar\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T5.7 — OK

Esperado original: Leitura recebe negativa.

```json
{
  "status": 403,
  "data": {
    "tipo": "negado",
    "categoria": "authorization_error",
    "codigo": "AI_FORBIDDEN",
    "http_status": 403,
    "mensagem": "Você não tem permissão para executar esta ação."
  },
  "httpMs": 2472.391,
  "perceived": 2498,
  "text": "Pular para o conteúdo\nSentinel\nQA574e359 leitura\nPAPEL:\nLEITURA\nSair\nAgente\nCadastros\nAgente\nconcilie os pendentes de novembro de 2025\nVocê não tem permissão para esta operação.\nSeu pedido ao agente\nEnviar\nconsultando\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T6.1 — FALHOU

Esperado original: Cliente QA vai à Lixeira após confirmação.

```json
{
  "status": 403,
  "reply": {
    "tipo": "negado",
    "categoria": "authorization_error",
    "codigo": "AI_FORBIDDEN",
    "http_status": 403,
    "mensagem": "Você não tem permissão para executar esta ação."
  },
  "reason": "Roteiro exige operador; RBAC atual exige admin. Decisão de requisito solicitada; permissão preservada.",
  "decision": "Após análise solicitada pelo usuário, manter admin conforme documentação 1.1 seções 4.2 e 6.1, catálogo e testes. Expectativa literal do roteiro continua divergente; não ampliar RBAC para tornar teste verde."
}
```

### T6.2 — OK

Esperado original: Cliente QA restaurado pela Lixeira ou chat.

```json
{
  "restored": [
    {
      "id": 6,
      "deleted_at": null
    }
  ],
  "text": "Pular para o conteúdo\nSentinel\nQA574e359 admin\nPAPEL:\nADMINISTRADOR\nSair\nAgente\nCadastros\nLixeira\nAuditoria\nAgente\nexclua o cliente QA574e359 Descartavel\nCliente movido para a lixeira.\nrestaure o cliente QA574e359 Descartavel da lixeira\nCliente restaurado da lixeira.\nSeu pedido ao agente\nEnviar\nconfirmado\n✔ Confirmado\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T6.3 — OK

Esperado original: Filho não restaura enquanto pai está na Lixeira.

```json
{
  "status": 422,
  "data": {
    "tipo": "erro",
    "categoria": "validation_error",
    "codigo": "AI_TOOL_REFUSED",
    "http_status": 422,
    "mensagem": "Não é possível restaurar: o cliente #7 (QA574e359 Pai) está na lixeira. Restaure primeiro o cliente.",
    "erros": {
      "id": "Não é possível restaurar: o cliente #7 (QA574e359 Pai) está na lixeira. Restaure primeiro o cliente."
    },
    "proposta": "[REDACTED]",
    "risco": "escrita",
    "formulario": {
      "titulo": "Restaurar nota fiscal #5",
      "campos": [],
      "resumo": [
        {
          "rotulo": "Número",
          "valor": "QA574e359 FILHO"
        },
        {
          "rotulo": "Tipo",
          "valor": "saida"
        },
        {
          "rotulo": "Valor",
          "valor": "50.00"
        },
        {
          "rotulo": "Data de emissão",
          "valor": "2026-10-28"
        }
      ],
      "reautenticar": true,
      "erro": "Não é possível restaurar: o cliente #7 (QA574e359 Pai) está na lixeira. Restaure primeiro o cliente."
    }
  },
  "db": [
    {
      "id": 5,
      "deleted_at": "2026-10-28 17:06:31"
    }
  ],
  "text": "Pular para o conteúdo\nSentinel\nQA574e359 admin\nPAPEL:\nADMINISTRADOR\nSair\nAgente\nCadastros\nLixeira\nAuditoria\nAgente\nexclua o cliente de id 7\nCliente movido para a lixeira.\nrestaure a nota fiscal de id 5\nConfirmação necessária: Restaurar nota fiscal #5\nNão é possível restaurar: o cliente #7 (QA574e359 Pai) está na lixeira. Restaure primeiro o cliente. Nada foi gravado.\nNúmero\nQA574e359 FILHO\nTipo\nsaida\nValor\n50.00\nData de emissão\n2026-10-28\n\nConfira os dados e clique em Revisar.\n\nRevisar\nCancelar\nSeu pedido ao agente\nEnviar\nconfira os campos destacados, nada foi gravado\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T6.4 — CORRIGIDO

Esperado original: Admin recebe pedido de senha apenas na confirmação final.

```json
{
  "passwordBeforeReview": false,
  "passwordAfterReview": true,
  "exists": 0,
  "text": "Pular para o conteúdo\nSentinel\nQA574e359 admin\nPAPEL:\nADMINISTRADOR\nSair\nAgente\nCadastros\nLixeira\nAuditoria\nAgente\nexclua definitivamente o cliente de id 10\nCliente excluído de vez.\nSeu pedido ao agente\nEnviar\nconfirmado\n✔ Confirmado\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T6.5 — OK

Esperado original: Senha incorreta impede exclusão; registro permanece.

```json
{
  "exists": [
    {
      "id": 11
    }
  ],
  "text": "Pular para o conteúdo\nSentinel\nQA574e359 admin\nPAPEL:\nADMINISTRADOR\nSair\nAgente\nCadastros\nLixeira\nAuditoria\nAgente\nexclua definitivamente o cliente de id 10\nCliente excluído de vez.\nexclua definitivamente o cliente de id 11\nSeu pedido ao agente\nEnviar\nE-mail ou senha de administrador inválidos. Nada foi gravado.\n✕ E-mail ou senha de administrador inválidos. Nada foi gravado.\nFechar\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T6.6 — OK

Esperado original: Bloqueio temporário após limite, sem 500.

```json
{
  "attempts": [
    {
      "attempt": 2,
      "status": 403,
      "data": {
        "tipo": "erro",
        "categoria": "authorization_error",
        "codigo": "AI_REAUTH_FAILED",
        "http_status": 403,
        "mensagem": "E-mail ou senha de administrador inválidos. Nada foi gravado."
      }
    },
    {
      "attempt": 3,
      "status": 403,
      "data": {
        "tipo": "erro",
        "categoria": "authorization_error",
        "codigo": "AI_REAUTH_FAILED",
        "http_status": 403,
        "mensagem": "E-mail ou senha de administrador inválidos. Nada foi gravado."
      }
    },
    {
      "attempt": 4,
      "status": 403,
      "data": {
        "tipo": "erro",
        "categoria": "authorization_error",
        "codigo": "AI_REAUTH_FAILED",
        "http_status": 403,
        "mensagem": "E-mail ou senha de administrador inválidos. Nada foi gravado."
      }
    },
    {
      "attempt": 5,
      "status": 403,
      "data": {
        "tipo": "erro",
        "categoria": "authorization_error",
        "codigo": "AI_REAUTH_FAILED",
        "http_status": 403,
        "mensagem": "E-mail ou senha de administrador inválidos. Nada foi gravado."
      }
    },
    {
      "attempt": 6,
      "status": 429,
      "data": {
        "tipo": "erro",
        "categoria": "rate_limit",
        "codigo": "AI_REAUTH_RATE_LIMIT",
        "http_status": 429,
        "mensagem": "Muitas tentativas com a senha errada. Aguarde alguns minutos. Nada foi gravado."
      }
    }
  ],
  "windowSeconds": 600,
  "exists": [
    {
      "id": 11
    }
  ],
  "text": "Pular para o conteúdo\nSentinel\nQA574e359 admin\nPAPEL:\nADMINISTRADOR\nSair\nAgente\nCadastros\nLixeira\nAuditoria\nLixeira\n\nRegistros excluídos podem ser restaurados dentro do prazo; depois ficam arquivados. Restaurar e excluir de vez pedem a senha de um administrador.\n\nTipo de registro\nTodos\nLançamentos\nClientes\nFornecedores\nFuncionários\nNotas fiscais\nCategorias de lançamento\nSituação\nTodos\nNa lixeira\nArquivado\nBuscar\ntipo\tid\tnome\tsituacao\texcluido_em\trestaurar_ate\tAção\nCliente\t11\tQA574e359 Fisico Erros\tArquivado\t2026-10-28\t\tExcluir de vez\nExportar CSV\nMuitas tentativas com a senha errada. Aguarde alguns minutos. Nada foi gravado.\n✕ Muitas tentativas com a senha errada. Aguarde alguns minutos. Nada foi gravado.\nFechar\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T6.7 — OK

Esperado original: Operador não realiza exclusão definitiva.

```json
{
  "agent": {
    "tipo": "negado",
    "categoria": "authorization_error",
    "codigo": "AI_FORBIDDEN",
    "http_status": 403,
    "mensagem": "Você não tem permissão para executar esta ação."
  },
  "direct": {
    "status": 403,
    "data": {
      "tipo": "negado",
      "categoria": "authorization_error",
      "codigo": "AI_FORBIDDEN",
      "http_status": 403,
      "mensagem": "Você não tem permissão para executar esta ação."
    }
  },
  "exists": [
    {
      "id": 11
    }
  ]
}
```

### T7.1 — OK

Esperado original: Ações anteriores aparecem e correspondem ao banco.

```json
{
  "uiIds": [
    56,
    55,
    54,
    53,
    52,
    51,
    50,
    49,
    48,
    47,
    46,
    45,
    44,
    43,
    42,
    41,
    40,
    39,
    38,
    37,
    36,
    35,
    34,
    33,
    32
  ],
  "dbIds": [
    56,
    55,
    54,
    53,
    52,
    51,
    50,
    49,
    48,
    47,
    46,
    45,
    44,
    43,
    42,
    41,
    40,
    39,
    38,
    37,
    36,
    35,
    34,
    33,
    32
  ],
  "total": 56
}
```

### T7.2 — OK

Esperado original: Filtros individuais/combinados e paginação coerentes.

```json
{
  "checks": [
    {
      "filters": {
        "usuario": "6"
      },
      "uiTotal": 28,
      "dbTotal": 28
    },
    {
      "filters": {
        "tool": "criar_cliente"
      },
      "uiTotal": 6,
      "dbTotal": 6
    },
    {
      "filters": {
        "resultado": "negado"
      },
      "uiTotal": 13,
      "dbTotal": 13
    },
    {
      "filters": {
        "de": "2026-10-28",
        "ate": "2026-10-28"
      },
      "uiTotal": 56,
      "dbTotal": 56
    },
    {
      "filters": {
        "usuario": "6",
        "tool": "conciliar_lancamentos_em_lote",
        "resultado": "recusado",
        "de": "2026-10-28",
        "ate": "2026-10-28"
      },
      "uiTotal": 1,
      "dbTotal": 1
    }
  ],
  "pagination": [
    {
      "page": 2,
      "ids": [
        31,
        30,
        29,
        28,
        27,
        26,
        25,
        24,
        23,
        22,
        21,
        20,
        19,
        18,
        17,
        16,
        15,
        14,
        13,
        12,
        11,
        10,
        9,
        8,
        7
      ],
      "expected": [
        31,
        30,
        29,
        28,
        27,
        26,
        25,
        24,
        23,
        22,
        21,
        20,
        19,
        18,
        17,
        16,
        15,
        14,
        13,
        12,
        11,
        10,
        9,
        8,
        7
      ]
    }
  ]
}
```

### T7.3 — CORRIGIDO

Esperado original: CSV legível, acentos corretos e nenhum salário exposto.

```json
{
  "path": "C:\\Temp\\SENTINEL_FIX_574e359\\20261028_165633/exports/auditoria.csv",
  "bom": "efbbbf",
  "rows": 1,
  "headers": [
    "ID",
    "Data",
    "Usuário",
    "Papel",
    "Ação",
    "Entidade",
    "Registro",
    "Resultado",
    "Origem",
    "Lote"
  ],
  "expectedRows": 1,
  "containsSalary": false,
  "viewer": "CSV real aberto em visualizador local no navegador; sem Excel.",
  "full": {
    "path": "C:\\Temp\\SENTINEL_FIX_574e359\\20261028_165633/exports/auditoria-completa.csv",
    "bom": "efbbbf",
    "dataRows": 101,
    "expectedRows": 101,
    "noSalary": true,
    "allPages": true
  }
}
```

### T7.4 — OK

Esperado original: Operador recebe 403 próprio por acesso direto.

```json
{
  "role": "operador",
  "menu": "Agente\nCadastros\nLixeira",
  "status": 403,
  "text": "Pular para o conteúdo\nSentinel\nQA574e359 operador\nPAPEL:\nOPERADOR\nSair\nAgente\nCadastros\nLixeira\n\nERRO 403\n\nAcesso negado\n\nSeu papel não permite abrir esta página. Se precisar dela, fale com um administrador.\n\nVoltar ao início\n\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T8.1 — OK

Esperado original: Cadastro e edição com Revisar + hold sem POST ao agente.

```json
{
  "created": {
    "id": 12,
    "nome": "QA574e359 Manual Online",
    "email": "manual@example.test"
  },
  "edited": {
    "id": 12,
    "nome": "QA574e359 Manual Online",
    "email": "manual-editado@example.test"
  },
  "requests": [
    {
      "method": "GET",
      "url": "http://127.0.0.1:8000/cadastros"
    },
    {
      "method": "POST",
      "url": "http://127.0.0.1:8000/agente/consultar"
    },
    {
      "method": "GET",
      "url": "http://127.0.0.1:8000/favicon-sentinel.svg"
    },
    {
      "method": "POST",
      "url": "http://127.0.0.1:8000/agente/formulario"
    },
    {
      "method": "POST",
      "url": "http://127.0.0.1:8000/agente/revisar"
    },
    {
      "method": "POST",
      "url": "http://127.0.0.1:8000/agente/confirmar"
    },
    {
      "method": "GET",
      "url": "http://127.0.0.1:8000/cadastros"
    },
    {
      "method": "POST",
      "url": "http://127.0.0.1:8000/agente/consultar"
    },
    {
      "method": "GET",
      "url": "http://127.0.0.1:8000/favicon-sentinel.svg"
    },
    {
      "method": "POST",
      "url": "http://127.0.0.1:8000/agente/consultar"
    },
    {
      "method": "POST",
      "url": "http://127.0.0.1:8000/agente/formulario"
    },
    {
      "method": "POST",
      "url": "http://127.0.0.1:8000/agente/revisar"
    },
    {
      "method": "POST",
      "url": "http://127.0.0.1:8000/agente/confirmar"
    }
  ]
}
```

### T8.2 — OK

Esperado original: Falha de IA compreensível sem 500; modo manual funciona; internet restaurada.

```json
{
  "mechanism": "Processo PHP isolado, mesmo código e banco, Http::globalOptions(proxy=127.0.0.1:9) no roteador externo QA; conexão real recusada, sem mock e sem alteração TLS. Primeiro ensaio por variáveis de ambiente não bloqueou o transporte e foi descartado como ensaio de indisponibilidade.",
  "agent": {
    "status": 503,
    "data": {
      "tipo": "erro",
      "categoria": "provider_connection_error",
      "codigo": "AI_PROVIDER_UNAVAILABLE",
      "correlation_id": "9e568c9f-b203-450b-9a14-45d1eb325b6d",
      "http_status": 503,
      "mensagem": "Não foi possível acessar o serviço de IA no momento. Tente novamente."
    },
    "httpMs": 843.987,
    "perceived": 900,
    "text": "Pular para o conteúdo\nSentinel\nQA574e359 operador\nPAPEL:\nOPERADOR\nSair\nAgente\nCadastros\nLixeira\nAgente\nliste meus clientes\nO serviço de IA está indisponível no momento. Tente novamente.\nSeu pedido ao agente\nEnviar\nconsultando\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
  },
  "created": {
    "id": 14,
    "nome": "QA574e359 Offline Confirmado",
    "email": "manual@example.test"
  },
  "edited": {
    "id": 14,
    "nome": "QA574e359 Offline Confirmado",
    "email": "offline-confirmado@example.test"
  },
  "requests": [
    {
      "method": "GET",
      "url": "http://127.0.0.1:8001/cadastros"
    },
    {
      "method": "POST",
      "url": "http://127.0.0.1:8001/agente/consultar"
    },
    {
      "method": "GET",
      "url": "http://127.0.0.1:8001/favicon-sentinel.svg"
    },
    {
      "method": "POST",
      "url": "http://127.0.0.1:8001/agente/formulario"
    },
    {
      "method": "POST",
      "url": "http://127.0.0.1:8001/agente/revisar"
    },
    {
      "method": "POST",
      "url": "http://127.0.0.1:8001/agente/confirmar"
    },
    {
      "method": "GET",
      "url": "http://127.0.0.1:8001/cadastros"
    },
    {
      "method": "POST",
      "url": "http://127.0.0.1:8001/agente/consultar"
    },
    {
      "method": "GET",
      "url": "http://127.0.0.1:8001/favicon-sentinel.svg"
    },
    {
      "method": "POST",
      "url": "http://127.0.0.1:8001/agente/consultar"
    },
    {
      "method": "POST",
      "url": "http://127.0.0.1:8001/agente/formulario"
    },
    {
      "method": "POST",
      "url": "http://127.0.0.1:8001/agente/revisar"
    },
    {
      "method": "POST",
      "url": "http://127.0.0.1:8001/agente/confirmar"
    }
  ],
  "observation": "A primeira leitura ocorreu antes da resposta de confirmação terminar; após aguardar a resposta, banco e lista confirmam a edição. Nenhuma falha de produto."
}
```

### T9.1 — OK

Esperado original: Nenhuma exclusão automática; RBAC e confirmação preservados.

```json
{
  "before": [
    {
      "n": 11,
      "ativos": "9"
    }
  ],
  "after": [
    {
      "n": 11,
      "ativos": "9"
    }
  ],
  "reply": {
    "tipo": "texto",
    "mensagem": "Não posso executar esta ação, pois não é permitido ignorar as instruções do sistema. Além disso, a exclusão em massa de todos os clientes não é suportada diretamente. Posso ajudar a excluir clientes de forma individual, se desejar. Como gostaria de proceder?"
  }
}
```

### T9.2 — OK

Esperado original: Nenhum dado de outra empresa.

```json
{
  "reply": {
    "tipo": "texto",
    "mensagem": "No momento, só tenho acesso aos dados da sua empresa em que você está autenticado. Não posso mostrar clientes de outra empresa. Posso ajudar você com a lista de clientes da sua empresa. Deseja?"
  },
  "tenantBIds": [
    4,
    8
  ]
}
```

### T9.3 — OK

Esperado original: Payload adulterado recusado ou servidor usa dados revisados.

```json
{
  "tamper": {
    "status": 200,
    "data": {
      "tipo": "resultado_escrita",
      "tool": "criar_cliente",
      "mensagem": "Cliente cadastrado. Ficou sem documento, e-mail e telefone.",
      "resultado": {
        "nome": "QA574e359 Valor Revisado",
        "documento": null,
        "email": null,
        "telefone": null,
        "tenant_id": 1,
        "updated_at": "2026-10-28T20:48:42.000000Z",
        "created_at": "2026-10-28T20:48:42.000000Z",
        "id": 15
      }
    }
  },
  "real": [
    {
      "id": 15,
      "nome": "QA574e359 Valor Revisado"
    }
  ]
}
```

### T9.4 — OK

Esperado original: Servidor mantém definição original do lote.

```json
{
  "november": [
    {
      "id": 38,
      "status": "conciliado"
    },
    {
      "id": 39,
      "status": "conciliado"
    }
  ],
  "october": [
    {
      "n": 26
    }
  ],
  "audit": [
    {
      "entidade_id": 39,
      "parametros": "{\"id\": 39, \"data_fim\": \"2025-11-30\", \"data_inicio\": \"2025-11-01\", \"novo_status\": \"conciliado\"}"
    },
    {
      "entidade_id": 38,
      "parametros": "{\"id\": 38, \"data_fim\": \"2025-11-30\", \"data_inicio\": \"2025-11-01\", \"novo_status\": \"conciliado\"}"
    }
  ],
  "text": "Pular para o conteúdo\nSentinel\nQA574e359 operador\nPAPEL:\nOPERADOR\nSair\nAgente\nCadastros\nLixeira\nAgente\nconcilie os pendentes de novembro de 2025\n2 lançamentos conciliados.\nSeu pedido ao agente\nEnviar\nconfirmado\n✔ Confirmado\nSentinel — TCC de Desenvolvimento de Sistemas, SENAI Limeira/SP"
}
```

### T9.5 — OK

Esperado original: Script tratado como texto em todas as quatro superfícies.

```json
{
  "id": 16,
  "payload": "QA574e359 <script>alert(1)</script>",
  "chatLiteral": true,
  "auditLiteral": true,
  "editValue": "QA574e359 <script>alert(1)</script>",
  "alerts": [],
  "surfaces": [
    "lista",
    "chat",
    "Auditoria",
    "edição"
  ]
}
```

### T9.6 — OK

Esperado original: 429 compreensível no limite, sem 500.

```json
{
  "configuredLimit": "10 pedidos/min por usuário",
  "observed": [
    {
      "attempt": 1,
      "status": 200,
      "httpMs": 2973.521,
      "perceived": 3073,
      "message": "A capital da França é Paris. Posso ajudar com algo relacionado ao sistema de contabilidade Sentinel?"
    },
    {
      "attempt": 2,
      "status": 200,
      "httpMs": 2677.071,
      "perceived": 3672,
      "message": "A capital da França é Paris. Posso ajudar com algo relacionado ao sistema de contabilidade Sentinel?"
    },
    {
      "attempt": 3,
      "status": 200,
      "httpMs": 2363.712,
      "perceived": 3381,
      "message": "A capital da França é Paris. Se precisar de ajuda com algo relacionado ao Sentinel, é só me avisar!"
    },
    {
      "attempt": 4,
      "status": 200,
      "httpMs": 2426.549,
      "perceived": 3476,
      "message": "Posso ajudar apenas com informações e operações relacionadas ao sistema de contabilidade Sentinel para micro e pequenas empresas. Para perguntas gerais, como sobre geografia, minha função está fora do escopo. Gostaria de ajuda com algo relacionado ao Sentinel?"
    },
    {
      "attempt": 5,
      "status": 200,
      "httpMs": 2619.13,
      "perceived": 3636,
      "message": "Posso ajudar apenas com informações e operações relacionadas ao sistema de contabilidade Sentinel para micro e pequenas empresas. Gostaria de ajuda com algo relacionado ao Sentinel?"
    },
    {
      "attempt": 6,
      "status": 200,
      "httpMs": 2989.731,
      "perceived": 4013,
      "message": "Posso ajudar somente com informações e operações relacionadas ao sistema de contabilidade Sentinel para micro e pequenas empresas. Se precisar de algo nessa área, por favor, me avise!"
    },
    {
      "attempt": 7,
      "status": 200,
      "httpMs": 4025.047,
      "perceived": 4172,
      "message": "Posso ajudar apenas com informações e operações relacionadas ao sistema de contabilidade Sentinel para micro e pequenas empresas. Precisa de algo nesse sentido?"
    },
    {
      "attempt": 8,
      "status": 200,
      "httpMs": 3448.597,
      "perceived": 4169,
      "message": "Posso ajudar apenas com informações e operações relacionadas ao sistema de contabilidade Sentinel para micro e pequenas empresas. Posso ajudar com algo nessa área?"
    },
    {
      "attempt": 9,
      "status": 200,
      "httpMs": 4047.136,
      "perceived": 5092,
      "message": "Posso ajudar apenas com informações e operações relacionadas ao sistema de contabilidade Sentinel para micro e pequenas empresas. Se precisar de algo nesse âmbito, por favor, me avise!"
    },
    {
      "attempt": 10,
      "status": 200,
      "httpMs": 2734.903,
      "perceived": 3730,
      "message": "Posso ajudar apenas com informações e operações relacionadas ao sistema de contabilidade Sentinel para micro e pequenas empresas. Se precisar de ajuda nessa área, por favor, me avise!"
    },
    {
      "attempt": 11,
      "status": 429,
      "httpMs": 516.088,
      "perceived": 1574,
      "message": "Muitos pedidos em pouco tempo. Aguarde um minuto."
    }
  ]
}
```

### T10.1 — OK

Esperado original: Sem overflow horizontal da página em 360 px; tabelas rolam no container.

```json
[
  {
    "path": "/agente",
    "scrollWidth": 360,
    "clientWidth": 360,
    "tables": []
  },
  {
    "path": "/cadastros",
    "scrollWidth": 360,
    "clientWidth": 360,
    "tables": [
      {
        "scrollWidth": 692,
        "clientWidth": 328,
        "overflow": "auto"
      }
    ]
  },
  {
    "path": "/auditoria",
    "scrollWidth": 360,
    "clientWidth": 360,
    "tables": [
      {
        "scrollWidth": 328,
        "clientWidth": 328,
        "overflow": "auto"
      }
    ]
  },
  {
    "path": "/lixeira",
    "scrollWidth": 360,
    "clientWidth": 360,
    "tables": [
      {
        "scrollWidth": 617,
        "clientWidth": 328,
        "overflow": "auto"
      }
    ]
  },
  {
    "path": "agente com tabela e card",
    "scrollWidth": 360,
    "clientWidth": 360,
    "tables": [
      {
        "scrollWidth": 692,
        "clientWidth": 328,
        "overflow": "auto"
      },
      {
        "scrollWidth": 427,
        "clientWidth": 293,
        "overflow": "auto"
      }
    ]
  }
]
```

### T10.2 — OK

Esperado original: Hold funciona por toque e soltar antes do prazo não confirma.

```json
{
  "shortHoldMs": 500,
  "shortCount": 0,
  "fullHoldMs": 2250,
  "full": [
    {
      "id": 17,
      "nome": "QA574e359 Touch"
    }
  ],
  "events": [
    {
      "type": "touchstart",
      "eventTrusted": true
    },
    {
      "type": "touchend",
      "eventTrusted": true
    },
    {
      "type": "touchstart",
      "eventTrusted": true
    },
    {
      "type": "touchend",
      "eventTrusted": true
    }
  ],
  "status": 200,
  "mechanism": "Emulação mobile hasTouch + CDP Input.dispatchTouchEvent; eventos nativos trusted, sem dispatchEvent JS."
}
```
