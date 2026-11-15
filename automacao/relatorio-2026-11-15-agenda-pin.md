# Agenda no chat, PIN pelo dono, pontas soltas e roteiro — 15/11/2026 (tarde)

Branch `automacao-na-madruga`. Nada foi commitado nem tocou o histórico. O `.env` e o Notion não foram tocados, e a chave não foi lida nem impressa. A IA real rodou só em `database/bateria.sqlite`. Decisões: `.claude/decisoes/2026-11-15.md` (seções "Bloco A" a "Bloco C").

## O que foi feito

### A — Agenda no chat segue a tela `/agenda`
- Fonte única no catálogo: `PAPEIS_DA_AGENDA` (atendente, recepção, veterinário, dono) e `setoresDaAgenda()`. `ClinicaTelasController::setoresDaAgenda` chama o do catálogo.
- O vendedor saiu de `consultar_agenda`: 403 no chat e na tela, com a negação auditada.
- Para o atendente, `consultar_agenda` fica presa no banho e tosa, qualquer setor pedido. Agendar e mudar status de horário da clínica são recusados no Revisar ("Seu papel só mexe na agenda do banho e tosa…").
- Arquivos: `app/Services/Agente/CatalogoDaClinica.php`, `app/Http/Controllers/ClinicaTelasController.php`, `tests/Feature/Clinica/AgendaDoChatPorPapelTest.php` (novo, 6 testes).

### B — O dono também gera e revoga PIN
- `SupervisaoService::gestorDoPin`: Administrador ou dono, só em tenant da clínica. O alvo continua sendo só quem `podeTerPin` (o dono e os supervisores; o Admin nunca).
- Rotas `gerar`/`revogar` liberadas para dono e Admin. O dono confirma a própria senha de login, com o mesmo teto contado antes do `Hash::check`.
- Tela `/supervisao`: o dono vê o formulário do PIN e os botões no próprio nome e nos supervisores.
- O dono não mexe no PIN de outro dono (só o Admin), e trocar o setor lê o alvo travado (2ª passada).
- Mantido: 6 dígitos de `random_int`, código mostrado uma vez, só o hash gravado, código novo invalida o anterior, auditoria sem o código. O Admin nunca autoriza. O dono sem PIN não autoriza, e a senha dele também não.
- Frases do estorno sem PIN: "ele ou o Administrador gera um na tela Supervisão" (dono) e "peça ao dono ou ao Administrador" (supervisor).
- Arquivos: `app/Services/SupervisaoService.php`, `app/Http/Controllers/SupervisaoController.php`, `resources/views/telas/supervisao.blade.php`, `app/Services/Concerns/EstornoPorItens.php` (2 frases); comentários em `TelasController`, `ReautenticacaoAdmin` e `bancada.blade.php`; `tests/Feature/Clinica/SupervisaoPinPeloDonoTest.php` (novo, 10 testes).
- Reviewer (Opus), 2 passadas: ver "Revisão" abaixo.

### C — Pontas soltas
1. **Sugestões do `/agente`:** já eram da clínica e seguiam o RBAC desde e03d33c. As frases antigas só aparecem no ramo da contabilidade. Nada mudou.
2. **Login:** já contava a tentativa antes do `Auth::attempt` desde 4b943ad (teste `test_tentativa_de_login_conta_antes_de_conferir_a_senha`). Nada mudou e, sem diff, não houve passada do reviewer.
3. **`automacao/pendencias-lucas.md`:** não sobrou item de back aberto e com decisão. Ver "Pulados".
4. **Supervisão sem segurar e "clique ignorado sem aviso":** só registrados no roteiro.

### D — Roteiro
`documentacao/ROTEIRO_DEMONSTRACAO.md`: preparação no dia, contas por papel (senha = `DEMO_SENHA`, sem imprimir) e roteiro de 10 a 15 min com pedidos que passaram na bateria (agendar, receber, continuação, recusa do prontuário, PIN pelo dono, estorno com PIN, venda, negação, Resultados). Também traz o PWA (PC e `adb reverse tcp:8000 tcp:8000`), os pontos fracos e uma tabela do que fazer se algo falhar.

## Decisões a auditar (da mais arriscada)
1. **CLAUDE.md desatualizado (não editei).** A seção "Estorno e PIN" ainda diz "O PIN de autorização é gerado só pelo Administrador da clínica". O mesmo vale para a skill `sentinel-backend`. Um próximo bloco pode "corrigir" o código de volta. Texto sugerido para trocar o primeiro subitem:
   > O PIN de autorização é gerado e revogado pelo Administrador da clínica (papel `admin` num tenant de `sentinel.tenants_clinica`, conta do `ClinicaSeeder`) ou pelo dono (decisão do Lucas, 15/11), na `/supervisao`: 6 dígitos aleatórios, mostrados uma vez, só o hash gravado; vale até alguém gerar outro; "Revogar" zera. O dono marca o supervisor do setor. O Admin da clínica só gerencia PINs: nenhuma tool, sem auditoria, sem autorizar a lixeira nem estorno.
