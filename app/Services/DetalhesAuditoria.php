<?php

namespace App\Services;

use Closure;

/**
 * Campos de audit_logs que a assinatura de AuditoriaService::registrar() não
 * carrega: parametros_propostos (RF04), autorizado_por (RF10), tempo_ia_ms
 * (RNF04) e origem. Uma instância por requisição (scoped, ver AppServiceProvider):
 * quem abre o escopo e quem grava o registro não precisam ser o mesmo objeto.
 */
class DetalhesAuditoria
{
    /** @var array<string, mixed> */
    private array $atuais = [];

    /**
     * Executa $acao com os detalhes dados valendo para todo registro de
     * auditoria feito dentro dela. Escopos aninhados somam; ao sair, volta ao anterior.
     */
    public function com(array $detalhes, Closure $acao): mixed
    {
        $anteriores = $this->atuais;
        $this->atuais = $detalhes + $anteriores;

        try {
            return $acao();
        } finally {
            $this->atuais = $anteriores;
        }
    }

    /** @return array<string, mixed> */
    public function atuais(): array
    {
        return $this->atuais;
    }
}
