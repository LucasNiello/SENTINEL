<?php

namespace Tests\Feature\Clinica;

use App\Models\CatalogoItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Preço em reais (23/11): o card do catálogo mostra e aceita R$ com duas casas ("80,00"); o banco e as regras seguem em
 * centavos inteiros. A IA continua mandando centavos (o schema da tool não mudou).
 */
class PrecoEmReaisTest extends TestCase
{
    use CenarioClinica, RefreshDatabase, SimulaAgente;

    private const BASE = ['tipo' => 'servico', 'setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho premium',
        'preco_centavos' => 8000, 'cobranca_por' => 'unidade', 'controla_estoque' => 'nao', 'exige_receita' => 'nao'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAgente();
        $this->logarComo('dono', self::T);
    }

    private function campos($resposta): \Illuminate\Support\Collection
    {
        return collect($resposta->json('formulario.campos'))->keyBy('nome');
    }

    public function test_o_card_mostra_o_preco_em_reais_e_nunca_em_centavos(): void
    {
        $resposta = $this->comando('criar_item_catalogo', self::BASE)->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente');

        $preco = $this->campos($resposta)['preco_centavos'];
        $this->assertSame('80,00', $preco['valor']);
        $this->assertSame('Preço (R$)', $preco['rotulo']);
        $this->assertTrue($preco['moeda']);
        $this->assertSame('numero', $preco['tipo']);
        $this->assertArrayNotHasKey('inteiro', $preco);
        $this->assertEquals(1000000, $preco['max'], 'o teto também em reais');
        $this->assertStringNotContainsString('centavos', $preco['rotulo']);
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function digitados(): array
    {
        return ['com vírgula' => ['80,50', 8050], 'inteiro' => ['45', 4500], 'uma casa' => ['45,9', 4590], 'milhar' => ['1.250,00', 125000], 'com R$' => ['R$ 12,34', 1234]];
    }

    #[DataProvider('digitados')]
    public function test_o_que_o_dono_digita_em_reais_vira_centavos_no_banco(string $digitado, int $centavos): void
    {
        $proposta = (string) $this->comando('criar_item_catalogo', self::BASE)->assertOk()->json('proposta');

        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => ['preco_centavos' => $digitado]])->assertOk()->json('token');
        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();

        $this->assertSame($centavos, CatalogoItem::query()->where('nome_comercial', 'Banho premium')->sole()->preco_centavos);
    }

    public function test_confirmar_sem_mexer_no_preco_grava_o_que_a_ia_propos(): void
    {
        $proposta = (string) $this->comando('criar_item_catalogo', self::BASE)->assertOk()->json('proposta');

        // O front devolve o que o card mostrou: "80,00" (texto em reais).
        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => ['preco_centavos' => '80,00']])->assertOk()->json('token');
        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();

        $this->assertSame(8000, CatalogoItem::query()->where('nome_comercial', 'Banho premium')->sole()->preco_centavos);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalidos(): array
    {
        return ['letras' => ['abc', 'em reais'], 'três casas' => ['45,999', 'em reais'], 'negativo' => ['-5', 'não pode ser negativo'],
            'passa do teto' => ['1.000.000,01', 'limite de R$ 1.000.000,00']];
    }

    #[DataProvider('invalidos')]
    public function test_preco_invalido_e_recusado_em_reais_e_nada_e_gravado(string $digitado, string $frase): void
    {
        $proposta = (string) $this->comando('criar_item_catalogo', self::BASE)->assertOk()->json('proposta');

        $resposta = $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => ['preco_centavos' => $digitado]])->assertStatus(422);

        $mensagem = (string) ($resposta->json('erros.preco_centavos') ?? $resposta->json('mensagem'));
        $this->assertStringContainsString($frase, $mensagem);
        $this->assertStringNotContainsString('centavos', $mensagem);
        $this->assertSame(0, CatalogoItem::count());
    }

    public function test_o_card_de_alterar_mostra_o_preco_atual_em_reais(): void
    {
        $item = $this->item(['nome_comercial' => 'Vacina V10', 'categoria' => 'vacina', 'preco_centavos' => 9050]);

        $resposta = $this->postJson('/agente/formulario', ['tool' => 'atualizar_item_catalogo', 'id' => $item->id])->assertOk();

        $this->assertSame('90,50', $this->campos($resposta)['preco_centavos']['valor']);
    }

    public function test_a_lista_continua_em_centavos_para_o_front_formatar_e_o_banco_nao_mudou(): void
    {
        $item = $this->item(['preco_centavos' => 9050]);

        $linha = collect($this->postJson('/agente/consultar', ['tool' => 'consultar_catalogo', 'filtros' => []])->assertOk()->json('resultado'))->firstWhere('id', $item->id);

        $this->assertSame(9050, $linha['preco_centavos']);
        $this->assertSame(9050, $item->fresh()->preco_centavos);
    }
}
