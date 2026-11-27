# Plano (27/11) — Cobrança completa: editar itens, descontos e pagamento à brasileira

Pedido do Lucas (27/11): (1) tirar o "Revisar" do card de receber; (2) poder editar a cobrança (cancelar produto ou serviço cobrado a mais); (3) campo de desconto; (4) uma cobrança "de acordo com a sociedade em que vivo", completa e editável item a item.

## O que existe hoje (verificado)
- Fluxo da cobrança: `aberta` → `fechada` ("a receber") → `paga`; ou `cancelada`; estorno por itens depois de paga.
- `CobrancaService::adicionarItem` e `removerItem` existem, mas só para cobrança ABERTA, e nenhuma tela ou tool os usa.
- `cobrancas` guarda só `total_centavos`; `cobranca_itens` guarda preço, quantidade e subtotal. **Não há desconto.**
- Revisar: só as ações da agenda confirmam em um passo (`passo_unico`, 26/11). Receber, cobrar, cancelar e estornar ainda passam pelo Revisar.
- Regra do projeto que continua: a IA nunca informa valor. O desconto é digitado pelo humano no card, e o servidor valida e calcula.

## Fases (cada uma entrega algo)
**F0 — Receber sem Revisar (~30 min).** Receber, cobrar atendimento e registrar venda passam a confirmar em UM passo (`passo_unico`). Cancelar cobrança e estornar mantêm o segurar 2 s.

**FE — Entrada de estoque (acrescentada por pedido do Lucas, 27/11; ~1 a 1,5 h).** Hoje o estoque só desce: criar o produto define o saldo inicial, `atualizar` não mexe nele (de propósito) e `CatalogoService::entradaDeEstoque` existe, mas nenhuma tela ou tool a usa.
- Ação "Dar entrada de estoque" para produto (em Cadastros → Catálogo, linha do produto, e pelo chat: "dê entrada de 20 ração premium"): quantidade inteira maior que zero, motivo opcional ("compra", nota). Card de confirmação em UM passo (não destrutiva).
- O servidor soma ao saldo (com trava de linha) e grava na auditoria o saldo anterior e o novo. A auditoria é o histórico de movimentações.
- Papéis: os mesmos que já cadastram o item do catálogo do setor (a confirmar).
- Fora (depois da banca): custo de compra, fornecedor e nota na entrada, estoque mínimo com alerta, e **ajuste de inventário** (perda, quebra, contagem: reduzir saldo com motivo obrigatório e autorização).

**F1 — Editar a cobrança (~1,5 a 2 h).** Card "Editar cobrança #N" para cobrança aberta OU a receber (nunca paga; depois de paga, a correção é o estorno por itens, que já existe):
- trocar a quantidade de cada linha;
- remover uma linha, com motivo obrigatório curto (o produto devolve o estoque se tinha baixado; o item do prontuário continua no prontuário, só deixa de ser cobrado);
- acrescentar item do catálogo;
- o total é sempre recalculado pelo servidor; a auditoria guarda o antes e o depois.

**F2 — Descontos (~2 a 3 h).** Colunas novas (migration): `desconto_centavos` por linha e geral, `desconto_motivo`, `desconto_autorizado_por`.
- Desconto por linha ou geral, em R$ ou em %, com motivo obrigatório. Nunca maior que o valor da linha ou da cobrança.
- Limite por papel sem PIN (valor a decidir). Acima dele, pede o PIN do supervisor do setor ou do dono (o mecanismo do estorno já existe).
- O recibo e a auditoria mostram valor cheio, desconto, motivo e líquido.

**F3 — Pagamento à brasileira (~1 a 1,5 h).**
- Formas: dinheiro (com valor recebido e troco), Pix, cartão de débito, cartão de crédito (com parcelas) e pagamento dividido em duas formas.
- Recibo para imprimir (comprovante, não fiscal): itens, descontos, total, forma, data. CPF do cliente só se ele pedir (LGPD).

**F4 — Fecho.** `reviewer` em 2 passadas (dinheiro, permissão, auditoria, tenant) e a suíte completa.

## Fora do escopo (documentar como pendência para sair do protótipo)
Nota fiscal (NFS-e/NFC-e: exige certificado digital e integração com a prefeitura/SEFAZ), juros e multa por atraso, conciliação bancária, TEF/maquininha.

## Estimativa e prazo
F0 a F3 somam 5 a 7 h com testes e revisão. **Código congela hoje (27/11); banca 30/11; uso limitado até o reset de sábado.** Mínimo defensável para a banca: **F0 + F1 + desconto geral simples** (R$ ou %, motivo obrigatório, limite fixo, sem PIN). O resto depois.

