---
name: sentinel-visual
description: Padrão visual do Sentinel (paleta, tipografia, logo, borda por risco, segurar para confirmar, estados, movimento e acessibilidade). Use sempre que criar ou alterar views Blade, CSS, Tailwind ou JS de interface do Sentinel.
---

# Padrão visual do Sentinel

Fonte da verdade: página "Direção visual" no Notion (SENTINEL), decisões de 28/09.
Se algo aqui conflitar com `.claude/agents/frontend-blade.md` ou com código antigo, **esta skill vence**.

## 1. As duas zonas

| Zona | Onde | Fundo | Movimento |
|---|---|---|---|
| **Vitrine** | antes do login | escuro `noite` | pode ter animação (farol 3D ligado à rolagem, loader) |
| **Bancada** | login, `/agente` e tudo depois do login | claro `creme` | **nenhuma animação**, exceto o preenchimento do segurar e barras de progresso |

O chat com o agente fica **dentro da bancada** (fundo creme). Não existe mais "chat escuro".

## 2. Tokens de cor

Defina uma vez em `resources/css/app.css`, dentro do `@theme` (Tailwind 4 gera as classes `bg-*`, `text-*`, `border-*` e as variáveis `var(--color-*)`):

```css
@theme {
    /* base */
    --color-noite: #0A0E13;     /* fundo da vitrine */
    --color-gelo: #A8C8E6;      /* destaque sobre fundo escuro */
    --color-creme: #FFFCEF;     /* fundo da bancada */
    --color-tinta: #1D232A;     /* texto sobre creme */
    --color-tinta-2: #5B6570;   /* texto secundário sobre creme; borda de risco neutro */
    --color-aco: #2E5B85;       /* destaque sobre creme: links, foco, rótulos */
    --color-borda: #E3DDC6;     /* divisórias e contornos de campo sobre creme */

    /* estados (bancada) */
    --color-sucesso: #2F6B4F;  --color-sucesso-faixa: #E4EFE6;
    --color-aviso: #8A5A12;    --color-aviso-faixa: #F6EBD0;
    --color-erro: #A3412E;     --color-erro-faixa: #F5E0D9;

    /* estados (vitrine, sobre noite) */
    --color-sucesso-escuro: #8FC4A4;
    --color-aviso-escuro: #E0B870;
    --color-erro-escuro: #D9A38F;
}
```

Regras:
- **Uma cor de destaque só**: o azul do farol (`gelo` no escuro, `aco` no claro). Não existe violeta, índigo nem teal.
- Cor de estado **sempre** acompanhada de texto ou ícone (nunca só a cor).
- Texto de estado usa a cor cheia; fundo de mensagem usa a `-faixa`. Todas as combinações passam WCAG AA (menor contraste: 4,9).
- Não invente tons novos. Se precisar de um, pare e pergunte ao Lucas.

## 3. Tipografia

- Títulos: `Sora` 300/400. Corpo: `IBM Plex Sans` 400/500. Números, códigos, CNPJ e horários: `IBM Plex Mono` com `font-variant-numeric: tabular-nums`.
- Pilha de fallback obrigatória: `"Segoe UI", system-ui, sans-serif` (a tela tem que funcionar sem a fonte carregar).
- Corpo com no mínimo 16px; nada abaixo de 15px, exceto rótulos em mono (12px, caixa alta, espaçado).

## 4. Logo e ícones

- Símbolo: **farol com um único facho apontando para a direita**. Não redesenhe; use os arquivos exportados em `public/marca/`.
- Fundo escuro: corpo `gelo`, janela `creme`. Fundo creme: corpo `tinta`, facho `aco`.
- 16px: só a torre com a janela acesa, sem facho. A partir de 32px: facho curto.
- Loader: farol 2D com a luz girando na lanterna (vale na vitrine e no carregamento inicial).

## 5. Sinalização de risco (bancada)

Todo card de ação do agente tem borda de 2px com a cor do risco:

