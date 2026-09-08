<?php

/**
 * Definição de ferramenta (whitelist) para o AiAgentService.
 *
 * Apenas dados — não é lido pelo AiAgentService ainda (isso é Parte 3).
 */
return [
    'nome' => 'desmarcar_conciliado',
    'descricao' => 'Reverte um ou mais lançamentos conciliados de volta para pendente.',
    'tipo' => 'escrita',
    'service' => 'LancamentoService::desmarcarConciliado',
    // Provisório: revisar quando a equipe definir papéis específicos do domínio contábil.
    'papel_minimo' => 'operador',
    'confirmacao' => 'simples',
    'pre_requisito' => 'Cada lançamento deve estar com status "conciliado" para ser alterado.',
    'parametros' => [
        [
            'nome' => 'ids',
            'tipo' => 'array<int>',
            'obrigatorio' => true,
        ],
    ],
];
