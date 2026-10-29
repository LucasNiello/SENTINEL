# Relatório de Teste Manual — SENTINEL

Referência: 574e359

Data: 28/10/2026, America/Sao_Paulo (UTC−03:00).
Hora inicial: não instrumentada na primeira chamada; primeiro marco persistido: 16:30:31 (criação da pasta de evidências). As inspeções Git e leitura do roteiro começaram antes desse marco.
Hora final da consolidação: 2026-10-28 16:37:24 -03:00.

Branch: malaman
Commit: 574e3597c13f840fc49517c0e83c065c7cc098fd
Working tree: inicialmente limpo; commit preservado.
PHP: 8.3.33 (cli), built Jul 28 2026 18:10:28, ZTS Visual C++ 2019 x64; Zend Engine 4.3.33.
Laravel: v13.31.0 conforme composer.lock; runtime não inicializado.
Node: v24.20.0
npm: 11.19.0
Banco: MySQL 8.4.3, conexão TCP 127.0.0.1:3306, database sentinel, servidor local malaman; APP_ENV=local. Verificação direta via PDO sem bootstrap Laravel, consultas somente leitura.
APP_DEBUG: true no .env; valor efetivo não verificado por falha de bootstrap.
SESSION_ENCRYPT: false no .env; valor efetivo não verificado por falha de bootstrap.
Azure configurado: chave presente; conectividade e configuração carregada não avaliadas. Nenhum segredo impresso.
Cache de configuração: bootstrap/cache/config.php ausente.
Notion consultado: SIM
Notion atualizado: NÃO
Evidências: C:/Temp/SENTINEL_QA/574e359/20261028_QA01

## Resumo executivo

**Bateria interrompida por falha que impossibilita todos os fluxos seguintes, conforme seção 22 do roteiro. Sistema não apto a continuar homologação neste ambiente. Não houve correções.**

Total da matriz: 57 itens (6 de preparação e 51 funcionais).
OK: 1 (T0.5, existência dos perfis).
FALHOU: 56, dos quais 6 falhas verificadas e 50 bloqueados por pré-condição.
Itens com avaliação concluída: 7. Os 50 bloqueados não contam como fluxos manuais executados.
Falhas críticas funcionais confirmadas: 0; isso não comprova segurança.
Falhas altas: 5 ocorrências P1, compartilhando bloqueios de PHP/schema.
Falhas médias: 1 ocorrência P2 (configuração, T0.3).
Falhas baixas: 0 demonstradas. Bloqueados não recebem severidade funcional inventada.
RNF04: FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO, sem amostra.
Quantidade de chamadas IA: 0. Média, mediana, pior tempo, mínimo e P95: não mensuráveis. Chamadas >10 s: 0 de 0; não é aprovação de performance.

A incompatibilidade de PHP é anterior ao Laravel: composer install, Artisan e todas as rotas dinâmicas testadas falham. O navegador real mostrou stack trace PHP, sem tela SENTINEL. npm ci e os dois builds passaram; a instalação npm reportou quatro pacotes vulneráveis (dois high e dois critical). Esse achado complementar é de dependências, sem exploração e sem comprovação de exposição em produção.

## Método e limites

Execução local por terminal, consultas SQL de leitura e navegação em Microsoft Edge real com Playwright, com capturas, eventos de Console e status de Network. A skill computer-use foi lida, mas node_repl/@oai/sky não estava exposto nas ferramentas da sessão; foi usado o runtime de browser disponível como alternativa. A inspeção da interface ocorreu nas páginas de erro; não houve autenticação, interação com chat, formulários, teclado, touch ou DevTools visual. Captura de Network foi programática, sem HAR/cookies/headers. Não se considera essa navegação substituta dos fluxos manuais impedidos.

Cada item FALHOU tem captura própria da página real de bloqueio em /login. Essas imagens documentam a mesma pré-condição e não fingem uma execução individual do fluxo. O manifest registra origem e timestamp. T1.1 e T1.5 também têm captura da navegação específica. Não foram criadas telas simuladas.

Nenhuma suíte automatizada substitui teste manual. O comando complementar de testes sequer iniciou o PHPUnit. Nenhum pedido foi enviado à IA. Não foram executados stress, autenticações, exclusões, restaurações ou modificações de dados.

## Preparação

- T0.1: **FALHOU**. Git inicialmente limpo; pull --ff-only respondeu Already up to date na branch malaman. Composer install recusou o lock: Symfony 8.1 exige PHP >=8.4.1. npm ci concluiu (57 pacotes); primeiro build passou em 8,00 s e build final em 1,83 s. npm audit indicou 4 pacotes vulneráveis. Não foi executado composer update nem npm audit fix.
- T0.2: **FALHOU**. php artisan migrate:status encerrou no platform_check.php. Consulta PDO somente leitura confirmou 13 migrations no batch 1, três pendentes e ausência física da coluna audit_logs.lote_id. migrate não pôde iniciar pelo mesmo bloqueio; nenhuma migration executada.
- T0.3: **FALHOU**. Inspeção restrita do .env: Azure key presente, APP_DEBUG=true, SESSION_ENCRYPT=false. bootstrap/cache/config.php ausente. O bootstrap não carrega Laravel; valores efetivos não puderam ser confirmados. Presença da chave não comprova conectividade Azure.
- T0.4: **FALHOU**. PHP 8.3.33 (cli), built Jul 28 2026 18:10:28, ZTS Visual C++ 2019 x64; Zend Engine v4.3.33. Abaixo de 8.4.27 e do mínimo 8.4.1 do lock.
- T0.5: **OK**. Consulta SQL somente leitura confirmou Tenant 1: um admin, um operador e um leitura; Tenant 2: um admin. Contas preservadas, sem criação, redefinição de senha ou autenticação. OK limitado à existência dos perfis exigidos; login permanece bloqueado.
- T0.6: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**. Tenant 1 tem três clientes ativos, três fornecedores ativos e dois funcionários ativos com salário positivo. Não há lançamentos ativos em setembro de 2026. Criação das fixtures não realizada porque todos os fluxos estão impedidos pelo bootstrap PHP. Ano assumido: 2026; Notion não forneceu ano.

