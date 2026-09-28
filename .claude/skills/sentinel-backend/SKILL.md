---
name: sentinel-backend
description: Use ao criar ou alterar migrations, models, services, Form Requests, rotas e tools do AiAgentService do Sentinel (Laravel).
---

# PAPEL + CONTEXTO
Backend do Sentinel (TCC Laravel, SENAI Limeira). Stack: Laravel 13 / PHP 8.4 +
services por entidade + AiAgentService (tool calling, Chat Completions API do
Microsoft Foundry/Azure). Documento de referência: requisitos formais
RF01-RF12/RNF01-RNF10 no `documentacao/`. Prazo: entrega até 30/11.

# PERMISSÕES
| Ação | Permitido |
|---|:---:|
| Ler/editar código em `app/`, `database/`, `routes/`, `config/` | Sim |
| Rodar `php artisan migrate`, `db:seed`, `tinker`, testes | Sim |
| Criar migration/model/service novo dentro do padrão já existente | Sim |
| Implementar tool nova no `AiAgentService` sem os quatro itens de "Tool de escrita" abaixo | Não |
| Obter usuário, papel ou tenant fora do `ContextoUsuario` (request, `config('sentinel.papel_atual')`, argumento vindo do modelo de IA) | Não |
| Mexer em `.env` com segredo real sem eu confirmar antes | Não |
| Implementar multi-tenancy real (`tenant_id` com FK) | Não — débito documentado, fora de escopo até eu pedir |
| `git add`/`commit`/`push`/qualquer comando que altere histórico | Não, nunca |
| Sugerir mensagem de commit como texto | Sim |

# REGRAS FIXAS
- **Usuário, papel e tenant**: só via `App\Services\ContextoUsuario`, que falha
  fechado (sem usuário, papel ou tenant, lança `AuthenticationException`; não há
  valor padrão). Nunca `config('sentinel.papel_atual')`, nunca valor vindo do
  request ou do modelo de IA.
- **Tenant obrigatório** (vale a partir do Lote 2): todo método de service que
  lê, altera, exclui ou restaura por id ou filtro recebe `int $tenantId`
  OBRIGATÓRIO — sem `null`, sem default — e filtra a consulta por ele: registro
  de outro tenant é tratado como inexistente. Motivo: achados M4/N5 da revisão
  de 28/09.
- **IA**: só dentro de `App\Services\AiAgentService` (regra do CLAUDE.md).
  Nenhum outro arquivo chama a API do Foundry.
- **Tool de escrita** só está pronta com os quatro itens. Faltou um → a tool
  não está pronta:
  1. RBAC mínimo: papel mínimo declarado na entrada da tool no `catalogo()`.
  2. Confirmação humana: o agente só devolve `confirmacao_pendente`; a escrita
     roda no `/agente/confirmar`, com o token de sessão do `AgenteController`:
     espera mínima de 2 s; validade de 10 s na interface; o servidor aceita até
     12 s (VALIDADE_MAXIMA_S); consumo único (já implementados). Consumo
     atômico do token (vale a partir do Lote 2).
  3. Auditoria real em `audit_logs` (o `executarTool()` grava na mesma
     transação da escrita).
  4. Validação por Form Request via `validar()`, que escopa toda regra
     `exists:` pelo tenant e traduz a falha em frase amigável.
- **Tool de leitura**: devolve só as colunas declaradas em `colunas`. CPF
  (documento com 11 dígitos) mascarado para todos os papéis; CNPJ visível. A
  auditoria mascara via `AuditoriaService::mascararCamposSensiveis()` (o mesmo
  método mascara as linhas devolvidas pela leitura).
- **Classe de risco**: toda tool declara sua classe de risco (leitura /
  escrita / exclusão), que a interface usa na borda do card (skill
  `sentinel-visual`, seção 5). Tool nova entra na classe do seu efeito, não do
  seu nome. (pendente: o catálogo e a resposta confirmacao_pendente ainda não
  levam a classe; entra na Tarefa B)
- **Datas**: use o timezone da config da aplicação (`now()`/Carbon seguem
  `config('app.timezone')`); nunca assuma UTC.
- **Teste**: toda mudança vem com teste (ver skill `sentinel-testes`).

# FLUXO
1. Antes de codar, releia o service/tool análogo já existente (ex.:
   `LancamentoService`, a entrada da tool em `AiAgentService::catalogo()`) e
   siga o mesmo padrão — não invente convenção nova.
2. Toda tool de escrita nova precisa dos quatro itens de "Tool de escrita"
   acima. Se algum faltar, pare e sinalize antes de considerar a tool pronta —
   isso é o que faltou no RF06/RF07 na Fase 1, não repita.
3. Rode os testes relevantes antes de reportar como concluído.
4. Reporte o que fechou e qualquer débito técnico que você conscientemente
   deixou de fora.

# NÃO FAÇA
- Não implemente `tenant_id` com FK real, filas, microsserviços, 2FA ou
  observabilidade não pedida — protótipo acadêmico de TCC, não produção.
- Não rode nenhum comando git de escrita.
- Não expanda o escopo da tarefa pedida "já que estou aqui".

# GIT
Commits são responsabilidade exclusiva do Lucas. Nunca execute git add, commit,
push ou qualquer comando que altere histórico. Pode sugerir mensagem de commit
apenas como texto.
