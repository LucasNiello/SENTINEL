# SENTINEL

## Ambiente e testes

Use PHP 8.4.27 ou superior em CLI, Composer, Artisan e servidor HTTP. O lock de
dependências exige PHP 8.4.1, mas a homologação usa o mínimo 8.4.27. No Apache com
mod_php, use a distribuição Thread Safe. Configure um CA bundle válido em
`curl.cainfo` e `openssl.cafile`, mantendo a verificação TLS ativa.

Execute `composer check-platform-reqs` antes de instalar e testar. A regressão é
`php artisan test --compact`: o bootstrap do PHPUnit isola os caches e força
SQLite em memória, mesmo quando o processo pai usa configuração local em cache.
O TestCase interrompe a execução antes de RefreshDatabase se detectar outro banco.

## Usuários de desenvolvimento

O seed cria um único tenant: o da clínica veterinária + petshop (tenant 3, que precisa estar
em `SENTINEL_TENANTS_CLINICA`). Defina `DEMO_SENHA` no `.env` (no mínimo 12 caracteres, fora da lista
de senhas conhecidas) e rode `php artisan migrate:fresh --seed` no ambiente `APP_ENV=local`. Todas as
contas abaixo usam essa senha, guardada só como hash. Sem `DEMO_SENHA`, o seed só avisa e não grava nada.
Não execute `migrate:fresh` em um banco que contenha dados que deseja preservar.

| E-mail | Papel |
|---|---|
| `vet1@clinica.sentinel.br` | dono (veterinário, CRMV) |
| `vet2@clinica.sentinel.br`, `vet3@clinica.sentinel.br` | veterinário (CRMV) |
| `recepcao1@` a `recepcao3@clinica.sentinel.br` | recepção |
| `estetica1@`, `estetica2@clinica.sentinel.br` | banho e tosa |
| `vendas1@`, `vendas2@clinica.sentinel.br` | vendedor (loja) |
| `administracao@clinica.sentinel.br` | admin (gera e revoga os PINs de autorização) |

O seed também cria 5 pets com seus tutores, 3 clientes só da loja, o catálogo, a agenda da semana e o
histórico do último mês. O isolamento entre tenants continua garantido pelo código e provado nos testes,
que criam os próprios tenants.

Sem o seed, `php artisan sentinel:preparar-acesso` garante o primeiro acesso: se o tenant da clínica
não tiver administrador, cria `administracao@clinica.sentinel.br` com uma senha aleatória de 24
caracteres, mostrada somente no console da criação (guarde-a naquele momento; executar de novo não
duplica, não redefine e não reexibe senhas). Uma conta existente nesse e-mail nunca é sobrescrita nem
promovida. Se a Administração foi criada assim (pelo comando ou pelo `instalar.bat`), ela mantém essa
senha aleatória: o seed rodado depois preserva a conta e não a troca pela `DEMO_SENHA`. O `instalar.bat` executa essa preparação após as migrations e o seed inicial; a saída com
credenciais não passa pelo logger do instalador e não é gravada no estado local.

Acesse http://127.0.0.1:8000/login. Após autenticar, o destino é `/agente`;
o ambiente local usa `SESSION_DRIVER=database`. Não há recuperação de senha
pela interface: se perder a senha, será necessária uma redefinição explícita
via Laravel, somente para a conta desejada, sem tentar recuperar o hash.