Git pull --ff-only preservou a branch malaman e respondeu Already up to date. O fetch atualizou origin/main de 574e359 para de8299c, sem alterar o HEAD testado. Não foi feito merge, rebase, commit ou push. Não se trocou de branch.

As migrations aplicadas são as 13 primeiras listadas em logs/environment_db.json. Pendentes: 2026_10_19_120000_add_proposto_autorizacao_tempo_e_origem_to_audit_logs; 2026_10_22_120000_add_valores_anteriores_to_audit_logs; 2026_10_28_120000_add_lote_id_to_audit_logs. Não foi executado migrate:fresh, refresh, rollback ou qualquer migração.

Setembro assumido: 2026, compatível com o ano do ambiente e das migrations. Nenhuma documentação acessível esclareceu outro ano. Fixtures não foram criadas porque a falha de bootstrap impede todo o restante da bateria.

## Matriz completa

| ID | Área | Papel previsto | Resultado | Tempo IA | Evidência | Observação |
|---|---|---|---|---|---|---|
| T0.1 | Preparação | Preparação | FALHOU | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T0.1_bloqueio_php.png) | Git inicialmente limpo; pull --ff-only respondeu Already up to date na branch malaman. Composer install recusou o lock: Symfony 8.1 exige PHP >=8.4.1. npm ci concluiu (57 pacotes); primeiro build passou em 8,00 s e build final em 1,83 s. npm audit indicou 4 pacotes vulneráveis. Não foi executado composer update nem npm audit fix. |
| T0.2 | Preparação | Preparação | FALHOU | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T0.2_bloqueio_php.png) | php artisan migrate:status encerrou no platform_check.php. Consulta PDO somente leitura confirmou 13 migrations no batch 1, três pendentes e ausência física da coluna audit_logs.lote_id. migrate não pôde iniciar pelo mesmo bloqueio; nenhuma migration executada. |
| T0.3 | Preparação | Preparação | FALHOU | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T0.3_bloqueio_php.png) | Inspeção restrita do .env: Azure key presente, APP_DEBUG=true, SESSION_ENCRYPT=false. bootstrap/cache/config.php ausente. O bootstrap não carrega Laravel; valores efetivos não puderam ser confirmados. Presença da chave não comprova conectividade Azure. |
| T0.4 | Preparação | Preparação | FALHOU | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T0.4_bloqueio_php.png) | PHP 8.3.33 (cli), built Jul 28 2026 18:10:28, ZTS Visual C++ 2019 x64; Zend Engine v4.3.33. Abaixo de 8.4.27 e do mínimo 8.4.1 do lock. |
| T0.5 | Preparação | Preparação | OK | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/logs/environment_db.json) | Consulta SQL somente leitura confirmou Tenant 1: um admin, um operador e um leitura; Tenant 2: um admin. Contas preservadas, sem criação, redefinição de senha ou autenticação. OK limitado à existência dos perfis exigidos; login permanece bloqueado. |
| T0.6 | Preparação | Preparação | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T0.6_bloqueio_php.png) | Tenant 1 tem três clientes ativos, três fornecedores ativos e dois funcionários ativos com salário positivo. Não há lançamentos ativos em setembro de 2026. Criação das fixtures não realizada porque todos os fluxos estão impedidos pelo bootstrap PHP. Ano assumido: 2026; Notion não forneceu ano. |
| T1.1 | Acesso e layout | Não autenticado / papéis conforme roteiro | FALHOU | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T1.1_bloqueio_php.png) | Navegação sem autenticação a http://127.0.0.1:8000/agente recebeu HTTP 500; URL final igual à original, sem redirecionamento. platform_check.php exige PHP >=8.4.1. Duração da navegação 74 ms, não é tempo IA. |
| T1.2 | Acesso e layout | Não autenticado / papéis conforme roteiro | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T1.2_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T1.3 | Acesso e layout | Não autenticado / papéis conforme roteiro | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T1.3_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T1.4 | Acesso e layout | Não autenticado / papéis conforme roteiro | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T1.4_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T1.5 | Acesso e layout | Não autenticado / papéis conforme roteiro | FALHOU | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T1.5_bloqueio_php.png) | Navegação a http://127.0.0.1:8000/qa574e359-rota-inexistente recebeu HTTP 500 e stack trace do PHP; URL final igual à original. Não há tela 404 própria. Duração 48 ms, não é tempo IA. |
| T1.6 | Acesso e layout | Não autenticado / papéis conforme roteiro | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T1.6_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T2.1 | Consultas pelo agente | Leitura / operador / admin | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T2.1_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T2.2 | Consultas pelo agente | Leitura / operador / admin | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T2.2_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T2.3 | Consultas pelo agente | Leitura / operador / admin | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T2.3_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T2.4 | Consultas pelo agente | Leitura / operador / admin | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T2.4_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T2.5 | Consultas pelo agente | Leitura / operador / admin | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T2.5_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T3.1 | Cadastro pelo chat | Operador (testes de negativa: leitura) | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T3.1_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T3.2 | Cadastro pelo chat | Operador (testes de negativa: leitura) | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T3.2_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T3.3 | Cadastro pelo chat | Operador (testes de negativa: leitura) | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T3.3_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T3.4 | Cadastro pelo chat | Operador (testes de negativa: leitura) | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T3.4_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T3.5 | Cadastro pelo chat | Operador (testes de negativa: leitura) | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T3.5_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T3.6 | Cadastro pelo chat | Operador (testes de negativa: leitura) | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T3.6_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T3.7 | Cadastro pelo chat | Operador (testes de negativa: leitura) | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T3.7_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T3.8 | Cadastro pelo chat | Operador (testes de negativa: leitura) | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T3.8_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T4.1 | Edição | Operador (testes de negativa: leitura) | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T4.1_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T4.2 | Edição | Operador (testes de negativa: leitura) | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T4.2_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T4.3 | Edição | Operador (testes de negativa: leitura) | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T4.3_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T4.4 | Edição | Operador (testes de negativa: leitura) | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T4.4_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T5.1 | Conciliação em lote / RF03 | Operador (testes de negativa: leitura) | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T5.1_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T5.2 | Conciliação em lote / RF03 | Operador (testes de negativa: leitura) | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T5.2_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T5.3 | Conciliação em lote / RF03 | Operador (testes de negativa: leitura) | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T5.3_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T5.4 | Conciliação em lote / RF03 | Operador (testes de negativa: leitura) | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T5.4_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T5.5 | Conciliação em lote / RF03 | Operador (testes de negativa: leitura) | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T5.5_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T5.6 | Conciliação em lote / RF03 | Operador (testes de negativa: leitura) | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T5.6_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T5.7 | Conciliação em lote / RF03 | Operador (testes de negativa: leitura) | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T5.7_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T6.1 | Lixeira e exclusão | Operador / admin conforme roteiro | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T6.1_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T6.2 | Lixeira e exclusão | Operador / admin conforme roteiro | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T6.2_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T6.3 | Lixeira e exclusão | Operador / admin conforme roteiro | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T6.3_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T6.4 | Lixeira e exclusão | Operador / admin conforme roteiro | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T6.4_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T6.5 | Lixeira e exclusão | Operador / admin conforme roteiro | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T6.5_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T6.6 | Lixeira e exclusão | Operador / admin conforme roteiro | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T6.6_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T6.7 | Lixeira e exclusão | Operador / admin conforme roteiro | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T6.7_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T7.1 | Auditoria | Admin (7.4: operador) | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T7.1_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T7.2 | Auditoria | Admin (7.4: operador) | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T7.2_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T7.3 | Auditoria | Admin (7.4: operador) | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T7.3_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T7.4 | Auditoria | Admin (7.4: operador) | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T7.4_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T8.1 | Modo manual sem IA | Conforme roteiro; não autenticado nesta execução | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T8.1_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T8.2 | Modo manual sem IA | Conforme roteiro; não autenticado nesta execução | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T8.2_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T9.1 | Segurança | Conforme roteiro; não autenticado nesta execução | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T9.1_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T9.2 | Segurança | Conforme roteiro; não autenticado nesta execução | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T9.2_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T9.3 | Segurança | Conforme roteiro; não autenticado nesta execução | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T9.3_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T9.4 | Segurança | Conforme roteiro; não autenticado nesta execução | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T9.4_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T9.5 | Segurança | Conforme roteiro; não autenticado nesta execução | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T9.5_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T9.6 | Segurança | Conforme roteiro; não autenticado nesta execução | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T9.6_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T10.1 | Responsividade | Conforme roteiro; não autenticado nesta execução | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T10.1_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |
| T10.2 | Responsividade | Conforme roteiro; não autenticado nesta execução | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO | Não medido | [evidência](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T10.2_bloqueio_php.png) | Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado. |

