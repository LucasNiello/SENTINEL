# 25/11 — Agenda: "Qualquer profissional" no servidor, card de tela sem prazo, Confirmar em um passo, toast e listas que se atualizam

Decisões do Lucas (mensagens de 25/11) e o que foi escolhido onde faltava decisão. Documentação vigente: 1.3 (a 1.2 está atrasada).

## Decidido pelo Lucas
- **"Qualquer profissional"** (profissional em branco) é escolhido pelo SERVIDOR, na hora de gravar: entre os do setor com vaga no horário, o de **menos atendimentos no dia** (ativos e realizados; cancelado e faltou não contam); empate no menor id.
- **Card aberto por uma tela não tem prazo** (fora do agente, sem IA propondo). Remover o timer do front e do servidor.
- **Confirmar sem Revisar na tela**: o Confirmar revisa e, com conflito, bloqueia e volta com aviso. Horário cheio não é selecionável.
- **Avisos de erro/negação** viram toast no topo, centralizado, entrando de cima para baixo (não mais um bloco na lista).
- **Toda tela com lista se atualiza sozinha** depois de gravar (padronizar), nada de faixa "Atualizar a tela".
- Profissional: **um campo só** (cascata + busca por digitação).

## Decidido por mim (opção mais simples; o Lucas pode trocar)
- **Chat mantém os 5 min** (CLAUDE.md fixa o prazo da proposta da IA). Só origem `formulario` perde o prazo. Contrato: `prazo_s` (`null` = sem prazo; `300` = chat) em `/agente/comando` e `/agente/formulario`. O front só cria contador se vier número.
- **Teto de 20 cards de tela por usuário**; o mais antigo que passar sai da sessão COM os tokens e é auditado como `cancelado` (toda proposta tem desfecho).
- **Marca de desfecho único** (`concluir`) passa a durar 30 dias (antes: `session.lifetime`), porque a pendência de tela pode viver bem mais; sem isso, duplo clique poderia reconfirmar depois da marca vencer.
- **Setor do papel conferido também na execução** das 4 tools da agenda (card parado ou token antigo após mudança de papel).
- **Confirmar em um passo só nos cards da agenda** (`agendar`, `remarcar`, `trocar serviço`, `mudar status`), via `passo_unico` na resposta. Estorno, PIN, lote e exclusão seguem no fluxo atual. O hold de 2 s continua só em: excluir, cancelar cobrança, estornar, conciliação em lote e cancelar agendamento.
- **Cirurgia e internação não usam "qualquer"** (regra do CRMV): profissional explícito. No remarcar em branco, mantém o atual.
- **Remarcar/trocar em branco**: o atual ganha se tiver vaga; senão o de menos atendimentos. Sem ninguém livre, volta ao atual e a grade explica o motivo (mensagem de antes).
- **Auditoria**: `profissional_escolhido_pelo_servidor: true` + `profissional_id` só quando o servidor escolheu (no remarcar/trocar, só se o profissional mudou).
- **"Não encontrei o serviço"**: causa provada — o select de Serviço listava produto (item #21 "Caminha tamanho M", `tipo=produto`). O campo `catalogo_item_id` agora só oferece `tipo=servico`; a recusa de produto continua com a frase do AgendaService ("é um produto").
- "Último agendamento" (item 1): ver `2026-11-25-ultimo-agendamento.md`.

## Descartado
- Pergunta "Quem atende?" (o servidor escolhe) e campo "Buscar profissional" separado do select (unificados).
- Argumento novo `recente` na tool genérica de atualizar (mexeria em todas as entidades).
- Sintaxe `exists:tabela,col,tipo,servico` nas Requests: tirava a frase amigável "é um produto". A restrição ficou só nas opções do select.
- Eliminar o limite de cards de tela por completo (a sessão incharia).

## Pendente de decisão do Lucas
- `PROFISSIONAIS['clinica']` inclui `dono`: com o desempate no menor id, "qualquer veterinário" tende a cair no dono do seeder em dia vazio. Excluir o dono da escolha automática? (hoje: não excluído)
- Texto do CLAUDE.md ("rascunho válido por 5 min", "token até o fim dos 5 min") e da Documentação 1.3 deve passar a dizer que vale para a proposta do CHAT; o card de tela não vence. (Não editei o CLAUDE.md.)
