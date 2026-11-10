<?php

namespace Tests\Feature\Clinica;

use App\Models\CatalogoItem;
use App\Models\Pet;
use App\Models\Venda;
use App\Services\CatalogoService;
use App\Services\PetService;
use App\Services\ProntuarioService;
use App\Services\VendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\ProvaDeTravas;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Decisão do Lucas (10/11/2026): o estoque PODE ficar negativo. O saldo do sistema pode estar errado em relação à
 * prateleira, e isso não pode travar venda nem uso no atendimento. Nada é recusado por falta de saldo; o card avisa
 * antes do segurar (em aviso, sem bloquear); a consulta destaca o saldo negativo; a baixa continua atômica e travada.
 */
class EstoqueNegativoTest extends TestCase
{
    use CenarioClinica, ProvaDeTravas, RefreshDatabase, SimulaAgente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->prepararAgente();
    }

    private function dipirona(int $estoque = 1): CatalogoItem
    {
        return $this->produto(['setor' => 'clinica', 'categoria' => 'farmacia', 'nome_comercial' => 'Dipirona', 'preco_centavos' => 1200, 'estoque' => $estoque]);
    }

    public function test_o_card_da_venda_avisa_que_o_saldo_vai_ficar_negativo_sem_bloquear(): void
    {
        $this->logarComo('recepcao', self::T);
        $dipirona = $this->dipirona(1);

        $resposta = $this->comando('registrar_venda', ['itens' => [['catalogo_item_id' => $dipirona->id, 'quantidade' => 3]], 'forma_pagamento' => 'pix'])->assertOk();

        $this->assertSame(['Estoque de Dipirona ficará em -2. Confira a prateleira; a operação não é bloqueada.'], $resposta->json('formulario.avisos'));
        $this->assertNull($resposta->json('formulario.erro'), 'é aviso, não erro');
        $this->assertSame(1, $dipirona->fresh()->estoque, 'a prévia não baixa nada');

        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $resposta->json('proposta'), 'valores' => []])->assertOk()->json('token');
        $this->confirmar($token)->assertOk()->assertJsonPath('tipo', 'resultado_escrita');
        $this->assertSame([-2, 1], [$dipirona->fresh()->estoque, Venda::count()]);
    }

    public function test_o_card_da_cobranca_avisa_o_saldo_negativo_do_que_veio_do_prontuario(): void
    {
        $this->logarComo('recepcao', self::T);
        $dipirona = $this->dipirona(0);
        $vet = $this->usuarioClinica('veterinario');
        $pet = $this->pet();
        $agendamento = $this->agendamentoDireto($pet, $vet, $this->item());
        $prontuarios = app(ProntuarioService::class);
        $prontuario = $prontuarios->abrir(['pet_id' => $pet->id, 'tipo' => 'consulta', 'agendamento_id' => $agendamento->id], $vet->id, self::T);
        $prontuarios->adicionarItem($prontuario->id, $dipirona->id, 2, $vet->id, self::T);
        $prontuarios->assinar($prontuario->id, $vet->id, self::T);

        $resposta = $this->comando('cobrar_atendimento', ['id' => $agendamento->id])->assertOk();

        $this->assertSame(['Estoque de Dipirona ficará em -2. Confira a prateleira; a operação não é bloqueada.'], $resposta->json('formulario.avisos'));
        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $resposta->json('proposta'), 'valores' => []])->assertOk()->json('token');
        $this->confirmar($token)->assertOk();
        $this->assertSame(-2, $dipirona->fresh()->estoque, 'o uso no atendimento nunca é recusado por falta de saldo');
    }

    public function test_sem_saldo_negativo_o_card_nao_tem_aviso(): void
    {
        $this->logarComo('recepcao', self::T);

        $resposta = $this->comando('registrar_venda', ['itens' => [['catalogo_item_id' => $this->dipirona(5)->id, 'quantidade' => 5]]])->assertOk();

        $this->assertNull($resposta->json('formulario.avisos'));
    }

    public function test_a_consulta_do_catalogo_destaca_o_saldo_negativo_com_texto(): void
    {
        $this->logarComo('recepcao', self::T);
        $negativo = $this->dipirona(-3);
        $this->produto(['nome_comercial' => 'Ração', 'estoque' => 4]);
        $this->item(['nome_comercial' => 'Consulta']); // não controla estoque: nunca destacada

        $linhas = collect($this->comando('consultar_catalogo')->assertOk()->json('resultado'))->keyBy('nome_comercial');

        $this->assertSame(['aviso', 'Estoque negativo: confira a prateleira', -3], [$linhas['Dipirona']['destaque'], $linhas['Dipirona']['alerta'], $linhas['Dipirona']['estoque']]);
        $this->assertSame($negativo->id, $linhas['Dipirona']['id']);
        foreach (['Ração', 'Consulta'] as $nome) {
            $this->assertArrayNotHasKey('destaque', $linhas[$nome]);
            $this->assertNull($linhas[$nome]['alerta']);
        }
    }

    public function test_a_baixa_e_um_update_relativo_que_nao_perde_baixa_e_a_venda_trava_os_itens(): void
    {
        $this->mostrarTravas();
        $dipirona = $this->dipirona(1);
        $vendedor = $this->usuarioClinica('recepcao');
        DB::connection()->enableQueryLog();

        $travadas = $this->leiturasTravadas(fn () => app(VendaService::class)->registrar([['catalogo_item_id' => $dipirona->id, 'quantidade' => 2]], $vendedor->id, null, 'pix', self::T));

        // Duas vendas ao mesmo tempo não perdem uma baixa: o UPDATE subtrai do valor do banco, não grava um saldo lido antes.
        $updates = array_filter(array_column(DB::getQueryLog(), 'query'), fn ($sql) => str_starts_with($sql, 'update "catalogo_itens"'));
        $this->assertNotEmpty(array_filter($updates, fn ($sql) => str_contains($sql, '"estoque" = "estoque" - 2')), json_encode($updates));
        $this->assertTravouLeitura($travadas, 'catalogo_itens');
        $this->assertSame(-1, $dipirona->fresh()->estoque);
    }

    public function test_saldo_negativo_nao_impede_corrigir_preco_nem_nome_do_item(): void
    {
        $dipirona = $this->dipirona(-4);

        app(CatalogoService::class)->atualizar($dipirona->id, ['preco_centavos' => 1500, 'nome_comercial' => 'Dipirona 500 mg'], self::T);

        $this->assertSame([1500, 'Dipirona 500 mg', -4], [$dipirona->fresh()->preco_centavos, $dipirona->fresh()->nome_comercial, $dipirona->fresh()->estoque]);
    }

    public function test_o_estoque_inicial_do_cadastro_continua_de_zero_para_cima(): void
    {
        $this->expectException(\App\Exceptions\RegraDeNegocioException::class);
        $this->expectExceptionMessage('O estoque deve ser um número inteiro, de zero a');

        app(CatalogoService::class)->criar(['tipo' => 'produto', 'setor' => 'petshop', 'categoria' => 'racao', 'nome_comercial' => 'X', 'preco_centavos' => 100,
            'controla_estoque' => true, 'estoque' => -1], self::T);
    }

    /** Revisão de 10/11 (achado 4): pet com venda não vai para a lixeira. */
    public function test_pet_com_venda_nao_vai_para_a_lixeira(): void
    {
        $pet = $this->pet();
        app(VendaService::class)->registrar([['catalogo_item_id' => $this->dipirona()->id, 'quantidade' => 1]], $this->usuarioClinica('recepcao')->id, null, 'pix', self::T, petId: $pet->id);

        $resultado = app(PetService::class)->excluir($pet->id, self::T);

        $this->assertSame([true, 'Não é possível excluir: existem vendas vinculadas a este registro.'], [$resultado['bloqueado'], $resultado['motivo']]);
        $this->assertNull(Pet::find($pet->id)->deleted_at);
    }
}
