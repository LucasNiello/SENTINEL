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
- Executor da bateria de 05/11: não foi versionado (ficou no scratchpad, `bateria.php`). O desenho está em `documentacao/testes/fase6/PROGRESSO.md`. Rodou no MySQL do lab, operador do tenant 1. Os nomes dos pedidos existem nos seeders (ClienteSeeder, FornecedorSeeder...), então o SQLite semeado reproduz o cenário. `database/bateria.sqlite` está coberto por `database/.gitignore` (`*.sqlite*`).
