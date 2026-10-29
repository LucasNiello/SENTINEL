# Proteção do roteamento de escritas — SENTINEL

## Problema e causa

Em 29/10/2026, 1/13 pedidos de exclusão como operador selecionou consultar_clientes e retornou HTTP 200. Pedido exato e status estão no CSV histórico; auditoria às 14:21:14 registra consultar_clientes/sucesso e a sessão guardou marcador de leitura. Cliente QA id 6 permaneceu ativo. O corpo HTTP 200 não foi capturado naquela rodada.

A requisição ao Azure oferecia todo o catálogo com tool_choice=auto. A descrição de consultar_clientes permitia procurar por nome; excluir_cliente descrevia corretamente a lixeira, mas não havia uma regra de correspondência no servidor. RBAC era aplicado após a escolha e verificava somente a ferramenta retornada: uma consulta permitida escapava da negativa da exclusão solicitada. O modelo também recebia o papel, podendo escolher uma operação permitida diferente. A ocorrência em sessão nova não sustenta contaminação pelo histórico como causa desta amostra. O prompt já reforçava o pedido atual em conversas existentes, mas isso não substituía validação no backend.

## Correção

OperacaoSolicitada reconhece a operação e entidade de comandos explícitos do pedido atual. A permissão e a ferramenta continuam vindo exclusivamente do catálogo do AiAgentService, sem matriz RBAC paralela:

1. Reconhecer comando explícito de escrita e objeto do catálogo.
2. Negar e auditar imediatamente quando o papel não permite a operação. Não consultar dados nem o provedor para decidir uma negativa já conhecida.
3. Para operação autorizada, disponibilizar somente sua ferramenta, com tool_choice explícito.
4. Antes de interpretar/executar qualquer tool call, rejeitar todas as chamadas incompatíveis (AI_INTENT_MISMATCH / 422), mesmo se o provedor desobedecer ao parâmetro.
5. Manter resolução do registro no tenant, validação, formulário, revisão, hold e confirmação existentes. Nenhuma escrita no processar().

O prompt reforça a mesma regra, como orientação adicional. Não há nome/id QA, regra exclusiva de teste, alteração de Azure/TLS, ampliação de permissões ou alteração de execução/confirmar.

## Vocabulário reconhecido

Comando no início da mensagem, com prefixos de cortesia/desejo opcionais (por favor, eu quero, desejo, preciso de, gostaria de, você pode/poderia). Espaços, caixa e acentos são normalizados apenas para reconhecer a operação; dados originais seguem ao fluxo existente.

- Excluir: exclua/excluir, delete/deletar, remova/remover, apague/apagar.
- Criar: cadastre/cadastrar, crie/criar, registre/registrar, adicione/adicionar.
- Atualizar: altere/alterar, atualize/atualizar, edite/editar.
- Restaurar: restaure/restaurar.
- Objetos: cliente, fornecedor, funcionário, lançamento, nota fiscal e categoria de lançamento/categoria, com artigo o/a/um/uma opcional.
- Exclusão definitiva: qualificadores de vez/definitivamente antes do objeto ou imediatamente após o tipo de objeto são vinculados a excluir_definitivo, nunca à exclusão lógica.

O reconhecedor não é um classificador universal de linguagem natural: construções fora desse vocabulário/ordem continuam no fluxo geral de IA. Não se infere intenção do histórico, de uma pergunta explicativa, de uma negação ou de um verbo dentro do nome de um registro. Consultas “liste meus clientes” e “mostre o cliente X” permanecem no caminho normal. Autorizações também continuam sendo verificadas nos pontos posteriores de proposta e execução para qualquer formulação.

## Verificação

RoteamentoIntencaoTest: 28 testes/141 assertions. Inclui as cinco variações de exclusão em leitura/operador, Azure indisponível, histórico de consulta anterior, admin sem execução, cadastro/alteração/restauração incompatíveis, múltiplas tools, outros objetos/tenant e consultas legítimas. Regressão completa: 457 testes/4197 assertions, zero falhas/erros.

Na bateria real de operador, tempo_ia_ms nulo significa autorização local antes do Azure; essas negativas não são contadas como inferências do provedor. Os caminhos autorizados de admin e consulta usam Azure real. Resultados e estatísticas da rodada constam do relatório final, separados das amostras históricas.

## Compatibilidade real do Azure verificada

Com tool_choice nominal, o provedor configurado devolveu uma chamada válida de excluir_cliente/busca com finish_reason=stop. Isso produziu uma falha intermediária 502 no validador anterior. A aceitação de stop + tool_calls é restrita à seleção nominal; o nome deve corresponder à operação do servidor e todos os argumentos continuam sujeitos à validação JSON/schema. No modo auto, a combinação continua sendo recusada. Regressões cobrem stop nominal válido e argumentos inválidos. A falha intermediária está preservada no CSV de variações; o reteste autorizado não executa exclusões.

Contrato de seleção nominal: [Microsoft Learn](https://learn.microsoft.com/en-sg/azure/foundry/openai/how-to/function-calling). A combinação stop + tool_calls foi observada diretamente no provedor configurado, com apenas metadados de formato registrados externamente.
