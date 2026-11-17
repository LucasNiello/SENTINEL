# RETOMADA — ajustes da agenda da clínica (15/11, noite)

**FECHADA.** Nada pela metade.

Relatório: `automacao/relatorio-2026-11-15-agenda-ajustes.md`. Decisões: `.claude/decisoes/2026-11-15.md` (seção "Ajustes da agenda").
Chamadas reais acumuladas: **314** (esta tarefa: 9). Última suíte completa: 996/996 (antes do commit `4968e83`); nesta tarefa, só os arquivos tocados: 691/691.

## Resumo
- Clínica aceita até 4 agendamentos simultâneos do mesmo profissional (o 5º é recusado, na transação com leitura travada); petshop mantém 1. Card avisa "já há N".
- `remarcar_agendamento` edita o existente (só o início); o fim é calculado pelo servidor; atendente não acha horário da clínica (remarcar e mudar status).
- Selects com o nome primeiro; "Falta preencher" some ao preencher.
- Reviewer (Opus), 1 passada: sem bloqueador; importantes e cosméticos aplicados; 17 sabotagens derrubaram.

## Para o Lucas
- Remarcar não confere a `versao` (duas pessoas ao mesmo tempo: a segunda vence).
- Rodar a suíte completa na renovação do limite; commits sugeridos no relatório.
