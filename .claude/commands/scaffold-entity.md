---
description: Gera migration + model + service + Form Request + seeder + teste básico para uma entidade nova, no padrão das entidades já existentes do domínio escolhido (contabilidade ou clínica).
---

Entidade a criar: $ARGUMENTS

Pergunte em qual domínio a entidade entra (contabilidade ou clínica/petshop)
se isso não estiver claro. O padrão a seguir é o das entidades existentes
desse domínio: em `app/Models` e `app/Services` (para a clínica, p. ex. Pet
ou Tutor e os services correspondentes; para a contabilidade, p. ex.
Cliente), com testes em `tests/Feature` (contabilidade) ou
`tests/Feature/Clinica` (clínica).

Siga a skill `sentinel-backend` (em especial: `int $tenantId` obrigatório em
todos os métodos do service que leem, alteram, excluem ou restauram).

Se nenhum nome de entidade foi passado, pergunte antes de continuar.

Passos:
1. Leia como referência de padrão os arquivos de uma entidade existente
   do domínio escolhido (ex.: Cliente na contabilidade, Pet ou Tutor na
   clínica): migration, model, service, Form Request, seeder e o teste
   correspondente (`tests/Feature/GovernancaTest.php` na contabilidade; na
   clínica, a pasta `tests/Feature/Clinica` com o trait `CenarioClinica`).
2. Se os campos da nova entidade não foram descritos, pergunte quais campos
   ela precisa antes de gerar qualquer arquivo — não invente schema.
3. Gere, no mesmo padrão (nomes em português, tabela snake_case, model
   PascalCase, $table explícito quando o Eloquent não adivinhar certo):
   - Migration
   - Model
   - Service (com o mesmo conjunto de métodos de governança: soft delete com
     bloqueio por dependência, restaurar, arquivar; tenant obrigatório em
     cada método, conforme a skill `sentinel-backend`)
   - Form Request de validação
   - Seeder de teste
   - Teste básico cobrindo criar/ler e o bloqueio por dependência
4. NÃO registre tool nenhuma no AiAgentService — isso é trabalho do comando
   /tool-forge, não deste.
5. Pode rodar `php artisan migrate` no banco local. NUNCA rode
   `migrate:fresh`, `migrate:reset`, `migrate:rollback` nem `db:wipe`. Não
   rode seed sem eu confirmar — me diga o que rodar.
6. NÃO rode nenhum comando git de escrita.
7. Ao final, rode `php artisan test` só nos testes novos e reporte o resultado.
