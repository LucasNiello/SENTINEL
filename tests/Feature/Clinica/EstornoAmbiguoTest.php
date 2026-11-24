<?php

namespace Tests\Feature\Clinica;

use App\Models\Cobranca;
use App\Models\Estorno;
use App\Models\Venda;
use App\Services\CobrancaService;
use App\Services\VendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * 23/11: pedido de estorno pelo nome do pet com mais de uma cobrança paga candidata. O servidor não escolhe nem abre
 * proposta: devolve a lista pedindo qual (a IA nunca decide o alvo por nome). Só um candidato abre a proposta.
 */
class EstornoAmbiguoTest extends TestCase
{
    use CenarioClinica, RefreshDatabase, SimulaAgente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->prepararAgente();
        $this->logarComo('recepcao', self::T);
    }

    private function paga(bool $comVermifugo = false): Cobranca
    {
        $cobrancas = app(CobrancaService::class);
        $cobranca = $cobrancas->abrir($this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item())->id, self::T);
        if ($comVermifugo) {
            $cobrancas->adicionarItem($cobranca->id, $this->produto(["setor" => "clinica", "categoria" => "farmacia", "nome_comercial" => "Vermífugo", "preco_centavos" => 3500, "estoque" => 5])->id, 2, self::T);
        }
        $cobrancas->fechar($cobranca->id, self::T);

        return $cobrancas->registrarPagamento($cobranca->id, 'pix', self::T);
    }

    public function test_mais_de_uma_cobranca_paga_do_mesmo_nome_lista_e_nao_abre_proposta(): void
    {
        $a = $this->paga();
        $b = $this->paga();

        $resposta = $this->comando('estornar_cobranca', ['busca' => 'Thor', 'motivo' => 'Cobrado a mais.', 'modo' => 'tudo'])->assertOk();

        $resposta->assertJsonPath('tipo', 'resultado_leitura')->assertJsonMissingPath('proposta');
        $this->assertStringContainsString('Escolha um na lista', (string) $resposta->json('aviso'));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $resposta->json('resultado.*.id'));
        $this->assertSame(0, Estorno::count());
        $this->assertSame([], (array) session('confirmacoes', []), 'nenhum rascunho aberto');
    }

    public function test_a_lista_de_candidatas_distingue_as_cobrancas_pela_data_e_pelo_total(): void
    {
        $simples = $this->paga();
        $maior = $this->paga(comVermifugo: true);

        $linhas = collect($this->comando('estornar_cobranca', ['busca' => 'Thor', 'motivo' => 'Erro.', 'modo' => 'tudo'])
            ->assertOk()->assertJsonPath('colunas', ['id', 'data', 'pet', 'total'])->json('resultado'))->keyBy('id');

        $this->assertSame('R$ 150,00', $linhas[$simples->id]['total']);
        $this->assertSame('R$ 220,00', $linhas[$maior->id]['total']);
        $this->assertNotSame($linhas[$simples->id]['total'], $linhas[$maior->id]['total']);
        $this->assertMatchesRegularExpression('#^10/11/2026 \d{2}:\d{2}$#', $linhas[$simples->id]['data']);
        $this->assertSame('Estornar', $linhas[$simples->id]['acao']['rotulo']);
    }

    public function test_so_um_candidato_abre_a_proposta(): void
    {
        $a = $this->paga();

        $this->comando('estornar_cobranca', ['busca' => 'Thor', 'motivo' => 'Cobrado a mais.', 'modo' => 'tudo'])
            ->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente')->assertJsonPath('argumentos.id', $a->id);
    }

    /** Venda avulsa paga, do pet dado, com a quantidade dada de ração (210,00 cada) — o total distingue as candidatas. */
    private function vendaPaga(\App\Models\Pet $pet, int $quantidade, int $tenant = self::T): Venda
    {
        return app(VendaService::class)->registrar([['catalogo_item_id' => $this->produto([], $tenant)->id, 'quantidade' => $quantidade]],
            $this->usuarioClinica('vendedor', $tenant)->id, $pet->tutor_id, 'pix', $tenant, $pet->id);
    }

    public function test_cobranca_de_outro_tenant_com_o_mesmo_nome_nao_entra_na_lista_de_candidatas(): void
    {
        $a = $this->paga();
        $b = $this->paga();
        $deFora = app(CobrancaService::class);
        $alheia = $deFora->abrir($this->agendamentoDireto($this->pet(null, self::OUTRO_TENANT), $this->usuarioClinica('veterinario', self::OUTRO_TENANT),
            $this->item([], self::OUTRO_TENANT))->id, self::OUTRO_TENANT);
        $deFora->fechar($alheia->id, self::OUTRO_TENANT);
        $deFora->registrarPagamento($alheia->id, 'pix', self::OUTRO_TENANT); // "Thor" também no outro tenant

        $resposta = $this->comando('estornar_cobranca', ['busca' => 'Thor', 'motivo' => 'Erro.', 'modo' => 'tudo'])->assertOk()->assertJsonPath('tipo', 'resultado_leitura');

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $resposta->json('resultado.*.id'));
        $this->assertNotContains($alheia->id, $resposta->json('resultado.*.id'));
        $this->assertSame(0, Estorno::count());
    }

    public function test_a_lista_de_candidatas_da_venda_distingue_pela_data_e_pelo_total_e_nao_traz_outro_tenant(): void
    {
        $pet = $this->pet();
        $simples = $this->vendaPaga($pet, 1);
        $maior = $this->vendaPaga($pet, 2);
        $alheia = $this->vendaPaga($this->pet(null, self::OUTRO_TENANT), 3, self::OUTRO_TENANT); // pet "Thor" no outro tenant

        $resposta = $this->comando('estornar_venda', ['busca' => 'Thor', 'motivo' => 'Erro.', 'modo' => 'tudo'])
            ->assertOk()->assertJsonPath('tipo', 'resultado_leitura')->assertJsonPath('colunas', ['id', 'data', 'tutor', 'pet', 'total']);
        $linhas = collect($resposta->json('resultado'))->keyBy('id');

        $this->assertEqualsCanonicalizing([$simples->id, $maior->id], $linhas->keys()->all());
        $this->assertFalse($linhas->has($alheia->id), 'a venda do outro tenant não aparece');
        $this->assertSame('R$ 210,00', $linhas[$simples->id]['total']);
        $this->assertSame('R$ 420,00', $linhas[$maior->id]['total']);
        $this->assertMatchesRegularExpression('#^10/11/2026 \d{2}:\d{2}$#', $linhas[$simples->id]['data']);
        $this->assertSame('Ana Souza', $linhas[$simples->id]['tutor']);
        $this->assertSame('Thor', $linhas[$simples->id]['pet']);
    }
}