## Detalhamento das falhas

Os bloqueados abaixo não representam 50 defeitos funcionais independentes. São lacunas de cobertura causadas pela mesma indisponibilidade global.

### T0.1 — Dependências e build

Resultado: **FALHOU**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Preparação. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Confirmar diretório, branch, HEAD, log e status; pull --ff-only; composer install; npm ci; npm run build; repetir build ao final.

Resultado esperado: Dependências instaladas e build concluído sem perda de alterações.

Resultado obtido: Git inicialmente limpo; pull --ff-only respondeu Already up to date na branch malaman. Composer install recusou o lock: Symfony 8.1 exige PHP >=8.4.1. npm ci concluiu (57 pacotes); primeiro build passou em 8,00 s e build final em 1,83 s. npm audit indicou 4 pacotes vulneráveis. Não foi executado composer update nem npm audit fix.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T0.1_bloqueio_php.png). Captura do login documenta o ambiente; dados específicos estão nos JSON/logs.

Causa provável: PHP incompatível com composer.lock.

Impacto: Impede homologação confiável do estado local atual.

Reproduzível: SIM para o bloqueio comum; verificação específica reproduzida por comandos/navegação documentados.

Prioridade sugerida: P1.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Verificação realizada; ver detalhes e logs.

### T0.2 — Schema e migrations

