# ADENDO (27/11/2026) ao plano de atualização da documentação — o que mudou DEPOIS, e a ordem de execução

**Complementa** `plano-atualizacao-documentacao-2026-11-26.md` (a seção 0 dele, as 5 decisões, segue valendo). **Confirmado pelo Lucas (27/11): a Documentação 1.3 é a mais recente; o alvo continua sendo a 1.4.**
**Contrato de quem executa (Claude web):** vê só os 3 arquivos do plano original, não inventa número, data nem comportamento, e não edita o CLAUDE.md (entrega texto). **Âncoras:** número de seção da 1.3 + frase curta entre aspas (use a busca pela frase; não há número de linha confiável). Onde estiver "VER COM O LUCAS", o plano não tem o detalhe: pergunte antes de escrever.

## A. Mudanças novas (lista tirada do git de 24/11 em diante e das decisões `.claude/decisoes/2026-11-24` a `2026-11-26`)

| # | Mudança | Onde na 1.3 | O que escrever |
|---|---|---|---|
| A1 | **Remarcar em lote** (`remarcar_agendamentos_em_lote`, 26/11) | §5.1 (catálogo: tool nova de leitura), §6 (parágrafo novo depois de "Estorno por itens"), §3.4 (telas) | O agente só monta o PLANO: o servidor propõe o novo horário de cada agendamento simulando a grade numa transação desfeita (nada é gravado). Dois modos: "mesmo horário" (primeiro dia em que todos cabem; se nenhum couber, o dia que acomoda mais e o resto vem bloqueado com o motivo) e "próximo horário livre" (por ordem de horário, mesmo profissional, cada um ocupa a vaga do seguinte). O usuário marca as linhas (caixas) e confirma; o endpoint grava SÓ os marcados, nos horários congelados na sessão (nunca um horário do navegador), com a versão de cada linha, tudo ou nada, uma linha de auditoria por item com o mesmo `lote_id`. Cirurgia e internação ficam fora (CRMV + senha, um a um). Máximo 25 por lote. Depois de gravar, o servidor confere o pedido ("ninguém ficou para trás" ou quem ficou) e as consultas seguintes realçam o último lote. **Estado: testado só por arquivo; falta a suíte completa e a passada do reviewer. Não cite número de testes para esta tool.** |
| A2 | **Consulta da agenda: resposta e realce** (779b5a8; 26/11) | §5.1 (`consultar_agenda`), §3.4 | Filtros por tipo de data (dia marcado, gravação, última alteração), ordenar e quantidade; a resposta vem em frase e a linha apontada fica realçada. Ordenada pela alteração, realça todo o lote (mesmo minuto). Dia vazio em que a clínica não abre: a frase explica o motivo e o funcionamento (decisão do servidor, não da IA). |
| A3 | **Atendimento do veterinário: prontuário com interface** (2e870eb, c8deac9; 26/11) | §3.4 (telas), §7.1 (Prontuário), §8 (segurança) | A 1.3 já diz "não há tool de prontuário; o prontuário é escrito pelo veterinário na tela": acrescentar que agora a TELA existe (rotas `/prontuarios`, fora do motor do agente) para abrir, preencher, assinar e adendar. Quem atende: veterinário, e o dono só porque é veterinário, com CRMV cadastrado; recepção, atendente, vendedor e Administração são barrados (403). Assinar e adendar: CRMV + senha + segurar 2 s; depois de assinado, só adendo. Rascunho de outro veterinário: só o dono lê; o veterinário recebe 404 (a mesma resposta de "não existe"). A auditoria grava ids, tipo e NOMES de campos, nunca o texto clínico. Botão Atender na Agenda (linhas do próprio veterinário); Novo prontuário e Abrir/Continuar na ficha do pet. |
| A4 | **Seed do movimento da demo** (`MovimentoDemoSeeder`, 26/11) | §11.3 ou onde a 1.3 trata os dados de demonstração; §13.2 | Seeder separado e aditivo (não apaga nada, roda uma vez): enche agenda, atendimentos, prontuários, cobranças e vendas de novembro e dezembro, com pets e tutores novos. Passado = realizado/cancelado/falta, com prontuário assinado e cobrança; futuro = só agendado ou confirmado (nada pago ou vendido no futuro). `migrate:fresh --seed` agora também planta isso, relativo à data do seed. Efeito: novembro fica muito acima dos meses anteriores no B.I. por ano (volume de demonstração). |
| A5 | **Conversa que sobrevive ao recarregar** (2edaa27; decisão 26/11) | §5.2 ("o histórico que vai ao modelo é montado pelo servidor e só contém frases fixas depois de uma escrita") e §5.3 | A conversa e as telas mostradas ficam guardadas na sessão por 2 h, mascaradas, e voltam ao recarregar; "Nova conversa" apaga. VER COM O LUCAS o texto exato da regra "dados não ficam no histórico" (mudou). |
| A6 | **Agendar em um passo, cadastro unificado, página Clientes, arquivar pet** (acf3b42) | §3.4, §5.1, §6.6 | Card Agendar com lista única; cadastro unificado de cliente com pets; página Clientes; arquivar e reativar pet. VER COM O LUCAS: quais papéis arquivam pet (hoje "a confirmar") e se `arquivar_pet` segura 2 s. |
| A7 | **"Qualquer profissional" e referências no servidor** (a8c4367, 200dee1; 25/11) | §5.1, §6.2 | O servidor escolhe o profissional quando o usuário diz "qualquer um" e resolve "o último", "o segundo" e "o Rex" sem a IA informar id. Confirmar em UMA requisição (sem a tela de Revisar). A 1.3 não menciona "qualquer profissional". |
| A8 | **Contas ativas e inativas** (e23582a; 26/11) | §3.2, §8.4 | A conta pode ser desativada; a conta desativada não entra (a mensagem específica só aparece depois da senha certa). |
| A9 | **E-mail simulado (Mailpit)** (24/11) | §7.6 (caixa de entrada), §3.1 | Saída: o tutor recebe e-mail ao criar, confirmar e cancelar agendamento. Entrada: e-mails para o endereço de contato viram "Mensagens novas". É SIMULAÇÃO local. "Mailpit" não aparece na 1.3. |
| A10 | **Editor de estornos por itens no React** (094bf50) | §6.5, §3.4 | Só se a 1.3 descreve a tela do estorno em Blade: atualizar para o React. |

