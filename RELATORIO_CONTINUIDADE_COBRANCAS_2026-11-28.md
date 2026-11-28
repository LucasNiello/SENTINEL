# Continuidade do plano de cobranças — 28/11/2026

Projeto: SENTINEL · Branch: `malaman-react` · Base: `0a40580`.
Fechamento parcial; F4 e fechamento final ainda pendentes.

## Concluído

- F0 — recebimento em um passo: `9e3ea8a`.
- FE — entrada de estoque: `af40cf2`.
- F1 — edição de cobrança, preservando o consumo clínico: `e4570a1`.
- F2 — desconto geral e estornos proporcionais: `0a40580`.
- Banco local `sentinel`: 41 migrations aplicadas, nenhuma pendente, conforme validação anterior ao fechamento.
- Backups completos F1 e F2 realizados e verificados, em pasta protegida fora do repositório: `%LOCALAPPDATA%\SENTINEL\Backups`.
- Testes direcionados e builds aprovados nas respectivas fases. Após a migration F2: 12 testes aprovados em SQLite isolado, com 9.394 assertions. Nenhuma suíte completa executada neste fechamento.
- React integrado; Blade preservado. Dados existentes, estoque, auditoria e 11 contas QA preservados na aplicação da migration F2.

## Pendências

- F4: reviewer em duas passadas.
- Executar regressão completa uma única vez.
- Atualizar o adendo técnico e o roteiro-demo.
- Validação real com Anthropic; validações anteriores utilizaram simulações.
- Itens adiados do plano: desconto por item, pagamento dividido, parcelas, troco, nota fiscal e demais integrações fora do escopo.

## Próxima tarefa

Executar **somente a primeira passada da revisão F4**, começando por cálculos financeiros, descontos e estornos. Não considerar as pendências acima concluídas nem iniciar outras fases automaticamente.
