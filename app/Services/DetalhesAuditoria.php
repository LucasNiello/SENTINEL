<?php

namespace App\Services;

use Closure;

/**
 * Campos de audit_logs que a assinatura de AuditoriaService::registrar() não
 * carrega: parametros_propostos (RF04), autorizado_por (RF10), tempo_ia_ms
 * (RNF04), origem e valores_anteriores (A2). Uma instância por requisição (scoped, ver AppServiceProvider):
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

    /**
     * Acrescenta um detalhe ao escopo aberto agora (ex.: valores_anteriores, que só o executor
     * da tool conhece). Some junto com o escopo, ao sair do com() que o envolve.
     */
    public function anotar(string $chave, mixed $valor): void
    {
        $this->atuais[$chave] = $valor;
    }

    /** @return array<string, mixed> */
    public function atuais(): array
    {
        return $this->atuais;
    }
}
