---
description: Executa o próximo item livre da leva em automacao/leva-atual.md (implementar, testar, revisar, corrigir, registrar)
---

<!-- Destino previsto: .claude/commands/leva.md. A escrita lá foi negada na sessão de 12/11
     (modo não interativo). Para ativar o /leva, copie este arquivo para .claude/commands/leva.md. -->

# /leva — próximo item da leva

1. Leia `automacao/leva-atual.md`, `automacao/decisoes.md` e `automacao/pendencias-lucas.md`.
2. Pegue o primeiro item com status `pendente` cujas dependências estejam `feito`. Se nenhum, pare e diga isso.
3. Marque o item como `em andamento` em `automacao/leva-atual.md` antes de mexer em código.
4. Leia os arquivos que o item toca (nunca edite com base só no resumo de um subagente). Use as skills
   `sentinel-backend` e `sentinel-testes` quando o item tocar o que elas cobrem.
5. Implemente a mudança cirúrgica, com teste novo. Nas proteções (B, C, H, I): prova por sabotagem
   (quebrar a proteção, ver o teste falhar, restaurar), um parágrafo por prova em `automacao/decisoes.md`.
6. Testes pelo subagente `test-runner` (haiku): durante o item, só o arquivo afetado (`--filter`), mostrando
   só as falhas; suíte completa ao fim do item C e antes do relatório.
7. Revisão pelo subagente `reviewer`: opus para B, C, H, I; sonnet para A, D, F, G (1 passada por item).
8. Corrija o que o reviewer apontar, rode de novo o teste filtrado.
9. Registre em `automacao/decisoes.md`: hora, item, decisão e porquê, alternativas descartadas, arquivos
   alterados, testes novos, linha final do test-runner, resultado do reviewer, mensagem de commit sugerida.
10. Atualize o status (`feito` só com suíte verde; senão desfaça à mão e marque `estacionado`, anotando
    em `automacao/pendencias-lucas.md`) e siga para o próximo item.

Nunca rode git de escrita. Não edite `resources/`, `documentacao/`, `instalar.bat`, `CLAUDE.md`, skills nem
`settings.json`. Não mude o contrato do JSON do agente: estacione.
