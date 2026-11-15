# Bateria da clínica com a IA real — 15/11/2026

Branch `automacao-na-madruga`. Nada commitado; nenhum comando git de histórico. `.env` não foi tocado; a chave não foi impressa. Rodou só em `database/bateria.sqlite` (SQLite, com o `ClinicaSeeder`); nunca no MySQL. Modelo: `claude-haiku-5-5`, o do `.env`. Decisões: `.claude/decisoes/2026-11-15.md`.

## Resultado

| Rodada | Acertos | Chamadas | Arquivo |
|---|---|---|---|
| 1ª, completa (antes) | **28/44** | 44 | `documentacao/testes/fase8/BATERIA_CLINICA_HAIKU_2026-11-15_R1.csv` |
| Ciclo 1, só as falhas | 16/18 | 18 | `…_C1.csv` |
| Ciclo 2, só as falhas | 3/3 | 3 | `…_C2.csv` |
| 2ª, completa (depois, banco recriado) | **44/44** | 44 | `…_R2.csv` |
| Paráfrases novas (16, escritas depois do ajuste, sem uso para corrigir) | **15/16** | 16 | `…/BATERIA_CLINICA_PARAFRASES_HAIKU_2026-11-15.csv` |

**Chamadas reais desta tarefa: 125 de 150. Acumulado: 169 → 294.**

**Como citar no TCC:** os 44/44 foram medidos nos mesmos pedidos que guiaram o ajuste (achado I4 do reviewer). A taxa honesta para pedidos que o modelo e o prompt nunca viram é a das paráfrases: 15/16.
- A falha das paráfrases (pedido 14, "e aquilo, quanto ficou?") é teste mal definido meu. A sessão dele é a mesma do pedido 12, que acabara de propor editar o Bolt; o modelo ligou "aquilo" a essa proposta, o que a regra do prompt permite.

Tokens da 2ª rodada: 550.070 de entrada e 9.312 de saída. Tempo da IA na 2ª rodada: p50 1,6 s, p90 3,1 s, máximo 4,0 s.

| Grupo | 1ª rodada | 2ª rodada |
|---|---|---|
| consulta | 8/9 | 9/9 |
| agenda (normal, conflito, encaixe, falta, cancelar) | 1/5 | 5/5 |
| cadastro (tutor e pet, completo e incompleto; item do catálogo) | 5/6 | 6/6 |
| cobrança (receber, cancelar, fechar a conta) | 3/3 | 3/3 |
| venda | 0/3 | 3/3 |
| estorno (inteiro, parcial 1 de 2, tudo menos o prontuário, venda) | 2/4 | 4/4 |
| continuação ("o segundo", "o último", "esse mesmo", "esse mesmo" depois de gravar) | 2/4 | 4/4 |
| caixa de entrada → agendamento | 1/2 | 2/2 |
| permissão negada | 2/3 | 3/3 |
| ambíguo | 1/2 | 2/2 |
| recusa (prontuário) | 3/3 | 3/3 |

## Falhas da 1ª rodada e causa provável

| Pedidos | Causa | Tipo |
|---|---|---|
| 7, 8, 9, 11, 14, 20, 21, 22, 24, 34, 36, 37 | O modelo consultou antes de agir, para descobrir números (pet, tutor, item, registro). Cada pedido tem uma resposta só, e a consulta vai para a tela, não volta para ele. O prompt da clínica mandava "consulte os tutores para achar o número dele". | prompt |
| 20, 21, 22 | Mesmo com o prompt certo, a venda precisa do número do item, e o card da venda não escolhe itens. | descrição da tool |
| 34 | "Esse mesmo" depois de gravar: o histórico só tinha a frase fixa da confirmação, sem o número do registro gravado. | histórico |
| 36 | "Remarcar" (mensagem da caixa de entrada) não estava entre as ações; o modelo consultou a agenda. | prompt |
| 28 | Alteração sem dizer o campo virou pergunta em texto. | prompt |
| 41 | Pedido curto sem objeto ("quanto deu?") virou consulta de cobranças. | prompt |
| 2, 25 | Testes mal definidos meus: marca de argumento numa consulta (o comando não devolve argumentos de leitura); a Luna tem 2 cobranças pagas, e o servidor devolveu a lista para escolher, que é o certo (trocada pelo Rex). | teste |

Nenhuma falha pareceu variação do modelo: as mesmas causas apareceram em vários grupos, e a correção de texto resolveu todas.

## O que mudou

