# Relatório de Testes — SENTINEL

Data: 01/10/2026, a partir de 16h00 (America/Sao_Paulo).  
Branch: `malaman`, HEAD `1e78ff6`.  
Ambiente: Windows, desenvolvimento local, Laragon; execução PHP CLI e navegadores automatizados.  
Laravel: 13.31.0. PHP: 8.5.11. Node: 24.21.0. Banco: MySQL 8.4.3.

## Resumo executivo

Testes executados: **195 casos/cenários contabilizados**.  
Aprovados: **193**.  
Falharam: **2 cenários adicionais de navegador**.  
Não executados: **9 grupos**, discriminados adiante.  
Críticos: **0 encontrados**. Altos: **1**. Médios: **3**. Baixos: **2**.

O sistema inicia, conecta ao banco e passa em toda a suíte PHP, mas a confirmação por teclado do agente e a suspensão da renderização invisível apresentam falhas reproduzíveis. Outros quatro achados são de inspeção estática ou qualidade de interface. Não foi feita nenhuma correção.

### Critério de contagem e limites

| Conjunto | Executados | Aprovados | Falharam |
|---|---:|---:|---:|
| PHPUnit: testes existentes, SQLite em memória e mocks | 149 | 149 | 0 |
| HTTP real, login e responsividade do login | 18 | 18 | 0 |
| Vitrine: cenários agrupados de responsividade, interação, fallback e navegadores disponíveis | 15 | 15 | 0 |
| Agente: quatro resoluções e dois cenários de confirmação, em view isolada | 6 | 5 | 1 |
| WebGL: contexto, canvas único, fallback oculto, renderização ativa, continuidade, transição, suspensão final | 7 | 6 | 1 |
| **Total** | **195** | **193** | **2** |

As 1.629 assertions pertencem exclusivamente ao PHPUnit. Inspeções de ambiente, código, logs e segurança não foram somadas como testes individuais. O número de achados difere do número de testes falhos porque inclui análise estática. Os dois navegadores indisponíveis não contam como falha do aplicativo. Esta bateria não comprova ausência de todos os defeitos nem substitui execução autenticada real, auditoria WCAG ou pentest.

## Tabela de problemas

| ID | Prioridade | Área | Problema | Evidência | Causa provável |
|---|---|---|---|---|---|
| SEN-001 | ALTO | Confirmação / acessibilidade | Hold do agente continua após perda de foco | Um POST simulado após Space → Tab → soltar Space | Ausência de cancelamento em `blur` |
| SEN-002 | MÉDIO | Vitrine / performance | WebGL continua renderizando com canvas invisível no final | `pausedOutsideStory: false`, opacidade final zero | RAF condicionado à interseção, não à visibilidade efetiva |
| SEN-003 | MÉDIO | Banco / seeders | Reexecução pode duplicar dados de domínio | Seis seeders usam `create()` sem deduplicação | Ausência de chave/idempotência na carga de exemplos |
| SEN-004 | MÉDIO | Banco / isolamento | Seeder de notas pode associar entidade de outro tenant | Busca por nome sem `tenant_id`; nota sempre tenant 1 | Resolução de relacionamento sem escopo |
| SEN-005 | BAIXO | Login / idioma | Validação do servidor retorna inglês | HTTP 422: `The email field is required.` | Locale `en`, regras sem mensagens traduzidas |
| SEN-006 | BAIXO | Agente / acessibilidade | Campo de comando sem rótulo persistente | Sem `label`, `aria-label` ou `aria-labelledby`; apenas placeholder | Identificação do campo depende do placeholder |

## SEN-001 — Confirmação continua após sair do botão pelo teclado

Prioridade: **ALTO**. Área: confirmação humana do agente.

Teste realizado: renderização da view Blade em arquivo de apoio, navegador com todas as requisições interceptadas e respostas simuladas. Propor uma ação, focar Confirmar, manter Space por aproximadamente 150 ms, pressionar Tab, soltar Space e aguardar 2,2 segundos.

