# 24/11 — Painel do agente: fila de trabalho (Pendentes agora + Atividade)

## Decisões do Lucas (aprovadas)
- O painel é a fila de trabalho do chat e não repete a Sidebar.
- Fora do escopo: "Aguardando você", "Repetir", lista de conversas, payload ou dado de tutor/pet. Sem migration, tabela ou pacote.

## Verificação pedida: contador x resultado da tool
- **Mensagens novas: bate.** Contador = `status = nova`; `consultar_mensagens` filtra pelo mesmo status.
  O clique agora ENVIA "mostre as mensagens novas da caixa de entrada" (`pedirAoAgente(texto, true)`, que usa o
  `requestSubmit` do mesmo formulário). Limite conhecido: a tool corta em 100 e o contador não.
- **Cobranças: diverge.** Contador = `aberta` + `fechada`; a tool filtra um status só (ou todos), então nenhum pedido
  devolve exatamente o contador. **Mantido o link para /cobrancas, sem `pedido`.**
- **Agenda de hoje: diverge.** Contador = só hoje, só `agendado`/`confirmado`, nos setores do papel; a tool mostra 7 dias
  e todos os status. **Mantido o link para /agenda, sem `pedido`.**
- Teste que prova isso: `test_contador_de_mensagens_bate_com_a_tool_e_so_ela_envia_pedido`.
- Opção descartada: mudar o contador para coincidir com a tool (mexe na definição de "pendente" decidida em 24/11).
- Zero: esmaecido e sem clique (elemento `div`, não `a`/`button`).
- O pedido ao chat depende do modelo escolher o filtro certo; o que o teste garante é o lado do servidor.

## Atividade (substitui Recentes)
- `audit_logs` guarda `entidade_tipo` e `entidade_id`. Só vêm preenchidos em sucesso de criar/atualizar (o executor
  devolve o Model) e em desfecho de atualizar/lixeira. Mas só Tutor e Pet têm tela por registro (`/tutores/{id}`,
  `/pets/{id}`).
- Então só criar/atualizar de Tutor e Pet abre a ficha, para quem pode consultar, e só se o registro ainda existe no tenant
  (excluído, de outro tenant ou inexistente: sem link). Agendamento, cobrança e venda não têm tela por registro; ficam sem
  link (a Sidebar já leva às listas). Alternativa descartada: apontar para a lista, que repetiria a Sidebar.
- Janela: últimas 50 linhas do próprio usuário, sem `consultar`. Agrupa por tool + resultado + registro (no sucesso),
  com ×N e a hora da mais recente. À vista: só `sucesso`, até 5 linhas. O resto vira "Tentativas sem efeito (N)", recolhido
  (`<details>`), até 8 linhas agrupadas. N conta as tentativas dentro da janela de 50.
- Resposta da rota: `atividade.com_efeito[]` e `atividade.sem_efeito{total,itens[]}`; chaves de cada item: acao,
  resultado, quantidade, quando e, às vezes, href. O campo `recentes` deixou de existir.
- Os cartões de "Estoque baixo" **não entraram**: não existe limite mínimo de estoque nem tool que filtre por ele (só o
  alerta de estoque negativo, sem filtro). O atalho existente (`/cadastros?cadastro=`) é do painel do dono.
- Exportação (`acao = exportar`) fica fora da Atividade, como a consulta (achado do reviewer, passada 1).
- Resultado desconhecido aparece como "Registrada", nunca o valor interno.

## Envio direto do pedido (achado da passada 1)
- Enviar um pedido novo descarta a proposta aberta (`AgenteController::comando`). Por isso `pedirAoAgente(texto, true)` NÃO
  envia com um card aberto (`.card-confirmacao:not(.card--expirado)`; card concluído é removido do DOM) nem por cima do que a
  pessoa está digitando: devolve o motivo, o painel o mostra (faixa de aviso, `role=status`) e a gaveta continua aberta.
  Sem card e com o campo vazio, envia.
- **Essa proteção é só de front (JS) e não tem teste automatizado** (o projeto não tem teste de JS). A rede de segurança do
  servidor (token de uso único, `concluir()`) não mudou. Alternativa descartada: só preencher o campo, sem enviar (o Lucas
  pediu envio).
- Limitação conhecida (passada 2): se a pessoa DIGITA outro pedido com um card vivo, o servidor descarta a proposta, mas o card
  antigo segue no DOM sem `card--expirado` até expirar (5 min) ou ser cancelado; nesse intervalo o atalho do painel responde
  "Conclua ou cancele a ação proposta antes" (mensagem imprecisa, sem risco).
- O aviso do painel é limpo ao atualizar o painel (`carregar()`) e antes de cada novo clique.
- `href` do JSON só vira link se for caminho interno ("/x", nunca "//host").
- O teste de "papel sem a leitura" usou o tenant fora da clínica, porque todo papel da equipe pode consultar tutor e pet.
