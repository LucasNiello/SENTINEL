# Correção pós-fumaça

Data: 03/11/2026 (America/Sao_Paulo).
Escopo: somente F7b, REG-SMOKE-01, F7 válido e regressão automatizada. Nenhuma repetição completa F1–F10. Zero chamadas Azure reais.

## Base

Branch: malaman.
HEAD inicial: 59104c33cbb11172bd2ab6518734c9f0f15691ce.
MERGE_HEAD inicial: 6f6083c860bd6cdab5b4eb8c9a026b7d0116015c.
Merge-base: ea97481fad26a784c7551106d09d8cb624bc88c8.
Origem do merge: integração de origin/main em malaman por pull origin main, comprovada pelo reflog de origin/main em 03/11/2026 15:42:11 -0300, pela igualdade entre origin/main e MERGE_HEAD e pela mensagem pendente em .git/MERGE_MSG.

O reflog de HEAD às 13:24:32 aponta para outro merge anterior (59104c3); não foi confundido com a integração pendente. O reflog de HEAD não registra uma conclusão para o merge em conflito. MERGE_HEAD foi criado antes desta tarefa. Autor do commit de origem: Lucas Terminiello; mensagem: “Auditoria: papel antes dos filtros; teto de 5.000 no CSV; Editar pede as credenciais de novo”. Os commits 9552000 e 6f6083c correspondem ao conteúdo que já estava sendo integrado.

Evidências e backups novos: C:/Temp/SENTINEL_FIX_SMOKE/20261103_160502.
Histórico preservado sem sobrescrita: C:/Temp/SENTINEL_SMOKE_F1_F10/20261103_154926.

Antes de editar foram executados branch, rev-parse HEAD, status completo/curto, diff --stat, diff --check, rev-parse MERGE_HEAD, show MERGE_HEAD, lista U, reflog -20, merge-base e log --graph --all -30. Os arquivos em conflito e os três estágios foram copiados para a pasta externa. Não houve reset, clean, restore, checkout global ou abort.

## F7b

Problema: Editar após Revisar apagava somente a senha administrativa; e-mail permanecia preenchido.
Causa raiz: voltarAoFormulario mantinha adminEmail.value, embora limpasse adminSenha.value.
Arquivo: resources/views/partials/bancada.blade.php, função voltarAoFormulario.
Correção de produto: acrescentada uma linha, adminEmail.value = '', imediatamente antes da limpeza de adminSenha.value.

Antes: senha vazia e e-mail retido.
Depois: ambos os inputs vazios, formulário editável e hold oculto.

Inspeção de estados: adminEmail/adminSenha são referências aos inputs do card dentro da closure, não cópias dos valores. Não foram encontradas cópias de credenciais em dataset, objetos de valores do formulário, hidden inputs ou localStorage/sessionStorage. O corpo de Revisar contém proposta/valores do formulário, sem credenciais administrativas. A confirmação monta o corpo com os valores atuais dos inputs e limpa a senha imediatamente. Editar já zera token e invalida o token anterior por /agente/editar; esse mecanismo e o backend foram preservados. Não foi alterado RBAC, tenant, hold, autorizado_por ou exigência de autorização administrativa.

### Teste manual F7b

Ambiente efetivo novamente confirmado local: APP_ENV=local, banco sentinel em 127.0.0.1. Conta qa574e359-operador@sentinel.local, user_id 6, papel operador, tenant 1. Admin autorizador: user_id 1, tenant 1. Nenhuma senha foi alterada nesta tarefa.

Fixture: Fumaça Teste 2, cliente 22, tenant 1, inicialmente na Lixeira.
Fluxo real no Microsoft Edge via Playwright já disponível: /lixeira → Restaurar → credenciais admin → Revisar → Editar.
Revisar HTTP 200; Editar HTTP 200.

Confirmações programáticas após Editar:

- email input value == "": verdadeiro;
- password input value == "": verdadeiro;
- Revisar sem redigitar nada: “Informe o e-mail e a senha de um administrador.”;
- novas requisições a /agente/revisar: 0;
- controles hold visíveis: 0;
- cliente 22 continua com deleted_at preenchido no checkpoint database/F7b_bloqueado.json;
- zero erros de página; zero comandos ao agente/Azure.

Evidências novas: screenshots/F7b_antes_editar.png, F7b_depois_editar.png e F7b_sem_credenciais.png. O campo de senha foi integralmente coberto pela captura, inclusive antes de Editar; não foram gravados o valor nem sua representação visual. Capturas históricas anteriores não foram alteradas. logs/browser-results.json guarda apenas booleanos e dados necessários; logs/browser-network.json guarda método/caminho/status, sem headers, cookies ou corpos de credenciais.