Resultado esperado: interromper o hold ao perder foco, sem enviar confirmação.

Resultado obtido: **uma requisição POST de confirmação simulada**. Soltar Space sem mudar o foco interrompe corretamente.

Erro: o temporizador continua ativo; não há exceção JavaScript. A falha está na interação, não no middleware de autenticação.

Evidência: `.sentinel/audit/agent-browser.json`, cenário `hold cancels on blur`, `ok: false`, `confirmations: 1`.

Causa provável: `keyup` está associado apenas ao botão original; após Tab, ocorre em outro elemento. Não existe handler de `blur` para `soltarHold()`.

Arquivos possivelmente envolvidos: `resources/views/agente.blade.php`, funções `iniciarHold`, `soltarHold`, `passoHold` e listeners do botão.

Impacto: uma ação pendente pode receber confirmação quando a pessoa já abandonou o controle. Não foi executada escrita real nem comprovado bypass dos controles de token, prazo ou RBAC do backend.

Sugestão de investigação: revisar cancelamento por mudança de foco, visibilidade, navegação por teclado e fechamento do card, preservando as validações do servidor. **Não corrigido.**

## SEN-002 — Renderização persiste quando a cena está invisível

Prioridade: **MÉDIO**. Área: performance da vitrine.

Teste realizado: instrumentar chamadas `WebGL2RenderingContext.drawElements`, percorrer as seções até a área final e comparar contagens com intervalo de 500 ms após estabilização.

Resultado esperado: suspender trabalho gráfico quando o canvas estiver totalmente oculto.

Resultado obtido: a contagem continua aumentando. A transição chega a `wash: 1` e `canvas: 0`; `pausedOutsideStory` é falso.

Erro: processamento gráfico sem resultado visível, sem exceção no console.

Evidência: `.sentinel/browser-tests/scene-v2-results.json` e `.sentinel/audit/webgl-output.txt`.

Causa provável: `render()` agenda novo RAF enquanto o estágio intersecta a viewport e o documento está visível. A opacidade calculada por `storyPose()` não interrompe esse fluxo.

Arquivos possivelmente envolvidos: `resources/js/lighthouse/scene.js`, `motion.js`, `resources/css/landing.css`.

Impacto: consumo desnecessário de CPU/GPU e bateria. Não foi medido consumo energético nem percentual de GPU; não há base para quantificar a perda.

Sugestão de investigação: correlacionar interseção do estágio, progresso narrativo e visibilidade final; verificar suspensão e retomada. **Não corrigido.**

## SEN-003 — Seeders de domínio não são idempotentes

Prioridade: **MÉDIO**. Área: carga de exemplos.

Teste realizado: inspeção dos seeders e migrations, **sem executar seed**.

Resultado esperado: uma rotina reutilizável de preparação local deve evitar duplicações ou comunicar explicitamente essa limitação.

Resultado obtido: os seis seeders criam novas linhas incondicionalmente por `Model::create()`. A proteção de usuários em `sentinel:preparar-acesso` não se estende aos dados de domínio.

Erro: risco estático confirmado no fluxo; duplicação real não foi provocada nesta bateria.

Evidência: `database/seeders/{Lancamento,CategoriaLancamento,Cliente,Fornecedor,Funcionario,NotaFiscal}Seeder.php`, invocados pelo `DatabaseSeeder`.

Causa provável: cargas demonstrativas escritas para primeira execução, sem identificação e reaproveitamento de registros.

Arquivos possivelmente envolvidos: os sete seeders acima e migrations das entidades.

Impacto: reexecutar a carga pode duplicar lançamentos, cadastros e notas, alterando resultados de consultas e totais.

Sugestão de investigação: definir contrato de reexecução e critérios de identidade dos exemplos antes de alterar a carga. **Não corrigido.**

## SEN-004 — Associação de notas no seeder não filtra tenant

Prioridade: **MÉDIO**. Área: isolamento entre empresas na carga local.

Teste realizado: inspeção de `NotaFiscalSeeder` e dos models Cliente e Fornecedor; nenhum seeder executado.

