<?php

namespace Tests\Feature\Clinica;

use App\Models\Cobranca;
use App\Models\Estorno;
use App\Services\CobrancaService;
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
}
