<?php

namespace Tests\Feature\Clinica;

use App\Models\Agendamento;
use App\Models\AuditLog;
use App\Services\AiAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Bloco A (15/11): a agenda no chat segue a mesma regra da tela /agenda (tabela da FASE oito). O vendedor não consulta
 * nem mexe na agenda; o atendente só vê e mexe no banho e tosa; recepção, veterinário e dono veem os dois setores.
 */
class AgendaDoChatPorPapelTest extends TestCase
{
    use CenarioClinica, RefreshDatabase, SimulaAgente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->prepararAgente();
    }

    /** Um horário da clínica e um do banho e tosa, amanhã. */
    private function doisSetores(): array
    {
        $pet = $this->pet();
        $clinica = $this->agendamentoDireto($pet, $this->usuarioClinica('veterinario'), $this->item(),
            ['inicio' => '2026-11-11 09:00', 'fim' => '2026-11-11 09:30', 'status' => 'agendado']);
        $banho = $this->agendamentoDireto($pet, $this->usuarioClinica('atendente'),
            $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho']),
            ['inicio' => '2026-11-11 10:00', 'fim' => '2026-11-11 10:30', 'status' => 'agendado']);

        return [$clinica, $banho];
    }

    public function test_vendedor_nao_consulta_a_agenda_no_chat_nem_na_tela_e_a_tentativa_fica_na_auditoria(): void
    {
        $this->doisSetores();
        $this->logarComo('vendedor', self::T);

        $this->comando('consultar_agenda')->assertStatus(403)->assertJsonPath('tipo', 'negado');
        $this->get('/agenda')->assertForbidden();

        $this->assertSame('negado', AuditLog::query()->where('tool', 'consultar_agenda')->latest('id')->value('resultado'));
    }

    public function test_atendente_so_ve_o_banho_e_tosa_no_chat_mesmo_pedindo_a_clinica(): void
    {
        [, $banho] = $this->doisSetores();
        $this->logarComo('atendente', self::T);

        foreach ([[], ['setor' => 'clinica'], ['setor' => 'petshop']] as $argumentos) {
            $this->assertSame([$banho->id], array_column($this->comando('consultar_agenda', $argumentos)->assertOk()->json('resultado'), 'id'));
        }
    }

    public function test_recepcao_veterinario_e_dono_veem_os_dois_setores_no_chat(): void
    {
        [$clinica, $banho] = $this->doisSetores();

        foreach (['recepcao', 'veterinario', 'dono'] as $papel) {
            $this->logarComo($papel, self::T);
            $ids = array_column($this->comando('consultar_agenda')->assertOk()->json('resultado'), 'id');
            sort($ids);
            $this->assertSame([$clinica->id, $banho->id], $ids, $papel);
        }
    }

    public function test_atendente_nao_agenda_na_clinica_mas_agenda_no_banho_e_tosa(): void
    {
        $this->logarComo('atendente', self::T);
        $base = ['pet_id' => $this->pet()->id, 'inicio' => '2026-11-11 09:00'];

        $proposta = (string) $this->comando('agendar_atendimento', [...$base, 'catalogo_item_id' => $this->item()->id,
            'profissional_id' => $this->usuarioClinica('veterinario')->id])->assertOk()->json('proposta');
        $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])
            ->assertStatus(422)->assertJsonMissingPath('token')
            ->assertJsonPath('mensagem', fn (string $m) => str_contains($m, 'só mexe na agenda do banho e tosa'));
        $this->assertSame(0, Agendamento::count());

        $this->confirmar($this->propor([...$base, 'catalogo_item_id' => $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho'])->id,
            'profissional_id' => $this->usuarioClinica('atendente')->id], 'agendar_atendimento'))->assertOk();
        $this->assertSame('petshop', Agendamento::sole()->setor);
    }

    public function test_atendente_nao_muda_o_status_de_horario_da_clinica_mas_muda_o_do_banho(): void
    {
        [$clinica, $banho] = $this->doisSetores();
        $this->logarComo('atendente', self::T);

        // 15/11 (reviewer): o horário da clínica nem é achado pelo atendente (antes o card abria, com pet, tutor e veterinário, e
        // só o Revisar recusava): fora do setor, o agendamento é inexistente.
        $this->comando('alterar_status_agendamento', ['id' => $clinica->id, 'novo_status' => 'cancelado'])
            ->assertStatus(422)->assertJsonMissingPath('proposta')->assertJsonPath('mensagem', fn (string $m) => str_contains($m, 'Não encontrei'));
        $this->comando('alterar_status_agendamento', ['busca' => 'thor', 'novo_status' => 'cancelado'])->assertOk()
            ->assertJsonPath('tipo', 'confirmacao_pendente')->assertJsonPath('argumentos.id', $banho->id);
        $this->assertSame('agendado', $clinica->fresh()->status);

        $this->confirmar($this->propor(['id' => $banho->id, 'novo_status' => 'cancelado'], 'alterar_status_agendamento'))->assertOk();
        $this->assertSame('cancelado', $banho->fresh()->status);
    }

    public function test_chat_e_tela_usam_a_mesma_lista_de_papeis_da_agenda(): void
    {
        foreach (['atendente', 'vendedor', 'recepcao', 'veterinario', 'dono'] as $papel) {
            $this->logarComo($papel, self::T);
            $agente = app(AiAgentService::class);
            $abre = $agente->setoresDaAgenda() !== [];

            $this->assertSame($abre, $agente->podeExecutar('consultar_agenda'), $papel);
            $this->assertSame($abre ? 200 : 403, $this->get('/agenda')->status(), $papel);
        }
    }
}