Resultado esperado: relacionamentos de uma nota tenant 1 devem ser resolvidos dentro do mesmo tenant.

Resultado obtido: `where('nome', ...)->first()` pesquisa globalmente e a nota recebe `tenant_id => 1`. Esses models não possuem global scope que faça o filtro automaticamente.

Erro: possibilidade de associação cruzada quando houver nomes iguais em empresas distintas; **não foi constatada ocorrência nos registros atuais**.

Evidência: `database/seeders/NotaFiscalSeeder.php`, resolução de `$clienteId` e `$fornecedorId`.

Causa provável: dependência de nomes de exemplo únicos no banco inteiro.

Arquivos possivelmente envolvidos: `NotaFiscalSeeder.php`, `app/Models/Cliente.php`, `app/Models/Fornecedor.php`.

Impacto: inconsistência de referências em reuso da carga local. A classificação não significa que consultas do agente vazem dados: o escopo de tenant do fluxo operacional possui testes aprovados.

Sugestão de investigação: revisar seleção de relacionamentos e cenário de homônimos entre tenants em banco descartável. **Não corrigido.**

## SEN-005 — Validação do login em inglês

Prioridade: **BAIXO**. Área: UX / localização.

Teste realizado: POST `/login` com CSRF válido, `Accept: application/json` e campos vazios.

Resultado esperado: rejeição 422 com mensagens coerentes com a interface em português.

Resultado obtido: rejeição correta, mas mensagens `The email field is required.` e `The password field is required.`. O formulário HTML possui validação nativa, que pode impedir esse envio no uso comum.

Erro: inconsistência de idioma; a validação continua funcionando.

Evidência: `.sentinel/audit/http-browser.json`, campo `validation`.

Causa provável: locale efetivo `en` e mensagens padrão do Validator.

Arquivos possivelmente envolvidos: `config/app.php`, `app/Http/Controllers/AutenticacaoController.php`, arquivos de tradução.

Impacto: mensagens técnicas em idioma diferente da interface em caminhos de validação do servidor.

Sugestão de investigação: revisar localização das mensagens de autenticação sem mudar a proteção. **Não corrigido.**

## SEN-006 — Campo do agente depende exclusivamente do placeholder

Prioridade: **BAIXO**. Área: acessibilidade básica.

Teste realizado: inspeção do DOM da view isolada nas quatro resoluções solicitadas.

Resultado esperado: identificação persistente e programática do campo de comando.

Resultado obtido: `labels.length === 0`, sem `aria-label`/`aria-labelledby`, apenas placeholder, que desaparece durante a digitação.

Erro: ausência de rótulo persistente. Alguns navegadores podem usar placeholder como nome de acessibilidade; não se afirma ausência universal de nome acessível.

Evidência: `.sentinel/audit/agent-browser.json` e `agent-390.png`.

Causa provável: interface mínima apoiada apenas no texto de exemplo.

Arquivos possivelmente envolvidos: `resources/views/agente.blade.php`, `#campo-mensagem`.

Impacto: orientação reduzida durante preenchimento e experiência inconsistente com tecnologia assistiva.

Sugestão de investigação: avaliar identificação do campo com teclado e leitor de tela. **Não corrigido.**

## Testes aprovados

- **PHPUnit:** 149 testes, 1.629 assertions, zero failures/errors/skipped; duração informada de 11,868 s. Evidências: `.sentinel/audit/phpunit-output.txt` e `phpunit.xml`.
- Autenticação isolada: login válido, regeneração de sessão, senha incorreta/e-mail inexistente com mesma resposta, bloqueio da sexta tentativa, arrays inválidos sem 500, recusa de usuário sem papel/tenant, logout e proteção das rotas.
- Regras de negócio isoladas: criação e consulta das seis entidades; filtros e atualização de status; validação de argumentos, catálogo de tools, RBAC, isolamento por tenant, confirmação única/prazo, auditoria e mascaramento; rollback quando auditoria falha; bloqueio de exclusões com dependências, lixeira, restauração condicionada à reautenticação e arquivamento.
- Preparação local: testes de criação segura de usuários e preservação de usuários existentes. Esses testes utilizam banco de teste; o comando de preparação não foi executado no MySQL local.
- HTTP real: `/up`, `/`, `/login`; rejeição de login inválido; proteção de `/agente`, POSTs internos e logout; CSRF ativo.
- Vitrine: cinco resoluções, três CTAs levando ao login, hold demonstrativo por mouse/teclado/toque, cancelamento e nenhuma escrita; Firefox e Chrome for Testing disponíveis.
- Fallback: JavaScript desabilitado, WebGL indisponível, movimento reduzido, importação bloqueada e perda de contexto. Conteúdo e links permanecem disponíveis; loader não bloqueia.
- Build, requisitos Composer, conexão MySQL e migrations aprovados.

