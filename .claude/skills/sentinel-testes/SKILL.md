---
name: sentinel-testes
description: Use ao escrever ou rodar testes do Sentinel (PHPUnit/Feature): governança, RBAC, tenant, auditoria, fluxo do /agente e validação por Form Request.
---

# PAPEL + CONTEXTO
Testes do Sentinel. A suíte roda com `php artisan test` (PHPUnit) em SQLite na
memória (`phpunit.xml`: `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`) —
nenhum teste toca o MySQL de desenvolvimento, e a API do Foundry é sempre
simulada com `Http::fake`. O RNF09 exige cobertura automatizada de RBAC,
validação e bloqueio por dependência.

# ONDE FICAM
Arquivos que existem hoje em `tests/Feature/`:
- `AgenteTest.php` — fluxo do /agente: comando, confirmação (espera, validade, uso único), falha do Foundry.
- `AgenteGovernancaTest.php` — auditoria (RF06/RF07), RBAC, tenant fixado no servidor, whitelist de tools.
- `AtualizarStatusLancamentoTest.php` — tool `atualizar_status_lancamento`.
- `AuditoriaMascaraTest.php` — máscara LGPD (CPF, salário, CPF no documento) em `audit_logs` e no log de arquivo.
- `AutenticacaoTest.php` — login/logout, throttle, `ContextoUsuario`, reautenticação de admin.
- `CategoriaLancamentoToolsTest.php`, `ClienteFornecedorToolsTest.php`, `CriarLancamentoToolTest.php`, `FuncionarioNotaFiscalToolsTest.php` — tools por entidade (consulta, criação, validação, RBAC).
- `GovernancaTest.php` — RF08-RF12 nos services e validação de Form Request.
- `ExampleTest.php` — exemplo padrão do Laravel.

Apoio: `tests/Concerns/SimulaAgente.php` (Foundry simulado e os passos
`comando()`, `propor()`, `confirmar()`) e `logarComo($papel, $tenantId)` em
`tests/TestCase.php`.

Siga o padrão do arquivo mais parecido com o que você vai testar.

# PERMISSÕES
| Ação | Permitido |
|---|:---:|
| Criar/editar arquivos em `tests/` | Sim |
| Rodar `php artisan test`, `phpunit` | Sim |
| Editar código de produção (`app/`) para fazer um teste passar | Não — reporte a falha, quem decide se é bug ou teste errado sou eu |
| Usar banco real de desenvolvimento sem transação/rollback | Não — todo teste roda isolado (RefreshDatabase ou transação) |
| `git add`/`commit`/`push` | Não, nunca |

# O QUE COBRIR SEMPRE QUE APLICÁVEL
- RBAC mínimo por tool (papel abaixo do mínimo → negado, e a tentativa é auditada).
- Bloqueio por dependência (RF08) e mensagem amigável (não vazar termos técnicos
  tipo nome de relação Eloquent).
- Ciclo soft delete → lixeira → arquivado (RF09-RF12).
- Confirmação no fluxo /agente (RNF02), conferida no servidor:
  - espera mínima (antes de 2 s → 422);
  - validade (depois de 12 s → 422);
  - token usado uma única vez, inclusive com duas confirmações simultâneas
    (consumo atômico — vale a partir do Lote 2);
  - token malformado → 422 (vale a partir do Lote 2).
- Isolamento por tenant (vale a partir do Lote 2): todo método de service ou
  tool tem teste mostrando que o registro de outro tenant dá
  `ModelNotFoundException` (no service; na tool, é recusado como inexistente)
  e fica intacto.
- Auditoria (RF06/RF07): o teste confere que gravou o registro certo E que o
  dado sensível (CPF, salário, CPF no documento) está mascarado — não só que
  não deu erro.

# PROVA DE SABOTAGEM (testes de segurança)
Para teste de segurança (RBAC, tenant, confirmação, máscara, validação):
retire a proteção temporariamente, rode e veja o teste falhar; desfaça e
mostre o `git diff` limpo do arquivo sabotado. Teste que continua verde sem a
proteção não protege nada.

# NÃO FAÇA
- Não marque um requisito como "coberto" só porque um teste rodou sem erro —
  confirme que o teste de fato exercitou o comportamento do requisito.
- Não edite `app/` para fazer um teste passar (ver Permissões).
- Não rode comandos git de escrita.

# GIT
Commits são responsabilidade exclusiva do Lucas. Nunca execute git add, commit,
push ou qualquer comando que altere histórico. Pode sugerir mensagem de commit
apenas como texto.
