# Sentinel — Relatório final das Fases 4 e 5

**Data:** 26/10/2026 · **Base:** `main` em `12dffba` · **Suíte:** 398/398 testes (3882 asserções), `php artisan test` com SQLite em memória e Foundry simulado.
**Congelamento de funcionalidades:** 31/10 · **Teste manual (Fase 6):** 03 a 06/11 · **Entrega:** 25/11.

Este relatório fecha a construção (Blocos 1 a 4 e Etapas A e B). Ele serve de base para a documentação 1.1. Não há código novo nesta etapa.

---

## 1. Placar

| Bloco / etapa | Commit | O que entrega | Status |
|---|---|---|---|
| Fases 0–3 + Matheus | até `ff24d4c` | Esqueleto Laravel, 5 entidades + categorias, tools com RBAC, autenticação real (RF10), contrato de erro, logs sem conteúdo bruto, `ConversaAgente`, vitrine e login v4 | Feito |
| Bloco 1 | `d660851` | Cancelar, expirar e sair auditados; `ConfirmacoesPendentes`; colunas novas em `audit_logs` (`origem`, `parametros_propostos`, `autorizado_por`, `tempo_ia_ms`) | Feito |
| Bloco 2 | `f4f1441` | RF04: formulário pré-preenchido no card (`FormularioTool`, `definirEscrita()`), proposto × confirmado, máscaras | Feito |
| Bloco 2b | `57a6ecc` | Revisar → segurar (rascunho de 5 min, `proposta`/`token`), salário em R$ só no card do admin, máscara de salário no histórico | Feito |
| Bloco 2c | `6ae9b30` | Casca (`layouts/app.blade.php`), telas de erro 401/403/404/419/429/500/503, contraste; `revisao_com_erro` não é desfecho final | Feito |
| Bloco 3 | `8c7f739` | `atualizar_*` pelo agente, `/agente/formulario` sem IA, botão Editar, alteração concorrente recusada | Feito |
| Bloco 4 | `63ccc42` + `bfd9e17` | Lixeira: `consultar_lixeira`, `excluir_*`, `restaurar_*`, `excluir_definitivo_*`; senha de admin conferida depois da permissão; limites atômicos | Feito |
| A1 | `0191fdc` | Consulta da auditoria: só admin, por tenant, filtros, 25 por página, detalhe mascarado | Feito |
| A2 + A3 | `9d797e4` | Valor anterior na auditoria; tool fora do catálogo grava só nomes de argumentos; exportar CSV (anti-fórmula, BOM, limite, auditoria) | Feito |
| B2 | `fe19b93` | Modo manual sem IA (`POST /agente/consultar`) | Feito |
| B3 | `4824a18` | Telas Cadastros, Lixeira e Auditoria; botão Exportar; menu sem "em breve"; card em `partials/bancada.blade.php` | Feito |
| B1 | `12dffba` | `tempo_ia_ms` em cada desfecho (RNF04); Lixeira recarrega depois de restaurar | Feito |
| B4 | — | 404 de usuário logado mostrando o menu | **Não feito** (fechado sem ele, a pedido do Lucas) |
| C | (este arquivo) | Relatório final | Feito |

---

## 2. Matriz de rastreabilidade

Fonte dos requisitos: `documentacao/Documentacao-Sentinel1.0.docx`, seção 10 (lido direto do .docx). Os testes ficam em `tests/Feature/` (exceto quando indicado).

### 2.1 Requisitos funcionais

| Req. | Descrição curta | Status | Evidência (código · teste) |
|---|---|---|---|
| RF01 | Interpretar linguagem natural e mapear para operações válidas | Feito | `AiAgentService::processar()`/`interpretar()` e catálogo de 37 tools · `AgenteTest::test_leitura_filtra_por_status_e_executa_direto`, `AgenteGovernancaTest::test_tool_inventada_pelo_modelo_nao_executa_nada`, `AgenteCorrecoesTest::test_operacao_nao_suportada_orientada_no_prompt_sem_proposta` |
| RF02 | Consultar com filtros em linguagem natural; tabela editável e exportável | Feito | `consultar_*` + `editavelPor` + `ConsultasExportaveis` + `PlanilhaCsv` · `AtualizarTest::test_consulta_traz_a_tool_de_editar_so_para_quem_pode_atualizar`, `ExportarCsvTest` (14 testes) |
| RF03 | Ações em massa só com confirmação explícita | **Parcial** | Não há ação em massa: toda escrita é de um registro por vez e sempre passa por Revisar → segurar · `AgenteTest::test_varios_pedidos_numa_mensagem_so_o_primeiro_e_feito_e_a_resposta_avisa` (vários pedidos numa mensagem: só o primeiro é atendido, com aviso). Ação em massa fica como trabalho futuro. |
| RF04 | Cadastro e atualização por formulário pré-preenchido invocado pela IA | Feito | `FormularioTool`, `AgenteController::revisar()` · `FormularioAgenteTest` (36 testes), `AtualizarTest::test_atualizar_por_nome_traz_os_valores_atuais_mais_a_mudanca_da_ia_e_grava` |
| RF05 | Telas de CRUD tradicionais como via alternativa | Feito | `TelasController`, `telas/consulta.blade.php`, `POST /agente/consultar`, `POST /agente/formulario` · `ModoManualTest`, `TelasTest::test_cadastros_usa_a_consulta_sem_ia_e_so_oferece_novo_a_quem_pode_cadastrar`, `AtualizarTest::test_formulario_sem_ia_abre_preenchido_e_segue_revisar_e_segurar`. Exclusão comum fica só pelo agente (na Lixeira: restaurar e excluir de vez). |
| RF06 | Registrar em log toda ação executada, cancelada ou negada | Feito | `AuditoriaService::registrar()`, `executarTool()`, `auditarDesfecho()` · `AgenteGovernancaTest::test_rf06_leitura_grava_audit_log_completo`, `ConfirmacaoDesfechoTest::test_cancelar_audita_cancelado_com_o_proposto_e_tira_a_proposta_da_sessao`, `AgenteGovernancaTest::test_rbac_leitura_nao_chega_a_propor_escrita_e_a_tentativa_negada_e_auditada` |
| RF07 | Consulta e filtragem do log por usuários autorizados | Feito | `ConsultaAuditoria`, `AuditoriaController`, `telas/auditoria.blade.php` · `AuditoriaConsultaTest` (16 testes), `TelasTest::test_auditoria_so_admin_nega_auditando_e_lista_so_usuarios_do_tenant` |
| RF08 | Bloquear exclusão com dependências ativas, com motivo simples | Feito | `ExclusaoSegura::excluirComBloqueio()`/`motivoDeBloqueio()` · `GovernancaTest::test_rf08_*` (3), `LixeiraTest::test_dependencia_bloqueia_a_exclusao_ja_no_revisar_com_frase_simples`, `LixeiraTest::test_dependencia_criada_depois_do_revisar_ainda_bloqueia_no_segurar` |
| RF09 | Exclusão vai para lixeira com retenção configurável, sem remoção física | Feito | SoftDeletes + `config('sentinel.retencao_lixeira_dias')` (padrão 90) · `GovernancaTest::test_rf09_exclusao_sem_dependencia_vai_para_a_lixeira_sem_remocao_fisica`, `LixeiraTest::test_excluir_mostra_o_registro_no_card_e_move_para_a_lixeira` |
| RF10 | Restaurar da lixeira com senha de admin, registrando na auditoria | Feito | `restaurar_*` + `ReautenticacaoAdmin` + `audit_logs.autorizado_por` · `LixeiraTest::test_restaurar_com_a_senha_do_admin_restaura_e_registra_quem_autorizou`, `LixeiraTest::test_restaurar_com_senha_errada_nao_restaura_e_encerra_a_proposta`, `ReautenticacaoCardTest` (12 testes) |
| RF11 | Após o prazo, mover para "arquivado" (invisível, mantido no banco) | Feito (depende do agendador do SO) | `sentinel:arquivar-expirados` (`ArquivarLixeiraExpirada`), agendado `daily` em `routes/console.php` · `GovernancaTest::test_rf11_rf12_prazo_expirado_arquiva_sem_excluir_fisicamente`, `AutenticacaoTest::test_comando_de_arquivamento_roda_sem_usuario_logado`. Sem `schedule:run` no Agendador do Windows, só roda à mão. |
| RF12 | Exclusão física só manual, por admin, nunca automática | Feito | `excluir_definitivo_*` (admin + senha) · `GovernancaTest::test_rf11_rf12_prazo_expirado_arquiva_sem_excluir_fisicamente`, `LixeiraTest::test_excluir_de_vez_e_negado_a_quem_nao_e_admin` |

