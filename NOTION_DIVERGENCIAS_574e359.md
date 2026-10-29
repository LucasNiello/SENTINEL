# Divergências SENTINEL — roteiro 574e359

Registro automático no Notion não realizado. Conexão respondeu, mas o banco 🔍 Divergências e Ajustes não foi encontrado no workspace acessível. Não criar banco/propriedades novos. Antes da importação, deduplicar por Test ID + commit + problema e mapear às propriedades existentes; campos sem correspondência devem ir ao corpo.

Branch: malaman. Commit: 574e3597c13f840fc49517c0e83c065c7cc098fd. Ambiente: APP_ENV=local; MySQL 127.0.0.1:3306/sentinel; PHP 8.3.33. Data: 28/10/2026, UTC−03:00.

56 registros propostos: seis falhas observadas e 50 bloqueios de cobertura; não interpretar os bloqueios como defeitos funcionais independentes.

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

Chave de deduplicação: T0.1 + 574e3597c13f840fc49517c0e83c065c7cc098fd + Dependências e build.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T0.2 + 574e3597c13f840fc49517c0e83c065c7cc098fd + Schema e migrations.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T0.3 + 574e3597c13f840fc49517c0e83c065c7cc098fd + Configuração de segurança e Azure.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T0.4 + 574e3597c13f840fc49517c0e83c065c7cc098fd + Versão PHP.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T0.6 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T1.1 + 574e3597c13f840fc49517c0e83c065c7cc098fd + Redirecionamento sem autenticação.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T1.2 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T1.3 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T1.4 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T1.5 + 574e3597c13f840fc49517c0e83c065c7cc098fd + Página 404 própria.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T1.6 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T2.1 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T2.2 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T2.3 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T2.4 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T2.5 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T3.1 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T3.2 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T3.3 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T3.4 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T3.5 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T3.6 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T3.7 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T3.8 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T4.1 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T4.2 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T4.3 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T4.4 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T5.1 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T5.2 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T5.3 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T5.4 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T5.5 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T5.6 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T5.7 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T6.1 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T6.2 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T6.3 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T6.4 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T6.5 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T6.6 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T6.7 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T7.1 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T7.2 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T7.3 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T7.4 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T8.1 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T8.2 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T9.1 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T9.2 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T9.3 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T9.4 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T9.5 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T9.6 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T10.1 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---

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

Chave de deduplicação: T10.2 + 574e3597c13f840fc49517c0e83c065c7cc098fd + bootstrap PHP impede fluxo.

Commit testado: 574e3597c13f840fc49517c0e83c065c7cc098fd. Branch: malaman. Ambiente: local, URL http://127.0.0.1:8000, PHP 8.3.33, MySQL sentinel local.

---
