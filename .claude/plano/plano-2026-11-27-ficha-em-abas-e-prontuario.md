# Plano de continuação (27/11) — ficha do pet em abas e fechamento do prontuário

Escrito em 26/11 no fim do dia para retomar em casa. Regras do projeto valem (CLAUDE.md): nenhum `git` de escrita, advisor (Opus) antes de bloco grande, `reviewer` com escopo (2 passadas quando mexe em CRMV, permissão, tenant ou auditoria), suíte completa antes de enviar para a main. Código congela na sexta (27/11); banca em 30/11.

## 0. Onde parou
- **Última suíte completa:** 1494/1494 (26/11), rodada ANTES das correções finais do `reviewer` no atendimento. **Depois delas só rodei** `ProntuarioAtendimentoTest`, `FichasReactTest` e `AgenteSemProntuarioTest`. **Rodar a suíte completa antes do commit/merge** (é o primeiro passo de amanhã).
- Commits do dia: o grande (telas React, loader, conversa que sobrevive, registro após escrita) e o pequeno (prontuário só leitura + cancelar neutro) já foram feitos pelo Lucas. **Sem commit ainda:** atendimento do veterinário (rotas `/prontuarios`), CRMV no avatar, `sujo` no Prontuario.jsx, `.claude/decisoes/` e este plano.
- Para rodar: `SENTINEL_FRONT=react` no `.env`, `php artisan migrate` (sem `fresh`) e `npm run build`. Contas e senha de teste em `.claude/plano/roteiro-demo.md`.

## 1. Atendimento do veterinário (feito, sem itens cobrados)
Rotas próprias em `ProntuarioController` (fora do motor do agente; o agente continua recusando prontuário e `AgenteSemProntuarioTest` vale).
- Quem atende: veterinário; o dono só porque é veterinário, com CRMV cadastrado. Recepção, atendente, vendedor e admin da clínica: barrados.
- Telas: `/prontuarios/novo?pet=&agendamento=` e `/prontuarios/{id}`. Botões: **Atender** na Agenda (linhas do próprio vet), **Novo prontuário** e **Abrir/Continuar** na ficha do pet.
- Assinar e adendar: CRMV + senha + segurar 2 s (`ReautenticacaoAdmin::autorizarComCrmv`). Depois de assinado, só adendo.
- Rascunho de outro veterinário: só o dono lê; o vet recebe 404 (GET e POST). Autor sempre o usuário logado.
- Auditoria: `abrir_prontuario`, `atualizar_prontuario`, `assinar_prontuario`, `adendar_prontuario`, `ver_prontuario`; só ids, tipo e NOMES de campos, nunca o texto clínico (teste procura o texto em todas as colunas).
- Corrigido pós-revisão: duplicidade de agendamento (lock + 422 sem SQL em log), erro de banco sem texto clínico no log, "negado" com a tool/ação certa, assinar bloqueado com alterações não salvas.

**Falta do atendimento (pendências):**
1. Rodar a suíte completa (acima).
2. Segunda passada do `reviewer`, só sobre as correções acima (CLAUDE.md exige).
3. Testes que o reviewer pediu: admin da clínica e as rotas atualizar/assinar/adendar para os papéis barrados (hoje só `criar` e `/novo`); teste da duplicidade (tentar abrir o mesmo agendamento duas vezes).
4. **Itens cobrados no prontuário (passo 4, fora desta entrega):** `ProntuarioService::adicionarItem/removerItem` já existem; falta rota e UI (só itens da clínica, só no rascunho do autor). A cobrança já puxa os itens.
5. Roteiro da demo (`roteiro-demo.md`, passo 7): trocar "mostrar na ficha" por "Atender na Agenda → preencher → assinar com CRMV+senha → adendo".

## 2. Ficha do pet em abas (planejada, não implementada)
Motivo (Lucas): com prontuários, agendamentos e cobranças a ficha cresce sem fim. Solução: **barra de abas** sob o cabeçalho.

**Cabeçalho fixo e compacto:** nome do pet; espécie, raça, sexo, idade, peso e microchip em linhas curtas; tutor (telefone, e-mail, link); **faixa de alertas de atendimento sempre visível** (não vai para aba: é segurança); ações (Novo prontuário, Editar o cadastro, ← Clientes).

**Barra de abas com contadores:** `Prontuário (n) · Agendamentos (n) · Cobranças (n) · Vendas (n)`. Acessível (role tablist, setas do teclado, aria-selected, rolagem horizontal no celular). A aba vai para a URL (`?aba=cobrancas`), então recarregar/voltar mantém. Aba padrão: Prontuário para veterinário/dono; Agendamentos para os demais.

**Conteúdo por aba:** Prontuário = linhas recolhidas (data, tipo, vet, situação) que expandem, "Abrir" vai à tela de atendimento, 10 mais recentes + "Ver mais". Agendamentos/Cobranças/Vendas = tabela atual limitada, "mostrando 20 de N". Vazio = mensagem curta.

**Carregamento por aba (recomendado):** o servidor manda só cabeçalho e contadores; cada aba busca a lista na primeira abertura (Inertia partial reload / lazy prop). Ganho de LGPD: `ver_prontuario_pet` só é auditado quando a aba Prontuário é aberta. Mantém papel, tenant e máscara; recepção/vendedor nem veem a aba Prontuário.

**Mesmo padrão depois:** ficha do tutor (pets, cobranças), só se sobrar tempo.

**Passos sugeridos (cada um entrega algo):**
1. `Components/ui/Abas.jsx` (acessível, sincroniza `?aba=`) + cabeçalho compacto na `Pet.jsx`.
2. Mover as seções atuais para as abas (mesmos dados), contadores vindos do servidor.
3. Lazy por aba + auditoria da leitura do prontuário só na aba.
4. Ajustar `FichasReactTest`/`ProntuarioAtendimentoTest` e testar papel, tenant e auditoria por aba; build.
5. `reviewer` (1 passada: leitura de dado clínico) + suíte completa.

**Decisões pendentes do Lucas:** (1) abas vs dashboard de cards (recomendo abas); (2) carregar por aba (recomendo) ou tudo de uma vez; (3) aba padrão como acima?; (4) ficha do tutor agora ou depois da banca.
Estimativa: 2 a 3 horas.

## 3. Outras pendências abertas (do dia)
- Decisões: prontuário aceita pet arquivado (permitido por ora); venda de atendimento de pet arquivado é recusada mas a cobrança passa; papéis que arquivam pet (recepção, veterinário, dono) "a confirmar"; `CLAUDE.md` não cita `arquivar_pet` entre os "segurar 2 s".
- Conferir no navegador (ninguém abriu hoje): registro após remarcar, conversa voltando ao recarregar, "Nova conversa", segurar para cancelar em lote, Resultados/Supervisão/Cadastros, tela de atendimento do prontuário em claro e escuro e no celular (360 px).
- Documentação final: regra "dados não ficam no histórico" mudou (conversa guardada 2 h, mascarada, sessão criptografada); prontuário agora tem interface; `.mjs` de navegador ficam com Lucas e Matheus.
- Decisões registradas em `.claude/decisoes/2026-11-26-*.md` (conversa que sobrevive, prontuário na ficha). Falta registrar: atendimento do prontuário fora do motor do agente (decisão de arquitetura aprovada pelo Lucas em 26/11).