### 2.2 Requisitos não funcionais

| Req. | Descrição curta | Status | Evidência (código · teste) |
|---|---|---|---|
| RNF01 | RBAC aplicado às tools da IA, não só às telas | Feito | `papel_minimo` por tool, `papelAtualPermite()` · `AgenteGovernancaTest::test_rbac_hierarquia_leitura_operador_admin`, `AgenteGovernancaTest::test_rbac_e_rechecado_na_confirmacao`, `AgenteGovernancaTest::test_rbac_papel_desconhecido_nega_tudo` |
| RNF02 | Toda escrita exige confirmação humana explícita | Feito | Proposta → Revisar → token → segurar 2 s → `/agente/confirmar` · `AgenteTest::test_rnf02_*` (4 testes), `FormularioAgenteTest::test_confirmar_proposta_nao_revisada_e_recusado` |
| RNF03 | Validação só no backend | Feito | Form Requests via `validar()` + `escoparPorTenant()` · `GovernancaTest::test_rnf03_validacao_de_nota_fiscal_no_backend`, `FormularioAgenteTest::test_valor_alterado_no_navegador_depois_do_revisar_e_ignorado`, `CriarLancamentoToolTest` |
| RNF04 | Tempo de resposta mensurável e dentro de limite (ref. 10 s) | **Parcial** | Medido: `tempo_ia_ms` em cada desfecho e no log · `TempoIaTest` (8 testes). O limite de 10 s não é imposto pelo código (timeout HTTP de 30 s; o front aborta em 35 s). Conferir na Fase 6 pelo detalhe da auditoria ("Tempo da IA"). |
| RNF05 | Com a IA fora do ar, o CRUD continua operável | Feito | Modo manual + `/agente/formulario` · `ModoManualTest::test_com_a_azure_fora_do_ar_lista_busca_cadastra_e_exporta`, `AgenteTest::test_falha_do_foundry_degrada_para_erro_amigavel` |
| RNF06 | Isolamento de tenant em todas as camadas, inclusive nas tools | Feito | `ContextoUsuario`, `$argumentos['tenant_id']` sobrescrito no servidor, `escoparPorTenant()`, services com `int $tenantId` · `AgenteGovernancaTest::test_tenant_enviado_pelo_modelo_e_ignorado`, `AgenteGovernancaTest::test_consulta_so_enxerga_o_tenant_atual`, `AtualizarTest::test_registro_de_outro_tenant_ou_na_lixeira_responde_igual_a_inexistente`, `ExportarCsvTest::test_so_exporta_o_tenant_do_usuario`, `ModoManualTest::test_cada_entidade_lista_so_o_tenant_do_usuario` |
| RNF07 | Estado de ação pendente em pelo menos dois canais (cor + texto) | Feito | Card: borda pela cor do risco + título + texto de etapa ("Segure Confirmar por 2 segundos…") + `aria-live` + popup com símbolo e texto · `AgenteTest::test_confirmacao_pendente_traz_o_risco_escrita_da_tool`. A parte visual é **sem teste automático**: conferir na Fase 6. |
| RNF08 | Expirar cancela só a ação pendente | Feito | `VarrerConfirmacoesVencidas`, `ConfirmacoesPendentes::retirarVencidas()` · `ConfirmacaoDesfechoTest::test_varredura_nao_mexe_na_proposta_ainda_valida`, `ConfirmacaoDesfechoTest::test_proposta_esquecida_e_auditada_como_expirada_na_requisicao_seguinte`, `FormularioAgenteTest::test_rascunho_expira_em_5_minutos_e_e_auditado` |
| RNF09 | Governança coberta por testes automatizados | Feito | 398 testes; arquivos de governança: `GovernancaTest`, `AgenteGovernancaTest`, `AuditoriaMascaraTest`, `ConfirmacaoDesfechoTest`, `LixeiraTest`, `ReautenticacaoCardTest` |
| RNF10 | Limite de taxa na rota da IA | Feito | `throttle:agente` (10/min por usuário); também `agente-sem-ia` (30/min), `auditoria` (60/min), `exportar` (5/min), `login` (5/min por e-mail + IP) · `AgenteTest::test_rnf10_a_11a_chamada_no_mesmo_minuto_recebe_429`, `ConfirmacaoDesfechoTest::test_cancelar_tem_limite_de_taxa_no_contrato_de_erro`, `ExportarCsvTest::test_limite_de_cinco_exportacoes_por_minuto`, `AutenticacaoTest::test_login_bloqueia_a_sexta_tentativa_no_mesmo_minuto` |

---

## 3. Roteiro de testes manuais por papel (Fase 6)

### 3.1 Pré-requisitos (em cada máquina)

1. **PHP ≥ 8.4.27** (`php -v`). Esta máquina está em 8.4.25: veja a seção 6.
2. No `php.ini`: `session.upload_progress.enabled=0`.
3. `.env`: `APP_KEY` preenchida; para a apresentação, `APP_DEBUG=false` e `SESSION_ENCRYPT=true`; credenciais do Foundry preenchidas (não copiar para nenhum documento).
4. `php artisan migrate` (obrigatório: as migrations de 19/10 e 22/10 acrescentam colunas em `audit_logs`).
5. `npm run build` (assets da vitrine e do login).
6. Usuários locais: `php artisan db:seed` (só em ambiente `local`) ou `php artisan sentinel:preparar-acesso --exemplos`. Perfis: `admin@sentinel.local` (admin, tenant 1), `operador@sentinel.local` (operador, tenant 1), `leitura@sentinel.local` (leitura, tenant 1), `admin.t2@sentinel.local` (admin, tenant 2). As senhas aparecem **só** na criação; anote-as na hora.
7. Navegador com DevTools aberto (para 375 px e para conferir que nenhuma resposta traz CPF ou salário em claro).

### 3.2 Papel **leitura** (`leitura@sentinel.local`)

