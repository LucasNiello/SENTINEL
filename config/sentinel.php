<?php

return [

    /*
    | Prazo (em dias) que um registro fica na lixeira (soft delete) antes
    | de ser movido automaticamente para o estado arquivado (RF09-RF11).
    | Documentação oficial cita "ordem de 1 a 3 meses" — default 90 dias.
    */
    'retencao_lixeira_dias' => env('SENTINEL_RETENCAO_LIXEIRA_DIAS', 90),

    /*
    | RBAC PLACEHOLDER — simplificação consciente do protótipo.
    | Não há autenticação (RF10 fora de escopo): o "papel atual" vem desta
    | config, não de um usuário logado. Cada tool do AiAgentService declara
    | um papel mínimo e é negada (403 + registro em audit_logs) se o papel
    | atual estiver abaixo dele. Hierarquia crescente: leitura < operador < admin.
    | Papel fora da lista nega tudo (falha fechada).
    */
    'papeis' => ['leitura', 'operador', 'admin'],
    'papel_atual' => env('SENTINEL_PAPEL_ATUAL', 'admin'),

    /*
    | TENANT FIXO — simplificação consciente do protótipo (multi-tenancy real
    | fica para quando a tabela de tenants existir; ver CLAUDE.md). O
    | tenant_id NUNCA vem do modelo de IA: o servidor sempre sobrescreve
    | com este valor ao gravar e filtra as consultas por ele.
    */
    'tenant_atual' => (int) env('SENTINEL_TENANT_ATUAL', 1),

];
