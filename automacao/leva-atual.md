# Leva atual (12/11/2026, branch automatizacao-12-11, base babfb7a)

**Sessão automática de 12/11 (05:30 às 06:12): nenhum item começou.** A base não ficou verde por um
problema de AMBIENTE (o PHP do pc-talita é 8.3.30 e o vendor exige 8.4.1). Ver `automacao/relatorio.md`.
Todos os itens continuam `pendente` para retomar no lab com `/leva` (`automacao/leva.md`).

| Ordem | Item | Critério de pronto | Depende de | Status |
|---|---|---|---|---|
| 1 | A. Catálogo: `exige_receita`, `controla_estoque` e `cobranca_por` obrigatórios na criação também no service (sem valor padrão) | criar no `CatalogoService` sem um dos 3 campos recusa; teste novo por campo; suíte verde; reviewer sonnet | base verde | pendente |
| 2 | B. Trocar o tenant de um usuário zera supervisor e PIN | teste novo; prova por sabotagem; reviewer opus | base verde | pendente |
| 3 | C. Lixeira: só o dono restaura, inclusive pelo chat | tool de restaurar no chat recusa recepção (e outros não-dono); teste novo; sabotagem; suíte completa; reviewer opus | base verde | pendente |
| 4 | J. Comprovação (só leitura, Explore haiku) → `automacao/comprovacao.md` | D4, D5, venda avulsa, M5-M7, D7, senha do root: comprovado / não comprovado / evidência | nenhuma | pendente |
| 5 | D. D6, pela definição do `sentinel_clinica_vet.md` (sem definição clara: estacionar) | definição encontrada e implementada com teste, ou estacionado com motivo | base verde | pendente |
| 6 | E. D2: frase proposta para o CLAUDE.md, escrita em `pendencias-lucas.md` (sem editar o CLAUDE.md) | frase registrada | nenhuma | pendente |
| 7 | F. P-C4: tools de agendar e de encaixe | Plan opus antes; tools com RBAC, Revisar/segurar, auditoria; testes; reviewer sonnet | base verde | pendente |
| 8 | G. P-C3: prompt do agente sem menções à contabilidade no domínio da clínica | Plan opus antes; teste do prompt por domínio; reviewer sonnet | base verde | pendente |
| 9 | H. Estorno no card com PIN (só o back e o JSON já existente; tela vira nota para o Matheus) | teste + sabotagem; reviewer opus | base verde | pendente |
| 10 | I. Endpoints do supervisor e do PIN | teste + sabotagem; reviewer opus | B | pendente |

Observação sobre o D6: o `sentinel_clinica_vet.md` só cita "Telas 13-15/11 = C11, D4-D7" e "Telas: P-D5, P-D4,
P-D6, P-D7"; não traz o conteúdo do D6. Pela regra do prompt, sem definição clara, o item D deve ser estacionado.
