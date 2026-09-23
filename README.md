# SENTINEL

## Usuários de desenvolvimento

Criados pelo `php artisan db:seed` (ou `migrate:fresh --seed`). Todos usam a
senha **`sentinel123`**. É senha de desenvolvimento: o seeder se recusa a rodar
em produção.

| E-mail | Papel | Tenant |
|---|---|---|
| `admin@sentinel.local` | admin | 1 |
| `operador@sentinel.local` | operador | 1 |
| `leitura@sentinel.local` | leitura | 1 |
| `admin.t2@sentinel.local` | admin | 2 |

O tenant 2 existe para mostrar o isolamento: quem entra nele não vê os dados
do tenant 1.
