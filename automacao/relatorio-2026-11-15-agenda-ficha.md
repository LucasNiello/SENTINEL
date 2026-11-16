# Ficha de agendamento e agenda em blocos (15/11/2026)

Branch `Manveru`. Nada foi commitado e nenhum comando git que altere o histórico foi executado. `.env`, MySQL e Notion não foram tocados.

As decisões e as alternativas descartadas estão em `.claude/decisoes/2026-11-15.md`, na seção "Ficha de agendamento e modelo de blocos".

## O que foi feito (ordem 3 → 1 → 2 → 4 → 5 → 6)

| # | Tarefa | Resultado |
|---|---|---|
| 3 | Modelo de agenda | **`GradeDaAgenda`** (PHP puro) e a seção `agenda` em `config/sentinel.php`.<br>**Clínica:** blocos de 1 h (08:00 … 17:00) com 4 vagas por veterinário; o fim é o fim do bloco. A cirurgia fecha os 2 blocos que cobre. A internação ocupa 1 vaga, só no bloco de entrada. Bloco em andamento ainda aceita agendamento; bloco encerrado fecha.<br>**Petshop:** fim = início + duração, um atendimento por vez, grade de 15 min, intervalo semiaberto.<br>**Removidos:** a contagem por pico, a duração padrão da clínica e o aviso "Já há N". A conferência continua na gravação, na mesma transação, com `lockForUpdate`. |
| 1 | Molde único | `partials/ficha-agendamento.blade.php` (`<template>` + JS), usado por agendar, remarcar e trocar serviço.<br>**Cabeçalho:** pet e tutor com link, serviço, profissional e horário atuais. Só os campos da ação são editáveis.<br>**Lista de candidatos:** as colunas da tabela da agenda, com o botão "Escolher". |
| 2 | Calendário | Profissional (com busca e a opção "qualquer") → dia (Hoje, Amanhã e mini mês; dia sem vaga fica apagado) → horário ("09:00 · 2 vagas"; lotado fica desabilitado).<br>Clicar no horário já faz o Revisar.<br>**Endpoint:** `GET /agenda/disponibilidade`. Papel e tenant vêm do servidor. Devolve só hora, vagas e o nome dos profissionais do setor. |
| 4 | Trocar serviço | Tool nova `alterar_servico_agendamento`, com RBAC, rascunho → Revisar → Confirmar, Form Request e auditoria do "antes" (serviço, setor, profissional, início e fim).<br>**Mesmo setor:** mantém o profissional e o horário; o fim é recalculado.<br>**Outro setor:** o profissional fica em branco e a ficha abre com os veterinários e o calendário.<br>Altera o mesmo agendamento, nunca duplica. É recusada com cobrança válida. |
| 5 | Tutor ↔ pet | Nova tela `/tutores/{id}`, só leitura, com os pets do tutor.<br>A ficha do pet leva ao tutor.<br>Em Cadastros › Tutores, "Ver ficha".<br>No chat, o card de alterar tutor mostra os pets e o de alterar pet mostra o tutor. |
| 6 | Prompt | Novas regras: "ver/abrir a ficha" → `remarcar_agendamento`; troca de serviço → a tool nova; nunca discutir opções em texto; "qualquer veterinário" → profissional em branco. |

**Também mudaram:**
- Remarcar pode trocar o profissional, desde que do mesmo setor.
- Remarcar, trocar serviço e mudar status conferem a `versao`.
- O agendar acha o pet pelo nome quando só um pet bate.
- O `ClinicaSeeder` usa hora cheia na clínica e pula o domingo (`diaDaAgenda`). A ordem de criação foi mantida, então os ids 21 e 22 não mudam.
- O CSS da ficha foi para `workspace.css` e o `npm run build` foi refeito (Node do Laragon).

**Limitação aceita:** a `versao` é o `updated_at`, com precisão de segundo. Duas alterações no mesmo segundo não são distinguidas.

## Testes
Por decisão sua no meio do bloco, só **escrevi** os testes das travas e **não rodei nenhum**:
- **`tests/Unit/GradeDaAgendaTest.php` (5):**
  - o 5º agendamento no bloco lota;
  - a cirurgia fecha 2 blocos;
  - a internação ocupa 1 vaga;
  - bloco em andamento aceita e bloco encerrado recusa;
  - o petshop recusa sobreposição, e encostar não conta.
