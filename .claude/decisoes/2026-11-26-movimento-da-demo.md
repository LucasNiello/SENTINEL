# 26/11 — Movimento da demo (novembro e dezembro) e atendimento fora do motor do agente

## Movimento da demo: seeder separado e aditivo
- **Decidi:** `MovimentoDemoSeeder` (novo arquivo), chamado pelo `DatabaseSeeder` depois do `ClinicaSeeder` e também sozinho: `php artisan db:seed --class=MovimentoDemoSeeder`. Não apaga nada, e roda uma vez só (marca: tutor "Larissa Campos").
- **Descartei:** colocar tudo dentro do `ClinicaSeeder` (ele sai cedo se já há tutores, então não rodaria no banco atual sem `migrate:fresh`, que apagaria PINs e prontuários feitos à mão).
- **Passado x futuro** na data em que o seed roda (não em data fixa): passado = realizado (clínica com prontuário assinado), cancelado, faltou, cobrança paga / a receber / aberta, e vendas de balcão. Futuro (até o fim do mês seguinte) = só agendado ou confirmado. Dezembro tem só agenda: receita futura apareceria nos Resultados.
- **Âncoras da demo protegidas:** só pets e tutores NOVOS (Rex e os outros 4 intocados); nos 7 primeiros dias à frente só vet3 e estetica2 recebem agenda nova (bloco lotado do vet2, estetica1 e "remarque o segundo" ficam como estavam). Sem despesas novas (só receita: nenhum dia fica negativo). Sem cirurgia (exige CRMV e autorização).
- **Efeito conhecido:** novembro fica bem acima dos meses anteriores no B.I. por ano (volume de demonstração).
- **Desfazer:** só `migrate:fresh --seed` (apaga também o que foi feito à mão).
- `ClinicaSeederTest` passou a aceitar o arquivo novo na pasta de seeders; prova em `MovimentoDemoSeederTest`.

## Atendimento do prontuário fora do motor do agente (aprovado pelo Lucas em 26/11)
- Rotas `/prontuarios` em `ProntuarioController`; o agente continua sem tool de prontuário (`AgenteSemProntuarioTest`). Assinar e adendar: CRMV + senha + segurar 2 s.
- Revisão (2ª passada, 26/11): campos travados com o painel de assinatura aberto; 404 com a mesma mensagem para inexistente, de outro tenant ou rascunho alheio.

## Remarcar em lote (26/11, decisão do Lucas)
- **Decidi:** tool `remarcar_agendamentos_em_lote` (leitura) + `POST /agente/agenda/remarcar-lote`, no desenho do cancelar em lote: o servidor monta o plano (simulação da grade numa transação desfeita), o usuário marca (caixas), segura 2 s, e só os marcados gravam, nos horários congelados na sessão, tudo ou nada, auditoria por item com o mesmo `lote_id`. Depois o servidor confere quem do pedido ficou para trás.
- **Dois modos:** `mesmo_horario` (primeiro dia em que todos cabem; se nenhum, o que acomoda mais, o resto bloqueado com o motivo) e `proximo_livre` (por ordem de horário, mesmo profissional, cada um ocupa a vaga do seguinte).
- **Descartei:** vários cards individuais abertos (oito confirmações); gravar parcialmente o que passa (estado que o usuário não revisou); escolher o horário por linha no card (fica para depois: a linha em conflito vem bloqueada e se remarca à parte).
- **Fora do lote:** cirurgia e internação (CRMV + senha, um a um).
- **Testes:** só `RemarcarEmLoteTest` e `CancelarEmLoteTest` (40/40). Sem suíte completa e sem passadas do reviewer (economia de uso até o reset de sábado); pendentes antes de enviar para a main.