| # | Passo | Resultado esperado |
|---|---|---|
| L1 | Abrir `/agente` sem login | Redireciona para `/login` |
| L2 | Errar a senha 6 vezes seguidas | Mesma mensagem para e-mail inexistente e senha errada; na 6ª, "muitas tentativas" (limite de 5/min) |
| L3 | Entrar com a senha certa | Vai para `/agente`; topo com nome e selo "Leitura"; menu mostra Agente e Cadastros, sem Lixeira e sem Auditoria |
| L4 | Pedir "liste os clientes" | Tabela com id, nome, documento, e-mail e telefone; CPF como `***.***.789-01`; CNPJ inteiro; sem botão Editar; botão "Exportar CSV" |
| L5 | Pedir "liste os funcionários" | Sem colunas de CPF e salário |
| L6 | Pedir "cadastre o cliente Teste" | Mensagem de permissão negada, sem card |
| L7 | Abrir `/lixeira` digitando a URL | Tela 403 em português, dentro da casca |
| L8 | Abrir `/auditoria` digitando a URL | Tela 403 (a tentativa fica registrada como "negado"; confira depois como admin) |
| L9 | Em Cadastros, trocar a entidade e buscar por nome | Lista filtrada; sem botão "Novo" |
| L10 | Sair | Volta ao `/login`; `/agente` exige login de novo |

### 3.3 Papel **operador** (`operador@sentinel.local`)

| # | Passo | Resultado esperado |
|---|---|---|
| O1 | Entrar | Menu: Agente, Cadastros, Lixeira (sem Auditoria) |
| O2 | "cadastre o cliente Padaria Sol, CPF 123.456.789-01" | Card com borda de escrita, formulário preenchido, CPF mascarado, etapa "Confira os dados e clique em Revisar" |
| O3 | Apagar o nome e clicar Revisar | Erro no campo ("nome obrigatório…"); sem botão de segurar; a proposta continua |
| O4 | Corrigir e Revisar | Texto "Dados conferidos. Segure Confirmar por 2 segundos…" |
| O5 | Segurar e **soltar antes** de 2 s | "Você soltou antes do fim. Nada foi gravado." |
| O6 | Segurar até o fim | Popup "✔ Confirmado" + frase do servidor; o cliente aparece numa nova consulta |
| O7 | Novo cadastro → **Cancelar** | "Cancelado. Nada foi gravado." |
| O8 | Novo cadastro → esperar sem agir (5 min no formulário ou 10 s depois do Revisar) | O card bloqueia por tempo; nada é gravado; a auditoria registra "expirado" |
| O9 | "mude o telefone do cliente Padaria Sol para 19 99999-0000" | Formulário com os valores atuais + o telefone novo; confirmar grava |
| O10 | Consultar clientes → **Editar** numa linha | Mesmo card, aberto sem IA, com os valores atuais |
| O11 | Dois nomes iguais ("mude o cliente Silva") | Lista para escolher, sem formulário |
| O12 | Abrir a mesma edição em duas abas; confirmar numa e depois na outra | A segunda é recusada (alteração concorrente) |
| O13 | "exclua o cliente Padaria Sol" | Negado (excluir é só admin) |
| O14 | `/lixeira`: listar | Lista com "Na lixeira" ou "Arquivado"; o botão Restaurar abre um card que pede e-mail e senha de **admin** |
| O15 | Restaurar com a senha do admin **errada** | Recusado; a proposta é encerrada; nada é restaurado |
| O16 | Restaurar com a senha do admin **certa** | Restaurado; a lista recarrega e a linha some; a auditoria registra "autorizado por" o admin |
| O17 | Exportar uma tabela de clientes e abrir no Excel | Acentos corretos (UTF-8 com BOM), separador `;`, CPF mascarado; nome que começa com `=`, `+`, `-` ou `@` aparece com apóstrofo; telefone `+55…` com apóstrofo; número negativo puro **sem** apóstrofo |
| O18 | Exportar 6 vezes em um minuto | Na 6ª: "Muitas exportações em pouco tempo" |

### 3.4 Papel **admin** (`admin@sentinel.local`)

| # | Passo | Resultado esperado |
|---|---|---|
| A1 | Entrar | Menu completo; selo "Administrador" |
| A2 | "cadastre o funcionário Ana, CPF …, salário 4500, admissão hoje" | Card com salário em R$ (só o admin vê); confirmar grava; a resposta e a auditoria mostram CPF mascarado e salário `[redigido]` |
| A3 | "exclua o cliente X" (sem vínculos) | Card com borda de exclusão; confirmar move para a lixeira |
| A4 | "exclua o cliente Y" (com lançamento ou nota) | Recusado já no Revisar, com frase simples ("existem … a este registro") |
| A5 | Arquivar: rodar `php artisan sentinel:arquivar-expirados` com um item vencido (ou reduzir `SENTINEL_RETENCAO_LIXEIRA_DIAS`) | O item passa a "Arquivado" |
| A6 | Lixeira → **Excluir de vez** num arquivado, com senha certa | Removido de vez; a auditoria registra quem autorizou |
| A7 | `/auditoria`: filtrar por período, usuário, ação, resultado e grupo | Lista filtrada, 25 por página, com Anterior e Próxima |
| A8 | Abrir o **detalhe** de uma atualização | Proposto × Confirmado × Anterior, com CPF e salário mascarados; "Tempo da IA" em ms quando veio do agente; "—" quando veio do formulário |
| A9 | Conferir na auditoria as linhas das tentativas negadas de L6, L8 e O13 | Aparecem como "Negado" |
| A10 | Login como `admin.t2@sentinel.local` | Não vê nenhum dado nem registro de auditoria do tenant 1 |

### 3.5 Transversais (qualquer papel com permissão)

| # | Passo | Resultado esperado |
|---|---|---|
| T1 | **Azure fora do ar**: apagar o endpoint do Foundry no `.env` (ou desligar a rede) e recarregar | O agente responde "serviço de IA indisponível"; **Cadastros** continua listando, buscando, cadastrando (Novo → Revisar → segurar), editando e exportando |
| T2 | Telas de erro: `/nao-existe`, sessão vencida (419), limite estourado (429) | Mensagens em português, sem detalhe técnico. O 404 com usuário logado **não** mostra o menu (B4 não feito) |
| T3 | Largura de 375 px (DevTools) em Agente, Cadastros, Lixeira e Auditoria | Nada rola na horizontal; as tabelas rolam dentro do próprio contêiner; botões com pelo menos 44 px |
| T4 | Teclado: Tab até Confirmar e segurar Espaço ou Enter | O segurar funciona por teclado; o foco é visível |
| T5 | Pedir duas ações numa mensagem | Só a primeira é atendida, com aviso |

---

## 4. Material para a documentação 1.1

### 4.1 Fluxos (para virar fluxograma)

