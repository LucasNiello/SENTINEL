# Leva atual (13/11/2026, branch automacao-na-madruga, base 5a51b20)

Sessão automática de 13/11 (00:32 às 06:00, pc-talita, PHP 8.4.27). Registro detalhado: `automacao/decisoes-13-11.md`.
(O quadro de 12/11 não começou nenhum item; ver `automacao/relatorio.md`.)

| Ordem | Item | Critério de pronto | Status |
|---|---|---|---|
| 0 | Etapa 0: PHP 8.4 + branch | php 8.4, extensões, composer install, branch no GitHub | feito |
| 1 | Etapa 1: base verde | suíte completa 757/757 | feito |
| 2 | Adaptador da Anthropic (AiAgentService) | Plan opus; testes Http::fake + sabotagem; reviewer opus; suíte verde | feito (794/794) |
| 3 | Bateria Sonnet × Haiku × Azure | 2 CSVs; tabela; decisão do modelo | feito (Haiku 5.5) |
| 4 | A. Catálogo: `exige_receita`, `controla_estoque` e `cobranca_por` obrigatórios na criação também no service (sem valor padrão) | teste novo por campo; suíte verde; reviewer sonnet | feito |
| 5 | B. Trocar o tenant de um usuário zera supervisor e PIN | teste + sabotagem; reviewer opus | feito |
| 6 | C. Lixeira: só o dono restaura, inclusive pelo chat | teste + sabotagem; suíte completa; reviewer opus | feito (810/810) |
| 7 | G. P-C3: prompt do agente por domínio (ContextoUsuario::dominio()) | Plan opus; teste do prompt por domínio; reviewer sonnet | feito |
| 8 | P-C1: memória da última lista ("edite o segundo") | Plan opus; ids congelados no servidor; sabotagem; suíte completa; reviewer opus | feito (continuações 3/4 com Haiku) |
| 9 | F. P-C4: tools de agendar e de encaixe | Plan opus; RBAC, Revisar/segurar, auditoria; reviewer sonnet | feito |
| 10 | H. Estorno no card com PIN (só back + JSON; tela vira nota para o Matheus) | teste + sabotagem; reviewer opus | em andamento: **código pronto, EstornoNoCardTest 6/6, sabotagens H1-H4 provadas (ver decisoes-13-11.md, entrada 01:35). FALTA: reviewer opus (1 passada) → corrigir → suíte completa → marcar feito. Nota para o Matheus: no card do estorno, o campo "senha_admin" é o PIN do supervisor ou a senha do dono (rótulo da tela).** |
| 11 | I. Endpoints do supervisor e do PIN | teste + sabotagem; reviewer opus | pendente |
| 12 | D. D6 | sem definição no sentinel_clinica_vet.md: estacionar | estacionado (sem definição) |
| 13 | E. D2: frase proposta em pendencias-lucas.md | frase registrada | estacionado (D2 sem definição; ver pendências nº 3) |
| — | J. Comprovação (D4, D5, venda avulsa, M5-M7, D7, senha do root) | fora da lista desta noite | não iniciado |
