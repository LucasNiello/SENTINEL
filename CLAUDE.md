# SENTINEL — regras fixas do projeto

- **Stack**: 100% PHP/Laravel (Blade, Tailwind, JS vanilla). Nunca introduzir outra linguagem de programação neste projeto.
- **Provedor de IA**: Microsoft Foundry (Azure), usando a Chat Completions API (`/chat/completions` com `tools`/`tool_choice`) de um deployment Azure OpenAI — chamada HTTP REST única, stateless, sem SDK. Nunca usar o Agent Service / Assistants API (threads, runs, polling). Sempre isolado dentro de `app/Services/AiAgentService.php` — nunca chamado diretamente de outro lugar do código.
- **Git**: Claude Code nunca executa `git add`, `git commit`, `git push` ou qualquer comando que altere o histórico. Apenas sugere mensagem de commit como texto.
- **Arquitetura**: já está fechada e documentada no Notion (workspace "SENTINEL", página "Arquitetura") e em `documentacao/Documentacao-Sentinel1.0.docx` (versão vigente — arquivos anteriores na mesma pasta, ex.: `Sentinel_0.x.docx`, `Sentinel_Doc_0.5.docx`, estão desatualizados). Não decidir ou alterar decisão de arquitetura autonomamente — se um conflito aparecer entre uma instrução e o que está documentado, sinalizar e perguntar, nunca escolher sozinho.
- **Escopo**: protótipo acadêmico de TCC, não produto em produção. Não adicionar pacotes, dependências ou infraestrutura (filas, cache distribuído, 2FA, observabilidade, etc.) além do estritamente pedido em cada tarefa. **Isso é controle de escopo, não desculpa para segurança relapsa** — dado sensível (CPF, salário, financeiro) é tratado com cuidado mesmo em protótipo, como já feito no mascaramento de PII em audit_logs (Fase 2). Ver seção "Agenda: quando deixar de ser protótipo acadêmico" para o que fica pendente por enquanto, não por já ter sido descartado.
- Não instalar `laravel/boost` nem qualquer pacote sugerido automaticamente por templates padrão do Laravel sem autorização explícita a cada vez.

## Pendências conhecidas (não resolver sem pedido explícito)