**F1. Comando → proposta → Revisar → segurar → confirmar, cancelar ou expirar**
1. O usuário digita o pedido em `/agente` → `POST /agente/comando` (limite de 10/min).
2. `AiAgentService::processar()` monta o prompt de sistema, o histórico curto (`ConversaAgente`) e o catálogo de tools, e chama a Chat Completions API do Foundry. O tempo da chamada é medido.
3. `interpretar()`: a resposta em texto vira mensagem. Se a IA chamou uma tool: argumentos validados contra o esquema, `tenant_id` descartado; tool fora do catálogo é negada e auditada.
4. Se a tool é de escrita: RBAC confere. Sem permissão → "negado" (auditado). Com permissão → resposta `confirmacao_pendente` com o formulário. O controller guarda a proposta crua na sessão como **rascunho** (5 min) e devolve ao front só os valores mascarados e o id `proposta`.
5. O humano edita e clica **Revisar** → `POST /agente/revisar`: RBAC, tenant e regras da tool sem gravar. Com erro, o formulário volta com a frase de cada campo (auditado como `revisao_com_erro`, não final). Sem erro, sai o **token** de segurar.
6. **Segurar** Confirmar por 2 s → `POST /agente/confirmar` com o token: uso único (`Cache::add`), espera mínima de 2 s e validade de 12 s. A tool e os valores vêm da sessão, nunca do corpo.
7. `executarConfirmada()` → `executarTool()`: RBAC de novo, tenant do servidor, executor da tool e auditoria "sucesso" **na mesma transação**.
8. **Cancelar** → `POST /agente/cancelar`: o servidor decide se foi "cancelado" ou "expirado" pelo horário. **Abandono**: o middleware `VarrerConfirmacoesVencidas` audita "expirado" na requisição seguinte. **Sair**: o logout audita o desfecho das propostas abertas.

**F2. Atualizar**
1. Pelo agente ("mude o telefone do cliente X") ou pelo botão **Editar** de uma linha (`POST /agente/formulario` com `tool` + `id`, sem IA).
2. `localizarRegistro()` acha o registro por id ou por nome/número, no tenant. Nenhum → "Não encontrei…". Vários → lista para escolher. Um → formulário com os valores atuais + o que a IA mudou + `versao` (o `updated_at`).
3. Segue o F1 a partir do passo 5. Se o registro mudou depois de o formulário abrir, a gravação é recusada. O "antes" dos campos alterados vai para `valores_anteriores`.

**F3. Lixeira com reautenticação**
1. Excluir (só admin): o card mostra o registro; o Revisar já recusa se houver dependência; a confirmação faz o soft delete.
2. A lixeira lista "Na lixeira" (dentro do prazo) e "Arquivado" (depois do prazo, via `sentinel:arquivar-expirados`).
3. Restaurar (operador; funcionário: admin) ou Excluir de vez (admin): o card pede o e-mail e a senha de **um admin do mesmo tenant**.
4. No `/agente/confirmar`, a ordem é: proposta revisada e no prazo → papel e tenant (`podeExecutar`) → só então a senha (`ReautenticacaoAdmin::autorizar`, com a tentativa contada antes de conferir). Senha errada encerra a proposta. Senha certa → executa e grava `autorizado_por`.

**F4. Consulta e exportar**
1. A consulta (pelo agente ou pelo modo manual) devolve a tabela e um id `exportar`. A tool e os argumentos ficam **na sessão** (`ConsultasExportaveis`, 30 min, últimas 20).
2. Exportar → `POST /agente/exportar` com o id (limite de 5/min). O servidor refaz a consulta com o RBAC, o tenant e a máscara de agora, gera o CSV (`PlanilhaCsv`: BOM, `;`, anti-fórmula) e audita `exportar`.

**F5. Modo manual (sem IA)**
1. `/cadastros` ou `/lixeira` → `POST /agente/consultar` `{tool, filtros}` (30/min).
2. `consultarManual()`: só tool de leitura do catálogo; filtros conferidos contra o esquema da tool; executa pelo mesmo `executarTool()` (origem `manual`).
3. "Novo" e "Editar" → `/agente/formulario` → F1 a partir do passo 5 (origem `formulario`). Nenhum passo chama a IA.

### 4.2 Arquitetura atual

**Camadas:** navegador (Blade + JS puro, só `textContent`) → rotas `web` (`auth`, CSRF, throttle, `VarrerConfirmacoesVencidas`) → controllers finos → services (regras, RBAC, tenant, auditoria) → Eloquent / MySQL. A IA só é acessada por `AiAgentService`.

| Classe | Papel |
|---|---|
| `AiAgentService` | Única porta para a IA. Catálogo de tools (fonte única: o que não está lá não é oferecido nem executável), `processar`/`interpretar`, `executarTool` (RBAC + tenant + transação + auditoria), `revisar`, `abrirFormulario`, `consultarManual`, `exportar`, `auditarDesfecho` |
| `FormularioTool` | Converte as regras do Form Request nos campos do formulário do card (tipo, obrigatório, opções, condições) |
| `ConfirmacoesPendentes` | Estado das propostas na sessão: rascunho (5 min), token (2 a 12 s), uso único atômico (`Cache::add`), vencidas, tempo da IA |
| `ConversaAgente` | Memória curta da conversa (16 mensagens, 8000 caracteres), só texto, isolada por usuário e tenant; leituras e propostas entram como frases fixas, sem dados |
| `AuditoriaService` | Ponto único de gravação em `audit_logs`; máscara LGPD (`cpf`, `salario`, `documento` com 11 dígitos) |
| `DetalhesAuditoria` | Escopo por requisição para campos extras da auditoria (`origem`, `parametros_propostos`, `autorizado_por`, `tempo_ia_ms`, `valores_anteriores`) |
| `ConsultaAuditoria` | Leitura da auditoria: só admin (a negação é auditada), por tenant, filtros, paginação, detalhe mascarado em qualquer nível |
| `ConsultasExportaveis` | Guarda a consulta na sessão; o front só conhece o id |
| `PlanilhaCsv` | Gera o CSV com BOM, `;` e neutralização de fórmulas |
| `ReautenticacaoAdmin` | Confere a senha de um admin do mesmo tenant, com limites atômicos (5 por admin digitado, 10 por usuário, por janela) |
| `ExclusaoSegura` (trait) | Excluir com bloqueio por dependência, restaurar, excluir de vez, motivos em linguagem simples |
| `ContextoUsuario` | Usuário, papel e tenant do usuário logado; falha fechada |
| Services de entidade | `LancamentoService`, `ClienteService`, `FornecedorService`, `FuncionarioService`, `NotaFiscalService`, `CategoriaLancamentoService` (todos com `int $tenantId` obrigatório) |
| `AgenteController` | `/agente/comando`, `revisar`, `confirmar`, `editar`, `cancelar`, `formulario`, `consultar`, `exportar` |
| `AuditoriaController` | JSON da auditoria (`/auditoria/registros`, `/auditoria/registros/{id}`) |
| `TelasController` | Páginas `/cadastros`, `/lixeira`, `/auditoria` (só montam a tela; os dados vêm das rotas JSON) |
| `AutenticacaoController` | Login, logout (audita propostas abertas) |
| `VarrerConfirmacoesVencidas` | Depois de cada requisição autenticada, audita como "expirado" as propostas vencidas |
| Comandos | `sentinel:arquivar-expirados` (RF11), `sentinel:preparar-acesso` (usuários locais) |

### 4.3 Trechos de código representativos

> As linhas se referem ao commit `12dffba`.

