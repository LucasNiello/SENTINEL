<?php

return [

    /*
    | Prazo (em dias) que um registro fica na lixeira (soft delete) antes
    | de ser movido automaticamente para o estado arquivado (RF09-RF11).
    | Documentação oficial cita "ordem de 1 a 3 meses" — default 90 dias.
    */
    'retencao_lixeira_dias' => env('SENTINEL_RETENCAO_LIXEIRA_DIAS', 90),

    /*
    | RBAC — papéis válidos. Cada tool do AiAgentService declara a LISTA de papéis
    | que podem usá-la (não há mais hierarquia). O papel atual e o tenant atual vêm
    | do usuário logado (users.papel e users.tenant_id), lidos pelo
    | App\Services\ContextoUsuario — não há valor padrão aqui. Papel fora desta
    | lista nega tudo e não entra no login (falha fechada). O tenant_id NUNCA vem
    | do modelo de IA: o servidor sobrescreve com o tenant do usuário ao gravar e
    | filtra as consultas por ele.
    |
    | leitura/operador/admin: papéis do domínio antigo (contabilidade), PROVISÓRIOS
    | até a remoção desse domínio. Os outros cinco são os da clínica veterinária +
    | petshop (o dono é veterinário: tudo do veterinário + administração).
    */
    'papeis' => ['leitura', 'operador', 'admin', 'atendente', 'vendedor', 'recepcao', 'veterinario', 'dono'],

    /*
    | Papéis administrativos: a senha de um deles autoriza restaurar e excluir de vez
    | (ReautenticacaoAdmin, ExclusaoSegura), e só eles veem a auditoria e o salário no card.
    */
    'papeis_administrativos' => ['admin', 'dono'],

    /* Como cada papel aparece na interface. */
    'rotulos_papeis' => [
        'leitura' => 'Leitura', 'operador' => 'Operador', 'admin' => 'Administrador',
        'atendente' => 'Atendente', 'vendedor' => 'Vendedor', 'recepcao' => 'Recepção',
        'veterinario' => 'Veterinário', 'dono' => 'Dono',
    ],

];