## Testes não executados

| Grupo | Motivo e cobertura alternativa |
|---|---|
| 1. Login válido no banco real | Existem quatro contas locais, mas nenhuma senha legitimamente disponível para esta execução; não criadas/resetadas. Login válido passou no PHPUnit. |
| 2. Logout após login real | Depende do grupo anterior; passou no PHPUnit. |
| 3. `/agente` autenticado em HTTP real, consultas e auditoria ponta a ponta | Depende da credencial; consultas do agente também gravam auditoria. View testada isoladamente, não apresentada como sessão autenticada real. |
| 4. Azure real | Serviço externo pago; apenas presença da configuração, análise do cliente HTTP e mocks existentes. |
| 5. Escritas de negócio no MySQL, exclusão/restauração/arquivamento, migrations e seeders | NÃO TESTADO no banco real — risco de alterar dados. Cobertura de serviços em SQLite descartável; seeders somente inspecionados. |
| 6. Chrome instalado | `browser.newPage: Protocol error (Target.createTarget): Failed to open a new tab`; infraestrutura do navegador. Chrome for Testing funcionou. |
| 7. Edge instalado | Mesmo erro de criação de aba; não permite concluir incompatibilidade da aplicação. |
| 8. Lint/test frontend por npm | `package.json` contém apenas `build` e `dev`; scripts de validação inexistentes. |
| 9. Operação externa prolongada | Não executados worker real, disparo do scheduler, carga concorrente, leitor de tela e medição física de GPU. Disparos podem alterar dados; avaliação completa depende de escopo/ambiente próprio. |

## Estado do ambiente

| Item | Resultado |
|---|---|
| PHP / ini | 8.5.11 ZTS x64; `C:\php\php.ini`; sem ini adicionais |
| Composer | 2.10.3; `check-platform-reqs` aprovado |
| Node / npm | 24.21.0 / 11.19.0; usado `npm.cmd`, pois o wrapper PowerShell está bloqueado pela política local |
| Laravel | 13.31.0; ambiente local; debug habilitado; manutenção desligada |
| MySQL / Database | 8.4.3; conexão mysql local funcional |
| Session / Cache / Queue | `database` / `database` / `database` |
| Cache de configuração | Não habilitado; views compiladas presentes |
| Vite / Three.js | 8.2.2 / 0.186.1 |
| Tailwind / plugin Laravel | 4.3.3 / laravel-vite-plugin 3.2.0 |
| Outras dependências diretas | @tailwindcss/vite 4.3.3, concurrently 10.0.5 |
| Azure Config | endpoint presente; deployment presente; API key presente |
| Git | malaman; sete commits à frente da referência local origin/malaman, sem fetch nesta tarefa |

Extensões relevantes presentes: `pdo_mysql`, `pdo_sqlite`, `mysqli`, `mbstring`, `openssl`, `curl`, `fileinfo`, `zip`, `dom`, `xml`, `ctype`, `tokenizer`, `bcmath`, OPcache. Nenhum requisito de plataforma ausente. `pdo_pgsql` não está carregado, mas o banco atual é MySQL; isso não é falha deste ambiente.

