# Pendências para a Documentação 1.3

Mudanças já feitas no código que a Documentação 1.2 (`Documentacao-Sentinel1.2.docx`) ainda descreve do jeito antigo. O `.docx` não foi editado; este arquivo lista o que precisa entrar na 1.3.

## 1. Confirmação das escritas do agente: sem janela de tempo no servidor (decisão do Lucas, 13/11/2026, opção B)

- **Antes (1.2):** toda escrita pedia segurar o botão por 2 s, e o servidor só aceitava a confirmação entre 2 e 12 s depois do Revisar.
- **Agora:**
  - o servidor não tem espera mínima nem validade de 12 s; o token de confirmação vale até o fim dos 5 min da proposta;
  - segurar 2 s virou só gesto visual, nas ações destrutivas: excluir (para a lixeira e de vez), cancelar cobrança, estornar, conciliação em lote e cancelar agendamento;
  - as demais escritas confirmam com um clique.
- **Motivo:** no domínio da clínica, um erro fora do prontuário pode ser corrigido sem consequência.
- **Continua igual:**
  - rascunho na sessão (5 min) e Revisar validando sem gravar;
  - token de uso único e execução só com os valores revisados da sessão;
  - RBAC, tenant do servidor, PIN/senha do estorno e da lixeira, auditoria e o lote com `lockForUpdate`;
  - o prontuário não muda, e o agente continua sem escrever conteúdo clínico.
- **Onde atualizar na 1.3:** RNF02 (confirmação humana), a descrição do fluxo Revisar → segurar → Confirmar e a seção de interface (sinalização de risco e segurar).
- **Também desatualizado:** o prompt do agente ainda cita "(hold-to-confirm)". Não mexi no prompt porque não houve chamada real para medir o efeito. (As skills do repositório e o checklist do `reviewer` já foram atualizados em 13/11.)
