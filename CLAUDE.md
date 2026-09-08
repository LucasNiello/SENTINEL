# SENTINEL — regras fixas do projeto

- **Stack**: 100% PHP/Laravel (Blade, Tailwind, JS vanilla). Nunca introduzir outra linguagem de programação neste projeto.
- **Provedor de IA**: Microsoft Foundry Agent Service (Azure), acessado via HTTP REST puro, sempre isolado dentro de `app/Services/AiAgentService.php` — nunca via SDK, nunca chamado diretamente de outro lugar do código.
- **Git**: Claude Code nunca executa `git add`, `git commit`, `git push` ou qualquer comando que altere o histórico. Apenas sugere mensagem de commit como texto.
- **Arquitetura**: já está fechada e documentada no Notion (workspace "SENTINEL", página "Arquitetura") e no arquivo Sentinel_documentacao na raiz do repositório. Não decidir ou alterar decisão de arquitetura autonomamente — se um conflito aparecer entre uma instrução e o que está documentado, sinalizar e perguntar, nunca escolher sozinho.
- **Escopo**: protótipo acadêmico de TCC, não produto em produção. Não adicionar pacotes, dependências ou infraestrutura (filas, cache distribuído, 2FA, observabilidade, etc.) além do estritamente pedido em cada tarefa.
- Não instalar `laravel/boost` nem qualquer pacote sugerido automaticamente por templates padrão do Laravel sem autorização explícita a cada vez.