Resultado F7b: OK.

### Reteste F7 válido

Após comprovar no banco que F7b não restaurou, foi usada a mesma fixture descartável 22 para o fluxo válido. Novas credenciais foram preenchidas nos campos vazios, Revisar recebeu 200, Space foi mantido por 2,2 s e a confirmação recebeu 200/resultado_escrita: “Cliente restaurado da lixeira.”.

Cliente 22 terminou ativo, deleted_at e arquivado_em nulos. Auditoria 332: restaurar_cliente, sucesso, executor user_id 6, autorizado_por 1, tenant 1, origem formulario, tempo_ia_ms nulo corretamente porque o fluxo não usa IA. Evidência screenshots/F7_restauracao_valida.png e database/F7_restaurado.json. Nenhum cliente real foi reutilizado, nenhum registro novo precisou ser criado e nenhuma exclusão foi necessária. Sessão encerrada.

Resultado F7 normal: OK. Estado final da fixture 22 é ATIVO em razão desse reteste válido posterior; o checkpoint de F7b preserva a prova de que ela permanecia excluída quando faltavam credenciais.

## REG-SMOKE-01

Conflito encontrado: somente tests/Feature/FormularioAgenteTest.php, no teste test_tela_monta_o_card_como_formulario_sem_json_nem_nome_de_tool.

BASE (:1): exigia o literal de CSS inline .campo[hidden] { display: none; }.
OURS (:2): trocou essa verificação por class="card-autorizacao" hidden, em razão do redesign que moveu CSS para resources/css/workspace.css.
THEIRS (:3): acrescentou assertDontSee da instrução genérica incorreta ao retornar para edição e assertSee do anúncio acessível que pede novamente credenciais; ainda continha a referência antiga ao CSS inline.

Nenhum lado adicionou métodos novos nesse arquivo em relação à base: as diferenças estavam nas assertions do mesmo teste. Não foi utilizado --ours/--theirs. A resolução conservou a verificação de autorização hidden de OURS e as duas verificações de instrução/acessibilidade de THEIRS. A cobertura de ocultação da BASE/THEIRS foi adaptada para conferir a regra real [hidden] {display:none!important} no arquivo de CSS atual, sem reintroduzir CSS inline desatualizado. Restante dos testes mantido.

Decisão de merge: origem legítima confirmada; integrar correções já pendentes de main e preservar os elementos do redesign em malaman. A comparação direta HEAD versus MERGE_HEAD mostra diferenças históricas de redesign que não são uma lista de remoções a aplicar. A revisão do índice final confirmou que esses arquivos de UI/fontes não foram apagados.

Arquivos resolvidos: tests/Feature/FormularioAgenteTest.php, marcado com git add após php -l.
php -l: No syntax errors detected.
Nenhum outro caminho U permaneceu.

A busca explícita git grep -n "<<<<<<<\|=======\|>>>>>>>" retornou apenas separadores legítimos em instalar.bat:404,406 e comentários de FuncionarioNotaFiscalToolsTest.php:69,197,344. Nenhum marcador de conflito remanescente. Tanto git diff --check quanto git diff --cached --check terminaram com exit 0.

## Testes direcionados

| Comando/grupo | Testes | Assertions | Resultado | Tempo |
| --- | ---: | ---: | --- | ---: |
| php artisan test --compact tests/Feature/FormularioAgenteTest.php | 36 | 394 | OK | 3,090 s |
| ReautenticacaoCardTest, LixeiraTest, ConfirmacaoDesfechoTest, PermissaoExclusaoLogicaTest, GovernancaTest | 87 | 699 | OK | 10,067 s |

Novo teste backend em ReautenticacaoCardTest: test_editar_invalida_token_de_restauracao_e_nova_revisao_nao_reutiliza_credenciais. Verifica que o token antigo rejeita confirmação mesmo com credenciais válidas, que uma nova revisão produz token diferente e que confirmar sem credenciais recebe 403/AI_REAUTH_REQUIRED, sem restauração. Verifica também ausência da senha na sessão/auditoria. Usa SQLite isolado e não Azure real.

A limpeza dos inputs é comportamento frontend: validada com browser real, sem instalar framework JS. Testes backend existentes de invalidação do token e reautenticação complementados pelo novo caso específico de restauração.

