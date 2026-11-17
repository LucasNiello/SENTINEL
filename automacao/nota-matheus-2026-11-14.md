# Para o Matheus — o que mudou nas telas (14/11/2026)

Três telas suas mudaram. Nada está commitado ainda; o Lucas revisa antes. Capturas em 360 px (antes e depois) em `automacao/capturas-pwa/`.

## 1. Supervisão (`/supervisao`, `resources/views/telas/supervisao.blade.php`)

- **Quem abre:** só o dono e o Administrador da clínica. O supervisor não abre mais: o PIN dele agora vem do Administrador.
- **Dono:** continua marcando o setor de cada pessoa. Saiu o botão "Zerar PIN".
- **Administrador (conta nova, `admin@clinica.sentinel.local`):**
  - a lista mostra só o dono e os supervisores;
  - cada linha tem "Gerar novo PIN" (pede a senha de login do Administrador) e "Revogar PIN" (só aparece quando a pessoa tem PIN);
  - o código de 6 dígitos aparece uma vez, num bloco com borda de aviso, até clicar em "Já anotei".
- **Saiu:** o formulário "Meu PIN" do supervisor e a rota `POST /supervisao/meu-pin`.
- **Rotas novas:** `POST /supervisao/pins/{id}/gerar` (corpo: `senha_atual`) e `POST /supervisao/pins/{id}/revogar`.
- **No celular:** a tabela vira um bloco por pessoa. As ações ficavam fora da tela, à direita.

## 2. Card de estorno (`resources/views/partials/bancada.blade.php`)

- **Campos:** "Escolha do que estornar" (tudo / tudo menos o prontuário / só os itens) e "Motivo".
- **Editor de itens (novo, abaixo dos campos):** uma linha por item da cobrança ou da venda, com preço, quanto foi lançado, quanto já foi estornado e o campo "Estornar agora".
  - Digitar uma quantidade muda o modo para "só os itens".
  - Trocar para "tudo" preenche o que resta.
  - No celular, cada linha vira um bloco.
- **Resumo:** cada item que vai ser estornado, com o valor, mais "Valor do estorno", "Total pago", "Já estornado antes" e "Líquido depois do estorno". Depois do Revisar, o servidor manda o card refeito com o que foi revisado: é esse valor que o Confirmar grava.
- **Autorização:** e-mail e PIN do supervisor do setor ou do dono. A senha do dono não vale mais, e os textos do card mudaram para "PIN de quem autoriza".
- **Confirmar:** continua segurar 2 s. Testei no Edge com toque emulado: o toque curto não confirma, o de 2 s confirma.
- **O editor monta tudo com `textContent`.** O formulário manda `linhas: [{item_id, quantidade}]`, nunca valor.
- **Card novo, `estornar_venda`:** é o mesmo card, para venda paga.

## 3. Celular (360 px) e app instalável

- **CSS novo, fora do Vite** (não há `node` na máquina para recompilar): `public/css/sentinel-extra.css` (bancada) e `public/css/sentinel-login-extra.css` (login). Os dois são ligados depois do `@vite`, só têm tokens e só valem até 599 px.
  - Quando houver build, dá para levar as regras para o `workspace.css` e o `login.css`.
- **Regras:**
  - corpo e rótulos de campo em 15 px (16 px nos campos de digitar);
  - rótulos em caixa alta com no mínimo 12 px;
  - área de toque de pelo menos 44 px.
- **App instalável:**
  - `public/manifest.webmanifest`, ícones em `public/icons/` e `public/sw.js`;
  - o `sw.js` só guarda arquivos estáticos;
  - o layout e o login registram o service worker.

## Ficou ruim e não mexi

- Na `/agente` da clínica, as sugestões de abertura ainda são do domínio antigo ("Liste meus clientes", "Lançamentos pendentes").
- O placeholder do chat fica cortado em 360 px ("Pergunte ou descreva uma açã…").
