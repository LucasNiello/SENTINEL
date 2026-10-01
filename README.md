# SENTINEL

## Usuários de desenvolvimento

No ambiente `APP_ENV=local`, execute `php artisan sentinel:preparar-acesso`
para preparar o primeiro acesso após as migrations. Se já houver um administrador
com tenant, ele será preservado. Caso contrário, o comando cria
`admin@sentinel.local`, com papel `admin` e tenant 1, desde que o e-mail esteja livre.
Uma conta existente nesse e-mail nunca será sobrescrita ou promovida automaticamente.

Cada conta nova recebe uma senha aleatória de 24 caracteres, armazenada com
`Hash::make` e mostrada somente no console da criação. Guarde-a naquele momento:
executar novamente não duplica usuários, não redefine e não reexibe senhas.
Não redirecione essa saída para logs ou arquivos versionados.

O `instalar.bat` executa essa preparação após as migrations e o seed inicial,
inclusive em bancos já instalados. A saída com credenciais não passa pelo logger
do instalador e não é gravada no estado local. Se o seed falhar, a transação é
revertida e nenhuma credencial dessa tentativa é exibida.

Acesse http://127.0.0.1:8000/login. Após autenticar, o destino é `/agente`;
o ambiente local usa `SESSION_DRIVER=database`. Não há recuperação de senha
pela interface: se perder a senha, será necessária uma redefinição explícita
via Laravel, somente para a conta desejada, sem tentar recuperar o hash.

O `php artisan db:seed` também prepara os quatro perfis abaixo, preservando
usuários existentes e gerando senhas diferentes para novos usuários. O seeder
é exclusivo do ambiente local. Os seeders de dados de demonstração continuam
destinados ao banco inicial vazio; não execute `migrate:fresh` em um banco que
contenha dados que deseja preservar.

| E-mail | Papel | Tenant |
|---|---|---|
| `admin@sentinel.local` | admin | 1 |
| `operador@sentinel.local` | operador | 1 |
| `leitura@sentinel.local` | leitura | 1 |
| `admin.t2@sentinel.local` | admin | 2 |

O tenant 2 existe para mostrar o isolamento: quem entra nele não vê os dados
do tenant 1.
