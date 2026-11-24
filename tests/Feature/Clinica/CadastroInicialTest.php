<?php

namespace Tests\Feature\Clinica;

use App\Services\AiAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

/**
 * 24/11: o card "Estoque" do painel do dono abre /cadastros?cadastro=catalogo. A tela só aceita o parâmetro para um cadastro que o
 * papel pode consultar; valor ausente, inválido ou não permitido abre em Tutores (o primeiro). Não mexe em consultar_catalogo.
 */
class CadastroInicialTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    public function test_parametro_valido_abre_o_catalogo(): void
    {
        $this->logarComo('dono', self::T);

        $resposta = $this->get('/cadastros?cadastro=catalogo')->assertOk();

        $this->assertSame('catalogo', $resposta->viewData('cadastroInicial'));
        $resposta->assertSee('const CADASTRO_INICIAL = "catalogo"', false);
    }

    public function test_sem_parametro_ou_com_valor_invalido_abre_em_tutores(): void
    {
        $this->logarComo('dono', self::T);

        $this->assertNull($this->get('/cadastros')->assertOk()->viewData('cadastroInicial'));
        foreach (['inexistente', '', 'lixeira', '../catalogo', 'CATALOGO'] as $valor) {
            $this->assertNull($this->get('/cadastros?'.http_build_query(['cadastro' => $valor]))->assertOk()->viewData('cadastroInicial'), "valor: [$valor]");
        }
        $this->assertNull($this->get('/cadastros?cadastro[]=catalogo')->assertOk()->viewData('cadastroInicial'), 'array não vale');
    }

    public function test_cadastro_que_o_papel_nao_consulta_abre_em_tutores(): void
    {
        $this->logarComo('recepcao', self::T);
        // O papel perde só a consulta ao catálogo; o resto do RBAC fica como está.
        $this->partialMock(AiAgentService::class, fn ($m) => $m->shouldReceive('podeExecutar')->andReturnUsing(fn (string $tool) => $tool !== 'consultar_catalogo'));

        $resposta = $this->get('/cadastros?cadastro=catalogo')->assertOk();

        $this->assertNull($resposta->viewData('cadastroInicial'));
        $resposta->assertSee('const CADASTRO_INICIAL = null', false);
    }

    public function test_cadastro_de_outro_dominio_nao_vale_na_clinica_nem_o_inverso(): void
    {
        $this->logarComo('operador', 1);
        $this->assertNull($this->get('/cadastros?cadastro=catalogo')->assertOk()->viewData('cadastroInicial'), 'o catálogo não existe fora da clínica');
        $this->assertSame('clientes', $this->get('/cadastros?cadastro=clientes')->viewData('cadastroInicial'));

        $this->logarComo('dono', self::T);
        $this->assertNull($this->get('/cadastros?cadastro=clientes')->viewData('cadastroInicial'), 'clientes não existe na clínica');
    }

    public function test_card_de_estoque_abre_o_catalogo_e_nao_promete_a_mesma_contagem(): void
    {
        $this->produto(['estoque' => 3]);
        $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho']); // serviço: não controla estoque
        $this->logarComo('dono', self::T);

        $resposta = $this->get('/painel')->assertOk();

        $estoque = collect($resposta->viewData('indicadores'))->firstWhere('titulo', 'Estoque');
        $this->assertSame('1 item', $estoque['numero']);
        $this->assertSame('/cadastros?cadastro=catalogo', $estoque['link']);
        $this->assertStringContainsString('controle de estoque', $estoque['nota']);
        $this->assertStringContainsString('também lista serviços', $estoque['nota']);
        $resposta->assertSee('href="/cadastros?cadastro=catalogo"', false)->assertSee('também lista serviços');
    }
}
