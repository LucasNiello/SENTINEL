---
description: Gera o esqueleto de uma tool nova para o AiAgentService, já com RBAC, confirmação dupla (se for escrita) e gravação em audit_logs embutidos por construção — não opcionais.
---

Tool a criar: $ARGUMENTS

Se faltar informação, pergunte antes de gerar código:
- Nome da tool (verbo_substantivo em português, ex.: criar_cliente)
- Tipo: leitura ou escrita
- Entidade/service que ela chama
- Papel mínimo de RBAC exigido
- Se escrita: quais parâmetros exigem confirmação dupla antes de gravar

Passos:
1. Releia como referência o par `consultar_lancamentos` (leitura) e
   `criar_lancamento` (escrita) no AiAgentService atual — siga a mesma
   estrutura de definição de tool e de execução.
2. Gere a definição da tool (formato function calling) e adicione ao array
   retornado por `tools()`.
3. Gere o branch de execução em `executarTool()`/fluxo de confirmação:
   - Leitura: chama o service e retorna direto.
   - Escrita: NUNCA grava direto. Retorna confirmação pendente primeiro,
     só grava depois do `/agente/confirmar`, igual ao `criar_lancamento`.
4. Adicione a checagem de RBAC mínima antes de executar.
5. Adicione a chamada real de gravação em `audit_logs` (tool, ação, entidade,
   parâmetros, resultado) — isto é obrigatório na tool, não deixe como TODO.
   Se `audit_logs` ainda não estiver sendo gravado em nenhuma tool existente
   no momento em que você rodar isso, PARE e me avise antes de prosseguir:
   é um débito conhecido (RF06/RF07) que precisa ser resolvido primeiro, ou
   a tool nova nasce com o mesmo problema.
6. Gere um teste cobrindo: RBAC negando acesso, fluxo de confirmação (para
   escrita), e o registro de auditoria sendo gravado de fato.
7. NÃO rode nenhum comando git de escrita.
8. Ao final, rode os testes novos e reporte o resultado.