**Prompt da clínica** (`AiAgentService::promptDaClinica`; a contabilidade não mudou, `PromptPorDominioTest` fixa o hash):
- cada pedido tem uma resposta só, e a consulta não volta para a IA: ação vai direto para a tool da ação, sem consultar antes;
- o registro vai pela `busca` (nome do pet ou do tutor); número que falta fica em branco, e o card mostra a lista; nunca inventar número;
- `posicao` para linha da última tabela; alteração sem campo abre o card;
- pedido curto sem objeto responde em texto, pedindo a que se refere;
- "remarcar" é agendar o novo horário;
- depois de uma confirmação, usar o número gravado quando o usuário se refere ao registro recém-gravado;
- saiu "(hold-to-confirm)"; entrou "tela de revisão e confirmação humana";
- saiu "consulte os tutores para achar o número dele".

**Descrição da tool `registrar_venda`:** `itens` lista "número = nome" do catálogo ativo do tenant logado. Não leva preço, estoque nem pessoa; acima de 80 itens, a lista sai.

**Histórico (só na clínica):** depois da confirmação, a frase leva "Número do registro gravado: N." (só o id). `ConversaAgente::registrarResultado` recebe o id; o `AgenteController::confirmar` só passa esse id, e o resto do confirmar não mudou.

**Comando `sentinel:bateria`:**
- `--csv`, `--tenant` e a coluna `papel` (usuário por linha);
- marcas na observação: `arg:`, `posicao=`, `confirmar`, `id_gravado:`;
- grupo `recusa`;
- critério em `CriterioDaBateria`.
O CSV original e o critério dele não mudaram (há teste).

**Nada mudou** em RBAC, tenant, PIN, valores, regra de negócio ou fluxo de confirmação.

## O que ainda é fraco (a bateria acerta, mas não é perfeito)

- **Agendamento:** o modelo não sabe os números do pet, do serviço e do profissional; o card abre com as listas para escolher (pedidos 7, 8, 9). O conflito de horário (pedido 8) só aparece no Revisar, depois que a pessoa escolhe o profissional. Na bateria ele não é testado até o fim.
- ~~**Estorno parcial (pedido 24):** o modelo mandou `linhas` sem `item_id`.~~ **Corrigido em 15/11 (ver "Pontos fracos corrigidos").**
- ~~**Item do catálogo incompleto (pedido 16):** o modelo preencheu "nao" em `controla_estoque` e `exige_receita` sem o usuário dizer.~~ **Corrigido em 15/11 (ver "Pontos fracos corrigidos").**
- **Descrição da venda:** a lista do catálogo vai em todo pedido da clínica (cerca de 250 tokens). Num catálogo grande, o certo é resolver o item pelo nome no servidor (trabalho futuro).
- **Uma rodada só por versão:** não medi a variação do modelo entre execuções do mesmo pedido.

## Suíte

931 (início do front, 14/11) → 981 (decisões de 15/11) → 990 (ciclo 2) → **993/993** (7851 asserções) depois das correções da revisão, pelo `test-runner`. Nenhum teste apagado ou afrouxado.

Testes novos e alterados:
- `BateriaAgenteTest`: 6 novos, incluindo o guarda com `DB_URL` e caminho fora do projeto;
- `PromptPorDominioTest`: 4 novos, incluindo a lista do catálogo por tenant e o lembrete; a regra do pedido curto foi escrita sem "pergunte", que um teste existente proíbe;
- `Clinica/NumeroGravadoNoHistoricoTest`: 2 novos.

Sabotagens: tirar a recusa do `DB_URL` e tirar o tenant da chave do cache. As duas derrubaram o teste e foram restauradas.

## Commits sugeridos

1. `feat(bateria): sentinel:bateria com --csv, --tenant e papel por linha; bateria da clinica (44 pedidos) e criterio testavel`
   - `app/Console/Commands/{BateriaAgente,CriterioDaBateria}.php`, `documentacao/testes/fase8/BATERIA_CLINICA.csv`, `tests/Feature/BateriaAgenteTest.php`
2. `fix(agente): prompt da clinica manda a acao direto para a tool (sem consultar antes); venda com os numeros do catalogo; numero gravado no historico; sai o hold-to-confirm`
   - `app/Services/AiAgentService.php` (só `promptDaClinica`), `app/Services/Agente/CatalogoDaClinica.php`, `app/Services/ConversaAgente.php`, `app/Http/Controllers/AgenteController.php`, `tests/Feature/PromptPorDominioTest.php`, `tests/Feature/Clinica/NumeroGravadoNoHistoricoTest.php`
3. `fix(bateria): correcoes da revisao (confirmar passa pelo revisar, guarda exato do banco, mascara LGPD na saida, cache do catalogo por tenant, nome do item limpo, excecao do pedido curto no lembrete)`
   - `app/Console/Commands/BateriaAgente.php`, `app/Services/AiAgentService.php` (chave do cache e lembrete da clínica), `app/Services/Agente/CatalogoDaClinica.php`, `tests/Feature/{BateriaAgenteTest,PromptPorDominioTest}.php`
   - Os arquivos se repetem com os commits 1 e 2: use `git add -p`, ou junte 2 e 3 num commit só.