- **`tests/Feature/Clinica/FichaDeAgendamentoTest.php` (8):**
  - o endpoint não devolve pet nem tutor e não enxerga outro tenant;
  - vendedor e atendente ficam barrados na clínica;
  - a troca de serviço é recusada com cobrança válida e liberada quando a cobrança está cancelada;
  - versão mudada recusa, no service e entre o Revisar e o Confirmar;
  - a leitura é travada no remarcar e na troca de serviço;
  - a troca no mesmo setor e a troca de setor alteram o agendamento sem duplicar.

**Sem sabotagens:** elas precisam rodar teste. Ficam para a sessão da meia-noite, junto com a suíte.

**Última suíte completa:** 996/996, em 15/11, antes do commit `4968e83`. Nenhuma rodada depois disso.

**Testes antigos que devem quebrar à meia-noite.** Todos pela regra nova; não reescrevi nenhum.
- **`AgendaLimiteERemarcarTest`:**
  - pico e limite: a frase "já tem 4 agendamentos neste horário" mudou;
  - `duracaoPadraoMinutos()` foi removido, e o teste vai dar Error;
  - fim de 15 ou 30 min virou fim do bloco;
  - o "fim informado vale" não existe mais;
  - inícios da clínica às 14:30 e 10:15 agora são recusados (fora do bloco);
  - o aviso "Já há N" saiu;
  - o card de remarcar agora tem também o campo profissional;
  - a lista de escolha mudou para as colunas da agenda.
- **`ToolsDeAgendaDaClinicaTest` e `AgendaServiceTest`:** a frase do limite, e horários da clínica fora da hora cheia.
- **`IsolamentoDeTenantTest`:** os casos de `sobrepostos*`, que saíram.
- **`TelasDeLeituraDaClinicaTest`:** horários "09:00–09:30" agora são "09:00–10:00".
- **`PromptPorDominioTest`:** se travar alguma frase da clínica que mudou.
- **`ClinicaSeederTest`:** se contar horários.

**Seeder sem prova de fumaça:** o `migrate:fresh --seed` no `bateria.sqlite` foi negado pela permissão, e não insisti. O `ClinicaSeederTest` da meia-noite mostra se a grade nova aceita todo o seed.

## Bateria real (Haiku)
Rodou em `documentacao/testes/fase8/BATERIA_CLINICA_FICHA.csv`, com saída em `BATERIA_CLINICA_FICHA_HAIKU_2026-11-15.csv`, no `database/bateria.sqlite` que já existia (semeado às 17:11, antes da grade nova).

**Resultado: 5/5.** Foram 5 chamadas reais; o acumulado passou de **314 para 319**.

| Pedido | Resultado |
|---|---|
| "do bidu foi marcado errado. é uma consulta" | `alterar_servico_agendamento`, agendamento 22, com "Consulta clínica" e o profissional em branco (troca de setor). |
| "troca o banho do Thor por tosa" | A lista de escolha: o Thor tem 2 agendamentos em aberto e a IA mandou só "thor". |
| "abre a ficha do agendamento 21" | A ficha do 21. |
| "remarcar a Luna" | A lista de escolha (2 candidatos). |
| "marca uma consulta para a Luna amanhã com qualquer veterinário" | A ficha com a Luna (`pet_id` resolvido pelo nome), o serviço Consulta e o profissional em branco. A IA mandou o início sem hora; a ficha resolve pelo calendário. |

## Revisão (reviewer, 1 passada)
Não houve achado de permissão nem de vazamento entre tenants, por isso não fiz a 2ª passada. Também não houve bloqueador.

**Aplicados:**
- **2:** teste de leitura travada no remarcar e na troca de serviço.
- **3:** o calendário ganhou um balde próprio (120 por minuto), para não gastar o Revisar e o Cancelar.
- **6:** remarcar e troca de serviço travam os dois profissionais em ordem de id, para não haver impasse quando duas trocas se cruzam.
- **7:** o "antes" da troca sempre registra os 5 campos.
- **8:** cirurgia livre conta 1 por veterinário (antes, "qualquer veterinário" mostrava 8 vagas).
- **9:** a entrada da internação no seeder segue o mesmo dia útil do agendamento.

**Para você decidir:**
- **4:** remarcar e trocar serviço não recusam agendamento que já tem prontuário. Hoje nenhum caminho de produção cria prontuário em agendamento aberto. Proteger custa uma linha em cada um (o mesmo `existeTravado` do `conferirStatus`). Não fiz porque a regra pedida listava só as recusas de realizado, cancelado e cobrança.
- **5:** a exclusividade da cirurgia é lida da categoria atual do catálogo. Se o dono mudar a categoria, as cirurgias já agendadas deixam de fechar os blocos.

