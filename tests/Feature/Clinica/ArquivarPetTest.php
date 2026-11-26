<?php

namespace Tests\Feature\Clinica;

use App\Models\AuditLog;
use App\Models\Pet;
use App\Services\PetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/** 27/11: arquivar o cadastro do pet (sem apagar), bloqueio com agendamento futuro, seletores, papéis, tenant e auditoria. */
class ArquivarPetTest extends TestCase
{
    use CenarioClinica, RefreshDatabase, SimulaAgente;

    private function arquivar(Pet $pet, string $motivo = 'Falecido')
    {
        $token = $this->propor(['id' => $pet->id, 'observacoes' => $motivo], 'arquivar_pet');

        return $this->confirmar($token);
    }

    public function test_arquivar_grava_motivo_audita_e_pet_some_do_seletor_de_agendar(): void
    {
        $this->prepararAgente();
        $this->logarComo('recepcao', self::T);
        $pet = $this->pet();
        $outro = $this->pet(null, self::T, ['nome' => 'Rex']);

        $this->arquivar($pet)->assertOk()->assertJsonPath('tipo', 'resultado_escrita');

        $pet->refresh();
        $this->assertNotNull($pet->inativado_em);
        $this->assertSame('Arquivado: Falecido', $pet->observacoes);
        $this->assertNull($pet->deleted_at);
        $this->assertSame(1, AuditLog::query()->where('tool', 'arquivar_pet')->where('resultado', 'sucesso')->count());

        $campos = array_column($this->comando('agendar_atendimento', [])->assertOk()->json('formulario.campos'), null, 'nome');
        $valores = array_column($campos['pet_id']['opcoes'], 'valor');
        $this->assertNotContains($pet->id, $valores);
        $this->assertContains($outro->id, $valores);
    }

    public function test_pet_com_agendamento_futuro_nao_e_arquivado(): void
    {
        $this->prepararAgente();
        $this->logarComo('recepcao', self::T);
        $pet = $this->pet();
        $this->agendamentoDireto($pet, $this->usuarioClinica('veterinario'), $this->item(), [
            'inicio' => now()->addDays(2), 'fim' => now()->addDays(2)->addHour(), 'status' => 'agendado',
        ]);

        $resposta = $this->arquivar($pet);
        $this->assertNotSame(200, $resposta->status());
        $this->assertNull($pet->fresh()->inativado_em);
    }

    public function test_agendamento_futuro_cancelado_nao_bloqueia_e_passado_tambem_nao(): void
    {
        $servico = app(PetService::class);
        $pet = $this->pet();
        $vet = $this->usuarioClinica('veterinario');
        $item = $this->item();
        $this->agendamentoDireto($pet, $vet, $item, ['inicio' => now()->addDay(), 'fim' => now()->addDay()->addHour(), 'status' => 'cancelado']);
        $this->agendamentoDireto($pet, $vet, $item); // realizado, no passado

        $this->assertNull($servico->motivoParaNaoArquivar($pet->id, self::T));
    }

    public function test_reativar_devolve_o_pet_aos_seletores_e_e_um_clique_sem_segurar(): void
    {
        $this->prepararAgente();
        $this->logarComo('veterinario', self::T);
        $pet = $this->pet();
        $this->arquivar($pet)->assertOk();

        $revisao = $this->comando('reativar_pet', ['id' => $pet->id])->assertOk()->json('proposta');
        $this->postJson('/agente/revisar', ['proposta' => $revisao, 'valores' => []])->assertOk()->assertJsonPath('segurar', false);
        $token = $this->postJson('/agente/revisar', ['proposta' => $revisao, 'valores' => []])->json('token');
        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();

        $this->assertNull($pet->fresh()->inativado_em);
    }

    public function test_arquivar_exige_segurar_e_motivo(): void
    {
        $this->prepararAgente();
        $this->logarComo('recepcao', self::T);
        $pet = $this->pet();

        $proposta = $this->comando('arquivar_pet', ['id' => $pet->id])->assertOk()->json('proposta');
        $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => ['observacoes' => '']])->assertStatus(422);
        $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => ['observacoes' => 'Mudou']])
            ->assertOk()->assertJsonPath('segurar', true);
    }

    public function test_atendente_e_vendedor_nao_arquivam(): void
    {
        $this->prepararAgente();
        foreach (['atendente', 'vendedor'] as $papel) {
            $this->logarComo($papel, self::T);
            $pet = $this->pet();
            $this->comando('arquivar_pet', ['id' => $pet->id, 'observacoes' => 'x'])->assertStatus(403);
            $this->assertNull($pet->fresh()->inativado_em);
        }
    }

    public function test_nao_arquiva_pet_de_outro_tenant(): void
    {
        $this->prepararAgente();
        $this->logarComo('recepcao', self::T);
        $alheio = $this->pet(null, self::OUTRO_TENANT);

        $this->assertNotNull(app(PetService::class)->motivoParaNaoArquivar($alheio->id, self::T));
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(PetService::class)->arquivarCadastro($alheio->id, 'x', self::T);
    }

    public function test_pet_arquivado_nao_agenda(): void
    {
        $pet = $this->pet();
        app(PetService::class)->arquivarCadastro($pet->id, 'Falecido', self::T);
        $servico = $this->item();
        $vet = $this->usuarioClinica('veterinario');

        $this->expectException(\App\Exceptions\RegraDeNegocioException::class);
        $this->expectExceptionMessage('arquivado');
        app(\App\Services\AgendaService::class)->agendar([
            'pet_id' => $pet->id, 'catalogo_item_id' => $servico->id, 'profissional_id' => $vet->id,
            'inicio' => Carbon::now()->addDays(3)->format('Y-m-d').' 10:00',
        ], self::T);
    }

    public function test_pet_arquivado_nao_vende(): void
    {
        $pet = $this->pet();
        app(PetService::class)->arquivarCadastro($pet->id, 'Falecido', self::T);
        $vendedor = $this->usuarioClinica('recepcao');
        $item = $this->item(['tipo' => 'produto', 'categoria' => 'racao', 'nome_comercial' => 'Ração', 'estoque' => 10, 'controla_estoque' => true]);

        $this->expectException(\App\Exceptions\RegraDeNegocioException::class);
        $this->expectExceptionMessage('arquivado');
        app(\App\Services\VendaService::class)->previa([['catalogo_item_id' => $item->id, 'quantidade' => 1]], $vendedor->id, null, self::T, $pet->id);
    }

    public function test_agendamento_em_aberto_de_hoje_cedo_tambem_bloqueia_o_arquivamento(): void
    {
        $pet = $this->pet();
        $this->agendamentoDireto($pet, $this->usuarioClinica('veterinario'), $this->item(), ['status' => 'agendado']); // começou há 2 h e ninguém fechou

        $this->assertStringContainsString('em aberto', (string) app(PetService::class)->motivoParaNaoArquivar($pet->id, self::T));
    }

    public function test_arquivar_preserva_as_observacoes_que_o_pet_ja_tinha(): void
    {
        $pet = $this->pet(null, self::T, ['observacoes' => 'Tutor prefere WhatsApp']);
        app(PetService::class)->arquivarCadastro($pet->id, 'Mudou de cidade', self::T);

        $this->assertSame('Arquivado: Mudou de cidade | Tutor prefere WhatsApp', $pet->fresh()->observacoes);
    }
}
