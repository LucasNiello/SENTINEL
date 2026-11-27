# SENTINEL — Plano de atualização da documentação (26/11/2026)

> **Atualização de 27/11:** leia também `plano-atualizacao-documentacao-ADENDO-2026-11-27.md` (mudanças posteriores, correções a este plano e ordem de execução). Enviar os dois ao Claude web.

**Para quem executa:** Claude web, com estes 3 arquivos enviados: `Documentacao-Sentinel1.3.docx`, `PENDENCIAS_DOCUMENTACAO_1.3.md` e `ROTEIRO_DEMONSTRACAO.md`. Você não vê o repositório nem as sessões anteriores: tudo o que precisa está aqui.
**Regras:** não invente número de teste, data ou comportamento que não esteja neste plano. Onde disser "MANUAL", é figura ou diagrama que precisa ser refeito à mão. Notion: só acrescentar bloco datado, nunca editar nem apagar.
**Sugestão de rótulo da versão:** "1.4 — 26/11/2026" (o Lucas decide; a 1.3 é de 24/11).

## 0. O que mudou desde a 1.3 (as 5 decisões)

1. **25/11, sem prazo nas propostas.** Nenhuma proposta de escrita vence mais, nem a do chat nem a do card de tela. Antes, o rascunho e o token valiam 5 min desde a proposta.
   - **Continua igual:** token de uso único, rascunho do dono (usuário + tenant), desfecho único por proposta, valores revalidados no Confirmar (vaga, versão, permissão, tenant), segurar 2 s nas destrutivas.
   - **Novo teto:** a sessão guarda no máximo 20 cards de tela por usuário; o mais antigo sai, com desfecho "cancelado" auditado.
   - O desfecho "expirado" continua existindo no código e na auditoria, mas hoje nunca é gerado. Foi mantido de propósito, para o caso de o prazo voltar.
2. **26/11, a lista de cancelar agendamentos em lote também não vence para o envio.** Referências por posição ("remarque o segundo") continuam valendo 30 min. Uma mensagem no chat não apaga a lista do lote.
   - **Freios:** só os ids da lista, a versão de cada linha guardada no servidor (o que mudou depois é recusado), tudo ou nada, envio único e segurar 2 s.
3. **O front React virou o front da demonstração** (flag `SENTINEL_FRONT=react`); o Blade fica congelado como plano B.
4. **Redesign visual do React:** tema claro e escuro com a identidade da vitrine (preto, branco, cinza, azul claro e azul escuro). **Saiu o creme/bege.**
5. **Interface nova do React:**
   - **Componentes:** avisos, campos, toast e botão padronizados.
   - **Chat:** card finalizado vira uma tira pequena.
   - **Lote:** seleção em lote com checkbox, barra fixa e "Tem certeza…" + segurar 2 s.
   - **Outros:** toast da exportação ancorado no botão e rolagem ao topo do card quando há erro.

## 1. Documentacao-Sentinel1.3.docx — o prazo e a expiração

Para cada linha, o número é o do texto corrido do documento. Substitua o texto atual pelo novo.

| Onde | Texto atual | Novo texto / ação |
|---|---|---|
| §2.2, ~L86 | "Toda decisão (sucesso, erro, recusa, negação, cancelamento, expiração) vira uma linha em audit_logs…" | Manter. Acrescentar: "A expiração por tempo deixou de ocorrer desde 25/11; o desfecho continua previsto." |
| §4.1, L237 | "Rascunhos na sessão: prazo de 5 min, dono (usuário + tenant), desfecho único" | "Rascunhos na sessão: sem prazo (desde 25/11), dono (usuário + tenant), desfecho único; teto de 20 cards de tela por usuário" |
| §5.5 códigos de erro, L346 | "Passou dos 5 min / rascunho inexistente ou de outro usuário" | "Rascunho inexistente, já concluído ou de outro usuário". Marcar o código `AI_PROPOSAL_EXPIRED` como "reservado, hoje não ocorre". |
| §6.3 Estados de uma proposta, L372 | "…vive na sessão como rascunho por até 5 minutos e termina com exatamente um desfecho auditado…" | "…vive na sessão como rascunho, sem prazo (decisão de 25/11), e termina com exatamente um desfecho auditado: executada, erro, negada ou cancelada. O que o servidor garante é o uso único, o rascunho do dono e os valores revalidados no confirmar." |
| §6.3, diagrama de estados e Figuras 5 e 6 | mostram a transição "passou de 5 min → expirado" | **MANUAL:** redesenhar sem a seta de expiração por tempo (ou a marcar como "reservado"). |
| §8.1, L433 | "Permissão e prazo são conferidos antes de senha, PIN ou CRMV." | "Permissão é conferida antes de senha, PIN ou CRMV." |
| §8.5 Código 4, L530-531 | legenda "…valores revalidados, prazo absoluto." | Legenda: "…valores revalidados, sem prazo". O bloco de código deve mostrar `prazoS()` e `expirou()` atuais (ver Anexo A). |
| §9.2 audit_logs, L893 | "sucesso, erro, recusado, negado, cancelado, expirado…" | Manter, e acrescentar nota: "expirado é reservado (não é gerado desde 25/11)". |
| §10.1, L959 | "rascunho dura 5 min" (em qualquer tabela/legenda de prazos) | "rascunho sem prazo" |
| §11.2 L1008 | "Partição de equivalência e valor-limite: prazo de 5 min (antes e depois)…" | Trocar por: "…proposta confirmada logo e horas depois (sem prazo), 6.ª tentativa de login no mesmo minuto, 11.º pedido à IA no minuto…" |
| §13.1 L1155 | "Em decisão: manter (menos tentativas por proposta) ou permitir nova tentativa dentro dos 5 min" | Remover "dentro dos 5 min"; reescrever como "permitir nova tentativa na mesma proposta" (o Lucas ainda decide). |
| Glossário L1215 | "Escrita sugerida pelo agente, guardada na sessão por 5 min, ainda não gravada" | "…guardada na sessão (sem prazo), ainda não gravada" |
| Glossário L1219 | "O fim único de uma proposta: executada, erro, negada, cancelada ou expirada" | "…executada, erro, negada ou cancelada (expirada: previsto, hoje não ocorre)" |