`npm ls --depth=0` informa `UNMET OPTIONAL DEPENDENCY @laravel/multiplex@^0.4.1`. É opcional, não impediu build; registrar como WARNING de dependência, sem inventar falha funcional. Não foi demonstrada dependência direta duplicada ou inutilizada; presença no manifesto não comprova uso em todos os fluxos. Nenhuma dependência atualizada/removida.

`public/storage` não está vinculado, segundo `artisan about`; nenhum asset testado dependeu desse link. Não classificado como defeito sem uso demonstrado.

### Presença das variáveis

| Variável | Estado |
|---|---|
| APP_NAME | presente |
| APP_ENV | presente |
| APP_KEY | presente |
| APP_DEBUG | presente |
| APP_URL | presente |
| DB_CONNECTION | presente |
| DB_HOST | presente |
| DB_PORT | presente |
| DB_DATABASE | presente |
| DB_USERNAME | presente |
| DB_PASSWORD | vazio |
| SESSION_DRIVER | presente |
| CACHE_STORE | presente |
| QUEUE_CONNECTION | presente |
| AZURE_FOUNDRY_ENDPOINT | presente |
| AZURE_FOUNDRY_API_KEY | presente |
| AZURE_FOUNDRY_DEPLOYMENT | presente |

## Banco de dados

Conexão: aprovada. Banco encontrado. Migrations: **13 aplicadas, zero pendentes**, batch 1. Nenhuma migration ou carga foi executada contra o MySQL.

| Tabelas / finalidade | Estado |
|---|---|
| migrations | Presente, 13 registros |
| users | Presente, 4 usuários; 2 admin, 1 operador, 1 leitura; nenhum sem papel/tenant |
| sessions | Presente; 48 registros iniciais, 59 na conferência posterior |
| cache / cache_locks | Presentes; cache de 4 para 8 entradas, locks zero |
| jobs / job_batches / failed_jobs | Presentes, zero registros |
| password_reset_tokens | Presente; não implica existência de fluxo público de recuperação |
| clientes / fornecedores / funcionarios | Presentes; 4 / 3 / 2 registros |
| categorias_lancamento / lancamentos / notas_fiscais | Presentes; 4 / 7 / 4 registros |
| audit_logs | Presente, zero registros no banco real |

Contagens de usuários, entidades e auditoria permaneceram iguais. Sessões e cache foram atualizados naturalmente pelo Laravel durante navegação e tentativas de login; não houve edição manual dessas tabelas. Igualdade de contagens não é comparação integral de todos os valores.

### Seeders

| Seeder | Finalidade | Usuários | Idempotência aparente |
|---|---|---|---|
| DatabaseSeeder | Preparação local e chamada dos seis seeders | Delega a `sentinel:preparar-acesso --exemplos` | Parcial: usuários preservados, domínio não |
| LancamentoSeeder | Lançamentos de exemplo | Não | Não |
| CategoriaLancamentoSeeder | Categorias de receita/despesa | Não | Não |
| ClienteSeeder | Clientes, incluindo outro tenant | Não | Não |
| FornecedorSeeder | Fornecedores | Não | Não |
| FuncionarioSeeder | Funcionários | Não | Não |
| NotaFiscalSeeder | Notas e associações | Não | Não; ver SEN-004 |

O comando de preparação utiliza senha aleatória e hash, preservando contas existentes. Não foi invocado nesta auditoria.

## Autenticação

`/login`: HTTP 200 e renderização sem erro. Login válido real: **NÃO EXECUTADO**, não marcado como falha. Login inválido: **OK**, mensagem “E-mail ou senha inválidos.”, permanece convidado. A sessão técnica do visitante pode existir; não houve sessão autenticada indevida.

Logout real autenticado: não executado; teste automatizado aprovado. Sucesso no login redireciona para destino pretendido ou `/agente`. Login usa `guest`; áreas internas usam `auth`. Sessão é regenerada no login e invalidada no logout, com renovação de CSRF. `User` oculta password/remember_token e utiliza cast `hashed`; os quatro hashes atuais foram identificados como bcrypt, sem divulgá-los.

### Rotas e respostas

