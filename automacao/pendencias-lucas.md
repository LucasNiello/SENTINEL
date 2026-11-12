# Pendências para o Lucas (12/11/2026), da mais para a menos importante

## 1. PHP do pc-talita (8.3.30) não roda o vendor/lock do SENTINEL (exige 8.4.1) — BLOQUEOU A SESSÃO
- **Problema:** o `composer.lock` tem Symfony 8 (PHP >= 8.4). O `composer.json` declara `"php": "^8.3"`,
  e isso engana: o lock não instala no 8.3. Por isso `composer install` deu exit 2 no script e a suíte não rodou.
- **Opções:**
  - (a) Instalar o PHP 8.4 (>= 8.4.27, como no lab) no Laragon do pc-talita e apontar o script para ele. O lock fica intocado.
  - (b) Rodar a automação só no lab, que já tem o 8.4.
  - (c) Rebaixar as dependências para caber no 8.3 (`composer update` com `platform.php`). Isso muda o lock
    e diverge do lab.
- **Recomendação:** (a). Ajustar também o `composer.json` para `"php": "^8.4"`, para que a exigência fique
  explícita (decisão sua, porque toca dependência).
- Detalhe do script: o log anotou `composer exit 2` e seguiu adiante. Vale abortar a preparação nesse caso.

## 2. Itens A–J da leva não começaram
Estão todos `pendente` em `automacao/leva-atual.md`. Retome no lab com `/leva` (primeiro copie
`automacao/leva.md` para `.claude/commands/leva.md`, porque a escrita lá foi negada nesta sessão).

## 3. Item D (D6) sem definição
O `sentinel_clinica_vet.md` só cita o D6 em listas ("Telas 13-15/11 = C11, D4-D7"), sem dizer o que ele é.
- **Opções:** (a) você define o D6 antes da próxima leva; (b) o `/leva` estaciona o item.
- **Recomendação:** (a). Senão, o item será estacionado de novo.

## 4. Permissão para `.claude/commands/`
A escrita em `.claude/commands/leva.md` é negada no modo não interativo.
- **Opções:** (a) liberar `Write(.claude/commands/leva.md)` no `settings.json`; (b) copiar à mão.
- **Recomendação:** (b), uma vez só. Não é preciso abrir permissão permanente.