## Decisões do Lucas (preciso delas antes de F2/F3)
1. **Limite de desconto sem PIN** (sugestão: 10%) e quem autoriza acima (sugestão: supervisor do setor ou dono, com o PIN).
2. **Desconto por linha** já na primeira versão, ou só o desconto geral?
3. **Quem pode editar cobrança** (sugestão: quem hoje registra a cobrança do setor; veterinário só a da clínica).
4. **Editar cobrança "a receber":** permitir? (sugestão: sim, até ser paga.)
5. **Pagamento:** débito, crédito parcelado, troco e dividido entram agora, ou só depois da banca?
6. **Nota fiscal fora do escopo:** confirmar.

---

# ROTEIRO DE EXECUÇÃO — sábado à noite (28/11), pronto para seguir

**Decidido pelo Lucas (27/11):** desconto GERAL basta (por item só depois que houver recebimento de estoque); editar a cobrança até ser paga e, depois de paga, só estorno; entrada de estoque entra no escopo. **Padrões que assumo (o Lucas confirma ao dizer "vai"):** desconto até 10% sem PIN (acima, recusa pedindo o supervisor); quem edita a cobrança é quem a registra no setor; editar "a receber" permitido; fora: pagamento dividido, parcelas, troco, nota fiscal, custo e fornecedor na entrada.
**Regras de trabalho:** só testes dos arquivos tocados em cada passo; a suíte completa e o `reviewer` (2 passadas: dinheiro, permissão, auditoria, tenant) só no fim do passo 5; nenhum `git` de escrita (o Lucas comita); registrar cada decisão em `.claude/decisoes/2026-11-28-*.md`.

## Antes de começar (5 min)
1. `git status` limpo e o último commit conhecido; `npm run build` ao tocar em `.jsx`; `SENTINEL_FRONT=react` no `.env`.
2. Ler este roteiro e o padrão de uma tool de escrita já pronta: `atualizar_item_catalogo` e `receber_cobranca` em `app/Services/Agente/CatalogoDaClinica.php` (`definirAcao`: FormRequest, `executar`, `conferir`, `localizar`, `resumir`, `campos`).

## Passo 1 — F0: receber sem Revisar (~30 min)
- **Onde:** `AiAgentService::confirmaEmUmPasso()` (a lista de tools, ~linha 1665) e `avaliaNoRevisar` se aplicável. Acrescentar `receber_cobranca` e `cobrar_atendimento` (e `registrar_venda` se o fluxo permitir). **Não** incluir `cancelar_cobranca`, `estornar_*` (continuam com Revisar e segurar 2 s).
- **Atenção:** o caminho de um passo (`AgenteController::confirmarEmUmPasso`) revisa e grava na mesma requisição; conferir se a prévia da cobrança (`previaDaCobranca`) e a "forma de pagamento" obrigatória voltam como 422 com o formulário, como na agenda. O front já trata `passo_unico` (`Confirmacao.jsx`, ~linha 43).
- **Testes:** os testes de cobrança do card (`Cobranca*Test`, procure com `Glob tests/**/Cobranca*`) e o de card em um passo (`CardUmPassoTest` ou similar). **Pronto quando:** o card de receber mostra só "Confirmar" (sem "Revisar").

## Passo 2 — FE: entrada de estoque (~1 a 1,5 h)
- **Tool nova** `dar_entrada_estoque` (escrita) em `CatalogoDaClinica.php`, no padrão de `atualizar_item_catalogo`: campos `id` (do item, só produto com controle de estoque), `quantidade` (inteiro 1 a 100000), `motivo` (texto opcional, máx. 200). FormRequest novo `EntradaEstoqueRequest`. `executar` chama `CatalogoService::entradaDeEstoque($id, $quantidade, $tenantId)` (já existe, ~linha 119; conferir que trava a linha e soma), e anota na auditoria `valores_anteriores` (saldo antes) e o saldo novo (nunca texto livre do motivo além do limite). Entrar em `confirmaEmUmPasso`.
- **Papéis:** os mesmos de `atualizar_item_catalogo` (conferir a constante de papéis do catálogo).
- **Tela:** em Cadastros → Catálogo, `acoesExtras(linha)` da `Consulta.jsx` ganha "Entrada de estoque" só para `tipo = produto`; o card abre via `usarCartao().abrir('dar_entrada_estoque', {id})`.
- **Testes novos** (`EntradaEstoqueTest`): soma o saldo; recusa serviço, quantidade zero/negativa/enorme e item de outro tenant; papel sem permissão negado e auditado; a auditoria tem saldo anterior e novo; vendas depois baixam do novo saldo; saldo negativo vira positivo pela entrada.
- **Também:** `CatalogoDaClinica::consultar_catalogo` já mostra o estoque; conferir que o agente acha o produto pelo nome ("ração premium").