| Método | URI | Nome | Middleware / controle | Observação |
|---|---|---|---|---|
| GET/HEAD | / | — | web | 200 |
| GET/HEAD | /login | login | web, guest | 200 |
| POST | /login | — | web, guest, throttle:login | Inválido rejeitado; 422 para campos vazios; 419 sem CSRF |
| POST | /logout | — | web, auth | 401 JSON sem autenticação |
| GET/HEAD | /agente | — | web, auth | 302 para /login sem autenticação |
| POST | /agente/comando | — | web, auth, throttle:agente | 401 JSON sem autenticação |
| POST | /agente/confirmar | — | web, auth | 401 JSON sem autenticação |
| GET/HEAD | /up | — | health | 200 em aproximadamente 98 ms |
| GET/HEAD | /storage/{path} | storage.local | Handler do filesystem | Arquivos privados exigem assinatura válida no handler |
| PUT | /storage/{path} | storage.local.upload | Handler do filesystem | Rota de upload do framework; não exercitada com escrita |
| GET | /dashboard, /admin | inexistentes | — | 404 esperado; não existem no route:list |

Fonte desta execução: `.sentinel/audit/environment.json`, saída `route:list --json`.

Nenhum 500, CORS ou 404 inesperado observado nas páginas/recursos normais. 401, 419 e 422 foram respostas esperadas dos cenários negativos. 403 por RBAC e 429 por limite foram cobertos nos testes PHP, não provocados por carga no serviço real.

## Front-end

`npm run build`: **sucesso**, 1,19 s, 12 módulos transformados, sem erro/warning de build. Principais assets: landing JS 7,65 kB (gzip 3,14), landing CSS 20,69 kB (gzip 5,37), Three core 188,89 kB (gzip 50,24), scene 359,55 kB (gzip 87,77), app CSS 22,21 kB e fontes Instrument Sans. O bundle do farol soma aproximadamente 548 kB sem compressão; não foi classificado automaticamente como bug por tamanho.

Three.js instalado e incorporado ao build. Um canvas, contexto WebGL2 válido, draw calls observadas (945 no primeiro snapshot), renderização contínua e mesmo canvas ao atravessar seções. Quando WebGL está ativo, fallback possui opacidade zero: não aparece sobreposto. Fallback assume corretamente nos cenários simulados. A falha de pausa é SEN-002.

Console normal: **zero ERROR de aplicação**, quatro WARNING do driver headless “GPU stall due to ReadPixels”, encerrando com aviso de que não repetirá. Não comprovam defeito de shader ou GPU física. Importação abortada e contexto perdido foram falhas injetadas para testar recuperação, não indisponibilidade real de assets.

Responsividade: `/` e `/login` em 1920×1080, 1366×768, 1024×768 e 390×844, sem overflow horizontal; vitrine também em 768×1024. View do agente passou nessas quatro dimensões em renderização isolada. Screenshots revisados em desktop/mobile; nenhum corte ou sobreposição clara constatado nos estados capturados. Não é avaliação estética nem garantia para todo conteúdo dinâmico possível.

Acessibilidade básica: vitrine com H1 seguido de H2, botões identificados, links acessíveis, estados de hold por teclado e suporte a movimento reduzido; login com labels e foco visível. Agente apresenta SEN-001 e SEN-006. Não houve medição completa de contraste ou validação com leitor de tela.

Performance: em uma navegação mobile local, DOMContentLoaded ~323 ms e load ~629 ms; resultados pontuais, com rede local/cache, sem valor de benchmark. Canvas mobile 390×360, DPR controlado pelo perfil; qualidade limita FPS a 30/45 e DPR a 1/1,5/2 conforme largura. Fontes externas responderam normalmente. Não foram observados requests repetidos indefinidamente. Consumo real de CPU/GPU não quantificado.

## Backend

Artisan e controllers carregam; inventário de rotas válido. `php artisan serve --host=127.0.0.1 --port=8001 --no-reload` iniciou sem exceção. A porta 8000 já atendia a vitrine e foi reutilizada pela suíte visual; 8001 isolou os testes HTTP adicionais.

