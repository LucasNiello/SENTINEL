<?php

namespace Tests\Feature\Clinica;

use App\Models\AuditLog;
use App\Services\PetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/** 26/11 (UX): depois de gravar pelo chat, o servidor devolve o registro como a consulta o mostraria (a tela o exibe como tabela). */
class RegistroAposConfirmarTest extends TestCase
{
    use CenarioClinica, RefreshDatabase, SimulaAgente;

    public function test_as_consultas_aceitam_filtro_por_id_e_respeitam_o_tenant(): void
    {
        $this->prepararAgente();
        $this->logarComo('dono', self::T);
        $tutor = $this->tutor(self::T, 'Maria Souza');
        $outro = $this->tutor(self::T, 'Joana Lima');
        $pet = $this->pet($tutor, self::T, ['nome' => 'Bolt']);
        $alheio = $this->tutor(self::OUTRO_TENANT, 'Alheia');

        $r = $this->postJson('/agente/consultar', ['tool' => 'consultar_tutores', 'filtros' => ['id' => $tutor->id]])->assertOk();
        $r->assertJsonCount(1, 'resultado')->assertJsonPath('resultado.0.nome', 'Maria Souza');
        $this->postJson('/agente/consultar', ['tool' => 'consultar_pets', 'filtros' => ['id' => $pet->id]])->assertOk()->assertJsonCount(1, 'resultado')->assertJsonPath('resultado.0.nome', 'Bolt');
        $this->postJson('/agente/consultar', ['tool' => 'consultar_tutores', 'filtros' => ['id' => $alheio->id]])->assertOk()->assertJsonCount(0, 'resultado');
        $this->postJson('/agente/consultar', ['tool' => 'consultar_cobrancas', 'filtros' => ['id' => 999]])->assertOk()->assertJsonCount(0, 'resultado');
        $this->postJson('/agente/consultar', ['tool' => 'consultar_vendas', 'filtros' => ['id' => 999]])->assertOk()->assertJsonCount(0, 'resultado');
        $this->postJson('/agente/consultar', ['tool' => 'consultar_catalogo', 'filtros' => ['id' => $this->item()->id]])->assertOk()->assertJsonCount(1, 'resultado');
        $this->assertNotNull($outro->id);
    }

    public function test_a_leitura_extra_fica_na_auditoria_com_origem_agente_e_a_escrita_tem_a_sua_linha(): void
    {
        $this->prepararAgente();
        $this->logarComo('veterinario', self::T);
        $pet = $this->pet();
        app(PetService::class)->arquivarCadastro($pet->id, 'Mudou', self::T);
        $proposta = $this->comando('reativar_pet', ['id' => $pet->id])->assertOk()->json('proposta');
        $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->assertOk();
        $token = $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->json('token');
        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();

        $leitura = AuditLog::where('tool', 'consultar_pets')->where('acao', 'consultar')->latest('id')->first();
        $this->assertNotNull($leitura);
        $this->assertSame('agente', $leitura->origem, 'não é uma consulta manual do usuário');
        $this->assertSame(1, AuditLog::where('tool', 'reativar_pet')->where('resultado', 'sucesso')->count());
    }

    public function test_card_aberto_fora_do_chat_nao_traz_registro_na_resposta(): void
    {
        $this->prepararAgente();
        $this->logarComo('veterinario', self::T);
        $pet = $this->pet();
        app(PetService::class)->arquivarCadastro($pet->id, 'Mudou', self::T);

        // /agente/formulario (Cadastros, Clientes): o card não nasceu de uma fala do chat, então não há tabela a anexar.
        $proposta = $this->postJson('/agente/formulario', ['tool' => 'reativar_pet', 'id' => $pet->id])->assertOk()->json('proposta');
        $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->assertOk();
        $token = $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->json('token');

        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk()->assertJsonPath('tipo', 'resultado_escrita')->assertJsonMissingPath('registro');
    }

    public function test_confirmar_devolve_o_registro_gravado_como_tabela(): void
    {
        $this->prepararAgente();
        $this->logarComo('veterinario', self::T);
        $pet = $this->pet();
        app(PetService::class)->arquivarCadastro($pet->id, 'Mudou', self::T);

        $proposta = $this->comando('reativar_pet', ['id' => $pet->id])->assertOk()->json('proposta');
        $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->assertOk();
        $token = $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->json('token');

        $r = $this->postJson('/agente/confirmar', ['token' => $token])->assertOk()->assertJsonPath('tipo', 'resultado_escrita');
        $r->assertJsonPath('registro.tipo', 'resultado_leitura')->assertJsonPath('registro.tool', 'consultar_pets')
            ->assertJsonPath('registro.resultado.0.id', $pet->id)->assertJsonCount(1, 'registro.resultado');
        $this->assertNotEmpty($r->json('registro.exportar'));
    }
}