**1. Uma tool no catálogo**: `app/Services/AiAgentService.php:1057-1073`
```php
$this->definirTool(
    nome: 'consultar_clientes',
    descricao: 'Consulta clientes cadastrados, opcionalmente filtrando por parte do nome. ...',
    tipo: 'leitura', risco: 'leitura', acao: 'consultar', entidade: 'Cliente',
    papelMinimo: 'leitura',
    propriedades: ['nome' => ['type' => 'string', 'description' => 'Parte do nome do cliente.']],
    obrigatorios: [],
    executar: fn (array $a) => $this->clienteService->buscarPorFiltro($a['nome'] ?? null, $a['tenant_id']),
    mensagemErro: 'Não consegui consultar os clientes agora. Tente novamente.',
    colunas: ['id', 'nome', 'documento', 'email', 'telefone'],
    editavelPor: 'atualizar_cliente',
),
```
Cada tool declara, num só lugar, o que a IA vê (descrição e esquema), quem pode usar (papel mínimo), a classe de risco, as colunas devolvidas e quem a executa. O esquema não tem `tenant_id`: o executor recebe o tenant do servidor.

**2. O Revisar**: `app/Http/Controllers/AgenteController.php:151-188`
```php
$revisados = $this->aiAgentService->aplicarValores($tool, $rascunho['argumentos'], $valores);
$recusa = $this->aiAgentService->revisar($tool, $revisados, ConfirmacoesPendentes::propostos($rascunho), ...);
// ...
return response()->json(['tipo' => 'revisada', 'proposta' => $proposta,
    'token' => $this->pendentes->emitirToken($request->session(), $proposta, $rascunho, $revisados), ...]);
```
O servidor aplica os valores editados sobre a proposta guardada (só os campos do formulário) e valida com RBAC, tenant e as regras da tool, **sem gravar**. Só uma revisão aprovada gera o token de segurar, e a confirmação usa exatamente os valores revisados.

**3. Confirmação: checagens sem segredo antes da senha**: `AgenteController.php:219-255`
```php
if (! $this->pendentes->concluir($proposta)) { return ...409; }          // uso único
if ($decorrido < ConfirmacoesPendentes::ESPERA_MINIMA_S || $this->pendentes->expirou($pendente)) { ... }
if ($this->aiAgentService->exigeReautenticacao($pendente['tool']) && $this->aiAgentService->podeExecutar($pendente['tool'])) {
    $autorizacao = $this->reautenticar($request);
```
Primeiro vem o que não envolve segredo (token de uso único, prazo, papel e tenant). Só depois a senha é conferida. Quem não tem permissão recebe a mesma negação com senha certa ou errada, então o card não serve para testar senhas.

**4. Máscara LGPD**: `app/Services/AuditoriaService.php:18, 98-128`
```php
private const MASCARAS = ['cpf' => 'mascararCpf', 'salario' => 'redigir', 'documento' => 'mascararDocumento'];
public static function mascararCamposSensiveis(array $parametros): array { ... }
// documento: com 11 dígitos é CPF (mascarado); CNPJ passa intacto; não-escalar vira '[redigido]'
```
Uma só máscara serve à auditoria, às respostas de leitura, ao card, ao CSV e ao histórico: CPF vira `***.***.789-01` e salário vira `[redigido]`. Os tipos inesperados também são cobertos.

**5. Auditoria na mesma transação**: `app/Services/AiAgentService.php:470-490`
```php
$argumentos['tenant_id'] = $this->contexto->tenantId();
$resultado = $this->detalhesAuditoria->com([], fn () => DB::transaction(function () use ($tool, $definicao, $argumentos) {
    $retorno = $definicao['executar']($argumentos);
    $this->auditar($tool, $definicao['acao'], $definicao['entidade'], ..., $argumentos, 'sucesso', null, true);
    return $retorno;
}));
```
A escrita e o registro de auditoria são atômicos: se gravar a auditoria falhar, a escrita é desfeita (`AgenteGovernancaTest::test_escrita_e_auditoria_sao_atomicas_se_o_audit_falha_a_escrita_e_desfeita`).

**6. Tenant injetado pelo servidor e regras `exists` escopadas**: `AiAgentService.php:470` e `1589-1605`
```php
return Rule::exists($tabela, $coluna)->where('tenant_id', $tenantId)->whereNull('deleted_at');
```
O tenant nunca vem da IA nem do front: é sobrescrito antes de executar. Toda regra `exists:` do Form Request é reescrita para o tenant atual e para registros fora da lixeira, então uma nota fiscal não pode apontar para o cliente de outra empresa.

**7. RBAC com falha fechada**: `AiAgentService.php:1848-1855`
```php
$hierarquia = (array) config('sentinel.papeis', []);
$atual = array_search($this->contexto->papel(), $hierarquia, true);
$minimo = array_search($papelMinimo, $hierarquia, true);
return $atual !== false && $minimo !== false && $atual >= $minimo;
```
Papel desconhecido ou ausente nega tudo. A checagem roda na proposta, no Revisar e de novo na confirmação.

**8. Anti-fórmula do CSV**: `app/Services/PlanilhaCsv.php:43-65`
```php
if (is_int($valor) || is_float($valor) || preg_match('/^-?\d+(\.\d+)?$/D', $texto) === 1) { return $texto; }
$texto = mb_scrub(strtr($texto, ["\r" => ' ', "\n" => ' ', "\t" => ' ']), 'UTF-8');
$texto = (string) preg_replace('/([,;][\s"\x{00A0}]*)([=+\-@])/u', "\$1'\$2", $texto);
return preg_match('/^[\s"\x{00A0}]*[=+\-@]/u', $texto) === 1 ? "'".$texto : $texto;
```
Célula que começa com `=`, `+`, `-` ou `@` (também depois de separador, espaço ou aspas) ganha apóstrofo, e o Excel não executa fórmula. Número puro passa intacto, por decisão.

**9. Limite atômico de tentativas de senha**: `app/Services/ReautenticacaoAdmin.php:77-90`
```php
$tentativasDoUsuario = RateLimiter::hit($porUsuario, self::JANELA_S);
$tentativasNoAdmin = RateLimiter::hit($porAdmin, self::JANELA_S);
if ($tentativasDoUsuario > self::LIMITE_POR_USUARIO || $tentativasNoAdmin > self::LIMITE_POR_ADMIN) { ... 'limite' }
```
A tentativa é contada **antes** de conferir a senha (incremento atômico), então pedidos em paralelo não escapam do limite. O e-mail entra na chave só como HMAC, e um acerto devolve a tentativa.

**10. Uso único do token**: `app/Services/ConfirmacoesPendentes.php:226-229`
```php
return Cache::add("agente:token_usado:{$proposta}", true, now()->addMinutes(...));
```
`Cache::add` só grava se a chave não existe. Assim, confirmar e cancelar ao mesmo tempo resultam em um único desfecho (`ConfirmacaoDesfechoTest::test_m1_confirmar_e_cancelar_simultaneos_so_o_primeiro_desfecho_vale`).

**11. Bloqueio por dependência (RF08)**: `app/Services/Concerns/ExclusaoSegura.php:42-59, 181-192`
```php
if ($registro->{$relacao}()->exists()) {
    return "Não é possível excluir: existem {$rotulo} a este registro.";
}
```
A exclusão confere as relações que bloqueiam e devolve o motivo em linguagem simples. Sem dependência, faz soft delete e limpa uma marca de arquivado que tenha sobrado.