Azure: o cliente lê `services.azure_foundry`, usa header `api-key`, timeout de 30 segundos, deployment no campo model e endpoint `/openai/v1/chat/completions`; retorna erro amigável quando falta configuração. Nenhuma chamada paga feita. Presença não comprova validade da chave, rede, quota ou deployment.

Auditoria e permissões: tenant vem do usuário, argumentos de tenant do modelo são sobrescritos, catálogo limita tools, RBAC é rechecado na execução, operações e auditoria usam transação. Form Requests com `authorize(): true` não representam sozinhos bypass: o caminho atual aplica autorização e escopo no serviço. Nenhuma rota pública de atualização de User foi encontrada; campos `papel`/`tenant_id` fillable exigem atenção se novos endpoints forem criados.

### Logs: histórico versus execução atual

Snapshot inicial: **414.216 bytes**; conferência: **414.902 bytes**. Os três novos registros são do ambiente `testing`:

| Timestamp local | Registro | Contexto |
|---|---|---|
| 01/10/2026 16:02:59, duas entradas | `AiAgentService: falha ao executar tool` | Exceções deliberadas nos testes de falha/rollback/mascaramento; suíte aprovada |
| 01/10/2026 16:03:00, uma entrada | `AiAgentService: resposta de erro da Chat Completions API` | Resposta HTTP mockada; não erro atual de Azure real |

Nenhum novo erro `local` observado durante esta bateria. Não existe stack de teste reprovado: o PHPUnit não reprovou testes. Evidência agregada sem credenciais: `.sentinel/audit/inspection.json`.

Histórico: chave de aplicação ausente e headers já enviados (23–24/09), driver PDO ausente (24/09), conexão recusada (24/09), acesso MySQL negado (até 01/10 13:29), tabela cache ausente (24/09), falhas de chamada à API (até 01/10 15:46). As primeiras causas não se reproduziram. Azure histórico permanece sem reteste real por restrição de custo; não declarar resolvido nem contar como falha atual.

### Pendências conhecidas e limitações arquiteturais

- Não existe tabela tenants/empresas nem FK de `tenant_id` nas seis entidades e users; integridade de tenant depende da aplicação. Pendência declarada no projeto, não migration faltante.
- Restauração possui serviço e teste de reautenticação, mas não tela/tool pública; fluxo ponta a ponta pendente, não rota quebrada.
- `schedule:list` declara arquivamento diário à meia-noite. A consulta às tarefas Windows não encontrou ação explícita com PHP/SENTINEL/`schedule:run`; isso não exclui acionador externo ou wrapper. Disparo periódico não comprovado. Não executar arquivamento para testar nesta base.
- A anotação em `CLAUDE.md` de que `criar_lancamento` não usa `validar()` está desatualizada: o código atual já passa por esse método. Não foi tratada como defeito presente.
- O corpo de erros Azure é registrado integralmente no log; revisar futuramente a necessidade de filtragem caso o provedor retorne conteúdo sensível. Não foi comprovado vazamento de segredo e não se atribuiu criticidade sem evidência.

## Segurança

`.env` existe, é ignorado pelo Git e não aparece em `git ls-files`. Busca de histórico por esse caminho não retornou commits nas referências locais. Entre candidatos rastreados de ambiente/credenciais, apenas `.env.example`, cujos campos de chave/senha estão vazios ou são placeholders.

Comparação de segredos não vazios do ambiente contra arquivos rastreados não encontrou correspondências; inspeção de fontes/configuração encontrou leitura por env, sem chave Azure hardcoded. JS/Blade não recebem a chave da integração. Isso é verificação básica do estado local, não auditoria forense completa do histórico ou de todos os formatos possíveis de segredo.

CSRF confirmado por HTTP 419 em POST sem token e campos CSRF nas views. Middleware auth confirmado por HTTP real. Hash bcrypt, senha oculta na serialização e regeneração de sessão verificados. Conteúdo dinâmico do chat utiliza `textContent` nos pontos inspecionados, incluindo argumentos de confirmação.

