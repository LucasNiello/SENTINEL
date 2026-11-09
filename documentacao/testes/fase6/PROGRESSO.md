# Fase 6: progresso

## Atualização de 05/11/2026: C0 concluído, parado no ⏸
- A bateria rodou com a Azure real como operador do tenant 1. Resultado em `BATERIA_ANTES_2026-11-05.csv`.
- **Acerto: 30/40.** Por grupo: consulta 10/10, cadastro 9/10, atualização 3/6, continuação 0/4, exclusão 4/4, lote 2/2, ambíguo 2/4.
- **Tempo** (38 pedidos com chamada à IA; 2 negados antes da IA): média 2,51 s, mediana 2,44 s, p90 2,99 s, máximo 3,78 s.
- O script da bateria fica fora do repositório (scratchpad do Claude, `bateria.php`), para a rodada pós-C1.
- Próximo passo: o Lucas lê o plano do C1 (segunda leitura) e libera. Nada do C1 foi codado.

---
(Registro de 04/11/2026, mantido abaixo.)

## Camada atual
**C0 (medir o "antes")**, em andamento. As camadas C1, C2 e C3 ainda não começaram. Nenhum código do app foi alterado.

## O que foi feito
- `documentacao/testes/fase6/BATERIA_PEDIDOS.csv`: 40 pedidos fixos, separados por `;`. Colunas: `n; grupo; pedido; depende_de; esperado_tool; esperado_tipo; observacao`.
  - 10 consultas, 10 cadastros (5 com dados faltando e 1 negado: funcionário pede admin), 6 atualizações por nome, 4 continuações, 4 exclusões/restaurações, 2 lotes e 4 ambíguos.
  - Os pedidos usam nomes reais do tenant 1 do MySQL do lab (ex.: "Padaria Pão Dourado"; não existe "Padaria Sol").
  - As continuações (27 a 30) dependem das consultas 1, 2, 3 e 5 (coluna `depende_de`).
- Fatos levantados para a bateria:
  - O operador (user id 2, tenant 1) não pode excluir: `excluir_*` pede admin, então o esperado é `negado`. O `OperacaoSolicitada` nega antes da IA, sem chamada HTTP.
  - A lixeira do tenant 1 está vazia: restaurar devolve erro "Não encontrei"; conta a tool escolhida.
  - O `processar()` não devolve `tempo_ia_ms` na resposta de leitura (só na `confirmacao_pendente` e na auditoria).

## Onde parou
Estava para escrever o script temporário da bateria, **fora do repositório**, no scratchpad do Claude. Nada dele foi criado ainda.

Desenho combinado do script:
1. Fazer o bootstrap do Laravel e `Auth::loginUsingId(2)` (operador do tenant 1).
2. Usar uma sessão em memória (`Illuminate\Session\Store` + `ArraySessionHandler`).
3. Para cada pedido: `ConversaAgente::ler()` → `AiAgentService::processar()` → `ConversaAgente::guardar()`.
4. Cada pedido independente começa com sessão nova. Cada continuação roda logo depois da sua consulta, na mesma sessão.
5. Ouvir `Illuminate\Http\Client\Events\RequestSending` e `ResponseReceived` para:
   - somar o tempo de cada chamada à Azure (o `tempo_ia_ms`);
   - pegar a tool escolhida pelo modelo (`choices.0.message.tool_calls.0.function.name`). Sem chamada HTTP, usar `resposta['tool']`.
6. Critério de acerto (S/N):
   - tool obtida = esperada **e** tipo dentro de `esperado_tipo` (separado por `|`);
   - nas continuações, o id proposto também tem que ser o da linha certa da consulta anterior;
   - nos ambíguos (`-`): resposta `texto`, sem tool e com pergunta (`?`).
7. Uma pausa curta entre pedidos; no máximo 50 pedidos por rodada.

## O que falta
- **C0**:
  - escrever e rodar o script;
  - gravar `BATERIA_ANTES_2026-11-04.csv` (ou a data da rodada) com as colunas pedido, esperado, obtido, acertou, tipo de resposta, erro e tempo_ia_ms (mais n e grupo);
  - relatório: acerto geral e por grupo; média, mediana, p90 e máximo do tempo;
  - ⏸ esperar o Lucas.
- **C1**: plano curto, segunda leitura do Lucas, depois implementação e bateria `BATERIA_DEPOIS_C1_<data>.csv`.
- **C2**: plano curto, ok do Lucas, depois implementação.
- **C3**: opcional, só se o Lucas liberar.

## Observações
- A bateria grava linhas de auditoria no MySQL do lab (leituras e negativas), como no uso normal. Propostas não gravam dados de negócio.
- O mysqld do Laragon foi iniciado à mão nesta sessão e estava rodando.
- Nada foi commitado. O stage tem a organização da `documentacao/` (30 linhas) mais as edições do CLAUDE.md e da SKILL.md, à espera do Lucas.
