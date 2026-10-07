# Correções do Agente — SENTINEL

Data: 07/10/2026. Branch: `malaman`.

## Resumo

| Problema | Resultado |
|---|---|
| AI-001 — confiança TLS PHP/OpenSSL | CORRIGIDO |
| AI-002 — contrato e classificação de erros | CORRIGIDO |
| AI-003 — logging externo sem conteúdo bruto | CORRIGIDO |
| AI-004 — contexto conversacional | CORRIGIDO |
| AI-005 — validação de argumentos e término da resposta | CORRIGIDO |
| AI-006 — operações não suportadas | CORRIGIDO |

Validação final: **200 testes, 1.966 assertions, zero falhas**, build concluído e integração Azure real funcionando. Nenhuma escrita de cliente foi confirmada. A consulta real acrescentou **um registro em audit_logs**, conforme o funcionamento previsto.

## AI-001

### Causa confirmada

O runtime `C:\php\php.exe` carregava `C:\php\php.ini` com `curl.cainfo`, `openssl.cafile` e `openssl.capath` vazios. O diagnóstico anterior já havia reproduzido cURL 60. Os caminhos padrão de certificados não forneciam a confiança necessária.

A inspeção com validação nativa do Windows confirmou cadeia pública: Microsoft MSFT RS256 CA-1 → DigiCert Global Root G2. Não foi observada CA corporativa nessa cadeia. WinHTTP tinha acesso direto; proxy do usuário estava desativado; HTTP_PROXY, HTTPS_PROXY e ALL_PROXY estavam ausentes. O sucesso posterior com somente o bundle público confirmou que nenhuma CA corporativa adicional era necessária para esse endpoint.

### Solução e CA utilizada