4. `docs(bateria): resultados da bateria da clinica com o Haiku real (28/44 -> 44/44; parafrases 15/16; 125 chamadas)`
   - `documentacao/testes/fase8/BATERIA_CLINICA_PARAFRASES.csv`, `documentacao/testes/fase8/BATERIA_CLINICA_HAIKU_2026-11-15_{R1,C1,C2,R2}.csv`, `documentacao/testes/fase8/BATERIA_CLINICA_PARAFRASES_HAIKU_2026-11-15.csv`, este relatório, `.claude/decisoes/2026-11-15.md`, `automacao/RETOMADA.md`

## Pontos fracos corrigidos (15/11, pedido do Lucas)

| Ponto | Correção | Teste e sabotagem | IA real |
|---|---|---|---|
| Estorno parcial sem `item_id` (pedido 24) | A linha sem `item_id` sai (camada da tool); o card abre com a lista e o aviso "Escolha pelo menos um item…"; o Revisar sem item é recusado com essa frase e nada grava. A descrição de `linhas` diz que o item é obrigatório e que, sem o número, a IA não manda linhas. Valor, PIN, service e regra do estorno não mudaram. | `EstornoPorItensNoCardTest`: 2 testes novos; tirar o filtro derrubou | Pedido 24 e paráfrase 10: `linhas: null`, e o card pede o item |
| Estoque e receita presumidos (pedido 16) | As descrições dos dois campos dizem "Só preencha se o usuário disser… Nunca presuma." (antes: 'Serviço: "nao"'), e o prompt da clínica diz o mesmo. Descartar os campos no rascunho ficou de fora: mudaria o contrato do cadastro e uns 10 testes. | `ToolsDeCadastroDaClinicaTest`: 1 teste novo; voltar a descrição antiga derrubou | Pedido 16: os dois campos ficaram de fora, e o card pergunta |

- **Chamadas:** 5, todas acertos (pedidos 16 e 24; paráfrases 5, 6 e 10). **Acumulado: 294 → 299.** Suíte depois das correções: **996/996** (7876 asserções).
- **Saídas:** `documentacao/testes/fase8/BATERIA_CLINICA_HAIKU_2026-11-15_PF.csv` e `BATERIA_CLINICA_PARAFRASES_HAIKU_2026-11-15_PF.csv`.
- **Limite:** o ponto 2 é instrução ao modelo, não trava no servidor. O card mostra os valores, e quem revisa decide.

**Commit sugerido:** `fix(clinica): estorno sem item pede o item no card; IA nao presume estoque nem receita no item do catalogo`
- `app/Services/Agente/CatalogoDaClinica.php`, `app/Services/AiAgentService.php` (uma frase do prompt da clínica), `tests/Feature/Clinica/{EstornoPorItensNoCardTest,ToolsDeCadastroDaClinicaTest}.php`, os 2 CSVs `_PF`, `.claude/decisoes/2026-11-15.md`, este relatório e `automacao/RETOMADA.md`

## Revisão (reviewer, 1 passada): 0 bloqueadores, 4 importantes, 4 cosméticos

| # | Achado | Situação |
|---|---|---|
| I1 | O `confirmar` da bateria pulava o Revisar e gravava proposto = executado | Feito: passa pelo `revisar()` e grava o proposto da IA |
| I2 | O guarda aceitava `DB_URL` e qualquer `…/database/bateria.sqlite` | Feito: caminho exato do projeto e `DB_URL` recusado; teste e sabotagem |
| I3 | A regra do pedido curto contradizia o lembrete (que vale com histórico) | Feito: a mesma exceção no lembrete, limitada a pedido sem ação nem registro identificáveis; o RF04 continua |
| I4 | 44/44 medido nos mesmos pedidos que guiaram o ajuste | Feito: 16 paráfrases novas, 15/16 (ver "Como citar no TCC") |
| C1 | Cache do catálogo por domínio, com a descrição por tenant | Feito: chave domínio:tenant; teste e sabotagem |
| C2 | `|erro` aceita qualquer erro | Só registrado (8 e 19 deram o erro certo) |
| C3 | Argumentos sem máscara no CSV versionado | Feito: máscara LGPD da auditoria; não há CPF nas saídas |
| C4 | Nome do item cru na descrição | Feito: uma linha, sem `;`/`=`, até 60 caracteres |

O reviewer confirmou que não há vazamento entre tenants nem de dado sensível, que o `confirmar` do controller só muda o histórico e que RBAC, PIN e Form Request continuam valendo pela bateria.