- `tenant_id` está sem foreign key em **todas as 6 tabelas de domínio**: `lancamentos`, `categorias_lancamento`, `clientes`, `fornecedores`, `funcionarios`, `notas_fiscais` (ver comentário em cada migration `create_*_table`), e também em `users` (migration `add_papel_e_tenant_id_to_users_table`, RF10). Motivo: a tabela de tenants/empresas ainda não existe. **Criar a FK em todas quando a entidade de tenants/empresas for implementada.**
- **RF10 (autenticação e restauração da lixeira com senha de administrador)**: autenticação implementada na Fase 3 (23/09), só com o que o Laravel traz: login/logout por sessão (`AutenticacaoController`, `Auth::attempt`, `session()->regenerate()`/`invalidate()`), throttle de 5 tentativas/min por e-mail + IP, rotas do agente atrás do middleware `auth`. Papel e tenant vêm do usuário logado (`users.papel`/`users.tenant_id`) via `App\Services\ContextoUsuario`, que falha fechado (lança `AuthenticationException` sem usuário, papel ou tenant; não há fallback em `config/sentinel.php`). A auditoria grava o `user_id` real. A reautenticação existe como mecanismo (`App\Services\ReautenticacaoAdmin::confirmar($email, $senha)`: admin do mesmo tenant + `Hash::check`), e o `bool` dela é o que deve alimentar `restaurarDaLixeira(..., $reautenticadoComoAdmin)`. **Pendente para a Fase 4: expor a restauração da lixeira (tela ou tool do agente) chamando `ReautenticacaoAdmin` antes de restaurar** — hoje nenhum código de produção chama `restaurarDaLixeira`. Sem cadastro público, recuperação de senha, verificação de e-mail, 2FA ou "lembrar de mim" (usuários vêm do seeder; senha de desenvolvimento no README).
- **RF11 — agendamento do arquivamento**: `sentinel:arquivar-expirados` está agendado (`daily`) em `routes/console.php`, mas nada dispara `php artisan schedule:run` nesta máquina. É infra do SO (ex.: Agendador de Tarefas do Windows chamando `schedule:run` a cada minuto), fora do alcance do código. Sem isso o arquivamento só acontece se o comando for rodado à mão.
- **Banco: migração para Supabase Postgres (sa-east-1) — adiada para quando o projeto sair de protótipo.** Decisão de 23/09: o banco fica em MySQL local (Laragon) até o fim do TCC, porque a rede do SENAI bloqueia o protocolo Postgres (o handshake TCP completa, mas os pacotes do protocolo são descartados — testado na porta 5432 do Session pooler). O destino continua sendo Supabase Postgres (decisão de 10/09: LGPD e portabilidade). Checklist para quando migrar: (1) `DB_CONNECTION=pgsql` + `DB_URL` no `.env` (template comentado em `.env.example`), usando a string do **Session pooler** — a Direct connection do Supabase só tem endereço IPv6 e não funciona em rede sem IPv6; (2) extensões `pdo_pgsql`/`pgsql` ativas no `php.ini` (o `instalar.bat` habilita `zip`, `openssl`, `mbstring`, `curl`, `pdo_mysql`, `mysqli` e `fileinfo`, mas não as de Postgres); (3) trocar `like` por `ilike` nos `buscarPorFiltro($nome)` de Cliente, Fornecedor e Funcionario (no Postgres `LIKE` diferencia maiúsculas); (4) adaptar o `instalar.bat`, que hoje grava `DB_CONNECTION=mysql` no `.env` e deixa o `php artisan migrate --force` criar o banco (a senha, `DB_PASSWORD`, é preenchida manualmente — o script não grava senha) — uma versão já adaptada para Supabase (sem Laragon) está no commit `32a7cac`; (5) `migrate:fresh --seed` no Supabase. As 13 migrations já foram compiladas com o grammar `pgsql` em modo `pretend`, sem erros: as 11 primeiras antes de 23/09 (42 statements), a 12ª (`add_papel_e_permitido_to_audit_logs`) e a 13ª (`add_papel_e_tenant_id_to_users`) em 23/09.
- **`NotaFiscalRequest` sem escopo de tenant no próprio arquivo** — as regras
  `exists:clientes,id`/`exists:fornecedores,id`/`exists:lancamentos,id` não
  filtram por tenant. Hoje sem risco: `AiAgentService::validar()` reescreve
  toda regra `exists:` via `escoparPorTenant()` antes de validar, então o
  filtro é aplicado em runtime. O risco só existe se, no futuro, um
  controller HTTP passar a injetar `NotaFiscalRequest` diretamente (fora do
  fluxo do agente) — nesse caso, aplicar o mesmo filtro direto nas regras do
  arquivo.
- **`criar_lancamento` não passa por `validar()`** — comportamento herdado
  da Fase 0, antes de o padrão `validar()`/Form Request existir. É o único
  tool de escrita que não usa esse caminho: uma falha de validação cai no
  catch genérico com mensagem canned, em vez da frase específica por campo
  que as demais tools de escrita têm. Sem risco de segurança (tenant e RBAC
  continuam valendo normalmente) — é dívida de consistência de UX. Migrar
  pra `validar()` quando `criar_lancamento` for tocado por outro motivo.

## Agenda: quando deixar de ser protótipo acadêmico

Este projeto tem plano de sair do escopo de TCC no futuro. A lista completa e
detalhada de pendências conscientemente adiadas (injeção SQL, bots/DDoS,
criptografia, autenticação real, segredos no frontend, LGPD, superfície
geral) fica no Notion, **dentro do projeto SENTINEL**, na página "🔐 Checklist
final — antes de sair de protótipo acadêmico" — é a fonte única, não duplicar
aqui.

Não implementar nada disso agora sem pedido explícito (continua valendo a
regra de não inflar escopo). A lista existe pra esses pontos não serem
esquecidos, não pra serem antecipados. Revisar item por item, na página do
Notion, quando todas as fases/etapas do projeto estiverem concluídas.

## Comportamento do agente (vale para qualquer tarefa, com ou sem subagente)

- Mudança cirúrgica: tocar só o necessário pra tarefa pedida — nunca
  refatorar código que já funciona "já que estou aqui".
- Se a solução ficou grande ou complexa, questionar antes de entregar: dava
  pra ser mais simples?
- Toda suposição não confirmada no pedido vira pergunta, nunca decisão
  silenciosa.
