<?php

return [

    /*
    | Prazo (em dias) que um registro fica na lixeira (soft delete) antes
    | de ser movido automaticamente para o estado arquivado (RF09-RF11).
    | Documentação oficial cita "ordem de 1 a 3 meses" — default 90 dias.
    */
    'retencao_lixeira_dias' => env('SENTINEL_RETENCAO_LIXEIRA_DIAS', 90),

    /*
    | RBAC — hierarquia de papéis, em ordem crescente: leitura < operador < admin.
    | O papel atual e o tenant atual vêm do usuário logado (users.papel e
    | users.tenant_id), lidos pelo App\Services\ContextoUsuario — não há valor
    | padrão aqui. Cada tool do AiAgentService declara um papel mínimo e é
    | negada (403 + registro em audit_logs) se o papel do usuário estiver
    | abaixo dele. Papel fora desta lista nega tudo (falha fechada). O
    | tenant_id NUNCA vem do modelo de IA: o servidor sobrescreve com o
    | tenant do usuário ao gravar e filtra as consultas por ele.
    */
    'papeis' => ['leitura', 'operador', 'admin'],

];