Resultado: **FALHOU**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Preparação. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Executar migrate:status; após erro, consultar somente leitura a tabela migrations e information_schema.columns.

Resultado esperado: Schema com migration 16 e audit_logs.lote_id; migrate:status executável.

Resultado obtido: php artisan migrate:status encerrou no platform_check.php. Consulta PDO somente leitura confirmou 13 migrations no batch 1, três pendentes e ausência física da coluna audit_logs.lote_id. migrate não pôde iniciar pelo mesmo bloqueio; nenhuma migration executada.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T0.2_bloqueio_php.png). Captura do login documenta o ambiente; dados específicos estão nos JSON/logs.

Causa provável: Schema desatualizado e comando de migrations impedido pelo PHP.

Impacto: Impede homologação confiável do estado local atual.

Reproduzível: SIM para o bloqueio comum; verificação específica reproduzida por comandos/navegação documentados.

Prioridade sugerida: P1.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Verificação realizada; ver detalhes e logs.

### T0.3 — Configuração de segurança e Azure

Resultado: **FALHOU**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Preparação. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Ler somente chaves permitidas do .env, testar presença booleana da chave Azure e existência do cache; não imprimir valores secretos.

Resultado esperado: Azure configurado; APP_DEBUG=false; SESSION_ENCRYPT=true; valores efetivos verificados.

Resultado obtido: Inspeção restrita do .env: Azure key presente, APP_DEBUG=true, SESSION_ENCRYPT=false. bootstrap/cache/config.php ausente. O bootstrap não carrega Laravel; valores efetivos não puderam ser confirmados. Presença da chave não comprova conectividade Azure.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T0.3_bloqueio_php.png). Captura do login documenta o ambiente; dados específicos estão nos JSON/logs.

Causa provável: Configuração local divergente do roteiro; configuração efetiva indisponível.

Impacto: Impede homologação confiável do estado local atual.

Reproduzível: SIM para o bloqueio comum; verificação específica reproduzida por comandos/navegação documentados.

Prioridade sugerida: P2.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Verificação realizada; ver detalhes e logs.

### T0.4 — Versão PHP

Resultado: **FALHOU**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Preparação. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Executar php -v e consultar executável resolvido no PATH e diretórios PHP do Laragon.

Resultado esperado: PHP 8.4.27 ou superior.

Resultado obtido: PHP 8.3.33 (cli), built Jul 28 2026 18:10:28, ZTS Visual C++ 2019 x64; Zend Engine v4.3.33. Abaixo de 8.4.27 e do mínimo 8.4.1 do lock.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T0.4_bloqueio_php.png). Captura do login documenta o ambiente; dados específicos estão nos JSON/logs.

Causa provável: PATH e Laragon resolvem somente PHP 8.3.33.

Impacto: Impede homologação confiável do estado local atual.

Reproduzível: SIM para o bloqueio comum; verificação específica reproduzida por comandos/navegação documentados.

Prioridade sugerida: P1.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Verificação realizada; ver detalhes e logs.

### T0.6 — Dados de setembro

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Preparação. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Consultar contagens de registros ativos e funcionários com salário positivo; consultar setembro/2026 no Tenant 1.

Resultado esperado: Tenant A: três pendentes de setembro, dois clientes, um fornecedor e um funcionário com salário.

Resultado obtido: Tenant 1 tem três clientes ativos, três fornecedores ativos e dois funcionários ativos com salário positivo. Não há lançamentos ativos em setembro de 2026. Criação das fixtures não realizada porque todos os fluxos estão impedidos pelo bootstrap PHP. Ano assumido: 2026; Notion não forneceu ano.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T0.6_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T1.1 — Redirecionamento sem autenticação

Resultado: **FALHOU**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Não autenticado / papéis conforme roteiro. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Abrir /agente em contexto novo, sem cookies; observar URL, resposta, Console e capturar tela.

Resultado esperado: Redirecionar página interna para login.

Resultado obtido: Navegação sem autenticação a http://127.0.0.1:8000/agente recebeu HTTP 500; URL final igual à original, sem redirecionamento. platform_check.php exige PHP >=8.4.1. Duração da navegação 74 ms, não é tempo IA.

HTTP: 500 na navegação deste teste.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T1.1_bloqueio_php.png). Captura do login documenta o ambiente; dados específicos estão nos JSON/logs. Navegação original: screenshots/T1.1_php_bloqueado.png e network/T1.1.json.

Causa provável: Falha no autoloader anterior ao middleware de autenticação.

Impacto: Impede homologação confiável do estado local atual.

Reproduzível: SIM para o bloqueio comum; verificação específica reproduzida por comandos/navegação documentados.

Prioridade sugerida: P1.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Verificação realizada; ver detalhes e logs.

### T1.2 — Bloqueio de login

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Não autenticado / papéis conforme roteiro. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Bloqueio temporário, mensagem clara e nenhum 500.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T1.2_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T1.3 — Layout e acesso por papel

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Não autenticado / papéis conforme roteiro. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Papel visível; Auditoria somente para admin, com proteção no servidor.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T1.3_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T1.4 — Navegação por teclado

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Não autenticado / papéis conforme roteiro. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Skip link funcional e foco visível em login, menu, formulário e agente.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T1.4_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T1.5 — Página 404 própria

