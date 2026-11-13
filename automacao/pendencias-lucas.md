# Pendências para o Lucas — sessão de 13/11/2026 (da mais para a menos importante)

(As de 12/11 continuam abaixo, sem alteração. A nº 1 de 12/11, o PHP 8.4, foi resolvida na Etapa 0 de 13/11.)

## 1. Texto proposto para a seção "Provedor de IA" do CLAUDE.md (não editei o CLAUDE.md)
- **Problema:** o CLAUDE.md ainda diz "Microsoft Foundry (Azure) … Chat Completions API". Você decidiu em 12/11 trocar para a API da Anthropic, e o adaptador foi feito nesta noite.
- **Texto proposto** (troca o item "Provedor de IA" inteiro):

  > - **Provedor de IA**: API da Anthropic (Messages API, `POST https://api.anthropic.com/v1/messages`, com `tools`/`tool_choice`), modelo `claude-haiku-5-5` (escolhido na bateria de 13/11; `claude-sonnet-5-5` como alternativa), em chamada HTTP REST única, stateless, sem SDK e sem pacote novo, pelo `Http` do Laravel. O Microsoft Foundry (Azure, Chat Completions) continua como alternativa, escolhida por `SENTINEL_IA_PROVEDOR` (`anthropic` | `azure`; vazio = `azure`, que é o padrão dos testes). Nunca usar Agent Service, Assistants API ou Managed Agents (threads, runs, polling). Os dois provedores ficam isolados em `app/Services/AiAgentService.php`, nunca chamados de outro lugar. A resposta da Anthropic é traduzida para o mesmo formato interno, e o motor (RBAC, tenant, Revisar → segurar → Confirmar, auditoria) não muda. Configuração: `ANTHROPIC_API_KEY` (só no .env), `ANTHROPIC_MODEL`, opcionais `ANTHROPIC_EFFORT` (padrão `low`), `ANTHROPIC_MAX_TOKENS` (8000) e `ANTHROPIC_VERSION` (`2026-10-21`). O `claude-sonnet-5-5` recusa `tool_choice` forçado: a tool solicitada vai com `tool_choice: auto`, só ela oferecida e uma instrução no system, e o servidor continua conferindo a divergência (AI_INTENT_MISMATCH).

- **Recomendação:** colar o texto acima. Também conferir se a Documentação 1.2 e o Notion ("Arquitetura") precisam da mesma troca.

## 2. Bateria: "cadastro" caiu de 9/10 (Azure) para 4/10 nos dois modelos da Anthropic
- **Problema:** nos pedidos com dado faltando (12 "cadastre um cliente", 14, 16, 17, 18), o Sonnet e o Haiku **perguntam em texto**. O system prompt manda isso ("se faltar informação obrigatória… pergunte ao usuário em texto antes de chamar a tool"). O Azure ignorava a frase e abria o card com o campo marcado, que é o esperado da bateria.
- **Opções:** (a) mudar o prompt: "com dado obrigatório faltando, chame a tool com o que tiver; o card marca o campo"; (b) mudar o esperado da bateria para aceitar `texto` com pergunta nesses 5; (c) deixar como está.
- **Recomendação:** (a), porque o card com o campo marcado é o fluxo documentado (RF04). É uma frase só, mas muda o comportamento do agente; por isso não fiz sozinho. Depois, rodar só os 5 pedidos de novo (`php artisan sentinel:bateria --somente=12,14,16,17,18`, no SQLite da bateria).

## 3. Item E (D2) e item D (D6): sem definição
- **Problema:** o D2 não está definido em lugar nenhum (`sentinel_clinica_vet.md`, `automacao/`, `.claude/`, os .md do repositório). O único "D2" é o nome "P-D2" numa lista da 2ª leva. O D6 continua igual a 12/11 (só "P-D6" na lista de telas). Sem saber o que é o D2, não há frase para propor.
- **Opções:** (a) você escreve uma linha com o que é cada um; (b) os dois saem da leva.
- **Recomendação:** (a). Os dois ficaram `estacionado`.

---

# Pendências para o Lucas (12/11/2026), da mais para a menos importante

## 1. PHP do pc-talita (8.3.30) não roda o vendor/lock do SENTINEL (exige 8.4.1) — BLOQUEOU A SESSÃO
- **Problema:** o `composer.lock` tem Symfony 8 (PHP >= 8.4). O `composer.json` declara `"php": "^8.3"`,
  e isso engana: o lock não instala no 8.3. Por isso `composer install` deu exit 2 no script e a suíte não rodou.
- **Opções:**
  - (a) Instalar o PHP 8.4 (>= 8.4.27, como no lab) no Laragon do pc-talita e apontar o script para ele. O lock fica intocado.
  - (b) Rodar a automação só no lab, que já tem o 8.4.
  - (c) Rebaixar as dependências para caber no 8.3 (`composer update` com `platform.php`). Isso muda o lock
    e diverge do lab.
- **Recomendação:** (a). Ajustar também o `composer.json` para `"php": "^8.4"`, para que a exigência fique
  explícita (decisão sua, porque toca dependência).
- Detalhe do script: o log anotou `composer exit 2` e seguiu adiante. Vale abortar a preparação nesse caso.

## 2. Itens A–J da leva não começaram
Estão todos `pendente` em `automacao/leva-atual.md`. Retome no lab com `/leva` (primeiro copie
`automacao/leva.md` para `.claude/commands/leva.md`, porque a escrita lá foi negada nesta sessão).

## 3. Item D (D6) sem definição
O `sentinel_clinica_vet.md` só cita o D6 em listas ("Telas 13-15/11 = C11, D4-D7"), sem dizer o que ele é.
- **Opções:** (a) você define o D6 antes da próxima leva; (b) o `/leva` estaciona o item.
- **Recomendação:** (a). Senão, o item será estacionado de novo.

## 4. Permissão para `.claude/commands/`
A escrita em `.claude/commands/leva.md` é negada no modo não interativo.
- **Opções:** (a) liberar `Write(.claude/commands/leva.md)` no `settings.json`; (b) copiar à mão.
- **Recomendação:** (b), uma vez só. Não é preciso abrir permissão permanente.
