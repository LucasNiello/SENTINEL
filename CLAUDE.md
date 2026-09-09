# SENTINEL — regras fixas do projeto

- **Stack**: 100% PHP/Laravel (Blade, Tailwind, JS vanilla). Nunca introduzir outra linguagem de programação neste projeto.
- **Provedor de IA**: Microsoft Foundry (Azure), usando a Chat Completions API (`/chat/completions` com `tools`/`tool_choice`) de um deployment Azure OpenAI — chamada HTTP REST única, stateless, sem SDK. Nunca usar o Agent Service / Assistants API (threads, runs, polling). Sempre isolado dentro de `app/Services/AiAgentService.php` — nunca chamado diretamente de outro lugar do código.
- **Git**: Claude Code nunca executa `git add`, `git commit`, `git push` ou qualquer comando que altere o histórico. Apenas sugere mensagem de commit como texto.
- **Arquitetura**: já está fechada e documentada no Notion (workspace "SENTINEL", página "Arquitetura") e em `documentacao/Documentacao-Sentinel1.0.docx` (versão vigente — arquivos anteriores na mesma pasta, ex.: `Sentinel_0.x.docx`, `Sentinel_Doc_0.5.docx`, estão desatualizados). Não decidir ou alterar decisão de arquitetura autonomamente — se um conflito aparecer entre uma instrução e o que está documentado, sinalizar e perguntar, nunca escolher sozinho.
- **Escopo**: protótipo acadêmico de TCC, não produto em produção. Não adicionar pacotes, dependências ou infraestrutura (filas, cache distribuído, 2FA, observabilidade, etc.) além do estritamente pedido em cada tarefa.
- Não instalar `laravel/boost` nem qualquer pacote sugerido automaticamente por templates padrão do Laravel sem autorização explícita a cada vez.

## Pendências conhecidas (não resolver sem pedido explícito)

- `tenant_id` está sem foreign key em **todas as 6 tabelas de domínio**: `lancamentos`, `categorias_lancamento`, `clientes`, `fornecedores`, `funcionarios`, `notas_fiscais` (ver comentário em cada migration `create_*_table`). Motivo: a tabela de tenants/empresas ainda não existe. **Criar a FK em todas quando a entidade de tenants/empresas for implementada.**
- **RF10 (restauração da lixeira com senha de administrador)**: não há sistema de autenticação implementado ainda. `ExclusaoSegura::restaurar()` já recebe o parâmetro `bool $reautenticadoComoAdmin`, mas quem decide esse valor é responsabilidade de quem chama — nenhuma reautenticação real acontece hoje. **Implementar a reautenticação de verdade quando o sistema de auth existir.**
