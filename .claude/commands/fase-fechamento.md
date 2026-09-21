---
description: Ao fechar uma fase do roteiro do Sentinel, roda a autorrevisão contra RF01-RF12/RNF01-RNF10, gera o texto pronto para colar no checklist do Notion, e atualiza a lista de débitos conhecidos no CLAUDE.md.
---

Fase a fechar: $ARGUMENTS

Passos:
1. Rode a mesma checklist do subagente `reviewer` (leia `.claude/agents/reviewer.md`
   se existir) contra o que foi feito nesta fase. Não pule isso mesmo se os
   testes estiverem verdes — teste verde não prova requisito cumprido.
2. Rode a suíte de testes relevante e reporte o resultado.
3. Gere um bloco de texto pronto para colar no Notion, no formato:
   - Itens fechados (✅)
   - Pendências não bloqueadoras (⏳)
   - Bloqueios reais que só o Lucas resolve (🔒)
4. Atualize a seção de débitos conhecidos do CLAUDE.md com o que mudou nesta
   fase (o que foi resolvido, o que é novo).
5. NÃO edite o Notion diretamente sem eu confirmar — me entregue o texto
   pronto e eu decido se colo eu mesmo ou peço para você colar.
6. NÃO rode nenhum comando git de escrita.
7. Termine sugerindo mensagem(ns) de commit como texto, sem executar nada.
