# RETOMADA — bateria da clínica com a IA real (15/11): FECHADA

Teto: 150 chamadas. **Usadas: 125** (R1 44 + C1 18 + C2 3 + R2 44 + paráfrases 16). **Acumulado: 169 → 294.**
Decisões: `.claude/decisoes/2026-11-15.md`. Relatório: `automacao/relatorio-2026-11-15-bateria-clinica.md`.

## Resultado
- 1ª rodada 28/44 → 2ª rodada **44/44** (mesmos pedidos) → paráfrases novas **15/16**.
- Suíte **993/993** (7851 asserções). Reviewer: 0 bloqueadores; os 4 importantes e 3 dos 4 cosméticos foram feitos.

## Nada pela metade.

## Para o Lucas
1. Auditar as decisões de 15/11, principalmente:
   - o número gravado no histórico da clínica;
   - a lista do catálogo na descrição da venda;
   - "(hold-to-confirm)" mantido só na contabilidade, por causa do teste de hash.
2. No TCC, citar as paráfrases (15/16) como a taxa em pedidos novos; os 44/44 foram medidos nos pedidos que guiaram o ajuste.
3. Commits sugeridos: seção "Commits sugeridos" do relatório.
4. Pontos fracos que ficaram (relatório, "O que ainda é fraco"):
   - estorno parcial sem `item_id`;
   - "nao" presumido no item do catálogo;
   - conflito de agenda só depois de escolher no card.

## Como rodar a bateria de novo (variáveis só no processo)
`. <scratchpad>/env-bateria.sh` e depois
`php artisan sentinel:bateria --csv=documentacao/testes/fase8/BATERIA_CLINICA.csv --tenant=3 --limite=N --saida=...`
Antes, recriar o `database/bateria.sqlite`: `migrate:fresh`, `db:seed`, `DemonstracaoSeeder` e `ClinicaSeeder`.