## Passo 3 — F1: editar a cobrança (~1,5 a 2 h)
- **Service** (`CobrancaService`): hoje `adicionarItem`/`removerItem` só valem para `aberta`. Criar `editarItens(int $cobrancaId, array $linhas, int $tenantId)` que, numa transação com trava de linha, aceita cobrança `aberta` OU `fechada` (nunca `paga` nem `cancelada`) e aplica: nova quantidade por linha, remoção (com `motivo` obrigatório, máx. 200; devolve estoque se `baixou_estoque`; linha vinda do prontuário só deixa de ser cobrada, o prontuário não muda) e itens novos do catálogo. Recalcula `total_centavos` no servidor. Cobrança sem itens depois de editar: recusa ("remova a cobrança cancelando-a").
- **Tool nova** `editar_cobranca` (escrita, um passo) no padrão do `estornar_cobranca` (que já edita linhas: ver `EstornoEditor` no React e `linhasParaEstornar` no service): reaproveitar o editor de linhas. A IA nunca informa valor; só o humano, no card.
- **Auditoria:** `valores_anteriores` com as linhas antes; uma linha de auditoria por edição.
- **Tela:** botão "Editar" ao lado de "Receber" em Cobranças (Financeiro.jsx) para `aberta` e `fechada`.
- **Testes novos** (`EditarCobrancaTest`): troca quantidade e o total recalcula; remove com motivo e devolve estoque; item do prontuário não altera o prontuário; paga e cancelada recusadas; motivo obrigatório; tenant e papel; auditoria com o antes.

## Passo 4 — Desconto geral (~1 a 1,5 h)
- **Migration** `add_desconto_to_cobrancas_table`: `desconto_centavos` (unsigned big, default 0), `desconto_motivo` (string 200 null), `desconto_autorizado_por` (null, sem FK de tenant como as outras). Mesma migration para `vendas` se a venda também tiver desconto (decidir no dia; sugestão: só cobrança na banca).
- **Regra no service:** `total = soma(subtotais) − desconto`; desconto em R$ ou % (o servidor converte para centavos); nunca maior que a soma; `motivo` obrigatório quando > 0; acima de 10% do subtotal: recusa com frase ("peça ao supervisor", PIN fica para depois). Desconto e itens editam juntos no card "Editar cobrança" (campo "Desconto" com R$/%, e "Motivo").
- **Tela:** `Consulta`/`Financeiro` mostram "valor cheio, desconto, total".
- **Testes novos** (`DescontoCobrancaTest`): R$ e %, limite de 10%, motivo obrigatório, não passa do valor, total e estorno coerentes (o estorno por itens usa o preço da linha; conferir como o desconto geral entra: **decisão a anotar no dia: o estorno devolve o valor proporcional já com desconto**), `Resultados` soma o líquido (conferir `ResultadosService`).
- **Cuidado:** o B.I. (Resultados) e o "Líquido" da lista usam `total_centavos`; confirmar que ficam coerentes com o desconto.

## Passo 5 — Fechamento (~1 h)
1. `reviewer` em 2 passadas, com escopo: `CobrancaService`, `CatalogoService::entradaDeEstoque`, as tools novas, os FormRequests e as migrations (dinheiro, permissão, auditoria, tenant).
2. **Suíte completa** (`test-runner`), uma vez.
3. Docs: acrescentar ao adendo `plano-atualizacao-documentacao-ADENDO-2026-11-27.md` as linhas A11 (editar cobrança e desconto geral) e A12 (entrada de estoque). Atualizar `roteiro-demo.md` com uma cena "cobrar → desconto → editar → receber" e outra "dar entrada de estoque".
4. Mensagem de commit sugerida (uma só).

## Ordem de corte, se o tempo apertar
Passos 1 e 2 sozinhos já deixam a demo coerente (receber em um clique e estoque com entrada). Passo 3 é o que o usuário mais sente. Passo 4 pode ficar para depois da banca. Não deixar nenhum passo pela metade: cada um termina com testes verdes ou não entra.

## Riscos conhecidos
- Mudar a cobrança pode afetar a lista de Cobranças, o "Líquido" e os Resultados: testar `FinanceiroReactTest`/`ResultadosTest` (nomes a confirmar com `Glob`).
- A cobrança `fechada` pode já ter importado itens do prontuário: ao remover, não apagar do prontuário.
- Concorrência: usar `lockForUpdate` na cobrança e nos itens, como o resto do `CobrancaService`.
