# 25/11 — O agente não fazia ajustes simples: referências resolvidas no servidor, data relativa e cancelar em lote

## O problema (conversas do Lucas, reproduzidas com a IA real, haiku, SQLite descartável, ~19 chamadas)
Causa raiz: **o modelo nunca vê as linhas de uma tabela** (só a contagem; decisão de privacidade, mantida). Toda referência ("o Rex", "o último", "esse", "mesmo horário", "todos menos o dia 25") dependia de o servidor resolver, e o servidor só resolvia `posicao` explícita.

| Pedido | Antes (reproduzido) | Depois (mesma conversa, IA real) |
|---|---|---|
| "mostre a agenda de hoje" → "alterar o REX, é tosa…" | `busca: "Rex"` → lista de 6 Rex ("Encontrei mais de um…"), embora a tabela de hoje tivesse 1 Rex | resolve o **#21** (o Rex da tabela) e abre o card |
| "agenda do Thor em dezembro" | `consultar_pets` (a `consultar_agenda` só filtrava por `pet_id`) | `consultar_agenda {pet: "Thor"}` → 1 linha |
| "remarque o último" | `busca: "último"` → último CRIADO do tenant (Rex #52, não o Thor da tela) | `posicao: -1` → #51 (a linha que ele viu) |
| "altere pro dia 21, mesmo horário" | a IA **chutou** `2026-12-21 14:00` (a hora de outro registro) | `dia_do_mes: 21` → `2026-12-21 11:00` (servidor mantém a hora do agendamento) |
| "Agendamento remarcado." (sem tool) | texto passava como se tivesse gravado | trocado por "Nada foi gravado…" (trava) |
| "cancele todos, vamos trabalhar apenas com o do dia 25" | consulta de 8 registros (não há tool de lote) | `cancelar_agendamentos_em_lote {exceto_dia: 2026-11-25}` → lista com checkbox |

## Decidido pelo Lucas
- **Cancelar em lote: sim, mas NUNCA às cegas**: o usuário revisa uma lista e **marca** (checkbox) quais cancelar; exclusão errada gera problema.
- **Dono entra no "qualquer veterinário"** (já era assim); o "qualquer" prioriza quem tem **menos agendamentos no dia** (já implementado).
- **Card de tela sem prazo vale para TODAS as telas** (já era assim).
- **Card compacto no chat (item 5): é no React** (migração em andamento). Não fazer no Blade.

## Decidido por mim (mais simples; o Lucas pode trocar)
- **Referência pela última tabela** (`AiAgentService::localizarRegistro`): só vale se a tabela for da MESMA entidade e estiver dentro do prazo (30 min). (a) `busca` que acha vários → fica só com os que estão na tabela (um = é esse; vários = lista menor); (b) sem id/busca e tabela de 1 linha → essa; (c) `busca` só "o último/o mais recente" com tabela → a última linha dela; sem tabela → o último criado (item 1 de hoje cedo). Tabela de outra entidade não vale (nunca chuta).
- **Data relativa no remarcar**: `dia` (AAAA-MM-DD), `dia_do_mes` (mês do próprio agendamento; 31 em mês de 30 dias → em branco, a ficha pergunta), `hora` (HH:MM). Com `dia`/`dia_do_mes`, o `inicio` que a IA chutar não vale (ela não sabe a hora). `inicio` completo, sozinho, continua como antes.
- **Troca de serviço pelo nome** (`servico`): só quando UM serviço do tenant bate com todas as palavras; senão fica em branco e a ficha pergunta.
- **`consultar_agenda` com `pet` (nome)**.
- **Trava de afirmação falsa** (`AiAgentService::afirmaGravacaoSemTool`): conservadora, só frase curta (≤8 palavras) cujo núcleo é o verbo de gravação, sem negação/pergunta/estado ("está cancelado"). Registra `claim_without_tool` no log (sem texto).
- **Cancelar em lote, desenho (não mexe no RF03)**: a tool `cancelar_agendamentos_em_lote` é de **leitura**: só monta a lista (em aberto, setores do papel, tenant; `exceto_dia`; máx. 25, nunca trunca). Cirurgia/internação e itens com cobrança/prontuário vêm **bloqueados** (checkbox desabilitado, com o motivo). Quem grava é `POST /agente/agenda/cancelar-lote` (`AgendaLoteController`): só ids que estavam na **última lista selecionável do próprio usuário**; **tudo ou nada**, numa transação; cada item passa pelo MESMO executor de `alterar_status_agendamento` (papel, setor, CRMV, cobrança, prontuário, status), com **uma linha de auditoria por item e o mesmo `lote_id`**; se algum barrar, nada é gravado e fica UMA linha `recusado` com os ids e os motivos; a lista vale para **um envio só**. Segurar 2 s (destrutivo) no front.
- Cancelar as datas passadas ainda "agendado" (#30–#34 na demo): pode querer dizer "faltou"; **não decidi isso** (o lote só cancela).

## Descartado
- Enviar rótulos das linhas ao modelo (desfaria a decisão de privacidade).
- Generalizar o lote do RF03 para a agenda (está ligado por nome em ~15 pontos; risco de quebrar o que funciona).
- Reexecução forçada da tool quando o modelo responde só em texto (custa segunda chamada; só se a bateria mostrar que ainda falha).

## Pendente
- Texto do CLAUDE.md sobre os 5 min (vale para a proposta do chat; card de tela não vence): o Lucas ainda não respondeu; não editei.
- Antes de 30/11: rodar na IA real cada pedido que vai para a demo (a bateria nova está em `documentacao/testes/fase8/BATERIA_CLINICA_REFERENCIAS.csv`).

## Revisão do lote (reviewer) — o que mudou depois
- **"Nunca às cegas" de verdade**: a lista guarda no servidor a *versão* (`updated_at`) de cada linha como o usuário a VIU; o lote só cancela se ela continua igual. Remarcado ou alterado por outra pessoa depois de marcar → o item é recusado (e, por ser tudo ou nada, o lote inteiro). A versão nunca vai ao navegador.
- **Prazo**: a lista COM checkbox vale **5 min** (o mesmo da proposta do agente), porque é um caminho de escrita sem rascunho nem token. A tabela segue valendo 30 min só para referências ("o Rex", "o último", "ele").
- **Tudo ou nada com erro técnico**: ids ordenados antes de travar (sem impasse entre lotes que se cruzam); erro técnico (5xx) interrompe o laço e desfaz a transação; fica **uma linha de auditoria "erro"** da tentativa (RF06/RF07).
- **Limite de 200 linhas da consulta** passou a avisar ("restrinja…") em vez de cortar calado antes de filtrar.
- **Filtros por nome** (cliente/tutor, animal/pet, profissional) e `dia`, todos combináveis, em `consultar_agenda` e no lote; sem resultado: "não há … para esses filtros".
- **Trava de afirmação falsa**: sem o radical do infinitivo ("Para excluir…" passa), com "Já cancelei." bloqueado, e "o último que *criei*" ignora a tabela (é o último criado).
- **Provado por teste** (CancelarEmLoteTest): remarcado depois de marcar; versão não vaza; lista vence em 5 min; papel rebaixado com a lista aberta (clínica → atendente); e-mail ao tutor só sai no lote gravado, nunca no recusado; falha técnica desfaz tudo e audita.
- **Não mudei** (baixo risco, relatado): nome ambíguo reduzido pela tabela sem avisar (só vale entre os que estão na tabela que o usuário viu); duplo envio simultâneo só pelo DevTools (recusado pelo estado, dados íntegros).

## A voz do agente (pedido do Lucas, 25/11): conversar, não "avisar"
Problema: "Aviso: Encontrei mais de um registro com esse nome. Escolha um na lista ou peça de novo com o número." e afins soavam como mensagem de sistema.
- **O modelo passa a escrever uma frase natural JUNTO da tool** ("Vou montar a lista dos agendamentos da Ana Beatriz para você marcar quais cancelar."). Vem em `introducao` (só nas respostas `confirmacao_pendente` e `resultado_leitura`), a tela mostra **antes** do card/tabela. Não entra no histórico nem em nada gravado. Sem custo extra: é a mesma chamada, o texto já vinha e era ignorado.
- **Filtro da frase** (`AiAgentService::introducaoDoModelo`): descarta (sem erro; o card/tabela seguem) se tiver mais de 300 caracteres, link, ou **afirmar que algo foi remarcado/agendado/cancelado/gravado ("Pronto", "Feito", "Já cancelei")**; isso só vale depois da confirmação humana, e quem avisa é o servidor.
- **Prompt (só o da clínica; o da contabilidade segue byte a byte)**: tom de colega prestativo, 1ª pessoa, PT-BR; uma frase curta junto de cada tool; nunca citar ids/CPF/telefone/valores; **variar a abertura** (a 1ª versão começava todas com "Beleza!").
- **Frases fixas reescritas** (servidor e telas): pergunta de desambiguação com a quantidade ("Achei 4 agendamentos que batem com “Rex”. Qual deles você quer? É só clicar em “Escolher” na linha certa (ou me diga o dia e o horário)."), exibida **antes** da lista; "Qual cliente você quer alterar? Me diga o número ou o nome."; "Calma, ainda não alterei nada! Me diz o que você quer fazer…"; lote ("Pronto, cancelei os 3 agendamentos que você marcou.", "Não cancelei nada: …O motivo está na linha…", "Não encontrei nenhum agendamento em aberto com esses filtros… Quer tentar outro período?"); cancelar/expirar/soltar o botão ("Tudo bem, cancelei o pedido. Não alterei nada."); erros HTTP do chat ("A IA demorou demais para responder. Tenta de novo?"); toast "✔ Feito!".
- **Mantido de propósito**: "Não encontrei …" (já é 1ª pessoa, 62 asserções em 24 arquivos de teste) e as recusas de regra de negócio do domínio; as mensagens de validação por campo. Se o Lucas quiser, dá para humanizar essas também, por rodada.
- Testado: `AgenteReferenciasTest` (introducao, descartes, pergunta de desambiguação) + 11 asserções de frases antigas atualizadas de propósito.
