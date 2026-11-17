# Comprovação (item J), 13/11/2026 04:40 — só leitura

Feita pelo Explore (haiku); o D7 eu conferi direto no arquivo. Nenhum arquivo foi alterado.

| Ponto | Status | Evidência |
|---|---|---|
| D4 (arquivos) | **sem definição** | Nenhuma no SENTINEL. Só aparece em `sentinel_clinica_vet.md:120`, na lista "Telas 13-15/11 = C11, D4-D7". O "D4" do `wellbook/CLAUDE.md:199` é de outro projeto. Busca em `automacao/`, `documentacao/`, `.claude/` e nos .md da raiz. |
| D5 (arquivos) | **sem definição** | Idem ao D4 (o `wellbook/CLAUDE.md:200` é de outro projeto). |
| Venda avulsa (`agendamento_id`/`pet_id` opcionais, do mesmo tenant) | **comprovado** | Migration `2026_11_10_100001_add_agendamento_e_pet_to_vendas_table.php:16-17`. `VendaService.php`: papéis `:37,52`; tenant `:130,138,147`; coerência agendamento × pet `:132-143`; item do tenant e `exige_receita` `:205-217`. Testes em `tests/Feature/Clinica/VendaServiceTest.php`: outro tenant `:235-240`; `exige_receita` `:83-93`; papéis por setor `:97-135`; agendamento/pet `:137-162`. |
| M5 (regeneração da sessão no login) | **comprovado** (o teste existe e protege) | Definição em `memoria-claude/sentinel_revisao_lotes.md:29`. Teste em `tests/Feature/AutenticacaoTest.php:55-66`: o id da sessão muda com o login, e há controle sem login. A prova por sabotagem não foi refeita nesta sessão. |
| M6 | **sem definição** (só um rótulo no teste) | `tests/Feature/AgenteTest.php:128-145` (rótulo na linha 130): tool fora do catálogo dá 422, nenhum `Lancamento` gravado e auditoria `negado`. O que o comentário promete está protegido. |
| M7 | **sem definição** (só um rótulo no teste) | `tests/Feature/GovernancaTest.php:137-151` (rótulo na linha 143): `ProhibitedIf` em `cliente_id` numa entrada; `fornecedor_id` não falha. O que o comentário promete está protegido. |
| D7 (`.env.example`) | **comprovado** | `.env.example:31-35`: "MySQL local (Laragon) no TCC. pgsql e Supabase estão fora de escopo… Nuvem só se o projeto virar produto". A frase "Destino final: Supabase Postgres" não aparece em nenhum arquivo do SENTINEL. |
| Senha do root: arquivo da memória | **comprovado** | `memoria-claude/mysql-root-reset-0511.md:11,15` diz só onde a senha está (`SENTINEL/.env`, `DB_PASSWORD`), sem o valor. |
| Senha do root: histórico do git do `memoria-claude` | **não comprovado** (acesso negado) | O `git log -p` fora do SENTINEL pede aprovação e não rodou. `sentinel_clinica_vet.md:110` registra que os valores antigos continuam no histórico e que você decidiu não reescrevê-lo. Falta a troca da senha. |
| Segredos em arquivos versionados do SENTINEL | **comprovado** | `.gitignore:3-5` ignora `.env` e `.env.*`, menos o `.env.example`. No `.env.example`, `DB_PASSWORD` (`:29`), `AZURE_FOUNDRY_API_KEY` (`:89`) e `ANTHROPIC_API_KEY` (`:94`) estão vazios. A busca não achou nenhum valor. |

Observações:
- `sentinel_clinica_vet.md:113` ("venda avulsa ainda não implementada") e `:114` ("Destino final: Supabase") estão desatualizados: o código e o `.env.example` já mudaram. Quem edita a memória é você.
- Para fechar o D4, o D5, o M6 e o M7, falta uma linha sua dizendo o que cada um é.