**Lote de cancelar agendamentos (novo texto):** em §6 (um parágrafo novo depois de "Estorno por itens") e em §3.4 (Telas), descrever a lista com checkbox: o agente só mostra a lista; o usuário marca; o botão pede "Tem certeza que quer cancelar N agendamentos?" e segurar 2 s; o servidor aceita só ids da lista, compara a versão de cada linha, grava tudo ou nada, uma linha de auditoria por item. A lista não vence para o envio, mas vale um envio só.

## 2. Documentacao-Sentinel1.3.docx — o front React

| Onde | Atual | Novo |
|---|---|---|
| §3.1 Tabela 3, linha "Interface" | "Blade + Tailwind (Vite) + JavaScript puro / PWA instalável; sem framework de front" | "React 19 + Inertia 3 (Vite 8, Tailwind 4) — front da demonstração, ativado por `SENTINEL_FRONT=react`; Blade + JavaScript puro congelado como plano B. PWA instalável." |
| §3.1 linha "Testes" | "1253 testes" | O Lucas informa o número depois de rodar a suíte completa (a última registrada é 1305/1305 em 25/11, antes dos blocos de 25-26/11). Não escreva um número sem ele. |
| §3.4 Telas e §4.1 Componentes | descrevem só o Blade | Acrescentar o React: página do agente, chat em coluna central (até 960 px), card de ação com um contorno só, calendário de agendamento, tabelas com cabeçalho fixo, seleção em lote. |
| §10.1, L963 | "Ação destrutiva … pede segurar o botão por 2 s" | Manter e acrescentar: "No React, o gesto vale para mouse, toque e teclado (Enter/Espaço); soltar antes não envia nada e mostra um aviso." |
| §10.2, L965-967 | "barra de comando fixa… painel lateral de contexto a partir de 1280 px" | **Remover o painel lateral de contexto** (saiu do React). Acrescentar: botão de tema claro/escuro, escolha guardada no navegador. |
| §10.2, L968 | "Identidade visual: paleta tinta/creme/aço… regras na skill sentinel-visual" | "Paleta da vitrine: preto (#0A0E13), branco, cinza (#5B6570 / #8A97A5), azul claro do farol (#A8C8E6) e azul escuro (#2E5B85). Sem creme. Claro e escuro com alternância. As regras vivem em `resources/js/react/tema.css` (a skill `sentinel-visual` está obsoleta)." |
| §10.3, L973 | "Contraste: tinta sobre creme ≈ 15:1…" | Ver a tabela de contraste no Anexo B. |
| L1188 | "Novo card do front: formulário editável com prévia, segurar…" | Manter e acrescentar a tira do card finalizado. |
| L1197 | "…front em React com Inertia (iniciado na branch migracao-react), entrada por voz e o chatbot público" | **Tirar o React de "Depois do TCC"**; manter "entrada por voz" e "chatbot público". |

**Padrões de interface do React (parágrafo novo em §10):**
- **Aviso** (info, sucesso, atenção, erro): ícone, texto curto, cor própria.
- **Campo:** "*obrigatório" em vermelho, borda vermelha no erro, mensagem embaixo; o resumo no topo do card ("N campos precisam de ajuste") leva ao primeiro.
- **Toast:** no mínimo 6 s, pausa com o mouse ou o foco, botão de fechar; erro não fecha sozinho. O toast da exportação sai de trás do botão e sobe.
- **Card finalizado:** vira uma tira de uma linha (✓ título · Confirmado/Cancelado · hora), até 50% da largura (80% no celular), sem botões.
- **Erro:** o chat rola ao topo do card e o foco vai para o aviso.

