# Plano — front React: pendências antes da banca + acessibilidade extra

**Para:** Matheus. **Data:** 27/11/2026. **Escopo:** só o front React.

**Ordem do documento:** a **Parte A** (pendências de estado do sistema, prioridade ALTA) vem **antes** da **Parte B** (acessibilidade extra, prioridade MÍNIMA). A Parte A está no fim do arquivo, em "Parte A", e é a que deve ser feita primeiro.

# Parte B — Acessibilidade extra (prioridade MÍNIMA)

## Prioridade

**Fazer por último**, só depois de tudo o que é funcional (B2, retestes, testes de navegador, suíte completa, merge, trocar a chave, banco e seed).
Não é função do Sentinel: é um ponto a mais no requisito de empatia (RNF07/RNF08). Se faltar tempo, **corta-se daqui para baixo**: primeiro o item 4, depois o 2, e assim por diante. Nada aqui bloqueia a entrega.

Regras do projeto que valem aqui: nenhum pacote novo; mudança cirúrgica (tocar só o necessário); o Blade fica como está; a escolha do usuário vale por aparelho (navegador), como o tema de hoje.

## O que já existe (não refazer)

- `resources/js/react/tema.css`: variáveis de cor (`--cor-fundo`, `--cor-texto`, `--cor-borda` etc.), trocadas por `html[data-tema]`.
- `resources/js/react/tema.js`: lê e grava a escolha em `localStorage` (`sentinel:tema`), com `prefers-color-scheme` na primeira visita.
- `resources/js/react/Components/ui/BotaoTema.jsx`: alterna claro/escuro.
- `resources/js/react/Layouts/Workspace.jsx`: controle de tamanho do texto (A / A+ / A++, `texto-tamanho`).
- `:focus-visible` com contorno e brilho em `tema.css` (`.workspace-main`).

## Itens (na ordem de execução sugerida)

### 1. Alto contraste (esforço baixo)
- Terceiro valor de `data-tema`: `contraste`. Novo bloco `html[data-tema="contraste"]` em `tema.css` só com as variáveis (fundo preto ou branco puro, texto com contraste ≥ 7:1, bordas visíveis, contornos de foco mais grossos).
- `tema.js`: `lerEscolha` aceita `contraste`. O botão de tema passa a escolher entre 3 opções (claro / escuro / alto contraste).
- Medir a razão de contraste de cada par de cores usado e anotar o resultado (serve para citar na banca; WCAG AAA = 7:1 para texto normal).
- Conferir também os componentes que usam cor própria fora das variáveis (gráficos do Resultados, tira do histórico, bordas por risco).

### 7. Fonte legível (esforço baixo)
- Interruptor "Fonte legível": troca o `font-family` (sugestão: **Atkinson Hyperlegible**, licença livre) e aumenta um pouco o espaçamento entre linhas e letras.
- A fonte entra como arquivo `.woff2` dentro do projeto (sem pacote, sem CDN), do mesmo jeito que o login já carrega Sora e IBM Plex. Baixar uma vez, de um computador com internet livre (a rede do SENAI pode bloquear).
- Salvo no navegador, em chave própria; não interfere no tema nem no tamanho do texto.

### Painel "Acessibilidade" (junta 1, 7 e o que já existe)
- Um único painel no lugar do botão de tema e do A/A+/A++: tema (claro / escuro / alto contraste), tamanho do texto e fonte legível.
- Os controles viram um grupo com rótulo e `aria-pressed` (mesmo padrão do `BotaoTema`).

### 2. Daltonismo (esforço baixo a médio)
- Princípio: **nunca só cor**. Auditar onde o estado depende só de cor e acrescentar ícone ou texto: pago / pendente / cancelado, borda por risco do card, alertas, estados da agenda, legendas dos gráficos do Resultados.
- Verificação: simular deuteranopia e protanopia nas ferramentas do navegador (DevTools → Rendering → emulate vision deficiencies) e anotar o que ainda ficou ambíguo.
- Opcional, se sobrar tempo: paleta segura para daltonismo nos gráficos.

### 4. Teclado (esforço médio — o primeiro a cortar)
- Link "Pular para o conteúdo" no topo do `Workspace.jsx`.
- Ordem do Tab coerente; foco visível em todos os controles (inclusive o campo do chat, cards e tabelas).
- **Segurar 2 s com teclado:** manter Enter ou Espaço pressionado no botão de ação destrutiva faz o mesmo que segurar com o mouse. Mexe no `Botao.jsx` / na confirmação das ações destrutivas, então:
  - teste dos arquivos tocados e uma passada do reviewer;
  - o servidor não muda (a confirmação continua sendo a de sempre: o segurar é só gesto visual, opção B de 13/11).

## Fora (descartado em 27/11)
Reduzir movimento, leitor de tela, confirmar com dois toques no lugar de segurar, texto simples. Não implementar.

## Antes de dar por pronto
- Passar pelas telas principais nos três temas e com a fonte legível ligada, em 1280 / 1024 / 360 px.
- Rodar os testes dos arquivos tocados; mudança só de CSS ou texto não pede teste.
- Se o item 4 entrar, uma passada do reviewer só sobre o que mudou na confirmação.

---

# Parte A — Pendências de estado do sistema (prioridade ALTA, fazer ANTES da Parte B)

Levantamento de 27/11, lendo o código (rotas, controllers, views, `.env`); nada disso foi aberto no navegador. A meta é a banca nunca cair numa tela fora do tema do React nem num e-mail que não chega.