## B. Correções ao plano original

1. **§2 "Testes".** O último número registrado é **1495/1495 (26/11)**, rodado ANTES do seeder do movimento e do remarcar em lote. O Lucas informa o número depois da suíte completa final; não escreva outro.
2. **Dois roteiros.** O alvo é `documentacao/ROTEIRO_DEMONSTRACAO.md`. Copie para ele o que o `.claude/plano/roteiro-demo.md` ganhou em 26/11: o **passo 7** (Atender na Agenda → preencher → Assinar com CRMV + senha → Adendo) e uma cena nova **"dia 27 fechado → remarcar em lote"** (pedir; revisar o plano com caixas; marcar; segurar 2 s; ler a conferência "ninguém ficou para trás"; perguntar "o que foi remarcado" e ver o lote realçado).
3. **Preparação da demonstração.** Acrescentar que o `migrate:fresh --seed` planta novembro e dezembro relativos à data do seed, e que as contas são só as 11 de `@clinica.sentinel.br` (as `@clinica.sentinel.local` não valem).

## C. NÃO documentar como feito

- **Bloqueios de agenda** (`.claude/plano/plano-2026-11-27-bloqueios-de-agenda.md`): só planejados.
- **Ficha do pet em abas** e a "opção B" (despesas de novembro para o B.I.): só planejadas.
- **Remarcar em lote:** feito e testado por arquivo, sem suíte completa e sem a passada do reviewer (A1 diz isso).
- **Dias de funcionamento editáveis:** hoje o painel do dono muda horário, intervalo e vagas; os DIAS (segunda a sábado) são fixos na configuração. Em §10 e §13.1, escrever isso.
- **RAG:** não existe e não está previsto. Se a banca perguntar: o conhecimento do agente (expediente, vagas, regras) é estruturado e vem do servidor.

## D. Decisões do Lucas ANTES de a documentação afirmar (perguntar, não decidir)

1. **Segurar 2 s no remarcar em lote.** O CLAUDE.md lista o segurar só nas ações destrutivas; o lote de remarcar usa. Documentar como regra só depois de ele confirmar.
2. **Texto do histórico depois do lote.** A frase do resultado do lote vai ao histórico que a IA lê nos pedidos seguintes e inclui nomes de pets e horários ("Remarcados: Fred para 28/11 12:30…"). A regra documentada diz que o histórico só leva frases fixas, sem nomes. Ou o Lucas aceita e a documentação diz isso, ou o histórico volta a ter só contagens e os destinos ficam só na tela. **Bloqueia a seção A1 e a §5.2.**
3. **Administração.** No código continua "só PINs"; a documentação mantém isso. O pedido de a Administração controlar horários depende da decisão sobre os bloqueios.
4. **Rótulo da versão (1.4) e data da banca (30/11)** continuam com ele.

## E. Ordem de execução (do que a banca vê para o que ela não vê)

1. `ROTEIRO_DEMONSTRACAO.md` (B.2, B.3 e a seção 3 do plano original).
2. Documentacao-Sentinel1.3.docx, seções de governança: **A1** (remarcar em lote) e **A3** (prontuário); depois a seção 1 do plano original (fim do prazo).
3. As demais linhas de A (A2, A4 a A10), na ordem da tabela.
4. Texto sugerido para o `CLAUDE.md` (o Claude web NÃO edita; entrega como texto): documentação vigente **1.3 → 1.4** quando existir, "5 min" removido, "JS vanilla" → React como front da demonstração, prontuário com interface própria (fora do motor) e, se D.1 confirmar, a lista dos "segurar 2 s" com o remarcar em lote.
5. Notion: um NOVO bloco datado "27/11/2026" na página "Arquitetura", com A1 a A10 em uma linha cada. Só acrescentar.
6. Figuras e diagramas **MANUAL**, por último.
