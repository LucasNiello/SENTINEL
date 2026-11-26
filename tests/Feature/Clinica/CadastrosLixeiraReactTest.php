<?php

namespace Tests\Feature\Clinica;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

/** 26/11: Cadastros e Lixeira em React. O botão Excluir/Arquivar só aparece para quem pode; o servidor confere de novo. */
class CadastrosLixeiraReactTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    public function test_dono_ve_excluir_e_arquivar_nos_cadastros_e_o_blade_continua(): void
    {
        $this->logarComo('dono', self::T);

        config(['sentinel.front' => 'blade']);
        $this->get('/cadastros')->assertOk()->assertSee('Cadastros');

        config(['sentinel.front' => 'react']);
        $this->get('/cadastros')->assertOk()->assertInertia(fn ($p) => $p->component('Consulta', false)
            ->where('entidades.pets.excluir', 'excluir_pet')->where('entidades.tutores.excluir', 'excluir_tutor')
            ->where('entidades.catalogo.excluir', 'excluir_item_catalogo')
            ->where('entidades.pets.consultar', 'consultar_pets')->where('podeArquivarPet', true));
    }

    public function test_recepcao_nao_recebe_excluir_mas_arquiva_pet(): void
    {
        $this->logarComo('recepcao', self::T);
        config(['sentinel.front' => 'react']);
        $this->get('/cadastros')->assertOk()->assertInertia(fn ($p) => $p->component('Consulta', false)
            ->where('entidades.pets.excluir', null)->where('entidades.tutores.excluir', null)->where('podeArquivarPet', true));
    }

    public function test_lixeira_em_react_so_para_quem_consulta(): void
    {
        config(['sentinel.front' => 'react']);
        $this->logarComo('dono', self::T);
        $this->get('/lixeira')->assertOk()->assertInertia(fn ($p) => $p->component('Consulta', false)->where('titulo', 'Lixeira')->has('entidades.lixeira.filtros'));
    }
}
