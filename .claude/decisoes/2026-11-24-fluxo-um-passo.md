# Rascunho de documentação — Confirmar em um passo (para o Lucas colar)

Nada aqui foi aplicado: o CLAUDE.md, a Documentação 1.2 e o Notion continuam como estavam. Escrito em 24/11.

## 1. Texto proposto para a seção "Fluxo de escrita do agente" do CLAUDE.md

Substitui os quatro primeiros itens da seção atual (rascunho de 5 min, `POST /agente/revisar` com token, a decisão do segurar de 13/11 e `POST /agente/confirmar` com token). **Mantém** o bloco "Estorno e PIN" e o "RF03", com uma única troca, mostrada no item 1b abaixo.

```markdown
## Fluxo de escrita do agente

- Toda tool de escrita vira um **rascunho** na sessão, válido por 5 min
  (`ConfirmacoesPendentes::PRAZO_RASCUNHO_S`). O `/agente/comando` devolve a `proposta` (uuid), o
  formulário, `segurar` e `segurar_quando`.
- **Confirmar em um passo (decisão do Lucas, 23/11, opção B):** `POST /agente/confirmar` recebe
  `{proposta, valores?, plano_ref?, email_admin?, senha_admin?}` e faz o Revisar e o Confirmar na mesma
  requisição: aplica os `valores` do card sobre o rascunho, confere (RBAC, tenant, Form Request), congela o
  plano do estorno, confere senha/PIN/CRMV e executa — sempre com a tool e o tenant
  da sessão. Os `valores` do card vêm do navegador neste mesmo POST, mas são revalidados no servidor antes de
  executar; `tool` e `tenant_id` do corpo são ignorados e segredos dentro de `valores` são removidos.
  Nada grava sem esse POST, que só o clique em Confirmar dispara.
- **Prévia:** `POST /agente/revisar?previa=1` devolve avisos e plano (e `plano_ref` nos estornos) sem emitir
  token, sem auditar, sem gravar e sem ler senha/PIN/CRMV. Tem balde próprio (30/min).
- **`plano_ref` (decisão do Lucas, 24/11, opção B):** referência (hash) das linhas que a prévia do estorno
  mostrou. Opcional no Confirmar; se vier e o plano recalculado for outro, 422 `AI_PLAN_CHANGED`, nada gravado,
  proposta continua em rascunho. Sem `plano_ref`, o servidor grava o plano recalculado no Confirmar.
- **Desfechos:** um erro de validação é 422 `AI_TOOL_REFUSED` (valores preservados, proposta continua,
  auditado `revisao_com_erro`); sem permissão, 403 e a proposta acaba (`negado`); senha/PIN/CRMV recusados
  encerram a proposta (`negado`); vencida é `expirado`; cancelada/substituída são `cancelado`; duplo clique
  grava uma vez só (409). O `/agente/confirmar` tem teto de 30/min por usuário (`agente-confirmar`, 429).
- Decisão do Lucas (13/11, opção B): o servidor não tem janela de tempo. O segurar 2 s (`DURACAO_HOLD_MS` no
  front) é só gesto visual, nas ações destrutivas: excluir para a lixeira e excluir de vez, cancelar cobrança,
  estornar, conciliação em lote e cancelar agendamento; a tela abre o card já sabendo disso
  (`segurar`; `segurar_quando` quando depende de um campo). As demais escritas confirmam com um clique.
- **Dívida técnica:** o contrato antigo (`/agente/revisar` com token + `/agente/confirmar {token}` e
  `/agente/editar`) continua aceito até o B2 (front) usar só o novo; depois sai.
```

### 1b. Frase a trocar no bloco "Estorno e PIN"

Hoje: "O Revisar congela o plano mostrado no card."
Proposta: "O plano do estorno mostrado na prévia vai no Confirmar como `plano_ref` (opcional); se o plano recalculado for outro, 422 `AI_PLAN_CHANGED` e nada é gravado. Sem `plano_ref` (o front ainda não o envia), o plano é congelado no Confirmar, com os valores recebidos, e o PIN autoriza o que o servidor recalculou."

## 2. Parágrafo equivalente para a Documentação 1.2

> **Fluxo de escrita (atualizado em 24/11/2026).** Toda operação de escrita proposta pelo agente nasce como rascunho, válido por cinco minutos, e só é gravada quando o usuário aciona "Confirmar". Na versão atual, o Confirmar acontece em um único passo: o navegador envia a proposta, os valores que o usuário editou no card e, quando a ação exige, a senha de administrador, o PIN do supervisor ou o CRMV com a senha; o servidor revalida tudo (perfil de acesso, empresa, regras do formulário), refaz o plano de estorno e executa com a ferramenta e a empresa guardadas na sessão; os valores editados no card chegam nesta mesma requisição e são revalidados pelo servidor antes de gravar. Antes de confirmar, a tela pode pedir uma prévia, que mostra avisos e o plano sem gravar, sem registrar auditoria e sem receber credenciais. Nos estornos, a prévia devolve uma referência do plano mostrado; se o plano mudar até o Confirmar (por exemplo, outro usuário estornou uma linha), o servidor recusa e mostra o plano novo, sem gravar. Todo desfecho continua auditado (sucesso, recusa de validação, negado, expirado e cancelado). Esta versão substitui o fluxo anterior de dois passos (Revisar emitindo um token, depois Confirmar com o token).

## 3. Linhas para o Notion — "Divergências e Ajustes"

Título | Descrição | Severidade sugerida
---|---|---
Fluxo de escrita virou "Confirmar em um passo" | O Revisar com token foi absorvido pelo `/agente/confirmar`; CLAUDE.md e Documentação 1.2 ainda descrevem dois passos. Texto novo proposto em `.claude/decisoes/2026-11-24-fluxo-um-passo.md`. | Média (documentação desatualizada)
Contrato antigo `{token}` e `/agente/editar` seguem vivos | Dívida técnica até o B2 (front) migrar; plano de remoção na seção T4 de `.claude/decisoes/2026-11-23.md`. | Baixa
Plano do estorno visto = plano estornado | Novo `plano_ref` opcional (hash das linhas); com ele divergente, 422 `AI_PLAN_CHANGED` e nada gravado. Sem `plano_ref` a garantia fica inerte até o B2 enviá-lo. | Média (regra de 14/11 só vale com o B2)
Teto no Confirmar | Limiter `agente-confirmar` (30/min por usuário) criado porque o caminho novo faz o trabalho do Revisar e audita `revisao_com_erro`. | Baixa
Segredos fora de `valores` | Lista única `CHAVES_SECRETAS` removida de `valores` no Revisar, na prévia e no Confirmar; linhas do estorno e itens da venda só mantêm as chaves do esquema. | Baixa (resolvido)
Lixeira: filtro por nome | `consultar_lixeira` ganhou o parâmetro `nome` (LIKE escapado); teste provado vermelho sem o filtro. | Baixa (resolvido)
Estorno ambíguo | A lista de candidatas ganhou data e total; o modelo ainda pode passar `id` por conta própria (só prompt resolve). Sugestões de prompt na T8. | Média (pendente: prompt)

## 4. Pendências para o Lucas

- Colar os blocos 1, 1b e 2 (CLAUDE.md e Documentação 1.2) quando decidir.
- Decidir se aplica as linhas de prompt da T8 (o prompt de sistema não foi alterado).
- O B2 (front) precisa existir para o `plano_ref` valer na prática.
