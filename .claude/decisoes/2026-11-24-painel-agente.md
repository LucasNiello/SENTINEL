# 24/11 — Painel lateral do /agente

## Decisões do Lucas
- **A conversa fica só na sessão, por desenho.** O agente é stateless (Documentação 1.2) e o chat pode carregar dado de tutor
  (privacidade/LGPD). Por isso **não existe** lista de "conversas abertas / não finalizadas": a Etapa 2 (tabelas `conversas` e
  `conversa_mensagens`) foi **descartada**. Persistir conversas é **trabalho futuro** e exigiria decidir retenção, apagamento e
  LGPD antes. Nenhuma tabela nem migration foi criada.
- **Painel só com o que já existe:** "Pendentes agora" (negócio: cobranças pendentes, mensagens novas, agenda de hoje; sem
  rascunhos da sessão) e "Recentes" (ações do próprio usuário em `audit_logs`: ação, resultado e horário, sem payload e sem
  dado de tutor). Sem "Atalhos" (a sidebar já tem).
- **Recolhível:** aberto por padrão a partir de 1280 px (a escolha fica no navegador); abaixo disso começa recolhido e, aberto,
  vira gaveta por cima do chat, sem encolher a conversa.
- **Rota** `GET /agente/painel`: só leitura, `throttle:agente-sem-ia`, tenant/papel/usuário do `ContextoUsuario`.

## Decisões minhas (opção mais simples, coerente com a Documentação 1.2)
- **"Nova conversa" não entrou:** zerar a conversa pede uma rota de escrita (a memória do agente está na sessão) e não estava
  na lista aprovada. Descartada; fica para quando houver decisão sobre conversas.
- **Recentes exclui `consultar`:** cada pergunta de leitura gera uma linha de auditoria; incluí-las afogaria o que foi
  *feito* (criar, atualizar, cobrar, negado). Alternativa descartada: mostrar tudo.
- **Pendentes só na clínica:** fora da clínica a lista vem vazia (o painel mostra "Nada para o seu papel aqui"); só os
  Recentes valem. Cada contagem exige a tool de leitura da tela correspondente (`consultar_cobrancas`, `consultar_mensagens`,
  `consultar_agenda`); sem permissão o item some. A agenda respeita os setores do papel (atendente só banho e tosa).
- **Cobranças pendentes = `aberta` + `fechada`** (a receber), a mesma noção da tela /cobrancas.
- **Administrador da clínica leva 403** (só gerencia PINs; a bancada o leva à /supervisao).
- **A leitura do painel não grava auditoria própria** (é um resumo de contagens e das próprias ações; auditar cada atualização
  encheria a trilha). As telas de detalhe seguem auditando como antes. **A confirmar com o Lucas:** o 403 do Administrador da
  clínica também não deixa rastro (o /painel do dono audita o `negado`). A rota fica atrás do middleware que varre
  confirmações vencidas, então pode gravar linhas "expirado" como qualquer outra rota do grupo.
- **Limite próprio** `throttle:120,1,painel-agente` (o painel recarrega com frequência e não deve gastar os 30/min do Revisar).
- **Rótulo da ação** vem do título da tool (`AiAgentService::tituloDaTool`), nunca do identificador interno; tool sem título
  aparece como "Ação registrada".
- **Contagem de cobranças sem teto**, enquanto a tela /cobrancas corta em 100: com volume alto o painel pode mostrar mais
  que a tela lista.
- **Altura da janela** passou do `.agent-workspace` para o `.command-layout`, para o painel e o chat terem a mesma altura.

## Conflito de merge a conferir
`origin/manveru-frontend` tem 6 commits (23-24/11) que mudam `agente.blade.php`, `workspace.css` e `bancada.blade.php`
(rolagem do chat, "Novas mensagens"). O painel foi feito em arquivos novos (`partials/painel-agente.blade.php`,
`resources/css/painel-agente.css`, `AgentePainelController`) com só ganchos pequenos nos arquivos compartilhados, para o
merge ser curto.
