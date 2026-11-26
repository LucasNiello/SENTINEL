# 26/11 — conversa do chat que sobrevive ao recarregar

**Decisão do Lucas.** Recarregar a página não pode apagar o trabalho em andamento (tabelas, cards). A proteção é o prazo, não a perda: logout, 2 h sem atividade ou "Nova conversa". Virar o dia ficou de fora.

**Implementação.**
- `ConversaAgente` guarda, junto da fala do assistente, a "tela" (`tela`): a tabela de consulta como o navegador a recebeu (já mascarada pelo servidor, até 200 linhas) ou o card de proposta. A IA nunca recebe isso: `ler()` remove `tela` e só entrega o texto.
- Mesmo limite de mensagens de sempre (16). Teto de 600 KB de tela por conversa: o excedente derruba a tela das falas mais antigas, o texto fica.
- Prazo: `PRAZO_CONVERSA_S` = 7200 s desde a última escrita (`em`). Vencida, a próxima leitura começa do zero e apaga também a lista de posições. `marcarProposta` e `vincularProposta` não renovam conversa vencida.
- Card pendente só volta com botões se o rascunho ainda existe e é do usuário; senão vira a tira "Não finalizado". Card finalizado perde a `tela` (fica a tira). Confirmar, Revisar e permissões seguem conferidos no servidor.
- `POST /agente/nova` descarta as propostas abertas (auditadas como canceladas, como num novo pedido) e apaga a conversa.
- Lote: `encerrarSelecao` tira os checkboxes e marca as linhas canceladas.

**Alternativa descartada.** Guardar só a consulta e refazê-la ao recarregar: não guarda dado, mas mostra dado novo (não o que estava na tela) e gera uma leitura auditada por consulta a cada recarga.

**Consequências a lembrar.**
- Isso muda a regra antiga "os dados não são guardados no histórico": agora ficam até 2 h na sessão (banco, criptografada), mascarados. Vale registrar na documentação e no checklist de segurança antes de produção.
- A decisão de 26/11 "a lista do lote não vence para o envio" passa a valer só dentro das 2 h da conversa, porque a leitura depois disso apaga a lista de posições.
- O card guardado pode trazer salário/CPF formatados quando é do Admin (como já aparece na tela). Não é mascarado no card guardado porque o valor mascarado sobrescreveria o real ao confirmar; por isso a `tela` sai da sessão quando a proposta termina de qualquer forma (confirmada, cancelada, substituída, expirada, recusada).
- Um GET /agente lê o histórico antes de a varredura de vencidas rodar; hoje nenhuma proposta vence, então não há efeito.