**12. Tempo da IA (RNF04)**: `app/Services/AiAgentService.php:148-150, 182-185`
```php
$inicio = hrtime(true);
$decorrido = fn () => (int) round((hrtime(true) - $inicio) / 1e6);
// ...
return $this->detalhesAuditoria->com(['tempo_ia_ms' => $tempoIaMs], fn () => $this->interpretar($resposta, $tempoIaMs));
```
A chamada ao provedor é cronometrada. Tudo o que a resposta auditar leva o tempo, e as propostas carregam o tempo até o desfecho. Resposta em texto e falhas registram só o tempo no log.

### 4.4 Segurança: ameaça → proteção → onde → teste

| Ameaça | Proteção | Onde | Teste que prova |
|---|---|---|---|
| Injeção de prompt / tool inventada | Whitelist estática; esquema `additionalProperties: false`; chave fora do esquema → resposta fora do formato; tool fora do catálogo negada e auditada só com nomes de argumentos | `AiAgentService::interpretar()`, `executarTool()` | `AgenteGovernancaTest::test_tool_inventada_pelo_modelo_nao_executa_nada`, `AgenteCorrecoesTest::test_argumentos_invalidos_nao_executam_tool`, `ValorAnteriorTest::test_tool_inventada_com_cpf_e_salario_grava_so_os_nomes_dos_argumentos` |
| IDOR / outro tenant | Tenant do servidor; services com `int $tenantId`; `exists` escopado; outro tenant = inexistente | `ContextoUsuario`, services, `escoparPorTenant()` | `AgenteGovernancaTest::test_tenant_enviado_pelo_modelo_e_ignorado`, `AtualizarTest::test_registro_de_outro_tenant_ou_na_lixeira_responde_igual_a_inexistente`, `AuditoriaConsultaTest::test_registro_de_outro_tenant_nao_aparece_na_lista_e_o_detalhe_e_404`, `GovernancaTest::test_service_com_o_tenant_de_outra_empresa_nao_encontra_nem_exclui` |
| Escalada de papel | RBAC por tool na proposta, no Revisar e na confirmação; falha fechada | `papelAtualPermite()` | `AgenteGovernancaTest::test_rbac_e_rechecado_na_confirmacao`, `FormularioAgenteTest::test_rbac_e_reaplicado_no_revisar_e_de_novo_na_confirmacao`, `ReautenticacaoCardTest::test_usuario_rebaixado_recebe_o_mesmo_negado_com_senha_certa_ou_errada_sem_gastar_tentativa` |
| Força bruta de senha | Login: 5/min por e-mail + IP; card: 5 por admin e 10 por usuário, contados antes de conferir | `AppServiceProvider` (`login`), `ReautenticacaoAdmin` | `AutenticacaoTest::test_login_bloqueia_a_sexta_tentativa_no_mesmo_minuto`, `ReautenticacaoCardTest::test_limite_de_5_erros_por_usuario_e_e_mail_do_admin`, `ReautenticacaoCardTest::test_a_tentativa_conta_antes_de_conferir_a_senha` |
| XSS | Blade só com `{{ }}`; JS só `createElement`/`textContent`; a IA nunca gera HTML | views e `partials/bancada.blade.php` | `LoginV4Test::test_erros_e_email_sao_escapados_e_senha_nao_e_reexibida`, `TelasTest::test_auditoria_so_admin_nega_auditando_e_lista_so_usuarios_do_tenant` (nome com `<b>` escapado). O JS: **sem teste automático** (revisão de código) |
| CSRF | Middleware padrão do grupo `web`; `X-CSRF-TOKEN` em todo `fetch`; 419 em português | `layouts/app.blade.php`, rotas `web` | O Laravel desliga o CSRF nos testes: **sem teste automático** do bloqueio. `LoginV4Test::test_layout_envia_credenciais_ao_login_real_com_csrf` prova o envio do token. Verificar na Fase 6 (sessão vencida → 419) |
| Injeção de fórmula (CSV) | Apóstrofo em `=`, `+`, `-`, `@`, também depois de separador ou aspas | `PlanilhaCsv::celula()` | `ExportarCsvTest::test_celula_que_comeca_com_formula_ganha_apostrofo`, `ExportarCsvTest::test_celula_regras_de_numero_lista_e_nulo` |
| Vazamento de dado pessoal (log, auditoria, CSV, front, IA) | Máscara única; logs só com metadados; senha só na requisição; histórico só com frases fixas | `AuditoriaService`, `ConversaAgente`, `falha()` | `AuditoriaMascaraTest` (15), `ReautenticacaoCardTest::test_a_senha_nao_aparece_na_resposta_log_auditoria_sessao_historico_cache_nem_na_ia`, `ExportarCsvTest::test_funcionarios_sai_sem_cpf_nem_salario`, `TempoIaTest::test_resposta_em_texto_vai_so_para_o_log_sem_conteudo` |
| Corrida de confirmação | Token de uso único com `Cache::add`; um desfecho por proposta | `ConfirmacoesPendentes::concluir()` | `ConfirmacaoDesfechoTest::test_m1_confirmar_e_cancelar_simultaneos_so_o_primeiro_desfecho_vale`, `AgenteTest::test_rnf02_token_so_vale_uma_vez` |
| Exclusão acidental | Revisar + segurar; bloqueio por dependência; lixeira com retenção; arquivado; exclusão física só manual com senha de admin | `ExclusaoSegura`, `excluir_*` | `GovernancaTest::test_rf09_*`, `GovernancaTest::test_rf11_rf12_*`, `LixeiraTest::test_excluir_e_so_para_admin` |

### 4.5 O agente

- **Catálogo:** `AiAgentService::catalogo()` monta 37 tools a partir de `definirTool`, `definirEscrita`, `definirAtualizacao` e `definirLixeira`:
  - 7 `consultar_*`, incluindo a lixeira;
  - 6 `criar_*`;
  - 6 `atualizar_*`, incluindo o status do lançamento;
  - 6 de cada: `excluir_*`, `restaurar_*` e `excluir_definitivo_*`.

  `tools()` deriva do catálogo exatamente o que vai para a IA (nome, descrição, esquema JSON).
- **O que vai para a IA:** o prompt de sistema (papel do assistente, data de hoje, regras de não inventar dados nem ações e de nunca pedir senha no chat), o histórico curto, a mensagem do usuário e as definições das tools.
- **O que nunca vai para a IA:** linhas de tabela, CPF, salário, senha, tenant, papel, ids de proposta e token, resultados de consulta. A IA recebe só a frase fixa "A consulta foi concluída" ou a frase de sucesso da escrita.
- **Memória (`ConversaAgente`):** guardada na sessão, até 16 mensagens e 8000 caracteres, ligada a usuário e tenant (se mudarem, a memória é apagada) e limpa no logout. Erros e mensagens com termos de credencial são omitidos; CPF e CNPJ viram "[documento omitido]"; o valor depois de "salário" ou "remuneração" vira "[valor omitido]" (`ConversaAgente::sanitizar()`).
- **Erros do provedor:** classificados no contrato `tipo: erro` + `categoria`/`codigo`/`correlation_id`/`http_status`: configuração ausente (503), conexão (503), timeout (504), HTTP 401/403/429/5xx (502/503/504), resposta fora do formato, truncada ou filtrada (502). O log tem só metadados, nunca o corpo da resposta. Só o primeiro de vários pedidos é atendido, com aviso.
- **Tempo de resposta (RNF04):** medido com `hrtime` em volta da chamada HTTP e gravado em `audit_logs.tempo_ia_ms` na linha do desfecho. O detalhe da auditoria mostra "Tempo da IA". O timeout HTTP é de 30 s.

