<?php

namespace Tests\Feature\Clinica;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Venda;
use App\Models\VendaItem;
use App\Services\CatalogoService;
use App\Services\VendaService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

class VendaServiceTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    private VendaService $vendas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->vendas = app(VendaService::class);
    }

    private function recusa(callable $acao, string $frase): void
    {
        try {
            $acao();
            $this->fail("Esperava a recusa: {$frase}");
        } catch (RegraDeNegocioException $e) {
            $this->assertStringContainsString($frase, $e->getMessage());
        }
    }

    public function test_venda_paga_copia_preco_calcula_total_e_baixa_o_estoque(): void
    {
        $racao = $this->produto();
        $petisco = $this->produto(['categoria' => 'petisco', 'nome_comercial' => 'Petisco', 'preco_centavos' => 1590, 'estoque' => 50]);
        $vendedor = $this->usuarioClinica('vendedor');

        $venda = $this->vendas->registrar([['catalogo_item_id' => $racao->id, 'quantidade' => 2], ['catalogo_item_id' => $petisco->id, 'quantidade' => 3]],
            $vendedor->id, null, 'cartao', self::T);

        $this->assertSame(['paga', 'petshop', 2 * 21000 + 3 * 1590, 'cartao'], [$venda->status, $venda->setor, $venda->total_centavos, $venda->forma_pagamento]);
        $this->assertNotNull($venda->pago_em);
        $this->assertSame([8, 47], [$racao->fresh()->estoque, $petisco->fresh()->estoque]);
        $linha = VendaItem::where('catalogo_item_id', $racao->id)->sole();
        $this->assertSame(['Ração 10 kg', 21000, 2, 42000], [$linha->descricao, $linha->preco_unitario_centavos, $linha->quantidade, $linha->subtotal_centavos]);
    }

    public function test_estoque_insuficiente_nao_grava_nada_nem_baixa_os_outros_itens(): void
    {
        $racao = $this->produto();
        $cama = $this->produto(['categoria' => 'cama', 'nome_comercial' => 'Caminha M', 'estoque' => 1]);

        $this->recusa(fn () => $this->vendas->registrar([['catalogo_item_id' => $racao->id, 'quantidade' => 3], ['catalogo_item_id' => $cama->id, 'quantidade' => 2]],
            $this->usuarioClinica('vendedor')->id, null, 'pix', self::T), 'Estoque insuficiente de Caminha M: há 1 e foram pedidos 2. Nada foi gravado.');

        $this->assertSame([10, 1], [$racao->fresh()->estoque, $cama->fresh()->estoque]);
        $this->assertSame([0, 0], [Venda::count(), VendaItem::count()]);
    }

    public function test_estoque_nunca_fica_negativo_vendendo_o_saldo_exato(): void
    {
        $cama = $this->produto(['categoria' => 'cama', 'nome_comercial' => 'Caminha M', 'estoque' => 2]);
        $vendedor = $this->usuarioClinica('vendedor');

        $this->vendas->registrar([['catalogo_item_id' => $cama->id, 'quantidade' => 2]], $vendedor->id, null, null, self::T);
        $this->recusa(fn () => $this->vendas->registrar([['catalogo_item_id' => $cama->id, 'quantidade' => 1]], $vendedor->id, null, null, self::T), 'Estoque insuficiente');

        $this->assertSame(0, $cama->fresh()->estoque);
    }

    public function test_item_que_exige_receita_nao_e_vendido_por_nenhum_papel(): void
    {
        $antibiotico = $this->produto(['setor' => 'clinica', 'categoria' => 'farmacia', 'nome_comercial' => 'Antibiótico', 'exige_receita' => true]);

        foreach (['vendedor', 'recepcao', 'veterinario', 'dono'] as $papel) {
            $this->recusa(fn () => $this->vendas->registrar([['catalogo_item_id' => $antibiotico->id, 'quantidade' => 1]], $this->usuarioClinica($papel)->id, null, 'pix', self::T),
                'exige receita e não pode ser vendido');
        }

        $this->assertSame([0, 10], [Venda::count(), $antibiotico->fresh()->estoque]);
    }

    public function test_venda_e_de_um_setor_so_e_na_clinica_so_farmacia_e_servico_nao_e_vendido(): void
    {
        $vermifugo = $this->produto(['setor' => 'clinica', 'categoria' => 'farmacia', 'nome_comercial' => 'Vermífugo', 'preco_centavos' => 3500]);
        $vendedor = $this->usuarioClinica('recepcao');

        $this->recusa(fn () => $this->vendas->registrar([['catalogo_item_id' => $vermifugo->id, 'quantidade' => 1], ['catalogo_item_id' => $this->produto()->id, 'quantidade' => 1]],
            $vendedor->id, null, null, self::T), 'Uma venda é de um setor só');
        $this->recusa(fn () => $this->vendas->registrar([['catalogo_item_id' => $this->item()->id, 'quantidade' => 1]], $vendedor->id, null, null, self::T), 'é um serviço');

        $venda = $this->vendas->registrar([['catalogo_item_id' => $vermifugo->id, 'quantidade' => 1]], $vendedor->id, null, null, self::T);
        $this->assertSame(['clinica', 'aberta', 3500], [$venda->setor, $venda->status, $venda->total_centavos]);
    }

    public function test_quantidade_precisa_ser_inteira_e_itens_repetidos_sao_somados(): void
    {
        $racao = $this->produto();
        $vendedor = $this->usuarioClinica('vendedor');

        $this->recusa(fn () => $this->vendas->registrar([['catalogo_item_id' => $racao->id, 'quantidade' => 1.5]], $vendedor->id, null, null, self::T), 'inteiro maior que zero');

        $venda = $this->vendas->registrar([['catalogo_item_id' => $racao->id, 'quantidade' => 1], ['catalogo_item_id' => $racao->id, 'quantidade' => 2]], $vendedor->id, null, null, self::T);
        $this->assertSame([3, 63000, 7], [$venda->itens()->sole()->quantidade, $venda->total_centavos, $racao->fresh()->estoque]);
    }

    public function test_pagamento_depois_e_cancelamento_devolve_o_estoque(): void
    {
        $racao = $this->produto();
        $vendedor = $this->usuarioClinica('vendedor');
        $aberta = $this->vendas->registrar([['catalogo_item_id' => $racao->id, 'quantidade' => 4]], $vendedor->id, $this->tutor()->id, null, self::T);

        $this->assertSame(6, $racao->fresh()->estoque);
        $this->assertSame('cancelada', $this->vendas->cancelar($aberta->id, self::T)->status);
        $this->assertSame(10, $racao->fresh()->estoque);

        $outra = $this->vendas->registrar([['catalogo_item_id' => $racao->id, 'quantidade' => 1]], $vendedor->id, null, null, self::T);
        $this->assertSame('paga', $this->vendas->registrarPagamento($outra->id, 'dinheiro', self::T)->status);
        $this->recusa(fn () => $this->vendas->cancelar($outra->id, self::T), 'Só uma venda aberta pode ser cancelada');
        $this->assertSame(9, $racao->fresh()->estoque);
    }

    public function test_cancelamento_devolve_o_que_a_venda_baixou_mesmo_se_o_controle_de_estoque_mudar_depois(): void
    {
        $catalogo = app(CatalogoService::class);
        $vendedor = $this->usuarioClinica('vendedor');
        $racao = $this->produto();
        $petisco = $this->produto(['nome_comercial' => 'Petisco', 'controla_estoque' => false, 'estoque' => 0]);
        $venda = $this->vendas->registrar([['catalogo_item_id' => $racao->id, 'quantidade' => 3], ['catalogo_item_id' => $petisco->id, 'quantidade' => 2]], $vendedor->id, null, null, self::T);

        $this->assertSame([true, false], $venda->itens()->orderBy('catalogo_item_id')->pluck('baixou_estoque')->all());
        $catalogo->atualizar($racao->id, ['controla_estoque' => false], self::T);
        $catalogo->atualizar($petisco->id, ['controla_estoque' => true], self::T);

        $this->vendas->cancelar($venda->id, self::T);

        $this->assertSame([10, 0], [$racao->fresh()->estoque, $petisco->fresh()->estoque]);
    }

    public function test_quantidade_acima_do_teto_e_recusada_sem_erro_tecnico(): void
    {
        $this->recusa(fn () => $this->vendas->registrar([['catalogo_item_id' => $this->produto()->id, 'quantidade' => 5_000_000_000]],
            $this->usuarioClinica('vendedor')->id, null, null, self::T), 'passa do limite');
        $this->assertSame(0, Venda::count());
    }

    public function test_outro_tenant_e_inexistente(): void
    {
        $vendedor = $this->usuarioClinica('vendedor');
        $racaoDeFora = $this->produto([], self::OUTRO_TENANT);

        $this->recusa(fn () => $this->vendas->registrar([['catalogo_item_id' => $racaoDeFora->id, 'quantidade' => 1]], $vendedor->id, null, null, self::T), 'Não encontrei o item do catálogo');
        $this->recusa(fn () => $this->vendas->registrar([['catalogo_item_id' => $this->produto()->id, 'quantidade' => 1]], $vendedor->id, $this->tutor(self::OUTRO_TENANT)->id, null, self::T),
            'Não encontrei o tutor');
        $this->assertSame(10, $racaoDeFora->fresh()->estoque);

        $venda = $this->vendas->registrar([['catalogo_item_id' => $this->produto()->id, 'quantidade' => 1]], $vendedor->id, null, null, self::T);
        $this->expectException(ModelNotFoundException::class);
        $this->vendas->cancelar($venda->id, self::OUTRO_TENANT);
    }
}
