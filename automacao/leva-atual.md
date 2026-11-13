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
| 10 | H. Estorno no card com PIN (só back + JSON; tela vira nota para o Matheus) | teste + sabotagem; reviewer opus | feito (843/843; reviewer opus 0 bloqueantes; o IMPORTANTE 1, chave nova no JSON do card, ficou estacionado; ver pendências nº 4) |
| 11 | I. Endpoints do supervisor e do PIN | teste + sabotagem; reviewer opus | feito (852/852; rotas JSON `/supervisao/*`; reviewer opus 0 bloqueantes) |
| 12 | D. D6 | sem definição no sentinel_clinica_vet.md: estacionar | estacionado (sem definição) |
| 13 | E. D2: frase proposta em pendencias-lucas.md | frase registrada | estacionado (D2 sem definição; ver pendências nº 3) |
| — | J. Comprovação (D4, D5, venda avulsa, M5-M7, D7, senha do root) | fora da lista desta noite | feito (`automacao/comprovacao.md`: 5 comprovados; D4, D5, M6 e M7 sem definição; histórico do memoria-claude não comprovado, acesso negado) |

## Tarde de 13/11 (lab SENAI, 14:27 em diante, base 2cd4889, PHP 8.4.27)
| Item | Status |
|---|---|
| B0: leva.md em `.claude/commands/`; base | feito (base não rodou por `.env` inválido; ver pendências A; suíte rodou com `.env.testing` + `APP_ENV=testing`) |
| B1: pendência 2 (frase do prompt, RF04) | feito (autorizada); re-execução dos 5 pedidos PULADA, sem chave |
| B1: pendência 3 (`tipo_autorizacao: 'estorno'`) | feito (autorizada) |
| B2: PIN fora do log, contadores compartilhados, "setor clínica" | feito |
| B2: demais menores das pendências 5 e 6 | só registrados |
| B3: P-D1, P-D2, defeito 11, P-C9, P-C8, P-C6, P-C7, P-D3 | estacionados (sem definição escrita) |
| D2, D6 | estacionados (sem definição) |
Suíte final: 856/856.

## Tarde 2 de 13/11 (lab SENAI, 15:38 em diante, PHP 8.4.27)
| Item | Status |
|---|---|
| B1: exemplos de pedido incompleto no prompt (RF04), nos dois domínios | feito; bateria 14/16/18 = 3/3 (3 chamadas reais, total 131/200) |
| B2: rate limit contado antes do `Hash::check` (`/supervisao/meu-pin`); estorno já contava | feito (sabotagem + reviewer opus, 2 passadas); login só registrado |
| B3: dono não se marca supervisor | feito |
| B4: CLAUDE.md, item "Provedor de IA" | feito (1 linha) |
| 2ª leva (P-D1, P-D2, P-C6 a P-C9, P-D3, defeito 11), D2, D6 | estacionados (sem definição) |