### 4.6 Modelo de dados

**Tabelas de domínio** (todas com `tenant_id` **sem FK**, débito documentado):

| Tabela | Colunas relevantes |
|---|---|
| `lancamentos` | `descricao`, `valor` (10,2), `status` (pendente/conciliado), `data`, `categoria_lancamento_id`, `cliente_id`, `fornecedor_id`, `funcionario_id`, `arquivado_em`, `deleted_at` |
| `categorias_lancamento` | `nome`, `tipo` (receita/despesa), `arquivado_em`, `deleted_at` |
| `clientes` / `fornecedores` | `nome`, `documento` (CPF ou CNPJ), `email`, `telefone`, `arquivado_em`, `deleted_at` |
| `funcionarios` | `nome`, `cpf`, `cargo`, `salario` (10,2), `data_admissao`, `data_demissao`, `arquivado_em`, `deleted_at` |
| `notas_fiscais` | `numero`, `tipo` (entrada/saida), `valor`, `data_emissao`, `cliente_id`, `fornecedor_id`, `lancamento_id` (FKs com `restrictOnDelete`), `arquivado_em`, `deleted_at` |
| `users` | `name`, `email`, `password`, `papel`, `tenant_id` |

**`audit_logs`:**

| Coluna | Conteúdo |
|---|---|
| `user_id` | Quem estava logado |
| `autorizado_por` | Admin que digitou a senha (RF10) |
| `tool` | Nome da tool (ou `[tool redigida]`) |
| `origem` | `agente`, `formulario` ou `manual` |
| `acao` | `consultar`, `criar`, `atualizar`, `excluir`, `restaurar`, `excluir_definitivo`, `exportar`, `desconhecida` |
| `entidade_tipo`, `entidade_id` | Registro afetado |
| `parametros` | O que foi executado (mascarado) |
| `parametros_propostos` | O que a IA propôs (mascarado; `[]` quando veio sem IA) |
| `valores_anteriores` | O "antes" dos campos alterados (mascarado) |
| `resultado` | `sucesso`, `erro`, `recusado`, `negado`, `cancelado`, `expirado` (finais) e `revisao_com_erro` (não final) |
| `mensagem` | Frase do desfecho (até 250 caracteres) |
| `tempo_ia_ms` | Tempo da chamada à IA |
| `papel`, `permitido` | Papel no momento e se foi permitido |
| `tenant_id`, `created_at` | Tenant e data |

**Migrations, em ordem:**
1. `0001_01_01_000000_create_users_table`
2. `0001_01_01_000001_create_cache_table`
3. `0001_01_01_000002_create_jobs_table`
4. `2026_09_08_173654_create_lancamentos_table`
5. `2026_09_09_222222_create_categorias_lancamento_table`
6. `2026_09_09_222224_create_clientes_table`
7. `2026_09_09_222226_create_fornecedores_table`
8. `2026_09_09_222228_create_funcionarios_table`
9. `2026_09_09_222230_create_notas_fiscais_table`
10. `2026_09_09_222232_add_relacionamentos_e_governanca_to_lancamentos_table`
11. `2026_09_09_222234_create_audit_logs_table`
12. `2026_09_21_180000_add_papel_e_permitido_to_audit_logs_table`
13. `2026_09_23_180000_add_papel_e_tenant_id_to_users_table`
14. `2026_10_19_120000_add_proposto_autorizacao_tempo_e_origem_to_audit_logs_table`
15. `2026_10_22_120000_add_valores_anteriores_to_audit_logs_table`

---

## 5. Limitações e riscos aceitos

| # | Limitação | Impacto / por que foi aceita |
|---|---|---|
| 1 | Campo sensível opcional (ex.: CPF no documento) não pode ser apagado pelo formulário: em branco mantém o valor anterior | Evita apagar dado sem querer; para limpar, é preciso outro caminho |
| 2 | Campo sensível com erro de validação volta em branco no formulário | O valor digitado não é devolvido ao front (LGPD) |
| 3 | No histórico da conversa, o salário só é escondido quando vem logo depois de "salário" ou "remuneração" (até 30 caracteres): "salário entre 3000 e 4500" esconde só o primeiro valor, e "ganha 4500" não esconde nada | O histórico fica só na sessão do próprio usuário e vai para a IA nas mensagens seguintes; CPF e CNPJ já viram "[documento omitido]" (`ConversaAgente::sanitizar()`) |
| 4 | Corrida de sessão: duas abas gravando a sessão ao mesmo tempo podem perder uma proposta ou um id de exportação | Efeito: "proposta indisponível" ou "faça a consulta de novo"; nada é gravado indevidamente |
| 5 | O 404 de usuário logado não mostra o menu (B4 não feito) | Só navegação; o link para o agente aparece |
| 6 | A lista de escolha (vários registros com o mesmo nome) não é auditada | Não grava nada; a ação escolhida depois é auditada |
| 7 | Recusado e erro de atualização sem `entidade_id` | O id aparece nos parâmetros; falta só na coluna |
| 8 | Senha de admin errada encerra a proposta | É preciso pedir de novo; reduz tentativas por proposta |
| 9 | `restaurarDaLixeira(..., bool $reautenticadoComoAdmin)` recebe um bool | O bool só é verdadeiro depois de `ReautenticacaoAdmin` no mesmo request; o executor também confere `autorizadoPor()` |
| 10 | `lockForUpdate` não tem efeito no SQLite dos testes | No MySQL a trava vale; nos testes ela não é exercitada (o uso único do token, com `Cache::add`, é testado) |
| 11 | CNPJ (12 ou mais dígitos) sem máscara | É dado público de empresa |
| 12 | O filtro "nome" da consulta fica sem máscara na auditoria | Um nome não é dado sensível pela regra atual; um CPF digitado no filtro apareceria |
| 13 | Número negativo puro sem apóstrofo no CSV | Decisão: `-150.00` é número, não fórmula |
| 14 | Telefone "+55…" ganha apóstrofo no CSV | Efeito colateral seguro do anti-fórmula |
| 15 | Modo manual: tool fora do catálogo recebe 422 sem linha na auditoria | Não executa nada; só não deixa rastro (o limite de 30/min continua) |
| 16 | `/auditoria` com o limite estourado responde em JSON cru | Só com mais de 60 pedidos por minuto do mesmo admin |
| 17 | Os campos de filtro de Cadastros e Lixeira herdam a largura dos campos do card | Só layout |
| 18 | RF03 (ações em massa) não existe | Toda escrita é unitária e confirmada; ação em massa fica para depois |
| 19 | RNF04: o limite de 10 s é medido, não imposto | Timeout de 30 s; acompanhar pelo `tempo_ia_ms` |
| 20 | RF11 depende do Agendador do Windows chamar `schedule:run` | Sem ele, o arquivamento só roda à mão |
| 21 | `tenant_id` sem FK em todas as tabelas de domínio e em `users` | Não existe tabela de tenants (débito do `CLAUDE.md`) |

---

## 6. Ambiente e segurança de dependências

Coletado em 26/10/2026 na máquina de desenvolvimento. Nada foi instalado nem atualizado.