**Não aplicado:** 10 (lacunas menores de teste: a versão pelo chat no mudar status, e a versão vinda do navegador ser ignorada).

## Adendo: cirurgia e internação só com CRMV (decisão sua de 15/11, que muda a matriz de 09/11)
**Regra.** A trava fica no `AgendaService::conferirRestrito`, e não só na ficha. Agendar, remarcar ou trocar PARA cirurgia ou internação exige um usuário com CRMV no perfil. O mesmo vale para mexer num agendamento que já é de cirurgia ou internação. Esse usuário confirma com o CRMV e a senha dele nesta requisição.
- **Configuração:** as categorias ficam em `sentinel.agenda.clinica.restritas_crmv`. Se a chave faltar, valem cirurgia e internação (falha fechada).
- **Encaixe de emergência:** não muda. Os demais papéis só consultam.
- **Substituição:** remarcar trocando o profissional, e o novo profissional só pode ser quem está logado. Se houver conflito, a recusa lista "No caminho: #id (hh:mm–hh:mm)", só número e horário, só do tenant.
- **Auditoria:** `valores_anteriores` guarda o vet anterior e `autorizado_por` registra o reautenticado. O prontuário não muda (há teste).

**Conferência.** É feita em `ReautenticacaoAdmin::autorizarComCrmv`. O CRMV (normalizado) e a senha de login precisam ser do próprio usuário.
- **Limite:** igual ao do login, 5 tentativas erradas por minuto, por usuário + IP. A tentativa é contada antes de conferir e devolvida quando acerta.
- **Tempo de resposta:** o mesmo com o CRMV errado.
- **Senha:** nunca vai para log, auditoria, sessão ou IA.
- **PIN:** nenhum PIN foi criado para veterinário.

**Card.**
- O card pede "Seu CRMV" e "Sua senha" só a quem tem CRMV. Quem não tem vê logo o aviso "Sem CRMV, você só consulta", e o Revisar recusa.
- Se o serviço muda no card, o Revisar devolve o card refeito, e o pedido de CRMV aparece ou some.
- O Confirmar não sai com os campos vazios.

**Testes.** Arquivo `tests/Feature/Clinica/CirurgiaComCrmvTest.php`, com 9 testes, escritos e **não rodados**:
- CRMV e senha certos passam;
- senha errada e CRMV de outro vet são recusados;
- sem CRMV é recusado, no Revisar e no service;
- o substituto não escala outro vet, e o prontuário fica intacto;
- o substituto com bloco ocupado é recusado com a lista do caminho, sem nada de outro tenant;
- limite de 5 por minuto;
- acertar devolve a tentativa;
- o executor chamado direto, sem a reautenticação do próprio usuário, recusa;
- a cirurgia que já existe não é mexida sem CRMV nem sem a senha.

Todos entram na sabotagem da meia-noite. Os testes antigos que agendam cirurgia sem ator (`AgendaLimiteERemarcarTest`) vão quebrar por esta regra.