Durante a autoria do teste novo houve duas execuções direcionadas com falha de expectativa no próprio teste: primeiro esperava campo codigo na resposta do token inválido, cujo contrato existente usa mensagem; depois esperava AI_REAUTH_FAILED em vez de AI_REAUTH_REQUIRED para credenciais ausentes. Ambas corrigidas no teste com base nas respostas/contrato existentes; nenhum backend foi modificado para satisfazê-lo. Logs intermediários preservados em logs/targeted-first.txt e targeted-second.txt. Resultado final acima corresponde ao teste ajustado e à implementação validada.

## Regressão completa

Comando: php artisan test --compact, somente após testes direcionados verdes.

Testes: 471.
Assertions: 4315.
Failures: 0.
Errors: 0.
Skipped: 0 (471/471 passaram).
Tempo: 31,543 s.
Exit: 0.
Evidência: logs/full-suite.txt, saída compacta JSON PHPUnit.

A configuração phpunit.xml e tests/bootstrap.php força APP_ENV=testing, SQLite :memory: e não utiliza o cache local MySQL. Não houve reconstrução do banco local. Consultas de leitura depois da suíte confirmaram a fixture e a auditoria preservadas. Não foram repetidos F1/F2/F3/F4/F6a/F6/F8/F9/F10 nem criada a massa de 26 lançamentos de F5.

## Build e dependências

| Verificação | Resultado |
| --- | --- |
| composer check-platform-reqs | OK, exit 0 |
| composer audit | 0 vulnerabilidades, exit 0 |
| npm audit | 0 vulnerabilidades, exit 0 |
| npm run build | OK, 2,09 s, exit 0 |
| php artisan migrate:status | 16 aplicadas, 0 pendentes, exit 0 |
| git diff --check | OK, exit 0 |
| git diff --cached --check | OK, exit 0 |

Nenhuma dependência instalada/atualizada e nenhuma migration executada.

## Git

Status inicial: sete arquivos já staged e um conflito UU:

- app/Http/Controllers/AuditoriaController.php;
- app/Services/AiAgentService.php;
- app/Services/AuditoriaService.php;
- app/Services/ConsultaAuditoria.php;
- resources/views/partials/bancada.blade.php;
- tests/Feature/ExportarAuditoriaTest.php;
- tests/Feature/RoteamentoIntencaoTest.php;
- tests/Feature/FormularioAgenteTest.php (UU).

As alterações anteriores de auditoria/intenção/teto CSV pertenciam aos commits de main em integração; foram revisadas e preservadas, não reimplementadas como escopo novo. Mudanças acrescentadas nesta tarefa: uma linha na bancada; resolução das assertions do conflito; um caso backend em ReautenticacaoCardTest.

Antes de concluir: índice revisado com nove arquivos pertinentes, nenhum .env, senha local, log, screenshot, CSV, backup, node_modules, vendor ou relatório. Scan externo conferiu ausência da senha local conhecida no conteúdo staged e ausência de marcadores de conflito. git diff --cached revisado e --check aprovado.

Merge concluído: SIM, por git -c core.editor=true merge --continue, preservando a mensagem já existente do Git.
Mensagem: Merge branch 'main' of https://github.com/LucasNiello/SENTINEL into malaman.
Commit local de merge: 19e39b4d4e71b281bb81a07899a85979ab9c0b3b.
Pais: 59104c33cbb11172bd2ab6518734c9f0f15691ce e 6f6083c860bd6cdab5b4eb8c9a026b7d0116015c.
MERGE_HEAD final: ausente.
Status imediatamente após merge: limpo.
Status final da entrega: somente este relatório não rastreado no projeto, produzido depois do commit; cópia externa mantida junto das evidências.
Push: NÃO EXECUTADO.

## Notion

Estrutura existente SENTINEL → Homologação 574e359 → Divergências e Ajustes, sem nova raiz/banco/registro duplicado.

F7b: RETESTADO, causa/correção/resultado e evidências atualizados no registro existente:
https://app.notion.com/p/40d877fb3070811ca3c1d0027bd6fbf6

REG-SMOKE-01: RETESTADO, origem real, três estágios, resolução, testes, commit e evidências atualizados no registro existente:
https://app.notion.com/p/40d877fb3070811fbc4df5d94a4cbb65

Homologação: seção “Correção pós-fumaça F1–F10” adicionada sem apagar o histórico da rodada anterior:
https://app.notion.com/p/407877fb307081b1841dfc2f59d6b4d8

## Conclusão

F7b: OK / CORRIGIDO.
CONFLITO GIT: RESOLVIDO.
REGRESSÃO: OK, 471 testes/4315 assertions/0 failures/0 errors.
SISTEMA PRONTO PARA NOVA RODADA: SIM, dentro do escopo validado.
PUSH: NÃO EXECUTADO. Resultado disponível para revisão local.