2. **3 testes alterados por causa da decisão B** (não afrouxados): `EstornoCobrancaTest`, `SupervisaoEndpointsTest` e `TelaDeSupervisaoTest`. O dono saiu da lista de recusados e ganhou as asserções positivas. Os outros 4 papéis e o próprio supervisor continuam recusados.
3. **Atendente pede a clínica no chat e recebe o banho e tosa sem aviso**, como na tela. Alternativa descartada: responder com erro (seria uma regra diferente da tela).
4. **A conferência do setor na agenda está no Revisar** (`conferir`), como as outras regras da agenda. O Confirmar executa os valores revisados.
5. **Sabotagem rodada por mim, não pelo test-runner.** O test-runner recusou o script, porque o papel dele só permite `php artisan test`. O script tira a proteção, roda o teste filtrado e restaura byte a byte; o md5 do `git diff` ficou igual antes e depois.
6. **A chave do limite `pin-admin:{id}` ficou com o nome antigo** (por pessoa; vale para o dono também).

## Testes
Pelo test-runner, só os arquivos tocados e as áreas de PIN, permissão, tenant e estorno:
- A: 164/164 (`AgendaDoChatPorPapelTest`, `ToolsDeAgenda…`, `ToolsDeAtendimento…`, `TelasDeLeitura…`, `RbacPapeisTest`, `IsolamentoDeTenantTest`).
- B: 214/214 (supervisão, os 4 `Estorno*Test`, `CorrecoesDaRevisaoTest`, `RbacPapeisTest`, `IsolamentoDeTenantTest`, `ReautenticacaoCardTest`). Depois do teste reforçado, 14/14. Depois da 1ª passada, 111/111.
- Depois da 2ª passada: 223/223 (os 12 arquivos de PIN, estorno, permissão, tenant e agenda).
- Sabotagens: A1–A3 e B1–B9, as 12 derrubaram, e conferi numa delas que a falha é de asserção. B3 (fora da clínica) e B6 (Admin com setor antigo) só derrubaram depois de testes novos.
- **Suíte completa não rodada nesta tarefa** (pedido). **Última suíte completa: 996/996** (7876 asserções), em 15/11, antes do commit `4968e83`.

## Chamadas de IA
6 nesta tarefa (teto 15): `documentacao/testes/fase8/BATERIA_CLINICA_AGENDA_PAPEIS.csv` → `…_HAIKU_2026-11-15.csv`, **6/6** (atendente: 2 consultas e 2 mudanças de status; vendedor: consulta e agendamento negados). **Acumulado: 299 → 305.**

## Pulados e por quê
- 2ª leva (P-D1, P-D2, defeito 11, P-C6 a P-C9, P-D3), D2 e D6: sem definição escrita.
- Os "Para decidir" de 13/11 (supervisão sem segurar; medir o pedido da caixa de entrada): sem decisão. A caixa de entrada já passou na bateria (pedido 36).
- CLAUDE.md e skill `sentinel-backend`: documentação, que o pedido mandou não mexer (texto acima).
- Nada irreversível foi feito.

## Revisão (reviewer Opus, Bloco B, 2 passadas)
- **1ª passada:** nenhum bloqueador. Aplicados: a frase do estorno sem PIN do dono (quem lê é quem pede o estorno) e `$admin` → `$gestor`. Registrado: a sabotagem "dono gera PIN" de 14/11 deixou de valer. Não aplicado: o CLAUDE.md (ver Decisões 1).
- **2ª passada:** nenhum bloqueador. Aplicados os 2 importantes: (1) `marcarSupervisor` trava o alvo, para que um PIN gerado em outra aba não sobreviva à troca de setor; (2) o dono não gera nem revoga o PIN de outro dono (o Admin sim). Também os 3 cosméticos: comentário duplicado; `pagehide` tira o código da tela; a auditoria confere `papel` e `permitido`.
- Depois das correções: **223/223** nos arquivos de PIN, estorno, permissão, tenant e agenda. Sabotagens B8 (trava) e B9 (dono → dono) derrubaram.

## Commits sugeridos (um por bloco)
1. `feat(clinica): agenda no chat com a mesma regra da tela (vendedor nao consulta; atendente so banho e tosa)`
   - `app/Services/Agente/CatalogoDaClinica.php`, `app/Http/Controllers/ClinicaTelasController.php`, `tests/Feature/Clinica/AgendaDoChatPorPapelTest.php`, `documentacao/testes/fase8/BATERIA_CLINICA_AGENDA_PAPEIS*.csv`
2. `feat(clinica): dono tambem gera e revoga o PIN (o proprio e o dos supervisores); Admin segue sem autorizar`
   - `app/Services/SupervisaoService.php`, `app/Http/Controllers/{SupervisaoController,TelasController}.php`, `app/Services/{ReautenticacaoAdmin,Concerns/EstornoPorItens}.php`, `resources/views/telas/supervisao.blade.php`, `resources/views/partials/bancada.blade.php`, `tests/Feature/Clinica/{SupervisaoPinPeloDonoTest,SupervisaoEndpointsTest,TelaDeSupervisaoTest,EstornoCobrancaTest}.php`
3. (C não tem diff de código: vai junto com o 4.)
4. `docs: roteiro da demonstracao; relatorio e decisoes de 15/11 (agenda e PIN)`
   - `documentacao/ROTEIRO_DEMONSTRACAO.md`, `automacao/relatorio-2026-11-15-agenda-pin.md`, `automacao/RETOMADA.md`, `.claude/decisoes/2026-11-15.md`
