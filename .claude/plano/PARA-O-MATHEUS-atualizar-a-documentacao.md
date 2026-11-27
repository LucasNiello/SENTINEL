# Para o Matheus — atualizar a documentação (da 1.3 para a 1.4)

**O que fazer:** continuar a partir de `documentacao/Documentacao-Sentinel1.3.docx` (a 1.3 é a mais recente; a 1.2 e as de `versoes-antigas/` estão obsoletas) e gerar a **1.4**. Prazo: banca em 30/11 (o código congela em 27/11, então a documentação descreve o que está na `main`).

## Leia nesta ordem
1. `.claude/plano/plano-atualizacao-documentacao-2026-11-26.md`: as 5 decisões de 24 a 26/11 e as linhas exatas a trocar na 1.3 (fim do prazo de 5 min das propostas, front React, tema novo, lote de cancelar). **Onde o plano diz "Claude web", leia "quem executa" (você).** Você vê o repositório: pode abrir o código e as decisões para conferir.
2. `.claude/plano/plano-atualizacao-documentacao-ADENDO-2026-11-27.md`: o que mudou depois (remarcar em lote, tela do prontuário, seed do movimento, conversa que sobrevive, agendar em um passo, e-mail simulado etc.), o que NÃO documentar como feito e as perguntas para o Lucas.
3. Fontes para conferir qualquer afirmação: `git log --oneline --since=2026-11-24` e `.claude/decisoes/2026-11-24` a `2026-11-26`.

## Ordem de trabalho (do que a banca vê para o que ela não vê)
1. `documentacao/ROTEIRO_DEMONSTRACAO.md`.
2. As seções de governança da 1.3: remarcar em lote e prontuário; depois o fim do prazo das propostas.
3. As demais linhas do adendo (A2 a A10).
4. Texto sugerido para o `CLAUDE.md` (entregar como texto; quem edita o `CLAUDE.md` é o Lucas).
5. Notion: só ACRESCENTAR um bloco novo datado na página "Arquitetura". Nunca editar nem apagar nada.
6. Figuras e diagramas (os marcados MANUAL), por último.

## Regras
- **Não invente** número de teste, data ou comportamento. Se não estiver no plano, no código ou nas decisões, pergunte ao Lucas.
- **Número de testes:** o último registrado é 1495/1495 (26/11), rodado antes de coisas novas. O Lucas informa o número final; até lá, deixe o campo marcado "a preencher".
- **Rótulo:** "Documentação 1.4" e data a confirmar com o Lucas.
- Salve a nova versão como `documentacao/Documentacao-Sentinel1.4.docx` e mantenha a 1.3 onde está.

## O que ainda NÃO está feito (não documentar como existente)
Bloqueios de agenda; ficha do pet já em seções recolhíveis (esta SIM está feita: pode documentar), mas **a cobrança completa (editar itens, desconto geral, receber sem Revisar) e a entrada de estoque são planos para sábado 28/11** (`.claude/plano/plano-2026-11-27-cobranca-completa.md`). Descreva-as só se o Lucas avisar que entraram; se entrarem, ele manda a lista final.
Também continuam só planejados: a ficha em abas completa, as despesas proporcionais de novembro no B.I. e a nota fiscal.

## Perguntas que dependem do Lucas (peça antes de escrever essas seções)
1. O remarcar em lote usa "segurar 2 s": documentar como regra?
2. O texto do histórico depois do lote tem nomes de pets e horários (a regra antiga diz "só frases fixas"): ele aceita ou o histórico volta a ter só contagens?
3. Quais papéis arquivam pet, e `arquivar_pet` segura 2 s?
4. A Administração continua "só PINs"? (depende dos bloqueios de agenda.)
5. O link "Clientes" saiu do menu e o Cadastros ficou como tela única de tutores e pets: documentar assim?
