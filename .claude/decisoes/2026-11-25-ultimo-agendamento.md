# 25/11 — "remarcar o último agendamento" não achava de primeira

**Reprodução (IA real, claude-haiku-5-5, SQLite descartável `database/bateria.sqlite`, 3 chamadas):**
- "remarca o último agendamento" → o modelo mandou `posicao: -1` sem tabela mostrada → "Não tenho uma tabela recente…".
- "quero remarcar o agendamento mais recente" e "…o último agendamento que eu criei" → o modelo não mandou `id` nem `busca` → "Informe qual agendamento…".
- Ou seja, a hipótese inicial (a palavra "último" não casava com nome) não era a causa: o `busca` nem chegava. Mas ela seria a próxima falha se o modelo mandasse `busca: "último"`.

**Decisão:**
- `AgendaService::localizarParaRemarcar`: "último", "última" e "recente" na busca viram ordenação por criação (`created_at desc, id desc`, um só resultado, só agendamento em aberto, do tenant e dos setores do papel). Palavras de enfeite ("agendamento", "mais", "quero", "criei"…) saem; as demais palavras continuam filtrando ("último da Mel").
- As descrições de `remarcar_agendamento`, `alterar_servico_agendamento` e `alterar_status_agendamento` (as três usam o mesmo localizar) mandam o modelo usar `busca = "último"` e nunca `posicao` quando não há tabela.
- Depois: as mesmas 3 frases, 3/3 abrem a ficha do agendamento certo.

**Alternativa descartada:** argumento novo (`recente: true`) na tool genérica de atualizar: mexe em todas as entidades. O `busca` já existe nas três tools.
**Escolha de significado:** "último" = último CRIADO em aberto, não o de data mais distante. Se o Lucas quiser o de data mais próxima/distante, é só trocar a ordenação.

Itens 2, 3 e 4 do plano (UI, Confirmar sem Revisar) ficam adiados: front em migração para React (Matheus).
