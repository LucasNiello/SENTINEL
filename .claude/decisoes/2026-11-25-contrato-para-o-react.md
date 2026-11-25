# Contrato para o React (Matheus): o que mudou no servidor em 25/11 e o que a tela deve fazer

O Blade já segue tudo isto (`bancada`, `ficha-agendamento`, `_acoes`, `consulta`). O React deve fazer igual; o servidor aceita os dois contratos (o antigo `revisar → token → confirmar` continua valendo).

## Respostas de `/agente/comando` e `/agente/formulario` (`confirmacao_pendente`)
- `prazo_s`: `300` = proposta do chat (vence em 5 min; mostrar "prazo terminou" e deixar **Fechar** ativo). `null` = card aberto por tela: **sem contador, sem prazo**.
- `passo_unico: true` (só a agenda: agendar, remarcar, trocar serviço, mudar status): **não há etapa Revisar**. Mostrar só **Confirmar** (+ Cancelar).
- `segurar` / `segurar_quando` (`{campo, valor}`): o segurar de 2 s vale quando o campo `campo` tiver o valor `valor` (hoje: `novo_status = cancelado`); reavaliar quando o campo mudar.

## Confirmar em um passo (`passo_unico`)
- `POST /agente/confirmar` com `{ proposta, valores: {campo: valor}, email_admin?, senha_admin? }` (sem `token`).
- **422 com `formulario`** (vaga, horário, versão, campo inválido): nada foi gravado. Manter o card **aberto e editável**, mostrar `formulario.erro`/`mensagem` e o erro de cada campo, recarregar o calendário e reaparecer o Confirmar. A senha/CRMV digitada foi apagada: avisar.
- Outros erros (403, 409 duplo clique, 5xx, `AI_TOOL_REFUSED` depois de executar): fechar o card e mostrar toast.
- Sucesso: `resultado_escrita` (`mensagem` inclui "Profissional escolhido: X." quando o servidor escolheu).

## Profissional "Qualquer"
- `profissional_id` **em branco** = "qualquer": o servidor escolhe na gravação, dentro da transação (menos atendimentos no dia; empate no menor id; só quem tem vaga no horário). Não existe a pergunta "Quem atende?".
- Cirurgia e internação não aceitam "qualquer" (CRMV): o profissional é explícito.
- UI: **um combobox só** (opção "Qualquer…" sempre presente + os profissionais do setor, filtro por digitação, ARIA combobox/listbox, `autocomplete="off"`), no lugar de "Buscar profissional" + select.
- O campo `profissional_id` do formulário **sempre** acompanha o combobox.

## Calendário
- Clicar num horário **só seleciona** (dá para trocar). Dia, horário e profissional escolhidos ficam **marcados** (quadrado cheio + `aria-pressed`). Horário cheio fica apagado e sem clique.
- `catalogo_item_id` nos formulários de agenda só oferece **serviço** (`tipo=servico`).

## Depois de gravar
- Toda tela com lista **se atualiza sozinha** (refaz a leitura com os mesmos filtros, destaca por alguns segundos o registro novo/alterado, anuncia para leitor de tela). Sem faixa "Atualizar a tela". Falha do refresh: aviso com "Tentar de novo".
- Erro e negação fora do chat: **toast** no topo, centralizado, entrando de cima para baixo (`prefers-reduced-motion` desliga o movimento). No chat do agente, a mensagem continua na conversa.

## Testes de navegador (`tests/browser/Chat*.mjs`)
Os que esperam o botão Revisar nos cards da agenda, "Quem atende?" ou "Buscar profissional" precisam ser atualizados para o contrato acima.

---

# Acréscimo (25/11, tarde): o que o agente passou a devolver e o que a tela do chat deve fazer

## Lista com checkbox: cancelar agendamentos em lote (`resultado_leitura` com `selecao`)
- A resposta da tool `cancelar_agendamentos_em_lote` é um `resultado_leitura` normal (colunas: id, horario, pet, tutor, servico, profissional, status, observacao) **mais** `selecao: { url: "/agente/agenda/cancelar-lote", rotulo: "Cancelar selecionados", segurar: true }`.
- Cada linha tem `bloqueado` (boolean): `true` = checkbox **desabilitado** e `observacao` traz o motivo (cirurgia/internação exigem CRMV e senha, um a um; cobrança ou prontuário).
- UI: 1ª coluna com checkbox por linha + "selecionar todos" (só os habilitados); **nada marcado por padrão**; botão "Cancelar N marcados" desabilitado com 0 marcados, **segurar 2 s** (mouse, toque, Enter/Espaço); soltar antes não envia nada. Alvos de toque de 44 px.
- Envio: `POST {selecao.url}` com `{ ids: [..] }` (até 25). O servidor só aceita ids que estavam na **última lista selecionável do próprio usuário** e **grava tudo ou nada**.
  - `200 resultado_escrita` (`cancelados`, `lote_id`, `mensagem`): marcar as linhas como canceladas (status "cancelado", riscadas), desabilitar tudo, toast de sucesso, disparar a atualização das listas das telas.
  - `422 AI_BATCH_REFUSED` com `erros: { "<id>": "<motivo>" }`: nada foi gravado. Apontar o motivo **na própria linha**, desmarcar essas linhas, manter a lista e o botão para tentar de novo.
  - `422 AI_BATCH_LIST_EXPIRED` / `AI_BATCH_IDS_NOT_LISTED`: a lista não vale mais (um envio só, ou 30 min): mostrar o aviso e pedir de novo.

## Referências ("o Rex", "o último", "esse", "ele") — nada a fazer na tela
O servidor resolve pela **última tabela mostrada** (mesma entidade, até 30 min). A tela só precisa continuar mostrando a tabela que o usuário vê e guardando nada dela; o front **não** manda ids. Se a tabela for de outra entidade, a referência não vale e o servidor pergunta.

## Remarcar com data relativa
O card de remarcar já chega com o `inicio` montado pelo servidor (`dia`, `dia_do_mes`, `hora` + a hora atual do agendamento). A tela só mostra e deixa editar no calendário.

## Resposta de texto
O servidor troca por "Nada foi gravado…" qualquer texto curto do modelo que afirme gravação sem tool. A tela mostra o texto como veio.

## Card compacto no chat (item 5 — React)
Para pedido explícito já resolvido (id e novo horário definidos, sem conflito), mostrar uma linha de resumo — "Thor · Banho porte grande: 22/12 08:00 → 21/12 08:00" — com **Confirmar**, **Editar** (abre a ficha completa) e **Cancelar**. O card hoje fica longo e corta atrás da barra de digitação.

## Voz do agente: `introducao` e a pergunta de desambiguação
- `confirmacao_pendente` e `resultado_leitura` podem trazer **`introducao`** (string, até 300 caracteres): a frase natural que a IA escreveu junto da tool. **Mostrar como mensagem do agente ANTES do card ou da tabela.** É texto simples (`textContent`), nunca HTML.
- `aviso` (ex.: "Achei 4 agendamentos que batem com “Rex”. Qual deles você quer? …") é uma **frase de conversa**: mostrar como mensagem normal do agente, **antes** da lista quando a resposta for uma lista para escolher. **Sem o prefixo "Aviso:"** e sem estilo de alerta.
- Textos de erro e de "nada gravado" do chat e dos toasts seguem o mesmo tom (1ª pessoa, curtos, dizendo o que aconteceu e o que fazer a seguir). Os do servidor já vêm prontos; a tela não deve reescrevê-los.
