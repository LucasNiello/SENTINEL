# Plano 27/11 — card Agendar, chat, cadastro unificado e página de Clientes (React)

Escrito em 26/11 para ser executado no PC do lab. Branch `migracao-react`. **Só planejado: nada deste plano foi implementado.**
Regras do Lucas: tudo em React (Blade fica congelado como plano B); nenhum `git` de escrita; testes de navegador e celular ficam com o Lucas e o Matheus; documentação só no fim (ver `plano-atualizacao-documentacao-2026-11-26.md`); usar o advisor (Opus) antes de cada bloco grande. Código congela na sexta (27/11), banca no dia 30/11.
Lembrete: o código mais recente (bloqueios 1 a 4 abaixo) **ainda não tem commit**. Antes de mexer, o Lucas confere `git status` e faz os commits sugeridos.

## 0. Estado de partida (já feito, sem commit)
1. Propostas de escrita não vencem mais (`ConfirmacoesPendentes::prazoS()` sempre `null`).
2. Lista de cancelar em lote sem prazo só para o envio (`ConversaAgente::lerLista(..., paraEnvioDoLote: true)`).
3. Redesign React: tema claro/escuro (`resources/js/react/tema.css`), `Components/ui/` (Aviso, Campo, ResumoErros, Toast, Botao, BotaoTema, AvisoConfirmacao), segurar 2 s, seleção em lote, tira do card finalizado.
4. Contas ativas/inativas: coluna `users.ativo`, `GarantirContaAtiva`, interruptor na `/supervisao` (só o admin liga/desliga). 16 usuários: 11 `@clinica.sentinel.br` ativos (senha `DEMO_SENHA`), 5 `.local` inativos. Dono único: `vet1`.
Para rodar: `SENTINEL_FRONT=react php artisan serve` e `npm run build` (o `npm run dev` é lento na 1ª vez; `public/hot` antigo atrapalha: apagar). Depois de mexer no front, F5 e `npm run build`.

## 1. Bloco A — card "Agendar" no chat (9 itens do Lucas)
Fatos conferidos no código:
- O servidor **já escolhe** o profissional quando `profissional_id` vem em branco: `AgendaService::escolherProfissional` (linha ~366) escolhe o de **menos atendimentos no dia**, empate pelo menor id. A pergunta "Quem atende às 14:00?" é sobra do React (`CalendarioAgendamento.jsx`, `escolherHorario`/`quem`).
- O servidor manda `passo_unico: true` nas ações de agenda (`AiAgentService::confirmaEmUmPasso`: agendar, remarcar, alterar serviço, alterar status). O **React ignora** o campo, por isso o botão Revisar voltou. O Blade obedece (`resources/views/partials/bancada.blade.php`, `passoUnico`, ~l.975-1051): sem Revisar; o Confirmar envia `{proposta, valores}` a `/agente/confirmar`; conflito volta 422 com `formulario`, card continua editável.
- Rótulo do pet "Bolt · #12" vem de `FormularioTool::referencias` (~l.231-262), genérico (nome + id).
- O Blade já tem combo único do profissional com busca (decisão de 25/11, `ficha-agendamento.blade.php`): usar como referência de comportamento.
- Riscado: o `fieldset disabled` do card travado ativa meu CSS `.ficha-dia:disabled,.ficha-horario:disabled {text-decoration: line-through}` (em `tema.css`). A intenção era riscar só dia/horário sem vaga.

