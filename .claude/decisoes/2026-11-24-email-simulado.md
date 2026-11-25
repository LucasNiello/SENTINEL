# 24/11 — E-mail simulado (Mailpit): saída e entrada

Escopo aprovado pelo Lucas: SAÍDA (e-mail ao tutor ao criar, confirmar e cancelar agendamento) e ENTRADA (e-mails para
`contato@clinica.sentinel.br` viram "Mensagens novas"). Só simulação com o Mailpit local (1.22.3, SMTP 1025, API 8025).

## Feito
- **Saída:** `NotificacaoDeAgendamento` + `AgendamentoMail` (texto puro) + view `emails/agendamento`. Disparo em
  `AgendaService` via `DB::afterCommit` (agendar, e alterarStatus para `confirmado` e `cancelado`). Sem e-mail válido do tutor,
  tutor de outro tenant, encaixe de emergência: não envia. Falha de envio nunca afeta o agendamento (log só com metadados).
  Timeout do SMTP: 3 s (`MAIL_TIMEOUT`). O seed desliga o aviso (`sentinel.notificar_agendamento`).
- **Entrada:** `CaixaDeEntrada` (lê a API do Mailpit: busca `to:<contato> is:unread`, detalhe, marca como lido). Rota
  `POST /agente/caixa/importar` (nunca GET), trava de 10 s por tenant (`Cache::add`), só no tenant do contato e para quem pode
  `consultar_mensagens`. O painel chama a rota antes de carregar e ao clicar em "Mensagens novas". Origem aparece sob o rótulo.
- **Migration nova (decisão minha):** `origem_id` em `mensagens` com índice único (`tenant_id`, `origem_id`). Alternativa
  descartada: só marcar como lido (falha = duplicata). O Lucas pediu "evite duplicar mesmo se marcar como lido falhar".
- **Configs:** `sentinel.notificar_agendamento` e `sentinel.contato.*`; `MAIL_*` no `.env` e no `.env.example`
  (smtp, 127.0.0.1:1025, remetente `contato@clinica.sentinel.br`). **O `.env` não vai para o git: refazer no outro PC.**
- **Doc da demo:** `documentacao/Demo-email-simulado.md` (comandos PowerShell e cmd, limitação SPF/DKIM).

## Correções da revisão (passada 1; só os críticos)
- Pedido de e-mail longo com aspas não cai mais no texto cru: corta o texto e mantém o enquadramento (`pedidoDaMensagem`,
  só para mensagem com `origem_id`; o seed mantém o comportamento antigo).
- A tabela mostra o remetente real marcado "(remetente não verificado)" nas mensagens de e-mail (o "De" é forjável).
- Entidades (`&lt;script&gt;`) são decodificadas ANTES de tirar as tags.
- O teste do e-mail malicioso lia o campo errado e pulava a checagem; corrigido (`resultado`).

## PENDENTE (do reviewer, não corrigido de propósito; o Lucas mandou fechar sem a passada 2)
1. **Hora errada para e-mail que não vem em -03:00** (`CaixaDeEntrada::criar`): falta
   `$chegada->setTimezone(config('app.timezone'))`; um `Date` em `Z` fica gravado 3 h no futuro. Falta teste com `Date` em `Z`.
2. **Teste da pergunta 6 pelo caminho real:** agendar / `alterar_status_agendamento` via `/agente/confirmar` com a auditoria
   lançando exceção dentro do `DB::transaction` de `AiAgentService` (~linha 888) → `Mail::assertNothingSent`; e o sucesso →
   exatamente 1 e-mail. Hoje só há teste com `DB::transaction` manual.
3. **Sem teto de tempo total na importação:** até 25 detalhes x 3 s mais busca e PUT; com o Mailpit LENTO (não fora do ar)
   passa do `max_execution_time` (30 s). Sugestão: prazo total de ~5 s no laço ou `POR_VEZ` menor.
4. **Doc, linha "qualquer pessoa na rede local":** só vale se o Mailpit escutar fora de 127.0.0.1; com os endereços da doc é
   qualquer processo da máquina. Ajustar o texto.
5. Sem 2ª passada do reviewer (mexeu em tenant e dado pessoal; o padrão pede 2). Fazer a 2ª, só sobre as correções acima, ao retomar.

## NÃO verificado
- **Comandos de PowerShell e de cmd da doc NÃO foram executados** (o harness bloqueou a execução). O mesmo envio foi
  testado com `curl` no Git Bash contra o Mailpit real: `POST /api/v1/send` aceitou, a busca `to:... is:unread` achou e
  `PUT /api/v1/messages` marcou como lido (as mensagens de teste foram apagadas).
- **Nenhum e-mail real passou pelo SMTP do Mailpit** a partir do app (os testes usam `Mail::fake`/`Http::fake`). Falta o
  teste manual ponta a ponta: agendar pelo agente → ver o e-mail no Mailpit; enviar e-mail ao contato → ver em Mensagens novas.
- O visual do painel (rótulo + origem em 2 linhas, faixa de aviso) não foi visto no navegador. Se o CSS parecer antigo: `npm run build`.
- Suíte completa NÃO rodada neste bloco (a última completa foi a de 24/11 do bloco do painel: 1274/1275, com a falha corrigida
  depois). Rodar em casa: `php artisan test`.

## Como retomar
1. `git pull`; `php artisan migrate` (migration nova); conferir o `.env` (`MAIL_*`, ver a doc da demo); Mailpit no ar.
2. `php artisan test` (suíte completa) e ver o resultado.
3. Teste manual ponta a ponta (acima), depois tratar os pendentes 1 a 5.
4. Fora deste bloco, e a pedido do Lucas: arrumar os calendários das remarcações; e-mail de REMARCAR (fica para depois).
