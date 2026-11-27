# Plano (26/11) — Bloqueios de agenda (horário de funcionamento sob controle do dono e da Administração)

Pedido do Lucas (26/11): o dono e a ADM precisam controlar o funcionamento da clínica: (1) fechar um dia inteiro; (2) bloquear um horário para um funcionário (ex.: o almoço do vet2); (3) bloquear o dia N das 12h às 15h. Hoje só existe o expediente fixo em `config/sentinel.php` (+ o painel de vagas).

## Modelo
Tabela `bloqueios_agenda` (por tenant): `profissional_id` (null = todos), `setor` (null = clínica e petshop), `data_inicio`/`data_fim` (dia ou período), `hora_inicio`/`hora_fim` (null = dia inteiro), `dias_da_semana` (null = pontual; lista ISO = recorrente, ex.: almoço todo dia), `motivo` (texto curto, sem dado pessoal), `criado_por`, `ativo`, timestamps. Sem FK de tenant (como as outras tabelas; pendência já conhecida).

## Como entra na grade (um ponto só)
`AgendaService::ocupados()` passa a devolver também o bloqueio como **ocupação exclusiva** do(s) profissional(is) atingido(s) (com o motivo). Assim agendar, remarcar, o calendário, o "qualquer profissional" e o plano de remarcação em lote respeitam o bloqueio sem mudar cada regra. A frase de recusa diz o motivo ("Bloqueado: almoço").

## Quem pode
Dono e Administração da clínica. **Conflito com decisão documentada (14/11 A–F): o Admin "só gerencia PINs: nenhuma tool, sem auditoria".** O Lucas pediu agora que a ADM controle os horários: autorizado por ele em 26/11; registrar em `.claude/decisoes/` e atualizar o CLAUDE.md (texto sugerido, sem editar). O admin passa a ter UMA permissão nova (bloqueios), sempre com auditoria; continua sem tools do agente, sem lixeira e sem estorno.

## O que acontece com o que já está marcado dentro do bloqueio
Nunca é cancelado sozinho. Ao criar o bloqueio, a tela mostra "N agendamentos caem aqui" e oferece **Remarcar estes** (abre o plano do lote de remarcação: mesmo horário em outro dia, ou próximo horário livre). O bloqueio vale mesmo que sobrem pendentes; eles ficam sinalizados na agenda.

## Passos (cada um entrega algo)
1. Migration + model + `BloqueioAgendaService` (criar, listar, desativar; valida período, horas, passado, teto de 200 ativos) + auditoria.
2. Integração em `ocupados()` + motivo na recusa; testes (dia inteiro, faixa de horas, almoço recorrente de um profissional, "qualquer profissional" pula o bloqueado, remarcação em lote respeita).
3. Tela no painel da Clínica (aba "Horários"): lista, criar (dia inteiro / faixa / recorrente), desativar; lista dos afetados com "Remarcar estes". Papéis: dono e admin.
4. Opcional: tool do agente para o dono ("bloqueie dia 27", Revisar → Confirmar, mostra os afetados). O admin continua sem agente.
5. `reviewer` (2 passadas: permissão, tenant, auditoria) + suíte completa.

## Fora do escopo (agora)
Feriados automáticos, bloqueio por serviço, bloqueio que cancela sozinho, calendário anual de fechamentos, notificação automática dos tutores afetados (o aviso sai pela remarcação).

## Ordem e prazo
Código congela sexta (27/11); banca 30/11. Ordem sugerida: (a) fechar o lote de remarcação (em andamento: falta endpoint, tela e testes); (b) bloqueios, passos 1 a 3; (c) abas da ficha só se sobrar tempo. Estimativa dos bloqueios: 4 a 6 h com testes e revisão. **Decisão pendente do Lucas:** manter o congelamento de sexta ou estender para o fim de semana.
