<?php

namespace Tests\Feature\Clinica;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Pet;
use App\Models\Tutor;
use App\Services\CatalogoService;
use App\Services\PetService;
use App\Services\TutorService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

class CadastrosClinicaTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    private function recusa(callable $acao, string $frase): void
    {
        try {
            $acao();
            $this->fail("Esperava a recusa: {$frase}");
        } catch (RegraDeNegocioException $e) {
            $this->assertStringContainsString($frase, $e->getMessage());
        }
    }

    public function test_pet_exige_tutor_do_mesmo_tenant_e_o_tenant_vem_do_parametro(): void
    {
        $pets = app(PetService::class);
        $deFora = $this->tutor(self::OUTRO_TENANT);

        $this->recusa(fn () => $pets->criar(['tutor_id' => $deFora->id, 'nome' => 'Mel', 'especie' => 'gato'], self::T), "Não encontrei o tutor #{$deFora->id}.");

        $pet = $pets->criar(['tutor_id' => $this->tutor()->id, 'nome' => 'Mel', 'especie' => 'gato', 'tenant_id' => 999, 'alertas' => 'Medo de secador'], self::T);
        $this->assertSame([self::T, 'Medo de secador'], [$pet->tenant_id, $pet->alertas]);
        $this->recusa(fn () => $pets->criar(['tutor_id' => $pet->tutor_id, 'nome' => 'Rex', 'especie' => 'dragao'], self::T), 'Informe a espécie');
    }

    public function test_microchip_e_unico_por_tenant(): void
    {
        $pets = app(PetService::class);
        $pets->criar(['tutor_id' => $this->tutor()->id, 'nome' => 'Mel', 'especie' => 'gato', 'microchip' => '985112003456789'], self::T);

        $this->recusa(fn () => $pets->criar(['tutor_id' => $this->tutor()->id, 'nome' => 'Bob', 'especie' => 'cao', 'microchip' => '985112003456789'], self::T),
            'Já existe um pet com este microchip.');

        $pets->criar(['tutor_id' => $this->tutor(self::OUTRO_TENANT)->id, 'nome' => 'Bob', 'especie' => 'cao', 'microchip' => '985112003456789'], self::OUTRO_TENANT);
        $pets->criar(['tutor_id' => $this->tutor()->id, 'nome' => 'Sem chip 1', 'especie' => 'cao'], self::T);
        $pets->criar(['tutor_id' => $this->tutor()->id, 'nome' => 'Sem chip 2', 'especie' => 'cao'], self::T);
        $this->assertSame(4, Pet::count());
    }

    public function test_tutor_com_pet_nao_vai_para_a_lixeira_e_pet_nao_volta_antes_do_tutor(): void
    {
        $tutores = app(TutorService::class);
        $pets = app(PetService::class);
        $tutor = $this->tutor();
        $pet = $this->pet($tutor);

        $this->assertSame('Não é possível excluir: existem pets vinculados a este registro.', $tutores->excluir($tutor->id, self::T)['motivo']);

        $pets->excluir($pet->id, self::T);
        $tutores->excluir($tutor->id, self::T);
        $this->assertSame(0, Tutor::count());

        $this->assertStringContainsString('o tutor #'.$tutor->id, $pets->restaurarDaLixeira($pet->id, self::T, true)['motivo']);
        $this->assertTrue($tutores->restaurarDaLixeira($tutor->id, self::T, true)['restaurado']);
        $this->assertTrue($pets->restaurarDaLixeira($pet->id, self::T, true)['restaurado']);
    }

    public function test_tutor_de_outro_tenant_nao_e_alterado(): void
    {
        $tutor = $this->tutor(self::OUTRO_TENANT, 'Original');

        try {
            app(TutorService::class)->atualizar($tutor->id, ['nome' => 'Trocado'], self::T);
            $this->fail('Esperava ModelNotFoundException');
        } catch (ModelNotFoundException) {
        }

        $this->assertSame('Original', $tutor->fresh()->nome);
    }

    public function test_tutor_pet_e_item_vao_da_lixeira_ao_arquivo_e_sao_excluidos_de_vez(): void
    {
        $tutores = app(TutorService::class);
        $pets = app(PetService::class);
        $dono = $this->usuarioClinica('dono');
        $tutor = $this->tutor();
        $pet = $this->pet($this->tutor(nome: 'Bruno Lima'));
        $item = $this->produto();

        $tutores->excluir($tutor->id, self::T);
        $pets->excluir($pet->id, self::T);
        $item->delete();

        $this->travel((int) config('sentinel.retencao_lixeira_dias') + 1)->days();
        $this->artisan('sentinel:arquivar-expirados')->assertSuccessful();

        // Tutor e pet têm dependentes sem lixeira (vendas, mensagens, agendamentos, prontuários): antes, quebrava.
        $this->assertTrue($tutores->excluirDefinitivo($tutor->id, self::T, $dono->id)['excluido']);
        $this->assertTrue($pets->excluirDefinitivo($pet->id, self::T, $dono->id)['excluido']);
        $this->assertNotNull($item->fresh()->arquivado_em);
        $this->assertSame(0, Tutor::withTrashed()->whereKey($tutor->id)->count() + Pet::withTrashed()->whereKey($pet->id)->count());
    }

    public function test_data_de_nascimento_do_pet_e_conferida(): void
    {
        $pets = app(PetService::class);
        $base = ['tutor_id' => $this->tutor()->id, 'nome' => 'Mel', 'especie' => 'gato'];

        $this->recusa(fn () => $pets->criar([...$base, 'data_nascimento' => '2024-02-31'], self::T), 'data válida no formato AAAA-MM-DD');
        $this->recusa(fn () => $pets->criar([...$base, 'data_nascimento' => '10/05/2024'], self::T), 'data válida no formato AAAA-MM-DD');
        $this->recusa(fn () => $pets->criar([...$base, 'data_nascimento' => now()->addDay()->toDateString()], self::T), 'não pode ser no futuro');

        $pet = $pets->criar([...$base, 'data_nascimento' => '2024-02-29'], self::T);
        $this->assertSame('Mel', $pets->atualizar($pet->id, ['nome' => 'Mel'], self::T)->nome, 'a data já gravada passa no update');
    }

    public function test_regras_do_catalogo(): void
    {
        $catalogo = app(CatalogoService::class);
        // Item A (12/11): o service não tem mais padrão para estes três; a base os informa.
        $base = ['tipo' => 'produto', 'setor' => 'petshop', 'categoria' => 'racao', 'nome_comercial' => 'Ração', 'preco_centavos' => 21000,
            'cobranca_por' => 'unidade', 'controla_estoque' => false, 'exige_receita' => false];

        $this->recusa(fn () => $catalogo->criar([...$base, 'preco_centavos' => 210.5], self::T), 'número inteiro de centavos');
        $this->recusa(fn () => $catalogo->criar([...$base, 'preco_centavos' => '21000'], self::T), 'número inteiro de centavos');
        $this->recusa(fn () => $catalogo->criar([...$base, 'preco_centavos' => 100_000_001], self::T), 'passa do limite');
        $this->recusa(fn () => $catalogo->criar([...$base, 'cobranca_por' => 'diaria'], self::T), 'Só serviço (ex.: internação) é cobrado por diária.');
        $this->recusa(fn () => $catalogo->criar([...$base, 'categoria' => 'vacina'], self::T), 'Categoria inválida para o setor petshop');
        $this->recusa(fn () => $catalogo->criar([...$base, 'exige_receita' => true], self::T), 'Só item de farmácia pode exigir receita.');
        $this->recusa(fn () => $catalogo->criar(['tipo' => 'servico', 'setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho', 'preco_centavos' => 5000,
            'cobranca_por' => 'unidade', 'controla_estoque' => true, 'exige_receita' => false], self::T),
            'Serviço não tem estoque nem receita.');

        $item = $catalogo->criar([...$base, 'controla_estoque' => true, 'estoque' => 5, 'tenant_id' => 999], self::T);
        $this->assertSame([self::T, 21000, 5], [$item->tenant_id, $item->preco_centavos, $item->estoque]);
    }

    /** Item A (12/11): na criação, os três campos são obrigatórios também no service (sem valor padrão). */
    public function test_criacao_sem_um_dos_tres_campos_e_recusada_no_service(): void
    {
        $catalogo = app(CatalogoService::class);
        $completo = ['tipo' => 'produto', 'setor' => 'petshop', 'categoria' => 'racao', 'nome_comercial' => 'Ração', 'preco_centavos' => 21000,
            'cobranca_por' => 'unidade', 'controla_estoque' => true, 'exige_receita' => false];

        $this->recusa(fn () => $catalogo->criar(\Illuminate\Support\Arr::except($completo, 'cobranca_por'), self::T), 'Informe a forma de cobrança');
        $this->recusa(fn () => $catalogo->criar(\Illuminate\Support\Arr::except($completo, 'controla_estoque'), self::T), 'Informe se o item controla estoque');
        $this->recusa(fn () => $catalogo->criar(\Illuminate\Support\Arr::except($completo, 'exige_receita'), self::T), 'Informe se o item exige receita');
        $this->recusa(fn () => $catalogo->criar([...$completo, 'cobranca_por' => null], self::T), 'Informe a forma de cobrança');
        $this->recusa(fn () => $catalogo->criar([...$completo, 'controla_estoque' => 'sim'], self::T), 'Informe se o item controla estoque');
        $this->recusa(fn () => $catalogo->criar([...$completo, 'exige_receita' => null], self::T), 'Informe se o item exige receita');
        $this->assertSame(0, \App\Models\CatalogoItem::count(), 'nada foi gravado com padrão');

        $item = $catalogo->criar($completo, self::T);
        $this->assertSame(['unidade', true, false, 0], [$item->cobranca_por, $item->controla_estoque, $item->exige_receita, $item->estoque]);
        // A edição continua parcial: o que não veio fica como estava.
        $this->assertSame(23000, $catalogo->atualizar($item->id, ['preco_centavos' => 23000], self::T)->preco_centavos);
    }

    public function test_estoque_so_muda_por_entrada_ou_venda_nunca_pelo_atualizar(): void
    {
        $catalogo = app(CatalogoService::class);
        $racao = $this->produto(['estoque' => 5]);

        $catalogo->atualizar($racao->id, ['estoque' => 999, 'preco_centavos' => 22000], self::T);
        $this->assertSame([5, 22000], [$racao->fresh()->estoque, $racao->fresh()->preco_centavos]);

        $this->assertSame(12, $catalogo->entradaDeEstoque($racao->id, 7, self::T)->estoque);
        $this->recusa(fn () => $catalogo->entradaDeEstoque($racao->id, 99_989, self::T), 'acima de 100000');
        $this->assertSame(100_000, $catalogo->entradaDeEstoque($racao->id, 99_988, self::T)->estoque);
        $this->assertSame(23000, $catalogo->atualizar($racao->id, ['preco_centavos' => 23000], self::T)->preco_centavos, 'no teto, o item segue editável');
        $this->recusa(fn () => $catalogo->entradaDeEstoque($this->item()->id, 1, self::T), 'não controla estoque');
    }
}