APP_DEBUG está habilitado e DB_PASSWORD está vazio no ambiente **local**; registrar condição, sem equiparar automaticamente a exposição pública. Os servidores desta bateria usam loopback. Configuração de produção não foi auditada. Nenhum segredo confirmado versionado foi encontrado; por isso não há achado crítico de credencial.

## Estado inicial e preservação

O trabalho já estava sujo antes da auditoria:

```text
 M CLAUDE.md
 M README.md
 M database/seeders/DatabaseSeeder.php
 M instalar.bat
 M package-lock.json
 M package.json
 M resources/views/welcome.blade.php
 M vite.config.js
?? app/Console/Commands/PrepararAcessoLocal.php
?? public/favicon-sentinel.svg
?? resources/css/landing.css
?? resources/js/landing.js
?? resources/js/lighthouse/
?? resources/views/components/lighthouse.blade.php
?? tests/Feature/PrepararAcessoLocalTest.php
?? tests/Feature/VitrineTest.php
```

O snapshot SHA-256 dos arquivos preexistentes, incluindo `.env`, não detectou mudanças durante a auditoria. Este relatório é o único arquivo novo não ignorado criado para a tarefa. Scripts de teste, JSONs e imagens ficaram em `.sentinel/audit` e `.sentinel/browser-tests`, ignorados pelo Git. O build regenerou assets ignorados; Laravel gerou views/logs/sessões/cache normalmente. Nenhum código de aplicação, configuração ou dado de negócio foi corrigido; sem commit, push ou merge.

## Ordem sugerida de correção

1. **Confirmação humana:** SEN-001, antes de validar novos fluxos de escrita pela interface; reproduzir regressão por teclado em ambiente isolado.
2. **Preparação de dados:** SEN-003 e SEN-004 juntos, antes de qualquer reexecução de seed; definir identidade dos exemplos e escopo de relacionamentos.
3. **Ciclo gráfico:** SEN-002, confirmar suspensão e retomada sem alterar a direção visual nesta etapa.
4. **Acessibilidade e idioma:** SEN-006 e SEN-005; revisar labels e tradução mantendo os controles funcionais aprovados.
5. **Validação pendente:** obter credencial local por meio legítimo para completar login/logout/agente real; decidir ambiente descartável para integração e acionamento de rotinas. Nenhuma correção realizada nesta bateria.

## Resultado dos comandos

| Comando | Resultado resumido |
|---|---|
| git branch --show-current | malaman |
| git status | Alterações preexistentes registradas; relatório acrescentado ao final |
| php -v / php --ini / php -m | PHP 8.5.11, ini e módulos registrados acima |
| composer --version / check-platform-reqs | 2.10.3; requisitos aprovados |
| node --version / npm.cmd --version | 24.21.0 / 11.19.0 |
| php artisan --version / about | Laravel 13.31.0; local; debug ativo |
| php artisan config:show database | mysql; valores secretos omitidos da evidência sanitizada |
| php artisan migrate:status | 13 Ran, zero pendentes |
| php artisan route:list --json | Inventário válido, incluindo auth/throttle |
| php artisan test --log-junit .sentinel/audit/phpunit.xml | 149 passaram; 1.629 assertions; zero skipped |
| npm.cmd run build | Sucesso; Vite 8.2.2; 1,19 s |
| npm.cmd ls --depth=0 | Dependências listadas; opcional multiplex ausente |
| php artisan serve --host=127.0.0.1 --port=8001 --no-reload | Inicialização aprovada, HTTP testado |
| php artisan schedule:list | Arquivamento diário declarado; não executado |
| git check-ignore .env / git ls-files -- .env | Ignorado / não rastreado |

Evidências locais: `.sentinel/audit/` (ambiente sanitizado, PHPUnit, HTTP, agente isolado, inspeção de logs/hashes) e `.sentinel/browser-tests/` (resultados e capturas da vitrine). Nenhuma credencial ou hash de senha é necessário para revisar este relatório.
