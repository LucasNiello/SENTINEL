---
description: Ao fechar uma fase do roteiro do Sentinel, roda a autorrevisão contra RF01-RF12/RNF01-RNF10, gera o texto pronto para colar no checklist do Notion, e entrega o texto sugerido para a seção de pendências do CLAUDE.md (sem editá-lo).
---

Fase a fechar: $ARGUMENTS

Passos:
1. Acione o subagente `reviewer` contra o que foi feito nesta fase (não leia o
   arquivo dele nem repita o checklist aqui). Não pule isso mesmo se os
   testes estiverem verdes — teste verde não prova requisito cumprido.
2. Acione o subagente `test-runner` para a suíte completa e reporte o
   resultado que ele devolver.
3. Gere um bloco de texto pronto para colar no Notion, no formato:
   - Itens fechados (✅)
   - Pendências não bloqueadoras (⏳)
   - Bloqueios reais que só o Lucas resolve (🔒)
   - Divergências para registrar no Notion (base Divergências e Ajustes): uma
     por item, com título, severidade e esperado × encontrado.
4. NÃO edite o CLAUDE.md. Entregue no relatório o texto sugerido para a seção
   de pendências conhecidas (o que foi resolvido, o que é novo nesta fase),
   pronto para eu colar.
5. NÃO edite o Notion diretamente sem eu confirmar — me entregue o texto
   pronto e eu decido se colo eu mesmo ou peço para você colar.
6. NÃO rode nenhum comando git de escrita.
7. Inclua no relatório o log de decisões da sessão (`.claude/decisoes/`, o
   arquivo do dia): o que foi decidido, a alternativa descartada e o porquê.
8. Termine sugerindo mensagem(ns) de commit como texto, sem executar nada.
