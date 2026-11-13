# Relatório da bateria pós-B1 (13/11/2026, lab)

Branch `automacao-na-madruga`, PHP 8.4.27, Anthropic `claude-haiku-5-5`, SQLite `bateria.sqlite`. Saída: `automacao/bateria-13-11-saida.txt`; CSV: `documentacao/testes/fase6/BATERIA_HAIKU_2026-11-13_B1.csv`.

## Total: antes 24/40 → depois **38/40**. Nenhuma regressão.

| Grupo | Antes (Haiku) | Agora |
|---|---|---|
| consulta | 10/10 | 10/10 |
| cadastro | 4/10 | 10/10 |
| atualização | 2/6 | 5/6 |
| continuação | 0/4 | 4/4 |
| exclusão (permissão) | 4/4 | 4/4 |
| lote | 2/2 | 2/2 |
| ambíguo | 2/4 | 3/4 |

p50 1709 ms, p90 3938 ms, máx 9542 ms (antes 1383 / 2172 / 2774). Tokens: 292062 entrada / 5701 saída.

## Falhas
| n | grupo | pedido | esperado × obtido | causa provável |
|---|---|---|---|---|
| 24 | atualização | marque como conciliado o lançamento da tarifa bancária | atualizar_status_lancamento/confirmacao_pendente × consultar_lancamentos/resultado_leitura | modelo variando: busca o lançamento por descrição antes de propor a alteração (já falhava antes) |
| 40 | ambíguo | mostra o relatório | texto perguntando "qual relatório?" × texto dizendo que não há ferramenta de relatório e oferecendo consultas | teste mal definido: resposta em texto é razoável, o critério (`-`/texto) aceitou o tipo mas o CSV marca N pela observação; já falhava antes |

## Mudanças
Nada. Prompt, código, CSV de pedidos e `BateriaAgente` intactos; sem suíte rodada (nenhuma edição).

## Chamadas reais
38 nesta bateria; acumulado 131 + 38 = **169/200**.

## Commit sugerido (texto)
`docs: bateria Haiku pós-reforço do cadastro, 38/40 sem regressão (relatório e CSV de 13/11)`
