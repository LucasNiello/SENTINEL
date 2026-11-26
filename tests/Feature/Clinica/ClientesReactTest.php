<?php

namespace Tests\Feature\Clinica;

use App\Models\AuditLog;
use App\Services\PetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

/** 27/11: página Clientes (React): lista com busca por tutor ou pet, detalhe com pets (ativos e arquivados), tenant, RBAC e auditoria. */
class ClientesReactTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    public function test_pagina_so_no_front_react_e_o_blade_vai_para_cadastros(): void
    {
        $this->logarComo('recepcao', self::T);

        config(['sentinel.front' => 'blade']);
        $this->get('/clientes')->assertRedirect('/cadastros');

        config(['sentinel.front' => 'react']);
        $this->get('/clientes')->assertOk()->assertInertia(fn ($p) => $p->component('Clientes', false)
            ->where('pode.arquivar_pet', true)->where('pode.reativar_pet', true));
    }

    public function test_busca_acha_o_tutor_pelo_nome_do_pet_e_marca_qual_pet(): void
    {
        $this->logarComo('recepcao', self::T);
        $tutor = $this->tutor();
        $pet = $this->pet($tutor, self::T, ['nome' => 'Bolt']);
        $this->pet($this->tutor(), self::T, ['nome' => 'Mel']);

        $r = $this->getJson('/clientes/dados?busca=Bol')->assertOk();
        $r->assertJsonCount(1, 'clientes')->assertJsonPath('clientes.0.id', $tutor->id)
            ->assertJsonPath('clientes.0.pets.0.id', $pet->id)->assertJsonPath('clientes.0.pets.0.achado', true);
    }

    public function test_detalhe_traz_pets_ativos_e_arquivados_e_audita_a_leitura(): void
    {
        $this->logarComo('veterinario', self::T);
        $tutor = $this->tutor();
        $ativo = $this->pet($tutor, self::T, ['nome' => 'Rex']);
        $velho = $this->pet($tutor, self::T, ['nome' => 'Tobi']);
        app(PetService::class)->arquivarCadastro($velho->id, 'Falecido', self::T);

        $r = $this->getJson('/clientes/dados?cliente='.$tutor->id)->assertOk();
        $pets = collect($r->json('cliente.pets'))->keyBy('id');
        $this->assertFalse($pets[$ativo->id]['arquivado']);
        $this->assertTrue($pets[$velho->id]['arquivado']);
        $this->assertSame('Arquivado: Falecido', $pets[$velho->id]['observacoes']);
        $this->assertSame(1, AuditLog::query()->where('tool', 'consultar_tutores')->where('entidade_id', $tutor->id)->where('resultado', 'sucesso')->count());
    }

    public function test_nao_enxerga_cliente_nem_pet_de_outro_tenant(): void
    {
        $this->logarComo('recepcao', self::T);
        $alheio = $this->tutor(self::OUTRO_TENANT);
        $this->pet($alheio, self::OUTRO_TENANT, ['nome' => 'Fantasma']);

        $this->getJson('/clientes/dados?cliente='.$alheio->id)->assertNotFound();
        $this->getJson('/clientes/dados?busca=Fantasma')->assertOk()->assertJsonCount(0, 'clientes');
    }

    public function test_vendedor_e_fora_da_clinica_nao_acessam_a_lista(): void
    {
        config(['sentinel.front' => 'react']);
        $this->logarComo('recepcao', 1); // tenant que não é da clínica
        $this->getJson('/clientes/dados')->assertNotFound();
    }

    public function test_abrir_formulario_de_pet_traz_o_tutor_preenchido(): void
    {
        $this->logarComo('recepcao', self::T);
        $tutor = $this->tutor();

        $this->postJson('/agente/formulario', ['tool' => 'criar_pet', 'tutor_id' => $tutor->id])
            ->assertOk()->assertJsonPath('argumentos.tutor_id', $tutor->id);
    }
}