Resultado: **FALHOU**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Não autenticado / papéis conforme roteiro. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Abrir rota aleatória segura; observar URL, resposta, Console e capturar tela.

Resultado esperado: 404 próprio do SENTINEL.

Resultado obtido: Navegação a http://127.0.0.1:8000/qa574e359-rota-inexistente recebeu HTTP 500 e stack trace do PHP; URL final igual à original. Não há tela 404 própria. Duração 48 ms, não é tempo IA.

HTTP: 500 na navegação deste teste.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T1.5_bloqueio_php.png). Captura do login documenta o ambiente; dados específicos estão nos JSON/logs. Navegação original: screenshots/T1.5_php_bloqueado.png e network/T1.5.json.

Causa provável: Falha no autoloader anterior ao roteamento e renderização de erros.

Impacto: Impede homologação confiável do estado local atual.

Reproduzível: SIM para o bloqueio comum; verificação específica reproduzida por comandos/navegação documentados.

Prioridade sugerida: P1.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Verificação realizada; ver detalhes e logs.

### T1.6 — Sessão expirada e 419

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Não autenticado / papéis conforme roteiro. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: 419 próprio ao enviar formulário com sessão invalidada, sem 500.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T1.6_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T2.1 — Listagem de clientes do tenant

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Leitura / operador / admin. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Somente clientes do próprio tenant, conferidos no banco.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T2.1_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T2.2 — Pendentes de setembro

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Leitura / operador / admin. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: IDs, quantidades e valores corretos para setembro do tenant.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T2.2_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T2.3 — Cliente exclusivo do outro tenant

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Leitura / operador / admin. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Nenhum dado do Tenant B.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T2.3_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T2.4 — Mascaramento de salário

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Leitura / operador / admin. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Salário mascarado no browser e JSON para operador.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T2.4_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T2.5 — Pedido fora do domínio

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Leitura / operador / admin. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Recusa educada ou orientação às capacidades do sistema.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T2.5_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T3.1 — Proposta de cadastro sem gravação

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador (testes de negativa: leitura). Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Formulário pré-preenchido no chat sem INSERT.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T3.1_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T3.2 — Revisar sem gravar

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador (testes de negativa: leitura). Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Validar sem gravar e habilitar hold.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T3.2_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T3.3 — Hold inferior a 2 segundos

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador (testes de negativa: leitura). Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Soltar antes de 2 segundos não grava.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T3.3_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T3.4 — Hold completo e Auditoria

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador (testes de negativa: leitura). Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Hold completo grava uma vez e gera Auditoria.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T3.4_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T3.5 — Validação de campo inválido

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador (testes de negativa: leitura). Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Erro no campo e confirmação indisponível.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T3.5_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T3.6 — Editar invalida confirmação

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador (testes de negativa: leitura). Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Editar invalida revisão/token anterior; reuso recusado.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T3.6_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T3.7 — Expiração após 5 minutos

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador (testes de negativa: leitura). Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Após mais de 5 minutos a proposta não pode confirmar.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T3.7_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T3.8 — Cadastro negado para leitura

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador (testes de negativa: leitura). Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Leitura não cadastra; Auditoria registra negado.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T3.8_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T4.1 — Edição pelo chat

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador (testes de negativa: leitura). Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Dados atuais e gravação só depois de Revisar e hold.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T4.1_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T4.2 — Edição pela lista e RBAC

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador (testes de negativa: leitura). Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Editar disponível somente a autorizados; testar leitura.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T4.2_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T4.3 — Concorrência em duas abas

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador (testes de negativa: leitura). Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Segunda edição antiga recusada após primeira alteração.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T4.3_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T4.4 — Auditoria de valores anterior e novo

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador (testes de negativa: leitura). Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Auditoria mostra valor anterior e novo.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T4.4_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T5.1 — Proposta de lote somente leitura

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador (testes de negativa: leitura). Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional; schema com lote_id e lançamentos QA apropriados.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Card com período, categoria, quantidade, total e itens; sem edição e sem gravação.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T5.1_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T5.2 — Confirmação e lote_id comum

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador (testes de negativa: leitura). Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional; schema com lote_id e lançamentos QA apropriados.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: N itens conciliados; N linhas de Auditoria com o mesmo lote_id.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T5.2_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T5.3 — Período sem pendentes

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador (testes de negativa: leitura). Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional; schema com lote_id e lançamentos QA apropriados.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Somente texto, sem card de confirmação.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T5.3_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T5.4 — Limite superior a 25

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador (testes de negativa: leitura). Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional; schema com lote_id e lançamentos QA apropriados.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Mais de 25 exige restringir filtros, sem card.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T5.4_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T5.5 — Concorrência antes de Revisar

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador (testes de negativa: leitura). Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional; schema com lote_id e lançamentos QA apropriados.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Item alterado em vermelho; Revisar some; apenas Cancelar.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T5.5_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T5.6 — Atomicidade na confirmação

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador (testes de negativa: leitura). Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional; schema com lote_id e lançamentos QA apropriados.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Conflito recusa lote inteiro; demais itens pendentes; uma linha recusado.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T5.6_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T5.7 — Lote negado para leitura

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador (testes de negativa: leitura). Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional; schema com lote_id e lançamentos QA apropriados.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Leitura recebe negativa.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T5.7_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T6.1 — Exclusão lógica com confirmação

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador / admin conforme roteiro. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Cliente QA vai à Lixeira após confirmação.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T6.1_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T6.2 — Restauração

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador / admin conforme roteiro. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Cliente QA restaurado pela Lixeira ou chat.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T6.2_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T6.3 — Dependência pai e filho

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador / admin conforme roteiro. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Filho não restaura enquanto pai está na Lixeira.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T6.3_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T6.4 — Senha apenas na confirmação final

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador / admin conforme roteiro. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Admin recebe pedido de senha apenas na confirmação final.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T6.4_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T6.5 — Senha incorreta

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador / admin conforme roteiro. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Senha incorreta impede exclusão; registro permanece.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T6.5_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T6.6 — Rate limit da senha

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador / admin conforme roteiro. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Bloqueio temporário após limite, sem 500.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T6.6_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T6.7 — Exclusão definitiva negada para operador

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Operador / admin conforme roteiro. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Operador não realiza exclusão definitiva.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T6.7_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T7.1 — Listagem e comparação com banco

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Admin (7.4: operador). Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Ações anteriores aparecem e correspondem ao banco.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T7.1_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T7.2 — Filtros e paginação

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Admin (7.4: operador). Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Filtros individuais/combinados e paginação coerentes.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T7.2_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T7.3 — Exportação CSV e salário

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Admin (7.4: operador). Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: CSV legível, acentos corretos e nenhum salário exposto.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T7.3_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T7.4 — 403 próprio para operador

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Admin (7.4: operador). Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Operador recebe 403 próprio por acesso direto.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T7.4_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T8.1 — Cadastro e edição sem IA

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Conforme roteiro; não autenticado nesta execução. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Cadastro e edição com Revisar + hold sem POST ao agente.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T8.1_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T8.2 — Indisponibilidade externa e modo manual

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Conforme roteiro; não autenticado nesta execução. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Falha de IA compreensível sem 500; modo manual funciona; internet restaurada.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T8.2_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T9.1 — Prompt injection e exclusão

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Conforme roteiro; não autenticado nesta execução. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Nenhuma exclusão automática; RBAC e confirmação preservados.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T9.1_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T9.2 — Consulta a outra empresa

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Conforme roteiro; não autenticado nesta execução. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Nenhum dado de outra empresa.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T9.2_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T9.3 — Adulteração após Revisar

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Conforme roteiro; não autenticado nesta execução. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Payload adulterado recusado ou servidor usa dados revisados.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T9.3_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T9.4 — Adulteração do filtro de lote

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Conforme roteiro; não autenticado nesta execução. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Servidor mantém definição original do lote.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T9.4_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T9.5 — XSS em lista/chat/Auditoria/edição

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Conforme roteiro; não autenticado nesta execução. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Script tratado como texto em todas as quatro superfícies.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T9.5_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T9.6 — Rate limit do agente

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Conforme roteiro; não autenticado nesta execução. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: 429 compreensível no limite, sem 500.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T9.6_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T10.1 — Viewport de 360 px e overflow

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Conforme roteiro; não autenticado nesta execução. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Sem overflow horizontal da página em 360 px; tabelas rolam no container.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T10.1_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.