| Risco | Tools | Borda |
|---|---|---|
| Leitura | `consultar_*` | `tinta-2` |
| Escrita | `criar_*`, `atualizar_status_lancamento` | `aviso` |
| Exclusão | mover para lixeira (ainda não existe como tool; Fase 4) | `erro` |

Tool nova entra na linha do seu efeito, não do seu nome.

A borda é o único sinal de risco. Não varie animação por risco.

## 6. Confirmação: segurar para confirmar

- Toda escrita pede confirmação por **segurar o botão por 2000 ms** (`DURACAO_HOLD_MS`). É o padrão, não um modo avançado. **Não existe duplo toque.**
- Funciona com mouse, toque e teclado (Espaço/Enter segurados). Soltar antes do fim cancela o preenchimento e mostra "Nada foi gravado".
- O botão de segurar usa a cor do risco da ação (`aviso` ou `erro`). O **Cancelar** é neutro (contorno `tinta-2`), nunca vermelho, e cancela com um clique.
- O card mostra em texto o que vai acontecer (ação + dados principais) antes de o usuário segurar.
- O servidor só aceita a confirmação entre 2 s (espera mínima) e a janela ociosa de 10 s (`DURACAO_IDLE_MS`, validade do token). Quando a janela passa, o card **não some calado**: fica visível com o texto "Confirmação expirada. Nada foi gravado. Peça de novo." e os botões desativados.

## 7. Mensagens e estados

- Resposta com sucesso: faixa `sucesso-faixa`, texto `sucesso`.
- Campo `aviso` do JSON (ex.: o agente pediu mais de uma ação e só a primeira foi feita): faixa `aviso-faixa`, texto `aviso`, exibido junto de qualquer tipo de resposta.
- Erro: faixa `erro-faixa`, texto `erro`, com a mensagem em português e o que o usuário pode fazer.
- Carregamento na bancada: texto de estado ("Consultando…") mais uma barra fina (1px, `aco`). Nada de spinner piscando nem esqueleto animado.
- Mensagem crítica **não some sozinha**. Some quando o usuário age.

## 8. Movimento

- Vitrine (provisória): página única em `/`, isolada em `resources/views/vitrine.blade.php` e `resources/js/vitrine.js` para ser trocada sem tocar no resto. Uma única ação: todo "Entrar" leva para `/login`. Sem outras navegações, demos interativas ou login sobreposto.
- Vitrine: animação permitida. O farol 3D (Three.js r128 via cdnjs) gira **só com a rolagem**: descer gira para um lado, subir volta, parado fica parado.
- Bancada: sem animação decorativa.
- Sempre respeite `prefers-reduced-motion: reduce`: desligue giro, loader e rolagem suave.

## 9. Acessibilidade (obrigatório)

- Estado nunca comunicado só por animação ou só por cor.
- Nada depende de hover.
- Área de toque mínima de 44×44px.
- Foco visível em tudo que é clicável: `outline: 2px solid` na cor de destaque da zona, com `outline-offset`.
- Mensagens do agente em região `aria-live`.
- Largura de 360px sem rolagem horizontal (tabelas largas rolam dentro do próprio contêiner).

## 10. Proibido

- Neon, brilho, glow, gradiente colorido, glassmorphism na bancada.
- Cristal, diamante, prisma, "estética de IA" genérica.
- React, Vue, GSAP ou qualquer framework JS. Só Blade + Tailwind + JS vanilla (Three.js apenas na vitrine).
- O LLM gerar HTML: a interface só monta componentes pré-existentes a partir do JSON do agente.
- Cores hex soltas no Blade/CSS: use os tokens da seção 2.

## 11. Autorrevisão antes de entregar

- [ ] Só tokens da seção 2 (procure por `#` solto nas views).
- [ ] Borda de risco certa em cada tipo de card.
- [ ] Segurar funciona com mouse, toque e teclado; Cancelar é neutro.
- [ ] `aviso` aparece quando vem no JSON.
- [ ] Nenhuma animação na bancada; `prefers-reduced-motion` respeitado na vitrine.
- [ ] Testado em 360px e em 1280px.
- [ ] Foco visível navegando só com Tab.
