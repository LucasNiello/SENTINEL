<?php

namespace App\Exceptions;

/**
 * Regra de domínio recusada por um service (clínica vet): conflito de horário, prontuário assinado,
 * estoque insuficiente... A mensagem é a frase para o usuário, sem termo técnico.
 *
 * Estende ToolRecusadaException de propósito: quando uma tool chamar o service (Fase B), o executor
 * do AiAgentService já traduz a recusa para o contrato de erro, sem mudar o motor.
 */
class RegraDeNegocioException extends ToolRecusadaException
{
}