### T10.2 — Hold por toque

Resultado: **FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO**

Data/hora: 28/10/2026; inspeção inicial do ambiente e navegador entre 16:30 e 16:33 -03:00. Data de cada captura em screenshots/manifest.json; fim da consolidação 2026-10-28 16:37:24.

Papel: Conforme roteiro; não autenticado nesta execução. Papel efetivamente autenticado: nenhum. Tenant: Tenant 1 para preparação; Tenant 2 somente contagem de perfis; nenhum contexto autenticado.

Pré-condição: PHP compatível e bootstrap funcional.

Passos realizados: Verificar disponibilidade da aplicação pelo login e rotas principais. Suspender este fluxo dependente após confirmação de bloqueio global. Não enviar comandos nem alterar dados.

Resultado esperado: Hold funciona por toque e soltar antes do prazo não confirma.

Resultado obtido: Fluxo não executado. Login e bootstrap indisponíveis: HTTP 500 por PHP 8.3.33 incompatível com dependências >=8.4.1. Nenhum comportamento próprio deste teste foi validado.

HTTP: 500 no login usado como pré-condição; nenhuma requisição específica do fluxo dependente.

Console: erros de recurso HTTP 500 nas navegações. Nenhum JavaScript da aplicação pôde ser avaliado.

Log: platform_check.php:22; erros antes da inicialização do logger Laravel. storage/logs/laravel.log não recebeu eventos da execução. correlation_id não gerado. Logs relevantes em logs/php_http_server.txt, composer_install.txt e migrate_status.txt.

Evidência: [captura](C:/Temp/SENTINEL_QA/574e359/20261028_QA01/screenshots/T10.2_bloqueio_php.png). Captura do impedimento comum em /login, não do fluxo solicitado.

Causa provável: Pré-condição global PHP; nenhuma causa funcional específica estabelecida.

Impacto: Impede avaliar este teste; resultado funcional permanece desconhecido.

Reproduzível: SIM para o bloqueio comum; fluxo específico NÃO executado.

Prioridade sugerida: Sem prioridade própria; depende de T0.4.

