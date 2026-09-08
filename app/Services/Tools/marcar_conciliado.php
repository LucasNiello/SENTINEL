<?php

/**
 * Definição de ferramenta (whitelist) para o AiAgentService.
 *
 * Apenas dados — não é lido pelo AiAgentService ainda (isso é Parte 3).
 */
return [
    'nome' => 'marcar_conciliado',
    'descricao' => 'Marca um ou mais lançamentos pendentes como conciliados.',
    'tipo' => 'escrita',
    'service' => 'LancamentoService::marcarConciliado',
    // Provisório: revisar quando a equipe definir papéis específicos do domínio contábil.
    'papel_minimo' => 'operador',
    'confirmacao' => 'simples',
    'pre_requisito' => 'Cada lançamento deve estar com status "pendente" para ser alterado.',
    'parametros' => [
        [
            'nome' => 'ids',
            'tipo' => 'array<int>',
            'obrigatorio' => true,
        ],
    ],
];