## A1. Páginas de erro voltam para a "página bege" (risco real)

**Situação:** `resources/views/errors/{401,403,404,419,429,500,503}.blade.php` estendem `layouts.app` (Blade, `workspace.css`, bege). Uma URL errada, uma sessão expirada (419) ou o limite de requisições (429) mostra uma página que parece de outro sistema, mesmo com `SENTINEL_FRONT=react`.

**Proposta (a mais simples):** manter as 7 páginas em Blade, mas com um layout de erro mínimo que:
- usa o mesmo `tema.css` do React e define `html[data-tema]` com um script curto, lendo `localStorage` `sentinel:tema` e caindo em `prefers-color-scheme` (a mesma lógica do `tema.js`);
- mostra a logo/farol do Sentinel, o código do erro, a mensagem em português (as de hoje, sem exceção, SQL ou caminho de arquivo) e o botão "Voltar ao início";
- continua funcionando sem o React carregado (um erro 500 não pode depender do JS).

**Alternativa descartada por ora:** renderizar a página de erro pelo Inertia (`withExceptions`). Fica frágil: no 419 e no 500 a sessão ou o middleware podem estar quebrados.

**Verificar:** abrir `/rota-que-nao-existe` logado e deslogado; forçar 403 (papel sem permissão), 419 (sessão expirada) e 429 (estourar o limite) nos temas claro e escuro. Nada de dado sensível na tela. Mudança só de view e CSS: sem teste automático; há `tests/` de erro? conferir se algum teste lê o texto das views antes de mexer.

## A2. Vitrine `/` ainda é Blade

`/` renderiza `welcome` (vitrine com `landing.css`/`landing.js`, fontes do Google). Decisão do Lucas pendente, as duas servem:
- **(a)** redirecionar `/` para `/login` (ou `/agente`, se já logado): zero risco na banca;
- **(b)** manter a vitrine, que tem visual próprio. Atenção: ela carrega fontes do Google (`fonts.googleapis.com`); sem internet na máquina da banca, cai em fonte padrão.

## A3. `/react-teste`

Remover a rota (`routes/web.php:32`), `ReactTesteController` e `Pages/Teste.jsx`. É página de teste da infraestrutura; com `react` ligado ela existe para qualquer usuário logado.

## A4. Configuração da máquina da banca (o risco mais silencioso)

- O padrão do código e do `.env.example` é `SENTINEL_FRONT=blade`. Sem `SENTINEL_FRONT=react` no `.env`, o sistema inteiro volta ao Blade bege, sem erro nenhum.
- O React precisa de `npm run build` (ou `npm run dev` rodando). Sem isso a tela fica branca.
- Checklist de 1 minuto antes de entrar: `.env` com `SENTINEL_FRONT=react`; build feito; abrir `/login`, entrar e abrir `/agente`, `/agenda`, `/clientes`; olhar se o tema está certo.
- Sugestão: mudar o padrão do `.env.example` para `react` (decisão do Lucas), para o PC do lab e novas instalações já nascerem certos. O CLAUDE.md ainda diz "Blade, Tailwind, JS vanilla": só o Lucas atualiza.

## A5. E-mail simulado (Mailpit)

**Situação:** o Mailpit roda neste PC (API em `127.0.0.1:8025`, 0 mensagens). O código de saída (tutor recebe e-mail ao criar, confirmar e cancelar agendamento) e de entrada (recepção importa e-mails em "Mensagens novas") existe desde 24/11, mas **o `.env` deste PC está com `MAIL_MAILER=log` e porta 2525**, então nada chega ao Mailpit.

**Fazer (Lucas, no `.env`, que ele não commita):**
```
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_FROM_ADDRESS="contato@clinica.sentinel.br"
MAIL_FROM_NAME="Clínica Sentinel"
```
Depois reiniciar o servidor (ou limpar o cache de configuração).

**Teste ponta a ponta (nunca rodou pelo app):** criar um agendamento, ver o e-mail chegar no Mailpit; responder pelo Mailpit para a clínica, importar em "Mensagens novas" e ver a origem no painel. Ver `documentacao/Demo-email-simulado.md`. O Mailpit também tem de estar rodando na máquina da banca.

**Pendentes antigos (24/11):** 2ª passada do reviewer; pendentes 1 a 5 do `.claude/decisoes/2026-11-24-email-simulado.md` (hora em fuso, teste do `afterCommit`, teto de tempo da importação, texto da doc); visual do painel "Mensagens novas" nunca visto; suíte completa não foi rodada naquele bloco.

**Artes do e-mail:** não existem. O e-mail é texto puro de propósito (`AgendamentoMail`, `Content(text: ...)`, `{!! !!}` para o nome não virar `&#039;`). Fazer versão HTML (logo, cores) é **opcional e fica para depois**; se for feito, escapar tutor e pet com `{{ }}`, manter o texto puro como alternativa e passar o reviewer (mexe em dado de tutor).

## Ordem sugerida
1. A4 (checklist da máquina) e A5 (`.env` do Mailpit + teste ponta a ponta): minutos, e evitam o pior cenário.
2. A1 (erros) e A3 (`/react-teste`).
3. A2 (vitrine): depois da decisão do Lucas.
4. Só então a Parte B (acessibilidade), na ordem que ela própria define.

**Fora desta lista:** artes HTML do e-mail e remarcar/calendários das remarcações (já combinados como "depois").