Passos:
1. **Combobox único** `Components/ui/Combobox.jsx`: abre a lista, digita dentro dela e filtra (setas, Enter, Esc, Home/End, ARIA combobox/listbox, toque ≥ 44 px). Só aceita valor da lista. Usar em **Pet, Serviço e Profissional**; some o campo "Buscar profissional". Entra em `CamposProposta.jsx` para os campos `referencia`/`opcoes` e no `CalendarioAgendamento.jsx`.
2. **Pet com o dono**: no back, ensinar `FormularioTool::referencias` a montar o rótulo do pet com o tutor ("Bolt · Maria Souza · #12"), e a busca acha por pet ou tutor. Limitar a mudança ao vínculo de pet (arquivo compartilhado). Teste novo + `IsolamentoDeTenantTest`.
3. **Confirmar em um passo** no `Confirmacao.jsx`: se `dados.passo_unico`, sem botão Revisar; Confirmar envia `{proposta, valores}`; tratar 422 com `formulario` (reabre editável); credenciais (CRMV/senha) continuam só nos inputs. Manter o Revisar nos cards sem `passo_unico`.
4. **Profissional escolhido pelo servidor**: remover o "Quem atende às…". Ao escolher dia e horário, mostrar a **prévia** (`POST /agente/revisar` com `previa: true`, já existe) e o profissional previsto; "definido ao confirmar" quando a prévia não traz. O servidor reconfere ao gravar e o card avisa se mudou. Serviço que exige escolher (cirurgia) continua pedindo.
5. **Destaque da seleção**: horário escolhido destacado como o dia do calendário; depois de escolher, o card **não risca nada** (usar um `data-sem-vaga` para o risco; `disabled` por travamento não risca). Campos escolhidos ficam em destaque.
6. **Texto da seleção**: "26/11 · 14:00 · vet2" (Data · Hora · Profissional). Sem "Selecionado:" técnico, sem "Use Editar para alterar".
7. Testes: PHP do rótulo do pet; ajustar `tests/browser/ChatConfirmacao.mjs` (fluxo sem Revisar, nomes `Serviço *obrigatório`, rótulos do combobox). Duas passadas do `reviewer` (muda a gravação da agenda).

## 2. Bloco B — campo de escrita do chat
`Pages/Agente.jsx`, composer: **remover** o `<kbd>ENTER ↵</kbd>` e o texto "Enviar"; deixar só o **círculo com a seta**. Manter `id="botao-enviar"`, `type="submit"` e dar `aria-label="Enviar"` (os testes e leitores de tela dependem). No print, o texto "Enviar" aparece cortado/sobreposto ao círculo: some junto. Conferir `tema.css` (`#botao-enviar`, `form#form-comando`).

## 3. Bloco C — cadastro unificado do cliente (tutor + pets)
Decisão do Lucas: um formulário só do cliente, com **vários pets** (cadastrar sem vincular depois).
Fatos: `Pet` tem `tutor_id, nome, especie, raca, sexo, data_nascimento, peso_gramas, microchip, alertas, tenant_id` (`PetRequest`); `Tutor hasMany Pet`; serviços `TutorService` (88 l.) e `PetService` (125 l.); entidades de cadastro vêm de `TelasController::CADASTROS_CLINICA` (tools `criar_tutor`, `criar_pet`, `atualizar_*`).
Passos:
1. Tipo de campo novo `lista` (repetível) no motor de formulário (`FormularioTool`) e em `CamposProposta`/novo `ui/ListaDePets.jsx`, no estilo do editor de itens do estorno (`itens_estorno`).
2. Serviço novo `TutorService::criarComPets` (ou tool nova): grava tutor + N pets em **uma transação**, tudo ou nada; erro aponta pet e campo. Auditoria: uma linha por registro, com o mesmo `lote_id` (padrão da conciliação em lote). Papel, setor e tenant do `ContextoUsuario`. Mantém `criar_pet` avulso.
3. Editar tutor: o card traz os pets atuais; adicionar pet; **sem remover** (ver Bloco D).
4. Testes PHP (isolamento de tenant, tudo-ou-nada, auditoria, RBAC) e duas passadas do `reviewer` (grava várias tabelas).

