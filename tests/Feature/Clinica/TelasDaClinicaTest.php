<?php

namespace Tests\Feature\Clinica;

use App\Models\Tutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** P-B1: as telas Cadastros e Lixeira da clínica são só configuração da view genérica (as tools vêm do domínio). */
class TelasDaClinicaTest extends TestCase
{
    use RefreshDatabase;

    public function test_cadastros_da_clinica_mostra_tutores_pets_e_catalogo_e_nada_da_contabilidade(): void
    {
        $this->logarComo('recepcao', 3);

        $pagina = $this->get('/cadastros')->assertOk();

        $pagina->assertSee('<option value="tutores">Tutores</option>', false)
            ->assertSee('<option value="pets">Pets</option>', false)
            ->assertSee('<option value="catalogo">Catálogo</option>', false)
            ->assertDontSee('Lançamentos')
            ->assertDontSee('Fornecedores');
    }

    public function test_novo_so_aparece_para_quem_pode_cadastrar_aquela_entidade(): void
    {
        $this->logarComo('recepcao', 3);
        $entidades = $this->get('/cadastros')->assertOk()->viewData('entidades');
        $this->assertSame(['criar_tutor', 'criar_pet', null], [$entidades['tutores']['criar'], $entidades['pets']['criar'], $entidades['catalogo']['criar']],
            'recepção cadastra tutor e pet, mas o catálogo (preços) é só do dono');

        $this->logarComo('dono', 3);
        $this->assertSame('criar_item_catalogo', $this->get('/cadastros')->viewData('entidades')['catalogo']['criar']);
    }

    public function test_a_consulta_da_tela_usa_a_tool_da_clinica_sem_ia(): void
    {
        $this->logarComo('vendedor', 3);
        Tutor::create(['nome' => 'Ana Souza', 'tenant_id' => 3]);
        Tutor::create(['nome' => 'Ana de Fora', 'tenant_id' => 4]);

        $this->postJson('/agente/consultar', ['tool' => 'consultar_tutores', 'filtros' => ['nome' => 'Ana']])
            ->assertOk()->assertJsonCount(1, 'resultado')->assertJsonPath('resultado.0.nome', 'Ana Souza');
    }

    public function test_lixeira_da_clinica_lista_os_tipos_da_clinica_e_e_do_dono(): void
    {
        $this->logarComo('recepcao', 3);
        $this->get('/lixeira')->assertForbidden();

        $this->logarComo('dono', 3);
        $filtros = $this->get('/lixeira')->assertOk()->viewData('entidades')['lixeira']['filtros'];
        $this->assertSame(['tutor' => 'Tutores', 'pet' => 'Pets', 'item_catalogo' => 'Itens do catálogo'], $filtros[0]['opcoes']);
    }

    public function test_o_chat_tem_o_botao_pedir_ao_agente_e_a_tela_sem_chat_nao(): void
    {
        $this->logarComo('recepcao', 3);

        $this->get('/agente')->assertOk()->assertSee('window.pedirAoAgente = (texto) =>', false)->assertSee("'Pedir ao agente'", false);
        // Cadastros usa a mesma tabela, mas não tem o chat: lá a função não existe e o botão não aparece.
        $this->get('/cadastros')->assertOk()->assertDontSee('window.pedirAoAgente = (texto) =>', false);
    }

    public function test_contabilidade_continua_com_as_telas_de_antes(): void
    {
        $this->logarComo('admin', 1);

        $this->get('/cadastros')->assertOk()->assertSee('<option value="lancamentos">Lançamentos</option>', false)->assertDontSee('Tutores');
        $this->assertArrayHasKey('lancamento', $this->get('/lixeira')->assertOk()->viewData('entidades')['lixeira']['filtros'][0]['opcoes']);
    }
}
