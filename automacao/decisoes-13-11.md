# Decisões da sessão de 13/11/2026 (pc-talita, branch automacao-na-madruga, base 5a51b20)

Formato: hora · item · decisão e porquê · alternativa descartada · arquivos · testes novos · linha final do test-runner · reviewer · commit sugerido.

## 00:32 · Etapa 0.1 · PHP 8.4
- Decisão: baixar `php-8.4.27-1-Win32-vs17-x64.zip` (versão 8.4 mais recente do releases.json, Thread Safe x64 vs17).
- SHA-256 conferido contra o releases.json: `53db2047…ccdf5b` = bate.
- Extraído em `C:\laragon\bin\php\php-8.4.27-1-Win32-vs17-x64\` (zip ficou no scratchpad, fora do repositório). Nenhum php.ini criado ou alterado.
- `php.exe -v` da pasta nova: PHP 8.4.27 (ZTS VC++ 2022 x64). O Apex One não barrou.
- Alternativa descartada: build NTS (o Laragon usa TS; o 8.3.30 instalado é TS).
- PHP do PATH antes da troca: 8.2.31.

## 00:40 · Etapa 0.3 · Conferir ambiente
- O `php` do PATH desta sessão é um PHP 8.2.31 do WinGet (`%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.2_...`), que está no PATH do usuário, antes do Laragon. A troca no Laragon não muda o que esta sessão enxerga.
- Decisão: chamar sempre pelo caminho absoluto `C:\laragon\bin\php\php-8.4.27-1-Win32-vs17-x64\php.exe` (artisan, composer, test). Alternativa descartada: editar o PATH, porque alteraria a máquina fora da exceção autorizada.
- php.ini: criado pelo Laragon na troca (não por mim). O Lucas ligou `zip` pelo menu do Laragon.
- Extensões conferidas no 8.4: pdo_sqlite, sqlite3, pdo_mysql, mbstring, openssl, fileinfo, curl e zip = todas presentes.
- Composer: `C:\laragon\bin\composer\composer.phar` (não está no PATH). `composer install --no-interaction` = exit 0. Instalou as versões do lock, porque o vendor local estava desatualizado. Lock intocado.

## 00:41 · Etapa 0.4 · Branch
- Status antes: só `?? automacao/decisoes-13-11.md`, que é o registro desta noite e foi criado por mim na Etapa 0.1. Decisão: seguir. O `switch -c` não mexe em arquivo não rastreado, e o arquivo pertence à branch nova. Alternativa descartada: parar e esperar o Lucas por um arquivo que eu mesmo criei.
- `git switch -c automacao-na-madruga` + `git push -u origin automacao-na-madruga`: OK. Branch atual = automacao-na-madruga, rastreando origin. Daqui em diante, nenhum git de escrita.

## 00:43 · Etapa 1 · Base verde
- test-runner (haiku): **757 passed / 0 failed, 5824 asserções, 30,14 s.**

## 00:45 · Adaptador · Fatos da API (skill claude-api, referência de 03/11/2026)
- `claude-sonnet-5-5`: `tool_choice` forçado (`{type: tool}` / `{type: any}`) devolve **400**. `temperature`, `top_p` e `top_k` fora do padrão também devolvem 400. Thinking adaptativo vem ligado, e o effort padrão é `high`.
- `claude-haiku-5-5`: thinking adaptativo ligado, effort padrão `medium`.
- Preço (US$ por 1M tokens, entrada/saída): Sonnet 5.5 = 2,00/10,00; Haiku 5.5 = 0,10/0,50.
- Erros: 401 `authentication_error`, 402 `billing_error`, 403 `permission_error`, 429 `rate_limit_error`, 529 `overloaded_error`.
- Versão atual da API: `2026-10-21`, que só tira temperature/top_p/top_k e citations; nenhum dos dois é usado aqui. O cabeçalho fica configurável.
- Mensagem `system` no meio de `messages` existe nesses modelos, mas tem de vir logo depois de um `user` e ser a última ou vir seguida de `assistant`. O nosso lugar (depois do histórico e antes do `user` novo) não cabe nessa regra, então vai para o campo `system`, como o prompt pede.
- **Decisão (desvio do prompt):** a tool "forçada" NÃO vai como `{type: tool, name}`, porque daria 400 no modelo padrão. Vai `{type: auto}`, com `tools` só com a tool solicitada (como já acontece no Azure) e uma linha no `system` dizendo que a ferramenta é essa. A conferência do servidor (AI_INTENT_MISMATCH) continua igual. Alternativa descartada: mandar `{type: tool}` só para o Haiku. São dois caminhos para testar, e a troca de modelo pelo .env quebraria um deles.
- **Decisão:** `output_config.effort` configurável (`ANTHROPIC_EFFORT`, padrão `low`): é chat curto com tool, e o p90 do Azure é 2,99 s. Thinking omitido (adaptativo). Alternativa descartada: desligar o thinking, porque o jeito muda entre os modelos (`between_tools` no Sonnet, `disabled` no Haiku).
- **Decisão:** sem `fallbacks` de recusa (beta). A recusa (`stop_reason: refusal`) vira o erro amigável AI_CONTENT_FILTERED, que já existe. Alternativa descartada: o beta server-side-fallback, que traz cabeçalho beta e outro modelo no meio, fora do escopo.
## 00:59 · Adaptador da Anthropic · FEITO
- **Plan (opus)** antes de codar. Ele apontou "conflito com o CLAUDE.md (Microsoft Foundry)". Não parei: o Lucas decidiu a troca em 12/11, e o prompt da noite diz que isso não é conflito a parar. O texto proposto para o CLAUDE.md fica em `pendencias-lucas.md`. Do Plan, adotei:
  - provedor desconhecido falha fechado (503), nunca cai no Azure calado;
  - `stop_reason` nulo ou ausente e `end_turn` com tool_use viram fora do formato (o `interpretar()` aceita `finish_reason` null e aceita "stop + tool_calls" na tool solicitada);
  - `max_tokens` 8000 (o thinking adaptativo consome o teto);
  - a chave da Anthropic também sai do histórico no `ConversaAgente::sanitizar()`.
- **Decisão (forma):** o corpo continua montado UMA vez, no formato do Azure. O adaptador `chamarAnthropic()` traduz a partir dele. Na volta, o `traduzirAnthropic()` converte a resposta 2xx para o formato interno do Azure e a entrega ao MESMO `interpretar()`, sem mudança nele. Alternativa descartada: um segundo `interpretar` para a Anthropic, que duplicaria as defesas (schema, tenant_id, RBAC, divergência) e abriria espaço para uma ficar mais fraca.
- **Decisão:** a mensagem system do meio (o "histórico acima… pedido abaixo") vai para o campo `system` com o prefixo "Regra para o pedido atual (a última mensagem do usuário…)" (achado do reviewer: no topo, "acima/abaixo" perdia a referência). Alternativa descartada: pôr esse texto como bloco do último `user`, o que tira autoridade de operador da regra.
- **Decisão:** no config, `?:` em model, versão, max_tokens e effort. A chave vazia no .env (como no .env.example) cai no padrão, em vez de virar "" e dar 503 sem pista.
- Arquivos:
  - `app/Services/AiAgentService.php`: `processar()`, `interpretar()` (402 → provider_billing_error, 529 → provider_overloaded, só no log), `falha()` (`provider` dinâmico), e os novos `provedor()`, `chamarAnthropic()` e `traduzirAnthropic()`;
  - `app/Services/ConversaAgente.php`, `config/services.php`, `.env.example` (só chaves), `phpunit.xml` (`SENTINEL_IA_PROVEDOR=azure` forçado);
  - `.env`: acrescentados `SENTINEL_IA_PROVEDOR=anthropic` e `ANTHROPIC_MODEL=claude-sonnet-5-5`.
- **Testes novos:** `tests/Feature/AnthropicAdaptadorTest.php`, com 36 casos:
  - formato do pedido (URL, cabeçalhos, model, effort, sem temperature, `properties` como objeto) e tool_use de leitura;
  - resposta em texto; 401, 402, 429 e 529; timeout;
  - tool solicitada: auto + só essa tool + instrução no system; divergência auditada; end_turn com tool_use; texto na tool solicitada não grava nada;
  - tenant_id da IA descartado até o Confirmar;
  - histórico só com role/content, e o lembrete com a referência;
  - 17 casos fora do formato;
  - o Azure continua padrão e o corpo dele não mudou; configuração inválida falha fechada; padrão do .env vazio; a chave não fica no histórico.
- **Prova por sabotagem** (script no scratchpad: aplica, roda `--filter=AnthropicAdaptadorTest`, restaura os bytes; os testes voltaram verdes depois de cada uma):
  1. Decodificar a resposta como array (`{}` vira `[]`): o tool_use de leitura com entrada vazia passa a dar 502. Prova que a entrada vazia só funciona porque o corpo é lido com objetos.
  2. `stop_reason` desconhecido → null: pause_turn, sem stop_reason e stop_reason null passam a ser aceitos (200). Prova que null nunca chega ao `interpretar()`, que o aceitaria.
  3. end_turn com tool_use aceito como `stop`: só o teste novo da tool solicitada falha (200 no lugar de 502). Antes dele, a sabotagem passava: sem tool solicitada, o próprio `interpretar()` já recusava. O teste foi criado por causa disso.
  4. `tool_choice` forçado: o teste da tool solicitada falha. Prova que o pedido não leva o `{type: tool}` que daria 400 no Sonnet 5.5.
  5. Histórico cru (com chaves extras): o teste do histórico falha. Prova que só role/content saem.
  6. Sem o `unset(tenant_id)` na validação do `interpretar()`: a proposta com tenant_id 2 vira 502 (chave fora do esquema). Prova que o descarte de tenant vale também neste provedor.
  7. Tirar a chave da Anthropic do `sanitizar()`: a chave aparece no histórico da sessão.
  8. Aceitar resposta sem texto e sem tool: "content vazio" e "só thinking" passam a dar 200.
  9. `env('ANTHROPIC_MODEL', padrão)` sem `?:`: o teste do .env vazio falha.
  10. Lembrete sem o prefixo: o teste do histórico falha.
- test-runner (haiku), depois da 1ª versão: **785 passed / 0 failed, 5921 asserções**. A suíte da versão final está em `leva-atual.md` e na entrada seguinte.
- **reviewer (opus), 1 passada: nada BLOQUEANTE.** 2 IMPORTANTES e 3 MENORES, todos corrigidos:
  - resposta 2xx vazia aceita como texto: agora fora do formato;
  - lembrete fora de contexto no `system`: ganhou o prefixo;
  - `ANTHROPIC_MODEL` vazio: `?:` no config;
  - testes fracos: corpo do Azure por chaves, `count(tools) > 5`, timeout com URL e `provider` no log, texto na tool solicitada, 6 casos novos fora do formato.
- Commit sugerido: `feat(ia): adaptador da API da Anthropic no AiAgentService (provedor por config, padrão azure)`

## 00:59 · Bateria · executor
- **Decisão:** o comando `sentinel:bateria` (`app/Console/Commands/BateriaAgente.php`) segue o desenho de 05/11 (PROGRESSO.md):
  - operador do tenant 1, sessão em memória, `ler()` → `processar()` → `guardar()`;
  - continuação na sessão da consulta da qual depende;
  - mesmo critério de acerto e mesmo formato de CSV;
  - tool obtida pela resposta HTTP (Anthropic ou Azure); negado antes da IA → a tool auditada.
- Proteção: só roda com `DB_CONNECTION=sqlite` e `DB_DATABASE=…/database/bateria.sqlite`. Para em 401, 402, 403, 400 (crédito) ou configuração inválida. Tem um teto de chamadas (`--limite`). Não grava chave, prompt nem corpo; só tool, tipo, código, tempos e o começo do texto (como em 05/11).
- Teste novo: `tests/Feature/BateriaAgenteTest.php` (fora do SQLite da bateria, recusa sem chamar o provedor).
- Banco: `database/bateria.sqlite` criado, `migrate --force`, `db:seed --force` com a saída descartada (o preparar-acesso imprime as senhas geradas). 4 usuários, 1 operador no tenant 1.
- Percentil: interpolação linear (o de 05/11 não diz o método; com ~38 valores a diferença é pequena).
- **Chamadas reais:**
  - fumaça: 1 (pedido 1, Sonnet: S, 1,56 s, ~9,7 mil tokens de entrada);
  - 1ª rodada do Sonnet com o adaptador ANTES das correções do reviewer: 38 chamadas, 23/40, p50 1,73 s, p90 2,38 s, ~272 mil tokens de entrada (~US$ 0,57). **Descartada**, porque o adaptador mudou (o lembrete) e o critério do "obtido" dos negados estava diferente do de 05/11. O CSV dela foi sobrescrito.
  - **Total até 00:59: 39 chamadas.**

## 01:03 · Bateria · FEITO · decisão do modelo
- Rodadas com o adaptador final (depois das correções do reviewer), no mesmo banco e com os mesmos 40 pedidos: `documentacao/testes/fase6/BATERIA_SONNET_2026-11-13.csv` e `BATERIA_HAIKU_2026-11-13.csv`.

| Grupo | Azure (05/11) | Sonnet 5.5 | Haiku 5.5 |
|---|---|---|---|
| consulta | 10/10 | 10/10 | 10/10 |
| cadastro | 9/10 | 4/10 | 4/10 |
| atualização | 3/6 | 2/6 | 2/6 |
| continuação | 0/4 | 0/4 | 0/4 |
| exclusão (permissão) | 4/4 | 4/4 | 4/4 |
| lote | 2/2 | 2/2 | 2/2 |
| ambíguo | 2/4 | 4/4 | 2/4 |
| **total** | **30/40** | **26/40** | **24/40** |
| p50 tempo_ia_ms | 2.440 | 1.800 | 1.383 |
| p90 tempo_ia_ms | 2.990 | 2.637 | 2.172 |
| máximo | 3.780 | 8.566 | 2.774 |
| tokens entrada / saída | — | 271.743 / 3.075 | 271.743 / 5.892 |
| custo estimado (US$) | — | ~0,57 | ~0,03 |

- **Decisão: Haiku 5.5** (`ANTHROPIC_MODEL=claude-haiku-5-5` no .env). Regra da noite: fica a no máximo 2 acertos do Sonnet (24 × 26) e não perde em escrita (cadastro, atualização, lote e continuação empatados) nem em permissão (exclusão 4/4). A única diferença está em "ambíguo" (38 "apaga aquele" e 40 "mostra o relatório"). Nos dois, o Haiku também pede esclarecimento, mas em lista e sem "?", e o critério de 05/11 exige "?". Alternativa descartada: Sonnet. Ele ganha só nesses 2 pedidos, custa ~20× mais e é mais lento (p90 2,64 s × 2,17 s).
- **Achado para o Lucas (cadastro 4/10 nos dois × 9/10 no Azure):** nos pedidos com dado faltando (12, 14, 16, 17, 18), os modelos da Anthropic PERGUNTAM em texto. O system prompt manda exatamente isso ("se faltar informação obrigatória… pergunte ao usuário em texto antes de chamar a tool"). O Azure ignorava a frase e abria o card com o campo marcado, que é o que a bateria espera. Ou o prompt muda, ou muda o esperado da bateria. Vai para `pendencias-lucas.md`.
- Pedido 20 (funcionário, operador): os três modelos perguntam CPF/salário em vez de chamar a tool que seria negada. Pedidos 21 e 24: consultam antes de atualizar (uma chamada só por pedido, então a resposta é a consulta).
- **Chamadas reais: 38 + 38 = 76. Total da noite: 115 (de 200).** Custo estimado acumulado: ~US$ 1,20.
- Commit sugerido: `test(fase6): bateria Sonnet 5.5 x Haiku 5.5 e comando sentinel:bateria`

## 01:07 · Item A · Catálogo sem valor padrão · FEITO (suíte no fim do C)
- Decisão: `CatalogoService::criar()` não tem mais padrão para `cobranca_por`, `controla_estoque` e `exige_receita`. Só o `estoque` inicial continua com padrão 0 (falha segura, como já dizia o comentário do `CatalogoDaClinica`). No `conferir()`, apenas na criação: `cobranca_por` ausente ou null → "Informe a forma de cobrança: \"unidade\" ou \"diaria\"."; `controla_estoque` e `exige_receita` que não são booleanos (ausente, null, "sim") → "Informe se o item controla estoque (sim ou não)." e "Informe se o item exige receita (sim ou não).". O `atualizar()` continua parcial. Alternativa descartada: checar também na atualização, que já mescla os valores atuais e nunca fica sem os três.
- **Testes antigos alterados** (contradição com a decisão do Lucas, o item A): `CadastrosClinicaTest::test_regras_do_catalogo` (a base e o caso do serviço ganharam os três campos), `EstoqueNegativoTest` (estoque inicial negativo) e `IsolamentoDeTenantTest` (o caso `CatalogoService::criar`). Os três criavam item contando com o padrão. Só acrescentei os campos, com os mesmos valores de antes (unidade, false, false); nenhuma asserção mudou.
- `database/seeders/ClinicaSeeder.php`: serviço passa `controla_estoque`/`exige_receita` false; produto passa `cobranca_por` unidade.
- Teste novo: `CadastrosClinicaTest::test_criacao_sem_um_dos_tres_campos_e_recusada_no_service` (cada campo ausente e inválido, nada gravado, criação completa e edição parcial).
- Filtro: Clínica 283/283. reviewer (sonnet), 1 passada: **nada a corrigir.** O único MENOR (combinações de booleanos) já está coberto por `test_regras_do_catalogo`.
- Commit sugerido: `fix(clinica): catalogo exige cobranca_por, controla_estoque e exige_receita na criacao (sem padrao no service)`

## 01:07 · Item B · Troca de tenant zera supervisor e PIN · FEITO (suíte no fim do C)
- Decisão: no `User::booted()` (evento updating), a troca de **tenant** zera `supervisor_setor` e `pin_autorizacao` SEMPRE, mesmo gravados junto. Marcar supervisor é ato do dono do tenant novo, e o PIN só vale com a marcação (MENOR do reviewer, adotado). A troca de **papel** passou a ser por campo: só fica o que for gravado nesta mesma operação. Antes, bastava um dos dois estar "dirty" para nenhum ser zerado, e um PIN antigo sobrevivia se a marcação fosse regravada junto. Alternativa descartada: só acrescentar `|| isDirty('tenant_id')` na condição antiga, o que herdaria esse furo.
- Limite documentado no comentário: update em massa e SQL manual não disparam o evento. O reviewer confirmou que nenhum caminho de produção muda papel ou tenant de usuário existente: não há tela de usuários, e o preparar-acesso e os seeders usam firstOrCreate.
- Testes novos em `CorrecoesDaRevisaoTest`: `test_trocar_o_tenant_do_usuario_zera_a_marcacao_de_supervisor_e_o_pin` (inclui a recusa do estorno), `test_na_troca_de_tenant_nem_a_marcacao_gravada_junto_fica`, `test_na_troca_de_papel_so_fica_o_que_foi_gravado_junto` (remarcado, mesmo setor, PIN novo).
- **Prova por sabotagem:**
  - B1: tirar o `isDirty('tenant_id')` da condição faz os 2 testes de tenant falharem. Prova que a troca de tenant é o que dispara a limpeza.
  - B2: voltar à regra antiga do PIN ("não zera se a marcação estiver dirty") faz 3 testes falharem (troca de papel e de tenant). Prova que o PIN antigo não sobrevive à remarcação.
  - B3: na troca de tenant, aceitar a marcação gravada junto faz `…nem_a_marcacao_gravada_junto_fica` falhar. Prova que a marcação não atravessa a empresa.
  - B4: zerar o PIN sempre faz `…troca_de_papel_so_fica_o_que_foi_gravado_junto` falhar (o PIN novo some). Prova que a regra por campo existe na troca de papel.
- Filtro: CorrecoesDaRevisaoTest 26/26 (com o C: 88/88). **reviewer (opus), 1 passada: nada BLOQUEANTE nem IMPORTANTE.** Os 3 MENORES foram corrigidos: marcação na troca de tenant, comentário do limite e testes que faltavam.
- Commit sugerido: `fix(clinica): trocar o tenant do usuario zera supervisor e PIN`

## Bateria · executor (fatos de 05/11)
- Executor da bateria de 05/11: não foi versionado (ficou no scratchpad, `bateria.php`). O desenho está em `documentacao/testes/fase6/PROGRESSO.md`. Rodou no MySQL do lab, operador do tenant 1. Os nomes dos pedidos existem nos seeders (ClienteSeeder, FornecedorSeeder...), então o SQLite semeado reproduz o cenário. `database/bateria.sqlite` está coberto por `database/.gitignore` (`*.sqlite*`).
