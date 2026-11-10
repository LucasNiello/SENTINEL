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

    /*
    | Domínio de cada tenant: o catálogo de tools do agente é o do domínio do tenant do usuário logado.
    | Os tenants listados aqui são clínica veterinária + petshop; qualquer outro é o domínio antigo
    | (contabilidade). Não há tabela de tenants (ver CLAUDE.md > Pendências conhecidas), então a lista
    | mora na configuração: SENTINEL_TENANTS_CLINICA=3,5. Tenant fora da lista NUNCA vê as tools da clínica.
    */
    'tenants_clinica' => array_values(array_filter(array_map('intval', explode(',', (string) env('SENTINEL_TENANTS_CLINICA', '3'))))),

    /*
    | Agenda: duração máxima de um agendamento, em minutos (padrão 480 = 8 h). Acima disso o
    | servidor recusa — inclusive no encaixe de emergência, que não confere conflito de horário.
    */
    'agenda_duracao_maxima_minutos' => (int) env('SENTINEL_AGENDA_DURACAO_MAXIMA_MIN', 480),

    /* Como cada papel aparece na interface. */
    'rotulos_papeis' => [
        'leitura' => 'Leitura', 'operador' => 'Operador', 'admin' => 'Administrador',
        'atendente' => 'Atendente', 'vendedor' => 'Vendedor', 'recepcao' => 'Recepção',
        'veterinario' => 'Veterinário', 'dono' => 'Dono',
    ],

];