Bundle oficial curl/Mozilla, obtido por HTTPS validado em [curl CA Extract](https://curl.se/ca/cacert.pem), instalado em `C:\php\certs\cacert.pem`.

SHA-256 do arquivo instalado:

```text
A41B5D356AEA97A529FE27E0F7316D2F9D946D75927476CF9CF1B90637D00505
```

Backup anterior à alteração: `C:\php\php.ini.sentinel-backup-20261007130816`.

Configuração confirmada por `php --ini`, `php -i` e inspeção das localizações OpenSSL:

```ini
curl.cainfo="C:\php\certs\cacert.pem"
openssl.cafile="C:\php\certs\cacert.pem"
```

`openssl.capath` permaneceu vazio. O arquivo existe e é legível. Não foi desativada a verificação TLS em nenhum teste ou alteração.

Não havia servidor SENTINEL rodando antes da mudança; somente o PHP do language server do editor, que foi preservado. O servidor temporário de validação foi iniciado depois da configuração e encerrado ao final.

### Testes de transporte e Azure

| Teste | Resultado | Latência |
|---|---|---|
| HTTPS PHP sem chave | HTTP 401; TLS concluído | 1,014 s |
| Uma chamada mínima autenticada, “Responda apenas OK.” | HTTP 200; resposta OK | 1,355 s |
| AiAgentService, “responda apenas olá”, antes das mudanças no agente | texto normal | 1,049 s |

A chamada mínima não enviou tools nem dados de banco, e limitou a resposta a 32 tokens de conclusão. Endpoint, chave e deployment existentes foram preservados. Não houve necessidade de investigação de configuração Azure alternativa.

## AI-002

### Contrato antigo e novo

Antes, exceções de transporte e erros HTTP Azure podiam retornar `tipo: erro` com HTTP 200. Agora falhas técnicas possuem `categoria`, `codigo`, `correlation_id` UUID gerado pelo servidor e `http_status`; o controller utiliza o status apropriado. Nenhum corpo, host, stack trace ou mensagem cURL bruta integra a resposta do agente.

| Falha | Categoria | HTTP local |
|---|---|---|
| Configuração de provedor ausente | provider_configuration_error | 503 |
| DNS/conexão/TLS | provider_connection_error | 503 |
| Timeout de transporte ou HTTP externo 408/504 | provider_timeout | 504 |
| HTTP Azure 401 | provider_authentication_error | 502 |
| HTTP Azure 403 | provider_authorization_error | 502 |
| HTTP Azure 429 | provider_rate_limit | 503 |
| Outros erros HTTP Azure | provider_http_error | 502, ou 503 para 5xx |
| Formato/argumentos/resposta incompleta | provider_response_error | 502 |
| Falha técnica de execução/auditoria de tool | tool_error | 500 |
| Pedido inválido ou recusa de dados da tool | validation_error | 422 |
| RBAC | authorization_error | 403 |
| Tool inexistente solicitada pelo modelo | unsupported_operation | 422 |

Códigos técnicos: `AI_PROVIDER_UNAVAILABLE`, `AI_PROVIDER_TIMEOUT`, `AI_PROVIDER_RESPONSE_INVALID`, `AI_RESPONSE_TRUNCATED`, `AI_CONTENT_FILTERED` e `AI_TOOL_ERROR`. Também há `AI_VALIDATION_ERROR`, `AI_TOOL_REFUSED`, `AI_FORBIDDEN` e `AI_UNSUPPORTED_OPERATION`.

A recusa textual educada de uma capacidade inexistente é uma resposta conversacional válida e pode retornar HTTP 200. Isso não se confunde com falha técnica do Azure.

Auth, CSRF, throttle e os status existentes de confirmação continuam sob suas proteções originais. Exceções de autenticação não são convertidas em falhas do provedor. Há tratamento seguro de falha total da auditoria, inclusive quando o registro de erro também falha, preservando o rollback.

### Frontend

Mensagens seguras distintas para validação, permissão, throttle, upstream inválido, indisponibilidade, timeout e falha genérica. HTTP 401/419 continua levando ao login. Corpos de erro HTTP não são usados para exibir detalhes técnicos.

AbortController limita o comando a 35 segundos, acima dos 30 segundos do backend. O timer é limpo em `finally`; campo, botão e `aria-busy` retornam ao estado normal após falha. Não há retry automático, incluindo escrita.

## AI-003

O logging anterior gravava `$resposta->body()` inteiro e mensagens brutas de exceptions de transporte. Esses caminhos foram removidos.

Logs de falha externa agora contêm somente categoria, provedor, correlation ID e status externo quando disponível. Exceptions técnicas acrescentam classe, código, nome do arquivo e linha. Mensagens externas e códigos livres do body não são gravados, evitando que campos arbitrários sejam usados para inserir dados sensíveis no log.

Logs técnicos de execução de tools também deixaram de conter argumentos, inclusive os anteriormente mascarados. A auditoria de negócio existente continua com seu mascaramento e sua transação.

### Prova de sanitização

- Testes com body, prompt e credencial fictícia marcados verificaram ausência desses valores nos metadados.
- Exceptions de conexão/TLS/timeout foram classificadas sem registrar sua mensagem bruta.
- Uma simulação Azure HTTP 500 fora do mock do logger escreveu um registro real em `storage/logs/laravel.log`; a resposta local foi 503 e o correlation ID apareceu no novo trecho.
- O novo trecho não continha os marcadores do body, código externo, prompt nem a chave real.
- A chave Azure real teve zero ocorrências em `resources`, `public` (incluindo build) e no arquivo completo `storage/logs/laravel.log`. A varredura não imprimiu o valor da chave.

## AI-004

`ConversaAgente` mantém contexto no servidor em `agente_conversa`, dentro da sessão Laravel. O controller recupera esse histórico e o passa ao service antes da mensagem atual. O DOM não é usado como fonte.

Limites: **16 mensagens / oito pares user-assistant**, teto de **8.000 caracteres**, máximo de 1.000 caracteres por mensagem. O descarte ocorre em pares para preservar a ordem conversacional.

A identidade combina usuário e tenant derivados de `ContextoUsuario`. Se qualquer um mudar, o histórico anterior é descartado. Sessão nova não possui histórico; o logout existente invalida a sessão e remove o contexto. Ambos foram cobertos em testes.

São armazenados apenas papel conversacional e texto de continuidade. Resultados de consultas, tool arguments e tokens de confirmação não são copiados para o histórico. Propostas são descritas como pendentes, nunca como escrita executada. Erros não entram no histórico. Mensagens com indicadores de credenciais são omitidas; valores conhecidos da chave Azure/APP_KEY e documentos CPF/CNPJ são redigidos no histórico.

### Complementação

Mock: “cadastre um cliente” → “Qual o nome?” → “Mercado Central”; a segunda requisição incluiu os três elementos e devolveu proposta sem gravar cliente.

Azure real, após ajuste do prompt para não exigir campos opcionais:

1. “cadastre um cliente” → pergunta pelo nome, informando que os demais campos são opcionais; 3,161 s.
2. “Cliente Contexto Teste” → HTTP 200, `confirmacao_pendente`, tool `criar_cliente`; 1,423 s.

Nenhuma confirmação executada; contagem de clientes inalterada e nenhum registro de auditoria adicional nesse teste de proposta.

## AI-005

Cada function.arguments deve estar presente, ser string, decodificar com `JSON_THROW_ON_ERROR` e produzir objeto JSON. `{}` é aceito; listas, escalares e null são rejeitados. Falha não vira `[]`. Todas as chamadas recebidas são validadas antes de atender a primeira.

Schemas agora possuem `additionalProperties: false`, sem habilitar strict mode. Propriedades desconhecidas são rejeitadas no servidor. Duas defesas já existentes foram preservadas: `tenant_id` é descartado; `status` em `criar_lancamento` também é descartado porque o lançamento nasce pendente por regra do servidor. Nenhuma delas permite controlar esses campos pelo modelo.

Os helpers de teste passaram a serializar argumentos vazios como `{}`, adequando os mocks ao contrato de function calling.

Casos testados: objeto com filtro, `{}`, `{abc`, null de transporte, JSON `null`, `[]`, string JSON, número, array de transporte e propriedade desconhecida. Os casos inválidos não executam tool, não geram token e não auditam execução.

`finish_reason`: stop e tool_calls são aceitos com estrutura coerente; length retorna `AI_RESPONSE_TRUNCATED`; content_filter retorna `AI_CONTENT_FILTERED`; outros valores explícitos e combinações incoerentes geram erro de resposta. Na ausência do campo, a validação estrutural é mantida para compatibilidade. Respostas incompletas nunca geram proposta nem execução.

O processamento continua atendendo somente a primeira tool e informando o aviso existente quando houver outras. Não foi implementado loop completo nem segunda chamada com role tool.

## AI-006

O system prompt declara explicitamente que somente as ferramentas disponibilizadas representam operações executáveis. Proíbe inventar capacidades, afirmar ações não executadas ou substituir entidades, distinguindo usuário de funcionário. Informa que cadastro de usuários não está disponível.

Teste real “cadastre um usuario para mim”: HTTP 200, resposta textual explicativa de indisponibilidade; nenhuma tool de escrita, proposta ou confirmação. Teste mockado confirma a orientação enviada no system prompt e ausência de proposta/auditoria.

## Testes

| Validação | Resultado |
|---|---|
| `php artisan test --compact` (suíte artisan completa) | 200 testes / 1.966 assertions / 0 failures |
| `npm.cmd run build` | sucesso |
| `php artisan migrate:status` | 13 aplicadas / 0 pendentes |
| `git diff --check` | sem erros de whitespace |
| GET /up | 200 |
| GET /login | 200 |
| GET /agente sem login | 302 para login |
| POST /agente/comando sem login e sem CSRF | 419 |
| POST /agente/comando sem login, com cookie anônimo e CSRF válido | 401 |
| TLS PHP sem API key | 401, handshake concluído |
| Azure mínimo | 200 / OK |
| Erro Azure simulado no log real | 503 / correlation ID presente / conteúdo bruto ausente |

Os testes automatizados usam mocks e banco SQLite em memória; não dependem de Azure real nem modificam o banco local de negócio.

### Fluxos Azure reais via service/controller

Um administrador já existente foi utilizado como principal autenticado somente no processo CLI de validação, com sessão temporária em memória. Nenhuma senha foi redefinida nem conta criada. Isso valida os services, controller, RBAC, tenant e Azure, mas **não representa login por senha nem sessão HTTP autenticada no navegador**.

| Pedido | Resultado | Latência |
|---|---|---|
| responda apenas olá | texto | 1,477 s |
| cadastre um usuario para mim | texto de operação indisponível | 1,788 s |
| liste os clientes | resultado_leitura / consultar_clientes / 3 linhas | 1,163 s |
| cadastre um cliente chamado Cliente Teste Sentinel | proposta / criar_cliente | 1,489 s |
| cadastro sem nome + complementação | pergunta, seguida de proposta / criar_cliente | 3,161 s + 1,423 s |

Somente a leitura escreveu auditoria: um audit_log. Propostas não criaram clientes. Nenhum POST de confirmação foi realizado nos testes reais.

### Frontend isolado no navegador

Blade renderizada pelo Laravel e carregada em Chromium com HTTP interceptado, sem banco/Azure:

- Mensagens seguras para HTTP 422, 403, 429, 502, 503, 504 e 500; nenhum body de erro exibido.
- Campo/botão desabilitados durante requisição; aria-busy e interface recuperados em erro.
- Avanço controlado de relógio confirmou aborto aos 35 segundos e recuperação do loading.
- Hold interrompido antes do prazo: zero confirmações.
- Space → Tab: zero confirmações, incluindo espera além do prazo do hold.
- Zero erros JavaScript; sem overflow em 1920, 1366, 1024 e 390 px.

Nesta branch o handler blur da correção SEN-001 não estava presente. Foi acrescentado ao cancelamento existente do hold, sem remover proteções ou alterar duração/token.

## Segurança

TLS continua verificado. RBAC, tenant, whitelist, validação server-side, confirmação humana, token único, janela temporal, transação, auditoria e mascaramento foram preservados. Os testes de regressão incluem esses mecanismos e falhas de auditoria.

Não foram impressos secrets, cookies, session IDs, tokens CSRF nem hashes de senha. Nenhuma credencial Azure foi enviada ao frontend. Sem retry automático de escrita e sem confirmação real de escrita.

Sem migrations novas, sem alteração de banco estrutural, sem alteração da vitrine ou do instalador. APP_DEBUG e os valores Azure foram preservados.

## Arquivos alterados

Arquivos de aplicação/teste no Git e novos arquivos desta tarefa:

- `app/Services/AiAgentService.php`
- `app/Services/ConversaAgente.php` (novo)
- `app/Http/Controllers/AgenteController.php`
- `resources/views/agente.blade.php`
- `tests/Feature/AgenteCorrecoesTest.php` (novo)
- `tests/Concerns/SimulaAgente.php`
- `tests/Feature/AgenteTest.php`
- `tests/Feature/AgenteGovernancaTest.php`
- `tests/Feature/AuditoriaMascaraTest.php`
- `RELATORIO_CORRECOES_AGENTE_SENTINEL.md` (novo)

O diagnóstico `RELATORIO_DIAGNOSTICO_AGENTE_SENTINEL.md` já estava não versionado antes da tarefa e foi preservado.

Artefatos locais ignorados pelo Git: build em `public/build/`, cache de resultados PHPUnit, novos registros em `storage/logs/laravel.log` e evidências em `.sentinel/audit/` (`agent.html`, `agent-browser.json`, screenshots agent-1920/1366/1024/390.png, `correcoes-browser.mjs` e `correcoes-browser.json`). Um registro foi acrescentado à auditoria do banco pela consulta real autorizada.

## Configuração externa alterada

- `C:\php\php.ini`: curl.cainfo e openssl.cafile.
- `C:\php\certs\cacert.pem`: bundle público instalado.
- `C:\php\php.ini.sentinel-backup-20261007130816`: backup preservado.

Nenhum certificado foi copiado para resources ou public. Scripts temporários de edição/validação em C:\php foram removidos ao término; as evidências de navegador permanecem na pasta local ignorada.

## Pendências

- **NÃO TESTADO:** login por senha e fluxo Azure real em sessão HTTP autenticada de navegador; não havia credencial local disponível e nenhuma senha foi redefinida. Autenticação HTTP com contas de teste foi coberta na suíte, e os fluxos reais foram validados via controller/service no processo CLI.
- Loop completo de múltiplas tools e retorno role tool continuam melhoria futura, explicitamente fora do escopo solicitado.

Não restam bloqueios observados de TLS/Azure ou falhas na suíte.

## Requisitos para instalar.bat

O instalador não foi alterado. Para reproduzir em outra máquina, deverá:

1. Identificar o executável PHP e o php.ini efetivamente usados, incluindo diferenças entre CLI e servidor.
2. Verificar proxy e cadeia certificadora sem desativar TLS.
3. Obter bundle confiável oficial curl/Mozilla via HTTPS validado, em diretório privado de configuração.
4. Quando houver inspeção corporativa comprovada, adicionar somente CA corporativa verificada na confiança administrativa do Windows; não confiar automaticamente no certificado remoto.
5. Fazer backup do php.ini e configurar curl.cainfo/openssl.cafile com caminho absoluto existente; não definir capath sem necessidade.
6. Validar legibilidade e certificados do bundle e sua manutenção/atualização.
7. Reiniciar somente processos da aplicação que já carregaram o php.ini anterior.
8. Testar HTTPS pelo PHP sem chave: qualquer resposta HTTP comprova handshake; 401 sem autenticação é esperado neste endpoint.
9. Fazer chamada mínima autenticada controlada, sem tools/dados pessoais e sem imprimir credenciais.
10. Nunca usar verify=false, withoutVerifying ou equivalentes.

## Git status

Antes: branch `malaman`, cinco commits locais à frente de origin/malaman; apenas o relatório de diagnóstico não versionado. Nenhuma alteração anterior revertida.

Estado final esperado e conferido:

```text
 M app/Http/Controllers/AgenteController.php
 M app/Services/AiAgentService.php
 M resources/views/agente.blade.php
 M tests/Concerns/SimulaAgente.php
 M tests/Feature/AgenteGovernancaTest.php
 M tests/Feature/AgenteTest.php
 M tests/Feature/AuditoriaMascaraTest.php
?? RELATORIO_CORRECOES_AGENTE_SENTINEL.md
?? RELATORIO_DIAGNOSTICO_AGENTE_SENTINEL.md
?? app/Services/ConversaAgente.php
?? tests/Feature/AgenteCorrecoesTest.php
```

Nenhum commit, push, merge, rebase, reset, restore ou checkout foi executado. Nenhum composer update, npm update ou migrate:fresh.
