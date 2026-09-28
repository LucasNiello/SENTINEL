---
description: Gera o esqueleto de uma tool nova para o AiAgentService, já com RBAC, confirmação dupla e validação por Form Request (se for escrita), classe de risco e gravação em audit_logs embutidos por construção — não opcionais.
---

Tool a criar: $ARGUMENTS

Siga as skills `sentinel-backend` e `sentinel-testes`.

Se faltar informação, pergunte antes de gerar código:
- Nome da tool (verbo_substantivo em português, ex.: criar_cliente)
- Tipo: leitura ou escrita
- Classe de risco: leitura, escrita ou exclusão (pelo efeito, não pelo nome)
- Entidade/service que ela chama
- Papel mínimo de RBAC exigido
- Se escrita: quais parâmetros exigem confirmação dupla antes de gravar e qual
  Form Request valida esses parâmetros

Passos:
1. Releia como referência o par `consultar_lancamentos` (leitura) e
   `criar_lancamento` (escrita) no `catalogo()` do AiAgentService atual — siga
   a mesma estrutura de definição de tool e de execução.
2. Adicione uma entrada `definirTool()` dentro de `catalogo()`, a fonte única
   de tools: nome, descrição, tipo, ação, entidade auditada, papel mínimo,
   propriedades do schema (nunca `tenant_id` — o servidor injeta), campos
   obrigatórios, executor e mensagem de erro genérica; em leitura, também
   `colunas`. `tools()` (o que vai ao modelo) e `executarTool()` derivam dessa
   entrada — não existe segunda lista para editar.
3. Escreva o executor (closure `executar` da entrada):
   - Leitura: chama o service passando `$a['tenant_id']` e devolve o resultado;
     o `executarTool()` corta as `colunas` declaradas e mascara CPF
     (`AuditoriaService::mascararCamposSensiveis()`).
   - Escrita: NUNCA grava direto. O `processar()` devolve confirmação pendente
     primeiro; o executor só roda depois do `/agente/confirmar`, igual ao
     `criar_lancamento`. Antes de chamar o service, o executor valida os
     argumentos com o Form Request da entidade via `validar()` (que escopa
     `exists:` pelo tenant e devolve frase amigável).
4. Declare o papel mínimo na entrada definirTool(); o RBAC já é aplicado a
   partir dele. Não duplique a checagem no código da tool.
5. A gravação real em `audit_logs` (tool, ação, entidade, parâmetros,
   resultado) é obrigatória na tool, não deixe como TODO: ela vem da ação e da
   entidade declaradas na entrada e é feita pelo `executarTool()` na mesma
   transação da escrita.
6. Declare a classe de risco da tool (leitura / escrita / exclusão), que a
   interface usa na borda do card (skill `sentinel-visual`, seção 5).
   (pendente: o catálogo e a resposta confirmacao_pendente ainda não levam a
   classe; entra na Tarefa B)
7. Gere um teste cobrindo: RBAC negando acesso, fluxo de confirmação (para
   escrita), validação com frase amigável (para escrita), CPF mascarado na
   resposta (para leitura que devolva documento/cpf) e o registro de auditoria
   sendo gravado de fato.
8. NÃO rode nenhum comando git de escrita.
9. Ao final, rode os testes novos e reporte o resultado.
