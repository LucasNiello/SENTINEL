---
description: Gera migration + model + service + Form Request + seeder + teste básico para uma entidade nova, no padrão exato das 5 entidades já existentes do Sentinel (Cliente, Fornecedor, Funcionario, NotaFiscal, CategoriaLancamento).
---

Entidade a criar: $ARGUMENTS

Se nenhum nome de entidade foi passado, pergunte antes de continuar.

Passos:
1. Leia como referência de padrão os arquivos de uma entidade existente
   (ex.: Cliente): migration, model, service, Form Request, seeder e o teste
   correspondente em tests/Feature/GovernancaTest.php.
2. Se os campos da nova entidade não foram descritos, pergunte quais campos
   ela precisa antes de gerar qualquer arquivo — não invente schema.
3. Gere, no mesmo padrão (nomes em português, tabela snake_case, model
   PascalCase, $table explícito quando o Eloquent não adivinhar certo):
   - Migration
   - Model
   - Service (com o mesmo conjunto de métodos de governança: soft delete com
     bloqueio por dependência, restaurar, arquivar)
   - Form Request de validação
   - Seeder de teste
   - Teste básico cobrindo criar/ler e o bloqueio por dependência
4. NÃO registre tool nenhuma no AiAgentService — isso é trabalho do comando
   /tool-forge, não deste.
5. NÃO rode migrate nem seed automaticamente sem eu confirmar — só gere os
   arquivos e me diga o que rodar.
6. NÃO rode nenhum comando git de escrita.
7. Ao final, rode `php artisan test` só nos testes novos e reporte o resultado.