**Bateria (Haiku).** O pedido "agende uma castração para o Thor sexta às 14h" deu **2/2**: com o veterinário e com a recepção, as duas viram Castração (#8) em 20/11 às 14:00. Saída em `BATERIA_CLINICA_FICHA_CRMV_HAIKU_2026-11-15.csv`.
- Na recepção, a IA mandou `pet_id` 0. Corrigi: id inválido passa a contar como "em branco" e o servidor usa o nome do pet.
- Foram 2 chamadas reais, e o **acumulado é 321**.

**Reviewer: 2 passadas, sem achado de permissão nem de senha.**
- **1ª passada:** acrescentei os testes que faltavam (limite de tentativas, cirurgia existente mexida pelo card, prontuário, "No caminho" sem outro tenant). As categorias passaram a falhar fechado se a chave faltar.
- **2ª passada:**
  - teste do executor chamado direto (a sabotagem em `autorizacaoDaAgenda` passava);
  - teste de que acertar devolve a tentativa;
  - asserção da frase na recusa da recepção;
  - janela de 60 s ao devolver a tentativa do CRMV;
  - `null` na configuração das categorias não abre a regra;
  - o CRMV só é pedido a quem tem;
  - o card refeito sempre volta nas fichas da agenda;
  - texto "Sem CRMV, você só consulta".

**Para você decidir:**
1. **Mudar o status** (cancelar, faltou, realizado) de uma cirurgia não pede CRMV. A recepção ainda cancela uma castração. A decisão não incluía o status; para incluir basta uma linha no `alterarStatus`.
2. **Trocar uma cirurgia por um serviço do petshop** fica impossível: o novo profissional teria de ser o próprio usuário. O mesmo acontece ao trocar por consulta com outro vet. Isso vem da regra "só você" na substituição; hoje só se resolve cancelando e agendando de novo.
3. **Cirurgia nova para outro veterinário:** quem tem CRMV pode agendar uma cirurgia NOVA para outro vet, mas não pode passar a de alguém para um terceiro. A regra de "só você" ficou só na substituição.
4. **Risco latente no Confirmar:** os ramos "senha de admin/PIN" e "CRMV" são exclusivos. Hoje nenhuma tool da agenda pede senha de admin, então não há como explorar. Se um dia uma tool pedir os dois, o `autorizado_por` não diz qual conferência foi feita.
5. **Limitação aceita:** a exclusividade da cirurgia e a restrição por CRMV são lidas da categoria ATUAL do catálogo.

## Adendo final e fechamento (15–16/11). SUBSTITUI as seções acima sobre ocupação, cirurgia e internação
**Ocupação** (`sentinel.agenda.clinica.por_categoria`):

| Serviço | Como ocupa o bloco do veterinário |
|---|---|
| Consulta | 1 de 4 vagas |
| Vacina, exame, microchipagem | O bloco inteiro, sozinho |
| Cirurgia | 2 blocos inteiros |
| Internação | Não ocupa a agenda (fica só a data de entrada) |
| Categoria fora da lista | Agendamento novo recusado (falha fechada); quem já está na agenda com ela conta como bloco inteiro |

No calendário, "N vagas" aparece só na consulta; nos demais serviços, "livre" ou "ocupado".

**Cirurgia** (trava no servidor, com CRMV + senha do próprio usuário):

| Ação | Quem pode |
|---|---|
| Agendar | Só para si mesmo |
| Assumir a de um colega | Remarcar trocando o profissional para si |
| Remarcar | Só a própria; mover a de um colega mantendo o colega é recusado (revisão de 16/11) |
| Cancelar | Qualquer usuário com CRMV |
| "Faltou" e "realizado" | Regra de antes, sem CRMV |
| Trocar de ou para cirurgia | Ninguém: é cancelar e agendar de novo |

- Quem não tem CRMV só consulta as cirurgias.
- A internação saiu da trava de CRMV.
- A auditoria grava o tipo de conferência na mensagem, sem campo novo: "(conferência: crmv+senha)", "pin" ou "senha de admin".

**Limitações aceitas:**
- a ocupação e a trava são lidas da categoria ATUAL do catálogo;
- trocar cirurgia por outro serviço = cancelar e agendar de novo;
- a `versao` é o `updated_at`, com precisão de segundo.

**Fechamento, na ordem combinada:**
1. **1ª suíte completa:** 1077 testes, 1035 passaram, 33 falharam. Fora os testes antigos, falharam 2 testes meus, o seed do lucro e dois testes de tela (por texto da página).
2. **Correções:**
   - **Código e seeder:**
     - a lista "No caminho" ficou só na clínica;
     - o comentário com "Tutores" saiu da tela da contabilidade;
     - o link de pets voltou ao texto que o teste trava;
     - as despesas do seed acompanham o dia útil da receita da agenda;
     - a mensagem da auditoria ganhou o tipo de conferência mesmo quando o sucesso não tem frase.
   - **Testes antigos, mudados só pela regra nova:**
     - `AgendaLimiteERemarcarTest`:
       - as frases do limite mudaram;
       - o fim agora é o fim do bloco;
       - o início segue os blocos de 1 h;
       - o fim informado é ignorado;
       - a ficha de remarcar tem profissional e início, e o cabeçalho ganhou tutor e os campos "atuais";
       - a lista de escolha usa as colunas da agenda com "Escolher";
       - o aviso "Já há N" saiu;
       - o pico virou bloco inteiro;
       - "o próprio não conta" passou a ser provado pela troca de serviço;
       - a cirurgia passou a ter CRMV.
     - `AgendaServiceTest`:
       - o fim agora é calculado e o informado é ignorado;
       - a grade do petshop é de 15 min;
       - a frase "Informe o início" mudou;
       - o máximo de duração vale sobre o fim calculado;
       - o encaixe segue o bloco em andamento.
     - `IsolamentoDeTenantTest`: os casos de `sobrepostos*` saíram e entraram os dos 6 métodos novos.
     - `ToolsDeAgendaDaClinicaTest`: a frase do limite.

   Nenhum teste foi apagado nem afrouxado além da regra nova; a 1ª passada do reviewer não viu afrouxamento.
3. **`ClinicaSeederTest`:** passou, inclusive "sem prejuízo em nenhum período". O `migrate:fresh --seed` no `bateria.sqlite` **foi negado pela permissão desta sessão**, duas vezes, e não insisti. Falta você rodar:
   `DB_CONNECTION=sqlite DB_DATABASE=<raiz>/database/bateria.sqlite php artisan migrate:fresh --seed --force`
4. **Bateria (Haiku):** **7/7** (os 5 da ficha + a castração com vet e com recepção). Rodou no `bateria.sqlite` antigo; saída em `BATERIA_CLINICA_FICHA_HAIKU_2026-11-16_final.csv`. **Chamadas reais acumuladas: 328.**
5. **Sabotagem:** script direto, restaurando cada arquivo (md5 igual no fim). **18 de 18 derrubaram o teste:** vaga do bloco, bloco inteiro/cirurgia, conflito do petshop, versão, troca de setor, cobrança válida, endpoint sem tenant, leitura travada, sem CRMV, senha errada, substituto escalando outro vet, substituto com bloco ocupado, reautenticado sempre true, cirurgia para outro vet, cancelar sem CRMV, internação fora da agenda, procedimento como vaga e limite de tentativas.
6. **Suíte de novo:** **1081/1081**, em 16/11, depois das sabotagens (era 996).
   - Depois dela, a revisão de 16/11 mudou: remarcar cirurgia só a própria (mais 2 testes) e duas frases.
   - Os 3 arquivos afetados passaram: 55/55, rodados direto, sem o `test-runner`.
   - A 2ª passada acrescentou o teste da senha no service para a cirurgia existente.
   - **Suíte completa final: 1084/1084, em 16/11** (pelo `test-runner`). É a última suíte completa.
7. **Reviewer, 2 passadas:**
   - **1ª:** sem permissão e sem senha. Aplicados o item 1 (remarcar cirurgia de colega sem assumir: recusado) e as frases visíveis. Ficaram como estão os comentários no código que ainda citam "cirurgia e internação" e os nomes de dois testes antigos ("pico").
   - **2ª:** sem bug de permissão nem de senha. Aplicados:
     - teste da senha exigida pelo service ao remarcar, assumir e cancelar uma cirurgia que já existe;
     - a decisão de 16/11 registrada no log;
     - o texto do card sem "internação".
   - **Brecha do "faltou" fechada (sua decisão de 16/11):** em cirurgia e internação, "faltou" só vale a partir do início do agendamento. Antes disso, o servidor recusa com o motivo, e para desfazer é preciso cancelar (na cirurgia, com CRMV e senha).
     - Teste novo e 2 sabotagens (S19 e S20), que derrubaram o teste.
     - Nos arquivos tocados e nos que usam "faltou": 199/199.
     - **Suíte completa depois da trava (16/11, pelo `test-runner`): 1085/1085.** Eram 1084; o teste novo entrou e nada quebrou. É a última suíte completa.

## Pendências (fora deste bloco)
- **Painel do dono:** abas Clínica (para configurar a seção `agenda`), Funcionários, Clientes e Petshop, e o dashboard.
- **Fichas dos outros domínios:** padronizá-las no mesmo molde.
- **Sabotagens:** nas travas novas (vaga do bloco, cirurgia, conflito do petshop, versão, troca de setor, cobrança válida, endpoint), na sessão da meia-noite.

## Autorrevisão
- **Dava para ser mais simples?** No backend, sim em um ponto: escolher o serviço pela lista "número = serviço" na descrição da tool (o mesmo padrão da venda) evitou um resolvedor de texto. O calendário (cerca de 250 linhas de JS) é o maior pedaço. O mini mês e a busca de profissional eram pedidos explícitos.
- **O que ficou de fora:** os testes de UI e do prompt, por decisão sua; as sabotagens; a prova de fumaça do seeder; os achados 4, 5 e 10.
- **Conflitos:** nenhum com a Documentação 1.2 nem com o CLAUDE.md. Clicar num horário chama o mesmo `/agente/revisar`, e o rascunho de 5 min, o Confirmar e a trava do servidor continuam.
- **Ponto para você ver no navegador:** o calendário só foi validado por sintaxe e pela build, não clicado. Vale abrir uma ficha antes de 18/11.

## Commits sugeridos (só texto)
1. `feat(agenda): clinica em blocos de 1 h com 4 vagas, cirurgia fecha 2 blocos, petshop por duracao (GradeDaAgenda + config agenda)`
   - `config/sentinel.php`, `app/Services/GradeDaAgenda.php`, `app/Services/AgendaService.php`, `app/Http/Requests/AgendarRequest.php`, `database/seeders/ClinicaSeeder.php`, `tests/Unit/GradeDaAgendaTest.php`
2. `feat(agenda): ficha unica de agendamento com calendario; remarcar troca o profissional; versao em remarcar/status`
   - `resources/views/partials/ficha-agendamento.blade.php`, `resources/views/partials/bancada.blade.php`, `resources/css/workspace.css`, `app/Http/Controllers/ClinicaTelasController.php` (disponibilidade), `routes/web.php`, `app/Http/Requests/RemarcarRequest.php`, `app/Services/Agente/ConstrutoresDeTool.php`, `app/Services/AiAgentService.php` (colunas_lista, preparar, ficha), `app/Services/Agente/CatalogoDaClinica.php`
3. `feat(agenda): alterar_servico_agendamento altera o mesmo agendamento (mesmo setor ou troca de setor)`
   - `app/Http/Requests/AlterarServicoAgendamentoRequest.php`, `app/Services/Agente/CatalogoDaClinica.php`, `tests/Feature/Clinica/FichaDeAgendamentoTest.php`
4. `feat(clinica): ficha do tutor com os pets; pet leva ao tutor; cards de tutor e pet ligados`
   - `resources/views/clinica/tutor.blade.php`, `resources/views/clinica/pet.blade.php`, `resources/views/telas/consulta.blade.php`, `app/Http/Controllers/{ClinicaTelasController,TelasController}.php`, `routes/web.php`
5. `feat(agente): prompt da clinica abre a ficha (ver/abrir, trocar servico, qualquer veterinario)`
   - `app/Services/AiAgentService.php`
6. `feat(agenda): cirurgia e internacao so com CRMV + senha do proprio usuario; substituicao so para si mesmo`
   - `config/sentinel.php`, `app/Services/AgendaService.php`, `app/Services/ReautenticacaoAdmin.php`, `app/Http/Controllers/AgenteController.php`, `app/Services/AiAgentService.php`, `app/Services/Agente/{CatalogoDaClinica,ConstrutoresDeTool}.php`, `resources/views/partials/bancada.blade.php`, `tests/Feature/Clinica/CirurgiaComCrmvTest.php`
7. `feat(agenda): ocupacao do bloco por categoria (consulta divide, procedimento e cirurgia ocupam inteiro, internacao fora); cirurgia so com CRMV + senha (agendar para si, assumir, cancelar); conferencia na auditoria`
   - `config/sentinel.php`, `app/Services/{GradeDaAgenda,AgendaService,AuditoriaService,AiAgentService}.php`, `app/Services/Agente/CatalogoDaClinica.php`, `app/Http/Controllers/AgenteController.php`, `resources/views/partials/{bancada,ficha-agendamento}.blade.php`, `resources/views/telas/consulta.blade.php`, `database/seeders/ClinicaSeeder.php`, `tests/Unit/GradeDaAgendaTest.php`, `tests/Feature/Clinica/{CirurgiaComCrmvTest,AgendaLimiteERemarcarTest,AgendaServiceTest,IsolamentoDeTenantTest,ToolsDeAgendaDaClinicaTest}.php`
8. `feat(agenda): faltou em cirurgia e internacao so a partir do inicio (antes, cancelar)`
   - `app/Services/AgendaService.php`, `tests/Feature/Clinica/CirurgiaComCrmvTest.php`
9. `docs: bateria da ficha (Haiku 7/7), decisoes e relatorio de 15-16/11`
   - `documentacao/testes/fase8/BATERIA_CLINICA_FICHA*.csv`, `.claude/decisoes/2026-11-15.md`, este relatório

O commit 6 toca de novo os arquivos dos commits 1 a 5. Os arquivos `CatalogoDaClinica.php`, `AiAgentService.php`, `ClinicaTelasController.php` e `routes/web.php` aparecem em mais de um commit: use `git add -p` ou junte os commits 2 a 5.