| Item | Resultado | Situação |
|---|---|---|
| `php -v` | **PHP 8.4.25** (cli, ZTS, x64) | **Abaixo do mínimo.** É preciso 8.4.27 ou mais por causa das falhas críticas de 21/10 (CVE-2026-109618 / CVE-2026-109619). Atualizar o PHP nas máquinas do lab e na da apresentação |
| `session.upload_progress.enabled` | `1` (ligado) | Recomendado `session.upload_progress.enabled=0` no `php.ini` |
| `APP_DEBUG` (`.env` local) | `true` | Na apresentação: `false` |
| `SESSION_ENCRYPT` (`.env` local) | `false` | Na apresentação: `true` |
| `APP_KEY` | Existe | OK |

**`composer audit`:** 3 avisos, nenhum pacote abandonado.

| Pacote | Gravidade | Identificador | Título | Exposição no Sentinel |
|---|---|---|---|---|
| `league/commonmark` | alta | sem CVE (GHSA-3q6v-r5mr-hxv8) | DoS de tempo quadrático na extensão de tabelas GFM | O Sentinel não renderiza Markdown de usuário; dependência indireta do Laravel |
| `league/commonmark` | média | sem CVE | `DisallowedRawHtml` contornado quando a tag proibida encerra o literal HTML | Idem |
| `symfony/http-foundation` | (não informada) | CVE-2026-108082 | ReDoS em `HeaderUtils::split()` pelo cabeçalho `Cache-Control` | Afeta qualquer app Laravel exposto; num protótipo local o risco é baixo, mas deve ser corrigido |

**Recomendação (para o Lucas decidir, fora do congelamento de funcionalidades):** `composer update league/commonmark symfony/http-foundation` numa branch, rodar a suíte e commitar o `composer.lock`. Não foi executado aqui.

---

## 7. Texto pronto para colar

### 7.1 `CLAUDE.md`

**Substituir o item "`criar_lancamento` não passa por `validar()`"** por:

> - ~~`criar_lancamento` não passa por `validar()`~~ **Resolvido:** `criar_lancamento` valida com `LancamentoRequest` via `validar()`, como as demais tools de escrita (falha de validação → frase por campo no formulário). Evidência: `CriarLancamentoToolTest`.

**Substituir o parágrafo "RF10 (…)" a partir de "Pendente para a Fase 4"** por:

> A reautenticação está exposta: `restaurar_*` e `excluir_definitivo_*` pedem o e-mail e a senha de um admin do mesmo tenant **no card de confirmação** (nunca no chat). O `/agente/confirmar` confere, nesta ordem, a proposta revisada e no prazo, o papel e o tenant (`podeExecutar`) e só então a senha (`ReautenticacaoAdmin::autorizar`, com limites atômicos de 5 por admin e 10 por usuário). O id do admin vai para `audit_logs.autorizado_por`. `restaurarDaLixeira(..., $reautenticadoComoAdmin)` é chamado pela tool `restaurar_*` com `autorizadoPor() !== null`.

**Substituir a contagem de migrations** ("As 13 migrations já foram compiladas…") por:

> Migrations atuais (15): as 3 do framework (`users`, `cache`, `jobs`); `create_lancamentos`; `create_categorias_lancamento`; `create_clientes`; `create_fornecedores`; `create_funcionarios`; `create_notas_fiscais`; `add_relacionamentos_e_governanca_to_lancamentos`; `create_audit_logs`; `add_papel_e_permitido_to_audit_logs`; `add_papel_e_tenant_id_to_users`; `add_proposto_autorizacao_tempo_e_origem_to_audit_logs` (19/10); `add_valores_anteriores_to_audit_logs` (22/10). As duas últimas ainda não foram compiladas com o grammar `pgsql` em `pretend`: fazer isso na migração para o Supabase.

**Acrescentar uma seção "Fluxos implementados (Fases 4 e 5)":**

> ## Fluxos implementados (Fases 4 e 5)
> - **Escrita (Revisar → segurar):** `/agente/comando` → proposta em rascunho na sessão (5 min) → `/agente/revisar` (RBAC, tenant e regras, sem gravar; erro volta por campo) → token → segurar 2 s → `/agente/confirmar` (uso único, 2 a 12 s, valores da sessão) → `executarTool` (transação + auditoria). Cancelar, expirar e sair auditam o desfecho (`ConfirmacoesPendentes`, `VarrerConfirmacoesVencidas`).
> - **Atualizar:** `atualizar_*` acha o registro no servidor (id ou nome, no tenant); o formulário vem com os valores atuais e a `versao`; alteração concorrente é recusada; o "antes" vai para `valores_anteriores`. O botão Editar abre o mesmo card por `/agente/formulario` (sem IA).
> - **Lixeira:** `excluir_*` (admin, bloqueio por dependência), `restaurar_*` e `excluir_definitivo_*` (senha de admin no card), `consultar_lixeira`; arquivamento por `sentinel:arquivar-expirados`.
> - **Auditoria:** `/auditoria` (tela) e `/auditoria/registros[/{id}]` (JSON): só admin (a negação é auditada), por tenant, filtros, 25 por página, detalhe proposto × confirmado × anterior mascarado e tempo da IA.
> - **Exportar:** `/agente/exportar` com o id da consulta guardada na sessão; o servidor refaz a consulta (RBAC, tenant, máscara), gera o CSV (BOM, `;`, anti-fórmula) e audita `exportar`. Limite de 5/min. A tabela da auditoria não é exportável.
> - **Modo manual (sem IA):** `/cadastros` e `/lixeira` usam `/agente/consultar` (só tools de leitura, filtros do esquema, origem `manual`) e `/agente/formulario` (origem `formulario`). Funciona com a Azure fora do ar.
> - **Tempo da IA (RNF04):** `audit_logs.tempo_ia_ms` em cada desfecho de algo vindo da IA; resposta em texto e falhas só no log.

### 7.2 Notion (projeto SENTINEL; só acrescentar, nunca apagar)

> **Construção encerrada — 26/10/2026 (`main` em `12dffba`, 398 testes)**
> - [x] Bloco 1: desfechos auditados (cancelar, expirar, sair)
> - [x] Bloco 2 / 2b / 2c: formulário no card, Revisar → segurar, casca e telas de erro
> - [x] Bloco 3: atualizar pelo agente e Editar sem IA
> - [x] Bloco 4: lixeira com senha de admin e limites atômicos
> - [x] Etapa A: consulta da auditoria, valor anterior, exportar CSV
> - [x] Etapa B: modo manual sem IA, telas Cadastros, Lixeira e Auditoria, botão Exportar, tempo da IA
> - [ ] B4: 404 logado com menu (não feito; limitação aceita)
> - [ ] PHP ≥ 8.4.27 e `session.upload_progress.enabled=0` em todas as máquinas
> - [ ] `composer update` de `league/commonmark` e `symfony/http-foundation` (decidir)
> - [ ] Máquina da apresentação: `APP_DEBUG=false`, `SESSION_ENCRYPT=true`, `php artisan migrate`, `npm run build`
> - [ ] Testes manuais da Fase 6 (03–06/11) pelo roteiro de `RELATORIO_FINAL_FASES_4_5.md`
> - [ ] Documentação 1.1
>
> Relatório completo: `RELATORIO_FINAL_FASES_4_5.md` na raiz do repositório.
