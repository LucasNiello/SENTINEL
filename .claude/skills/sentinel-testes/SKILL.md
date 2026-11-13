---
name: sentinel-testes
description: Use ao escrever ou rodar testes do Sentinel (PHPUnit/Feature): governança, RBAC, tenant, auditoria, fluxo do /agente e validação por Form Request.
---

# PAPEL + CONTEXTO
Testes do Sentinel. A suíte roda com `php artisan test` (PHPUnit) em SQLite na
memória (`phpunit.xml`: `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`) —
nenhum teste toca o MySQL de desenvolvimento, e a API da Anthropic é sempre
simulada com `Http::fake`. O RNF09 exige cobertura automatizada de RBAC,
validação e bloqueio por dependência.

# ONDE FICAM
A lista de arquivos cresce a cada bloco; descubra por pasta e padrão de nome
(`Glob` em `tests/**/*Test.php`), não por uma lista fixa:
- `tests/Feature/*Test.php` — domínio contábil e transversais (agente,
  governança/RBAC, tenant, auditoria e máscara LGPD, autenticação, lixeira,
  exportação, tools por entidade). Um arquivo por assunto, nome descritivo em
  PascalCase com sufixo `Test`.
- `tests/Feature/Clinica/*Test.php` — domínio da clínica/petshop (serviços como
  agenda, cobrança, venda, prontuário, estoque; tools da clínica; RBAC por
  papel; isolamento de tenant; construtores de tool). Mesmo padrão de nome.
- `tests/Unit/*Test.php` — poucos testes sem banco nem fluxo HTTP.
- `tests/Concerns/` — apoio reutilizável, sem sufixo `Test`: `SimulaAgente`
  (IA simulada e os passos `comando()`, `propor()`, `confirmar()`),
  `CenarioClinica` (dados-base da clínica) e `ProvaDeTravas` (prova que a
  leitura usa trava), mais a classe auxiliar da gramática SQLite que mostra
  a trava.
- `logarComo($papel, $tenantId)` em `tests/TestCase.php`.

Siga o padrão do arquivo mais parecido com o que você vai testar, na pasta do
domínio dele.

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
