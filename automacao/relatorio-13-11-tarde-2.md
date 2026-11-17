# Relatório da tarde 2 de 13/11/2026 (lab SENAI, 15:38 a ~15:50)

Branch `automacao-na-madruga`, PHP 8.4.27. Registro: seção "Tarde 2" de `.claude/decisoes/2026-11-13.md`. `test-runner` e `reviewer` do projeto não estão registrados nesta sessão: usei subagentes genéricos (haiku para testes, opus para revisão).

## Por bloco
- **B1:** 3 exemplos curtos de pedido incompleto no system principal, nos dois domínios ("Nunca responda esses pedidos só com uma pergunta em texto"). Teste novo (2 casos); hash do principal da contabilidade regravado. **Bateria 14/16/18: 3/3** (criar_fornecedor, criar_lancamento, criar_nota_fiscal, todos `confirmacao_pendente`).
- **B2:** `definirMeuPin` conta a tentativa (`RateLimiter::hit`) antes do `Hash::check`; acima de 5 dá 429 no mesmo contrato, e acerto ou barrada devolvem a tentativa. `throttle:pin` e o limiter `pin` saíram; a rota ganhou `throttle:agente-sem-ia`. O estorno já contava antes de conferir (nada a mudar). Login intocado, só registrado. 2 testes novos; sabotagem (`hit` → `attempts`) derrubou 2 testes. Reviewer opus, 2 passadas: 0 crítico.
- **B3:** `marcarSupervisor` recusa marcar `dono` (422 `AI_BUSINESS_RULE`, auditado `recusado`); desmarcar continua. 1 teste novo.
- **B4:** CLAUDE.md, só o item "Provedor de IA" (`git diff CLAUDE.md`: 1 linha). Sem conflito com outra regra.
- **B5:** feito antes das 16:15, porque não restava bloco. `leva-atual.md` e `pendencias-lucas.md` atualizados.

## Suíte final: 861/861 (6548 asserções; eram 856)
## Chamadas reais à API: 3 nesta tarde (total 131/200)

## Testes EXISTENTES alterados
1. `PromptPorDominioTest`: hash do principal da contabilidade regravado (texto mudou de propósito no B1).
2. `SupervisaoEndpointsTest::test_tentativas_do_pin_tem_teto`: uma asserção a mais (pedido barrado devolve a tentativa).
Novos: `test_prompt_traz_exemplos...` (2 casos), `test_tentativa_do_pin_e_contada_antes...`, `test_acerto_devolve_a_tentativa_do_pin`, `test_dono_nao_pode_ser_marcado_supervisor...`.

## Autorrevisão
`git status` e `git diff --stat` conferem: CLAUDE.md, `AiAgentService`, `CobrancaService`, `SupervisaoController`, `SupervisaoService`, `AppServiceProvider`, `routes/web.php`, 5 testes, 2 arquivos de `automacao/` (mais os novos). Busca de `sk-ant` no diff: 0; nenhum segredo (o único acerto do filtro é texto de pendência sobre o `.env`). Nada em `resources/`, `instalar.bat`, `.claude/skills`, `.claude/settings.json`, `.env`; sem pacote; nenhum git de escrita.

## Para você decidir
1. Revisar o reforço do prompt e a troca de throttle (`throttle:agente-sem-ia` na rota do PIN, dividindo o balde de 30/min com as outras rotas de supervisão e o card).
2. O login tem a mesma fraqueza (conta depois de conferir); registrado nas pendências.
3. Conferir Documentação 1.2 e Notion quanto ao provedor de IA.
4. Os pedidos 12 e 17 não foram reexecutados (limite de 3); definir os 8 itens da 2ª leva, D2 e D6.

## Commits sugeridos (só texto)
- `fix(agente): exemplos de pedido incompleto no prompt, nos dois dominios (RF04)`
- `fix(clinica): tentativa do PIN contada antes de conferir a senha`
- `fix(clinica): dono nao pode ser marcado supervisor`
- `docs: CLAUDE.md com o provedor de IA atual (Anthropic); relatorio da tarde 2`
