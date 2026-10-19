<?php

namespace Tests\Unit;

use App\Services\DetalhesAuditoria;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * O escopo dos detalhes de auditoria não pode vazar: o que vale dentro de com()
 * some ao sair, mesmo com exceção, e escopos aninhados somam e depois voltam.
 */
class DetalhesAuditoriaTest extends TestCase
{
    public function test_escopo_aninhado_soma_e_volta_ao_anterior(): void
    {
        $detalhes = new DetalhesAuditoria;

        $detalhes->com(['origem' => 'agente'], function () use ($detalhes) {
            $this->assertSame(['origem' => 'agente'], $detalhes->atuais());

            $detalhes->com(['tempo_ia_ms' => 120], function () use ($detalhes) {
                $this->assertSame(['tempo_ia_ms' => 120, 'origem' => 'agente'], $detalhes->atuais());
            });

            $this->assertSame(['origem' => 'agente'], $detalhes->atuais());
        });

        $this->assertSame([], $detalhes->atuais());
    }

    public function test_excecao_dentro_do_escopo_tambem_restaura_o_estado(): void
    {
        $detalhes = new DetalhesAuditoria;

        try {
            $detalhes->com(['parametros_propostos' => ['cpf' => '111.222.333-44']], function () {
                throw new RuntimeException('falhou no meio');
            });
            $this->fail('a exceção deveria ter subido');
        } catch (RuntimeException) {
        }

        $this->assertSame([], $detalhes->atuais(), 'nada do escopo que falhou fica para o próximo registro');
    }

    public function test_com_devolve_o_retorno_da_acao(): void
    {
        $this->assertSame(42, (new DetalhesAuditoria)->com(['origem' => 'manual'], fn () => 42));
    }
}
