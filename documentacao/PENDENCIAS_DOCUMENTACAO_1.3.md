# Pendências para a Documentação 1.3

Mudanças já feitas no código que a Documentação 1.2 (`Documentacao-Sentinel1.2.docx`) ainda descreve do jeito antigo. O `.docx` não foi editado; este arquivo lista o que precisa entrar na 1.3.

## 1. Confirmação das escritas do agente: sem janela de tempo no servidor (decisão do Lucas, 13/11/2026, opção B)

- **Antes (1.2):** toda escrita pedia segurar o botão por 2 s, e o servidor só aceitava a confirmação entre 2 e 12 s depois do Revisar.
- **Agora:**
  - o servidor não tem espera mínima nem validade de 12 s; o token de confirmação vale até o fim dos 5 min da proposta;
  - segurar 2 s virou só gesto visual, nas ações destrutivas: excluir (para a lixeira e de vez), cancelar cobrança, estornar, conciliação em lote e cancelar agendamento;
  - as demais escritas confirmam com um clique.
- **Motivo:** no domínio da clínica, um erro fora do prontuário pode ser corrigido sem consequência.
- **Continua igual:**
  - rascunho na sessão (5 min) e Revisar validando sem gravar;
  - token de uso único e execução só com os valores revisados da sessão;
  - RBAC, tenant do servidor, PIN/senha do estorno e da lixeira, auditoria e o lote com `lockForUpdate`;
  - o prontuário não muda, e o agente continua sem escrever conteúdo clínico.
- **Onde atualizar na 1.3:** RNF02 (confirmação humana), a descrição do fluxo Revisar → segurar → Confirmar e a seção de interface (sinalização de risco e segurar).
- **Também desatualizado:** o prompt do agente ainda cita "(hold-to-confirm)". Não mexi no prompt porque não houve chamada real para medir o efeito. (As skills do repositório e o checklist do `reviewer` já foram atualizados em 13/11.)

## 2. PIN de autorização gerado pelo Administrador (decisões A, B e F do Lucas, 14/11/2026)

- **Antes:** o supervisor definia o próprio PIN ("Meu PIN", com a senha de login); o dono zerava o PIN e autorizava estorno com a senha de login.
- **Agora:**
  - papel `admin` no tenant da clínica (conta "Administrador da clínica" no `ClinicaSeeder`), que só gera e revoga PINs na tela Supervisão; não vende, não cobra, não autoriza estorno, não vê a auditoria nem autoriza a lixeira;
  - "Gerar novo PIN" pede a senha de login do Admin e mostra uma vez um código aleatório de 6 dígitos; só o hash é guardado; o código novo invalida o anterior; vale até o Admin gerar outro;
  - o dono marca o supervisor do setor; o dono tem PIN sem precisar de setor e autoriza com o PIN (a senha não vale mais);
  - saíram o "Meu PIN" do supervisor e o "Zerar PIN" do dono.
- **Onde atualizar na 1.3:** RF10 (autorização sensível), matriz de papéis (novo uso do `admin` na clínica), telas (Supervisão) e o caso de uso do estorno.

## 3. Estorno por itens da cobrança e da venda (decisões C, D e E, 14/11/2026)

- **Antes:** estorno só da cobrança paga, inteiro (paga → cancelada), com a senha do dono ou o PIN do supervisor.
- **Agora:**
  - estorno por itens, da cobrança paga e da venda paga, com vários parciais até esgotar; parcial continua paga, com estornado e líquido à vista; o que esgota cancela;
  - modos: tudo, tudo menos o que veio do prontuário (só cobrança), ou itens escolhidos (quantidade por linha);
  - valor = preço copiado no lançamento × quantidade; a IA nunca informa valor;
  - item do prontuário pode ser estornado de propósito: só o valor, o estoque nunca volta;
  - tabelas novas `estornos` e `estorno_itens` (migration aditiva `2026_11_14_100000`); as colunas `estorno_*` da cobrança continuam (estado final);
  - tools novas: `estornar_venda` e `consultar_vendas`; `consultar_cobrancas` mostra estornado e líquido;
  - a autorização por PIN vale só para estorno de cobrança/venda paga; cancelar o que não foi pago continua igual.
- **Onde atualizar na 1.3:** RF de cobrança/venda, modelo de dados (2 tabelas), catálogo de tools e o card de confirmação (editor de itens).

## 4. Instalável no celular (PWA) e 360 px (14/11/2026)

- `public/manifest.webmanifest` (Sentinel, standalone, início em `/agente`), ícones do farol 192/512 e `public/sw.js`, que só guarda o shell estático (nunca páginas, `/agente`, JSON, login ou dados; sem modo offline).
- Ajustes de 360 px (toque ≥ 44 px, corpo ≥ 15 px, tabelas da Supervisão e do estorno empilhadas) em CSS estático (`public/css/sentinel-extra.css` e `sentinel-login-extra.css`), porque não há build do Vite nesta máquina.
- **Onde atualizar na 1.3:** RNF de usabilidade/acessibilidade e a seção de interface.