Registro Notion: NÃO. Banco de destino não localizado no workspace acessível; sem link/ID de registro.

Observações: Bloqueio comum confirmado, sem atribuir defeito funcional ao fluxo não executado.


## Testes aprovados

T0.5: perfis exigidos existem nos dois tenants (contagens no banco); credenciais e login não validados. Como verificações parciais, npm ci e build concluíram; não tornam T0.1 aprovado, pois Composer falhou. Banco confirmado local; nenhuma alteração nele foi efetuada.

## RNF04 — tempos da IA

| Teste | Pedido | Papel | Horário | Tempo HTTP | Tempo percebido | <=10s? |
|---|---|---|---|---|---|---|

Nenhuma chamada enviada a POST /agente/comando: 0 observações. Não há cold start nem outliers omitidos. Mínimo, máximo, média, mediana e P95: não calculáveis. CSV contém somente cabeçalho; nenhum zero artificial foi usado como duração. A regra de 10.000 ms será aplicada quando houver amostras. Tempos de navegação HTTP neste relatório são do erro de bootstrap e não da IA.

## Isolamento de tenant

T2.1, T2.3 e T9.2: bloqueados. SQL de preparação contou os perfis por tenant; isso não valida isolamento da aplicação. Nenhuma consulta cross-tenant foi enviada ao agente.

## RBAC

Leitura, operador e admin existem no Tenant 1, admin no Tenant 2. Nenhum papel foi autenticado. Menus, proteção de rotas, permissões e negativas não avaliados. Nenhuma regra RBAC foi alterada.

## Confirmação humana

T3.2, T3.3, T3.4, T3.6, T3.7, T5.2, T5.6, T6.4, T9.3 e T10.2 bloqueados. Não houve proposta, revisão, hold, token, espera de TTL ou adulteração. Nenhuma confirmação removida ou contornada.

## RF03 — conciliação em lote

T5.1–T5.7 bloqueados. Atomicidade, limite 25, concorrência, Auditoria e RBAC não demonstrados. Ausência física de lote_id constatada por information_schema; schema precisa chegar à migration 16 antes de repetir a bateria. Não foi conciliado nenhum item.

## Segurança

Prompt injection, tenant, tampering, XSS, rate limit, CSRF/sessão e exclusão definitiva: não avaliados por bloqueio. T0.3 encontrou debug habilitado e sessão sem criptografia no .env; não se comprovou carregamento desses valores pelo Laravel. A página de falha exibe caminhos locais e stack trace; nenhum segredo ou salário foi encontrado nas evidências coletadas. Não há evidência de banco de produção. TLS, CSRF, segurança e permissões preservados.

Achado complementar de dependências: npm audit reportou concurrently/shell-quote como critical e source-map-js/vite como high, total de quatro pacotes afetados; relações transitivas não significam quatro vulnerabilidades distintas nem quatro explorações demonstradas. Ver logs/npm_audit.json para versões/faixas e avisos oficiais. Não foi executado audit fix, update ou exploração. Classificação de exposição requer análise posterior.

## Acessibilidade

Skip link, foco, teclado, mensagens e agente não avaliados: nenhuma tela de aplicação foi carregada. A página de erro PHP não serve de amostra de acessibilidade do SENTINEL.

## Responsividade

Captura em 360 px efetuada somente na página de erro de /agente: scrollWidth=499 e clientWidth=360. O overflow é da stack trace de bootstrap, não prova defeito das telas reais do sistema. Agente, cadastros, Auditoria, cards, tabelas e Lixeira não renderizaram. Touch hold não executado. T10.1 e T10.2 permanecem bloqueados.

## Modo manual

Não avaliado online/offline: /cadastros recebeu 500. Não foi desligada a rede externa, pois o bloqueio PHP antecede a IA e a mudança não produziria teste válido. Internet e MySQL permaneceram ativos.

## Auditoria

/auditoria recebeu 500 sem autenticação; não é avaliação de RBAC. Listagem, filtros, paginação, CSV, anterior/novo, negados, lote_id e salário mascarado bloqueados. exports/ está vazio. Não foram gerados Audit Logs de ações QA.

## Regressão automatizada

- php artisan test --compact: comando executado, falha fatal no autoload antes do runner. Testes, assertions, failures e skipped: indisponíveis; nenhuma suíte executada e nenhum resultado de teste inventado.
- npm run build: OK, primeira execução 8,00 s e execução final 1,83 s; 15 módulos transformados.
- php artisan migrate:status: comando executado, falha fatal na versão PHP. PDO complementar leu 13 migrations aplicadas e três pendentes.

## Console e Network

GET /login: 500, navegação 212 ms. GET /agente: 500, 74 ms. GET rota aleatória: 500, 48 ms. /cadastros: 500, 44 ms. /auditoria: 500, 45 ms. /lixeira: 500, 39 ms. Tempos obtidos entre início do goto e término do load, não tempos HTTP puros nem RNF04. URL final preservada em todos. Capturas não demonstram proteção de rotas, pois o bootstrap falhou antes do roteador. Console registrou recursos 500; não houve código JS da aplicação a avaliar. Nenhum 400/401/403/404/419/422/429/502/503/504 funcional foi produzido.

## Logs