## 4. Bloco D — arquivar pet (sem apagar)
Decisões do Lucas: o pet **não é removido**; é **arquivado** com o botão de **segurar 2 s**, e o histórico (agendamentos, prontuário, cobranças) permanece. Campo **observações** no cadastro do animal (motivo: "falecido", "mudou" etc.). O pet arquivado aparece com **outra cor** + etiqueta "Arquivado" escrita (não só cor; não usar vermelho). **Pet com agendamento futuro não é arquivado**: bloquear com "cancele ou remarque antes". Reativar: um clique. Arquivar e reativar viram linha de auditoria com o motivo.
Passos:
1. Migration aditiva em `pets`: `arquivado_em` (timestamp nulo) e `observacoes` (texto, ≤ 500). `alertas` continua para alertas clínicos.
2. Arquivado some dos seletores de pet (agendar, vender, cobrar) como o profissional inativo some (`where arquivado_em is null` nos mesmos pontos de `FormularioTool::referencias` e `AgendaService`); o histórico continua mostrando o nome.
3. Tools/serviço: `arquivar_pet` (com `observacoes` obrigatório, risco "destrutivo" → segurar 2 s) e `reativar_pet`; regra do agendamento futuro; papéis (recepção e veterinário; confirmar no catálogo).
4. Testes PHP (bloqueio por agendamento futuro, some das listas, auditoria, tenant) + duas passadas do `reviewer`.

## 5. Bloco E — página "Clientes" (React)
Hoje `/cadastros` é Blade (`TelasController::cadastros` → `view('telas.consulta')`, entidades e tools, `Front::tela` não é usado). Criar página React `Pages/Clientes.jsx` e registrar com `Front::tela('telas.consulta', 'Clientes', $dados)` (padrão do `AgenteController`), **mantendo o Blade como plano B** e o menu (`HandleInertiaRequests`, `menu`).
- **Lista** de clientes com busca por **tutor ou por pet**.
- **Detalhe do cliente**: dados do tutor e os pets **empilhados** (efeito cascata), cada pet **recolhido em uma linha**; a setinha abre o cadastro do pet (editar, observações, arquivar com segurar 2 s). Seção **"Arquivados"** (recolhida) com "Reativar"; a linha do arquivado em tom apagado + etiqueta "Arquivado".
- **Buscar pelo pet**: ao abrir o cadastro dele, o sistema vai para a página do **dono**, já **rolando até o pet** (âncora/scroll + realce breve).
- Dados: reutilizar as rotas JSON existentes (`/agente/consultar`, `/agente/formulario`) ou criar endpoints JSON próprios no back (decidir ao explorar; preferir reutilizar). Toda escrita passa por Revisar/Confirmar ou pelo card.
- Tema, componentes `ui/`, 360×740 e leitor de tela como no resto. Testes PHP dos endpoints novos + `ChatReact*`/novo `ClientesReactTest`; `.mjs` ajustado (não roda aqui).

## 6. Ordem e estimativa
1. Bloco B (rápido, 10 min) → 2. Bloco A (60-90 min) → 3. Bloco D back + C back (migration, serviços, testes) → 4. Bloco C front → 5. Bloco E (maior; 2-3 h) → 6. Revisões e testes → 7. Relatório curto.
Tempo total estimado: de 5 a 7 horas de trabalho, com as revisões. O Lucas aceitou prolongar, mas o código congela na sexta: se faltar tempo, cortar o **rolar até o pet** do Bloco E, nunca o arquivar/bloqueio.

## 7. Cuidados
- Mexe em permissão, tenant, auditoria e gravação em várias tabelas: **duas passadas do `reviewer`** nos blocos A (passo único), C e D; só testes dos arquivos tocados + os de tenant/auditoria/estorno; **suíte completa** antes do commit/merge.
- Não commitar nada (só sugerir mensagens). Não apagar dado; `migrate:fresh` proibido (o Lucas roda se quiser).
- Credenciais nunca em saída. `DEMO_SENHA` está no `.env`.
- Registrar decisões e pendências no relatório final (`.claude/decisoes/` só se o Lucas pedir).
- Pontos a confirmar com o Lucas ao começar: (1) papéis que podem arquivar/reativar pet (sugestão: recepção, veterinário e dono); (2) texto do rótulo do pet com tutor.
