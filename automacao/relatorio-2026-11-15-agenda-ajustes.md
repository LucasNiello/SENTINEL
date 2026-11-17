# Ajustes da agenda da clínica — 15/11/2026

Branch `Manveru`. Nada commitado; nenhum comando git de histórico. `.env` e Notion não foram tocados. Decisões e alternativas descartadas: `.claude/decisoes/2026-11-15.md` (seção "Ajustes da agenda").

## O que mudou

| # | Pedido | O que foi feito |
|---|---|---|
| 0 | Clínica aceita até 4 agendamentos sobrepostos do mesmo profissional; o 5º é recusado; petshop continua recusando | `AgendaService::conferirHorarioLivre`, na mesma transação do `agendar` (profissional travado + leitura travada). Conta o **pico** de agendamentos ao mesmo tempo (4 consultas em fila não impedem uma cirurgia de 2 h). Mensagem: "O profissional já tem 4 agendamentos neste horário, o limite da clínica…". O card avisa "Já há N agendamentos neste horário…" (N ≥ 1, só na clínica), também no Revisar depois de a pessoa mudar o horário. |
| 1 | Remarcar abria "Agendar" vazio | **Causa:** não existia tool de remarcar, e o prompt dizia "remarcar = agendar o novo horário". Nova tool `remarcar_agendamento`: acha o agendamento (id, linha da tabela ou palavras do usuário: pet, tutor, serviço, profissional), edita só o início (o fim recalcula, mantendo a duração dele), nunca cria um novo. Card: só o início editável; pet, tutor, serviço e profissional só para conferir. Vários candidatos: o card pergunta qual. Vendedor negado; atendente só banho e tosa (o horário da clínica nem é achado). Limite de 4, Revisar → Confirmar e auditoria com o "antes". |
| 2 | Fim não é pedido | **Conferido:** nada preenchia o fim (era campo obrigatório). Agora o servidor calcula início + duração padrão do serviço, por categoria (consulta 30, vacina 15, cirurgia 120, banho 60, tosa 90…; `DURACAO_PADRAO_MINUTOS`, sem campo novo no banco, limitada ao máximo da configuração). O card mostra só o início. |
| 3 | Selects não achavam pela digitação | Rótulo agora é "Nome · #id" (o type-ahead do select casa o começo). Vale para todos os domínios (`FormularioTool`). Descartado o `datalist`. |
| 4 | "Falta preencher" ficava em campo preenchido | O servidor calcula o aviso ao montar o card e o front nunca o tirava. Agora some ao escolher ou digitar, e volta se esvaziar (JS em `bancada.blade.php`). |

Extra (achado do reviewer): o atendente via pet, tutor e veterinário de horários da clínica ao remarcar **e ao mudar o status** (a busca não filtrava o setor). Corrigido nos dois.

## Testes (só os arquivos tocados e as áreas de agenda, PIN, permissão e tenant, pelo test-runner)
- Novo: `tests/Feature/Clinica/AgendaLimiteERemarcarTest.php` (31 testes): limite de 4 e pico, cancelado/faltou liberam, petshop, enche entre o Revisar e a gravação, leitura travada, fim calculado, remarcar (service, agente, papéis, tenant, auditoria, falha fechada), aviso, selects, "Falta preencher".
- Concorrência: o SQLite dos testes ignora `FOR UPDATE`. A disputa real só existe no MySQL; o que está provado é que a contagem que decide é a leitura travada, dentro da transação que trava o profissional, e que o horário que enche entre o Revisar e a gravação é recusado.
- Alterados **por esta decisão** (a regra de 09/11 vale só no petshop agora): `AgendaServiceTest` ×2, `ToolsDeAgendaDaClinicaTest` ×2 (conflito simples virou cenário de petshop ou de 4 existentes); `ToolsDeAgendaDaClinicaTest` e `AgendaDoChatPorPapelTest` sem o campo `fim`; `PromptPorDominioTest` (a frase "remarcar = agendar o novo horário" era o bug); `FormularioAgenteTest` (rótulo do select); `IsolamentoDeTenantTest` (casos dos 5 métodos novos). Nenhum teste apagado.
- **17 sabotagens**, todas derrubaram o teste e foram restauradas (script direto: o test-runner só roda `php artisan test`).
- **Última suíte completa: 996/996, em 15/11, antes do commit `4968e83`.** Esta tarefa não a rodou (regra: só os arquivos tocados). Rodada filtrada final (test-runner): 691 testes, 690 passaram; a falha era o meu CSV da bateria (um ";" dentro do texto da observação), corrigido, e o `BateriaAgenteTest` voltou a 7/7. Total dos caminhos tocados: **691/691** (a parte já rodada limpa foi conferida de novo só no arquivo afetado).

