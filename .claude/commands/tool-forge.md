---
description: Gera o esqueleto de uma tool nova para o AiAgentService, já com RBAC, confirmação dupla e validação por Form Request (se for escrita), classe de risco e gravação em audit_logs embutidos por construção — não opcionais.
---

Tool a criar: $ARGUMENTS

Siga as skills `sentinel-backend` e `sentinel-testes`.

Se faltar informação, pergunte antes de gerar código:
- Domínio: contabilidade ou clínica (define o catálogo onde a tool entra; o
  catálogo é escolhido por tenant em `ContextoUsuario::dominio()`, via
  `sentinel.tenants_clinica`)
- Nome da tool (verbo_substantivo em português, ex.: criar_cliente)
- Tipo: leitura ou escrita
- Classe de risco: leitura, escrita ou exclusão (pelo efeito, não pelo nome)
- Entidade/service que ela chama
- Papel mínimo de RBAC exigido
- Se escrita: quais parâmetros exigem confirmação dupla antes de gravar e qual
  Form Request valida esses parâmetros

Passos:
1. Releia como referência uma tool análoga do domínio escolhido e siga a
   mesma estrutura de definição e de execução:
   - Contabilidade: o par `consultar_lancamentos` (leitura) e
     `criar_lancamento` (escrita) em `AiAgentService::toolsDeContabilidade()`.
   - Clínica: uma tool de `CatalogoDaClinica::toolsDaClinica()`
     (`app/Services/Agente/CatalogoDaClinica.php`).
2. Adicione a entrada no catálogo do domínio certo, a fonte única de tools:
   `toolsDeContabilidade()` em `app/Services/AiAgentService.php` ou
   `toolsDaClinica()` em `app/Services/Agente/CatalogoDaClinica.php`. Na
   contabilidade, monte a entrada com `definirTool()`. Na clínica, use os
   construtores do trait `ConstrutoresDeTool`
   (`app/Services/Agente/ConstrutoresDeTool.php`) quando couberem:
   `definirCadastro` (consultar + criar + atualizar de uma entidade) e
   `definirAcao` (operação de negócio que não é CRUD: agendar, cobrar,
   vender...); só use `definirTool()` direto se nenhum dos dois servir. A
   entrada leva: nome, descrição, tipo, ação, entidade auditada, papel
   mínimo, propriedades do schema (nunca `tenant_id` — o servidor injeta),
   campos obrigatórios, executor e mensagem de erro genérica; em leitura,
   também `colunas`. `tools()` (o que vai ao modelo) e `executarTool()`
   derivam dessa entrada — não existe segunda lista para editar. Tool de
   outro domínio nunca pode aparecer nem executar para o tenant deste.
   Exclusão/lixeira não se declara à mão: vem de `definirLixeira` (lista
   `lixeiras()` / `lixeirasDaClinica()`).
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
6. Declare a classe de risco da tool (`risco`: leitura / escrita / exclusao),
   coerente com o `tipo`; ela já vai no catálogo e na resposta
   `confirmacao_pendente`, e a interface usa na borda do card (skill
   `sentinel-visual`, seção 5).
7. Gere um teste cobrindo: RBAC negando acesso, fluxo de confirmação (para
   escrita), validação com frase amigável (para escrita), CPF mascarado na
   resposta (para leitura que devolva documento/cpf) e o registro de auditoria
   sendo gravado de fato.
8. NÃO rode nenhum comando git de escrita.
9. Ao final, rode os testes novos e reporte o resultado.
