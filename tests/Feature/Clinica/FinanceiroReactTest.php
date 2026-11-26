<?php

namespace Tests\Feature\Clinica;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

/** 26/11: Cobranças e Vendas em React (mesma página). Blade continua; React recebe só as colunas exibidas. */
class FinanceiroReactTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    public function test_cobrancas_e_vendas_renderizam_a_pagina_react_com_colunas_e_resumo(): void
    {
        $this->logarComo('dono', self::T);

        config(['sentinel.front' => 'blade']);
        $this->get('/cobrancas')->assertOk()->assertSee('Cobranças');

        config(['sentinel.front' => 'react']);
        $this->get('/cobrancas')->assertOk()->assertInertia(fn ($p) => $p->component('Financeiro', false)
            ->where('titulo', 'Cobranças')->where('rota', '/cobrancas')->has('colunas.pet')->has('resumo.liquido')->where('resumo.registros', 0));
        $this->get('/vendas')->assertOk()->assertInertia(fn ($p) => $p->component('Financeiro', false)
            ->where('titulo', 'Vendas')->has('colunas.tutor'));
    }

    public function test_papel_sem_acesso_continua_barrado_no_react(): void
    {
        $this->logarComo('vendedor', self::T);
        config(['sentinel.front' => 'react']);
        $this->get('/cobrancas')->assertForbidden();
    }
}