## Chamadas de IA reais
9 nesta tarefa (acumulado 305 → 314), só em `database/bateria.sqlite`: `documentacao/testes/fase8/BATERIA_CLINICA_REMARCAR.csv`.
- 1ª rodada, 6 pedidos: **6/6**. O pedido do Lucas ("remarcar o retorno do dr. rafael para dia 16/11, às 10h") achou o agendamento do Dr. Rafael e abriu o card de remarcar. Remarcar pelo atendente, vendedor negado, agendar novo (pedido 7), mudar status (pedido 11) e a mensagem da caixa de entrada deram certo.
- Depois da revisão, 3 pedidos de novo (1, 2 e 6): 2/3. O pedido 6 (caixa: "Queria remarcar o banho do Thor para sábado") desta vez veio em **texto pedindo o horário** em vez de abrir o card; na 1ª rodada abriu a lista de escolha. É variação do modelo (a mensagem não traz hora): ponto fraco conhecido, sem trava no servidor.

## Pontos fracos / para o Lucas decidir
- Remarcar não confere a `versao` (duas pessoas remarcando o mesmo horário ao mesmo tempo: a segunda vence; o log diz de onde saiu). `alterar_status_agendamento` também não confere.
- O select de "Profissional" lista todos os usuários do tenant, não só quem pode atender; a regra do servidor recusa o papel errado.
- A duração padrão por serviço é uma constante no código; se o catálogo precisar de duração por item, é campo novo no banco (fora do pedido).
- A IA mandando `fim` na tool de agendar vira resposta fora do formato (502), como qualquer chave fora do esquema.

## Commits sugeridos
1. `feat(agenda): clinica aceita ate 4 agendamentos simultaneos do mesmo profissional (petshop mantem 1); fim calculado pelo servidor; aviso no card`
   - `app/Services/AgendaService.php`, `app/Services/Agente/CatalogoDaClinica.php` (agendar), `app/Http/Requests/AgendarRequest.php`, `app/Http/Controllers/AgenteController.php`, `app/Services/AiAgentService.php`, testes de agenda (`AgendaServiceTest`, `ToolsDeAgendaDaClinicaTest`, `AgendaDoChatPorPapelTest`, `IsolamentoDeTenantTest`)
2. `feat(agenda): remarcar_agendamento edita o agendamento existente (so o inicio); atendente nao acha horario da clinica`
   - `app/Http/Requests/RemarcarRequest.php`, `app/Services/Agente/CatalogoDaClinica.php`, `app/Services/AiAgentService.php` (prompt), `tests/Feature/Clinica/AgendaLimiteERemarcarTest.php`, `tests/Feature/PromptPorDominioTest.php`, `documentacao/testes/fase8/BATERIA_CLINICA*.csv`
3. `fix(front): selects com o nome primeiro e "Falta preencher" some ao preencher o campo`
   - `app/Services/FormularioTool.php`, `resources/views/partials/bancada.blade.php`, `tests/Feature/FormularioAgenteTest.php`
4. `docs: decisoes e relatorio dos ajustes da agenda (15/11)`
   - `.claude/decisoes/2026-11-15.md`, `automacao/relatorio-2026-11-15-agenda-ajustes.md`

(Os arquivos se repetem entre os commits 1 e 2: use `git add -p` ou junte 1 e 2.)