laravel.log: 18.290 bytes, última escrita em 19/10/2026 13:05:30 -03:00; zero entradas da data desta execução. Erros históricos não foram atribuídos à bateria. As falhas atuais estão no stderr do PHP e nos logs de Composer/Artisan, sempre anteriores ao logger Laravel. Não há correlation_id de aplicação disponível. Evidências não contêm request headers, cookies, session IDs, tokens, senhas ou API keys.

## Alterações realizadas no ambiente

- Git: atualização de refs remotas via pull --ff-only; HEAD e branch preservados.
- Dependências npm: node_modules reconstruído por npm ci (57 pacotes); public/build regenerado pelos builds. Arquivos ignorados; fontes e lockfiles sem alterações.
- PHP/Composer: instalação recusada; nenhuma correção, atualização ou bypass de platform_check aplicado.
- Banco: somente SELECT/SHOW, sem INSERT/UPDATE/DELETE, sem migrations, sem fixtures e sem criação de usuários; dados existentes preservados. Nenhuma senha criada ou redefinida.
- Servidor PHP temporário iniciado em 127.0.0.1:8000 para reproduzir o erro e encerrado ao terminar. Apache e MySQL preexistentes preservados.
- Browser Edge temporário usado para evidências e fechado. Nenhuma sessão QA autenticada; logout não aplicável.
- Nenhuma configuração temporária alterada; .env intacto. Internet não desligada.
- Quatro relatórios no projeto, sem commit. Scripts de coleta, screenshots, JSON e logs somente na pasta externa de evidências.
- Audit Logs e exports: nenhum gerado. Evidências preservadas.

## Notion

Conexão disponível, busca e fetch funcionais, porém o banco do projeto não foi localizado no workspace acessível. Buscas: Sentinel; 574e359; RF03; RNF04; lote_id; conciliação em lote; Auditoria; Lixeira; modo manual; confirmação humana; segurança; Divergências e Ajustes. Resultados vazios ou página irrelevante Bem-vindo ao Notion!, inspecionada em https://app.notion.com/p/296877fb3070801598a1c894cd3145ab. Nenhum requisito SENTINEL recuperado. Não se afirma ausência do banco em outros workspaces/permissões.

Divergência entre roteiro 574e359 e documentação atual do Notion: não comparável, pois nenhuma documentação do projeto foi encontrada. O roteiro permaneceu como matriz principal. Nenhum registro criado, nenhuma propriedade ou banco inventado. Deduplicação por Test ID + commit + problema não pôde ser concluída sem o banco; deve preceder importação manual.

**Registro automático no Notion não realizado.**

Arquivo NOTION_DIVERGENCIAS_574e359.md preparado com 56 itens, distinguindo seis falhas constatadas de 50 bloqueios. A conexão existe; indisponibilidade é especificamente do destino/documentação do projeto nesta conexão.

## Ordem sugerida de correção

Somente recomendações, sem implementação nesta execução:

1. P1 — Disponibilizar runtime PHP compatível com roteiro e dependências; elimina bloqueios T0.1/T0.4/T1.1/T1.5 e permite reteste dos 50 itens dependentes.
2. P1 — Após o runtime, aplicar migrations pendentes de forma não destrutiva para alcançar schema real da RF03 (T0.2).
3. P2 — Adequar APP_DEBUG e SESSION_ENCRYPT ao roteiro e verificar valores carregados em runtime (T0.3).
4. Repetir preparação T0.6 com fixtures QA de setembro/2026 e então a bateria completa; nenhuma falha funcional dos fluxos impedidos foi presumida.
5. Investigar o achado complementar npm audit, começando pelos pacotes classificados critical; avaliar exposição antes de atribuir prioridade de produto.

## Conclusão

SISTEMA APTO PARA CONTINUAR HOMOLOGAÇÃO: **NÃO**.

Nos itens abaixo, FALHOU por pré-condição significa ausência de validação, e não comprovação de defeito da regra de negócio:

| Critério | Resultado |
|---|---|
| ISOLAMENTO DE TENANT | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO |
| RBAC | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO |
| CADASTRO VIA CHAT | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO |
| EDIÇÃO | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO |
| RF03 | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO (schema também incompleto) |
| LIXEIRA | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO |
| AUDITORIA | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO |
| MODO MANUAL | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO |
| SEGURANÇA | FALHOU (T0.3); demais controles bloqueados |
| RESPONSIVO | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO |
| RNF04 <= 10s | FALHOU — BLOQUEADO POR PRÉ-CONDIÇÃO; zero chamadas |

## Arquivos finais e integridade

Relatório: RELATORIO_TESTE_MANUAL_SENTINEL_574e359.md
Resultados: RESULTADOS_TESTES_574e359.csv (57 linhas de dados, UTF-8 BOM)
RNF04: TEMPOS_IA_RNF04_574e359.csv (cabeçalho somente, UTF-8 BOM)
Registro manual: NOTION_DIVERGENCIAS_574e359.md

Git final:

```text
?? NOTION_DIVERGENCIAS_574e359.md
?? RELATORIO_TESTE_MANUAL_SENTINEL_574e359.md
?? RESULTADOS_TESTES_574e359.csv
?? TEMPOS_IA_RNF04_574e359.csv
```

Comparação: status inicial limpo; final contém somente os quatro relatórios não rastreados acima. git diff --numstat vazio (nenhum arquivo rastreado alterado). Nenhum código-fonte alterado pelo executor; nenhuma correção, commit ou push.
