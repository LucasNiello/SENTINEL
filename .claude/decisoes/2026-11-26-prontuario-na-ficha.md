# 26/11 — prontuário (só leitura) na ficha do pet

**Pedido do Lucas.** O veterinário logado não achava o prontuário em lugar nenhum: o sistema tinha dados e regras (`ProntuarioService`, modelos, testes), mas nenhuma tela. Aprovada a opção 1: bloco de leitura na ficha do pet.

**O que entrou.**
- Bloco "Prontuário" na ficha do pet (React), só leitura, para `veterinario` e `dono` (os mesmos `PAPEIS_CLINICOS` do service). Recepção, atendente, vendedor e admin da clínica não recebem nenhum texto clínico.
- Mostra tipo, data, veterinário, situação (assinado ou em rascunho), anamnese, exame físico, diagnóstico, conduta, entrada/alta e adendos. A assinatura e o autor do adendo vêm do snapshot gravado na assinatura (nome e CRMV), não do cadastro atual.
- Auditoria: `ver_prontuario_pet` por abertura, com parâmetros vazios (nenhum dado clínico no log). Só no front React: o Blade (plano B) não mostra o bloco, então não lê nem audita.
- Sem tool nova do agente: o prontuário continua fora da IA.

**Decisão assumida (confirmar).** Rascunhos de outro veterinário ("Em rascunho") aparecem para qualquer veterinário e para o dono. O `ProntuarioService` só define regra de EDIÇÃO (só o autor), não de leitura; o dono e os veterinários do tenant já são clínicos. Se o Lucas quiser que rascunho só apareça ao autor, é um `where` a mais.

**Fora desta entrega (pendência pós-banca).** Criar/editar, assinar (CRMV + senha) e adendar pela interface; itens cobrados no prontuário; vínculo da emergência.

**Também hoje.** Cancelar um card aberto fora do chat (Cadastros, Clientes, fichas) responde com texto neutro do sistema ("Cancelado. Nada foi alterado."), não com a fala do agente; o chat mantém a frase de antes.
