<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Recusa de regra de negócio numa tool do AiAgentService (ex.: lançamento
 * inexistente, já está no status pedido).
 *
 * A mensagem é exibida ao usuário como está — escreva sempre uma frase
 * amigável, sem termo técnico (mesmo padrão do motivo de bloqueio do RF08).
 * Qualquer OUTRA exceção vira a mensagem genérica da tool, nunca o texto dela.
 */
class ToolRecusadaException extends RuntimeException
{
}
