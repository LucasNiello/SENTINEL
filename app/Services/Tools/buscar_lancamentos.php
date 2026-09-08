<?php

/**
 * Definição de ferramenta (whitelist) para o AiAgentService.
 *
 * Apenas dados — não é lido pelo AiAgentService ainda (isso é Parte 3).
 */
return [
    'nome' => 'buscar_lancamentos',
    'descricao' => 'Busca lançamentos financeiros por status e/ou intervalo de datas.',
    'tipo' => 'leitura',
    'service' => 'LancamentoService::buscarPorFiltro',
    'papel_minimo' => 'operador',
    'confirmacao' => 'nenhuma',
    'parametros' => [
        [
            'nome' => 'status',
            'tipo' => 'string',
            'obrigatorio' => false,
            'valores_possiveis' => ['pendente', 'conciliado'],
        ],
        [
            'nome' => 'data_inicio',
            'tipo' => 'date',
            'obrigatorio' => false,
        ],
        [
            'nome' => 'data_fim',
            'tipo' => 'date',
            'obrigatorio' => false,
        ],
    ],
];