## 3. ROTEIRO_DEMONSTRACAO.md

- **Título:** "banca de 25/11/2026" → a entrega é 30/11 (código congela em 27/11). O Lucas confirma a data da banca.
- **Passo 0:** acrescentar "defina `SENTINEL_FRONT=react` no `.env` e rode `npm run build`"; Blade = plano B (`SENTINEL_FRONT=blade`).
- **Tabela de problemas, linha 92:** `"Confirmação expirada" | Passaram os 5 min da proposta: peça de novo.` → remover (não ocorre mais) ou trocar por "Proposta encerrada: peça de novo".
- **Acrescentar à demonstração:** mostrar o botão de tema claro/escuro; a seleção em lote ("cancele os agendamentos de amanhã"): marcar, "Tem certeza…", segurar 2 s; o card virando tira depois de confirmar.
- Conferir os cenários que citam o "segurar": agora o servidor decide pelo campo `segurar` (destrutivas = segurar; demais = um clique).

## 4. PENDENCIAS_DOCUMENTACAO_1.3.md

- **Item 1:** "o token vale até o fim dos 5 min da proposta" e "rascunho na sessão (5 min)" → acrescentar "Atualizado em 25/11: sem prazo; veja o item 5 (novo)".
- **Itens novos 5, 6 e 7:** (5) fim do prazo das propostas e da lista do lote; (6) front React como front da demonstração, com o redesign; (7) interface nova (padrões do §2 deste plano).
- **Rótulo:** propor "Pendências para a Documentação 1.4".

## 5. Notion — página "Arquitetura" (workspace SENTINEL)

Acrescentar **um bloco datado "26/11/2026"** ao fim, com as 5 decisões da seção 0. **Não editar nem apagar nada existente.** A página não deve afirmar "5 min".

## 6. Arquivos do repositório (para o Lucas ou o Claude Code; o Claude web não edita)

| Arquivo | O que está desatualizado |
|---|---|
| `CLAUDE.md` linhas 28-29 e 38 | "rascunho válido por 5 min" e "aceita o token… até o fim dos 5 min". Também a linha de stack "JS vanilla" (agora há React). |
| `.claude/decisoes/2026-11-25-agenda-qualquer-e-sem-prazo.md` L14 e L33 | "Chat mantém os 5 min" |
| `.claude/decisoes/2026-11-25-agente-referencias-e-lote.md` L36, L41, L46 | prazo de 5 min da lista com checkbox |
| `.claude/decisoes/2026-11-25-contrato-para-o-react.md` L6 | "`prazo_s`: 300 = proposta do chat (vence em 5 min…)". Agora `prazo_s` é sempre `null`. |
| `.claude/skills/sentinel-testes/SKILL.md` L50 | "depois dos 5 min da proposta → 422 e auditoria 'expirado'" |
| `.claude/skills/sentinel-backend/SKILL.md` L47 | "5 min da proposta (PRAZO_RASCUNHO_S)" |
| `.claude/agents/reviewer.md` L41 | "prazo de 5 min da proposta" |
| `.claude/skills/sentinel-visual/SKILL.md` | A paleta creme está obsoleta. **Não apagar**, só marcar como obsoleta (o React usa `tema.css`). |

## Anexo A — código atual para o "Código 4" (§8.5)

```php
// ConfirmacoesPendentes.php
public static function prazoS(string $origem): ?int
{
    return null; // 25/11 (Lucas): nenhuma proposta vence mais
}

public function expirou(array $pendente): bool
{
    if (self::prazoS(self::origem($pendente)) === null) {
        return false; // o resto do método fica para o caso de o prazo voltar
    }
    // ... (lógica antiga dos 5 min)
}
```

## Anexo B — cores e contraste (React, medidos em 25/11)

| Papel | Claro | Escuro |
|---|---|---|
| fundo / superfície | #F3F5F7 / #FFFFFF | #0A0E13 / #121821 |
| texto / texto suave | #1D232A / #5B6570 | #E6ECF2 / #8A97A5 |
| marca forte (botão primário) | #2E5B85 | #A8C8E6 |
| sucesso / atenção / erro / info | #2F6B4F / #8A5A12 / #B42318 / #0F6E6E | #6FCF97 / #E8B45A / #F2877A / #5FC4C4 |

**Contrastes (WCAG):**
- texto sobre fundo: 14,5:1 (claro) e 16,3:1 (escuro);
- texto suave: 5,4:1 e 6,0:1;
- botão primário: branco sobre azul escuro 7,1:1 (claro); fundo escuro sobre azul claro 11,1:1 (escuro);
- estados (sucesso, atenção, erro, info): entre 5,9 e 9,4:1;
- borda de campo: 3,7:1 (claro) e 3,5:1 (escuro), acima do mínimo de 3:1 para componentes.

O azul claro `#A8C8E6` sobre branco dá 1,7:1, então nunca carrega texto no tema claro.
