<?php

namespace Tests\Feature\Clinica;

use App\Models\Agendamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

class CalendarioReactTest extends TestCase
{
    use CenarioClinica, RefreshDatabase, SimulaAgente;

    public function test_calendario_agenda_e_remarca_somente_apos_revisao_e_confirmacao(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        config(['sentinel.front' => 'react']);
        $this->prepararAgente();
        $this->logarComo('recepcao', self::T);
        $vet = $this->usuarioClinica('veterinario');
        $servico = $this->item();
        $pet = $this->pet();
        $resposta = $this->comando('agendar_atendimento', ['pet_id' => $pet->id, 'catalogo_item_id' => $servico->id])
            ->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente')->assertJsonStructure(['formulario' => ['ficha', 'campos']]);
        $proposta = $resposta->json('proposta');
        $this->getJson('/agenda/disponibilidade?servico='.$servico->id.'&mes=2026-11')
            ->assertOk()->assertJsonPath('dias.2026-11-11', true);
        $dia = $this->getJson('/agenda/disponibilidade?servico='.$servico->id.'&dia=2026-11-11')->assertOk();
        $horario = collect($dia->json('horarios'))->first(fn ($h) => $h['vagas'] > 0);
        $token = $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => ['profissional_id' => (string) $vet->id, 'inicio' => $horario['inicio']]])
            ->assertOk()->assertJsonPath('tipo', 'revisada')->json('token');
        $this->assertDatabaseCount('agendamentos', 0);
        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk()->assertJsonPath('tipo', 'resultado_escrita');
        $agendamento = Agendamento::sole();

        $remarcar = $this->comando('remarcar_agendamento', ['id' => $agendamento->id])
            ->assertOk()->assertJsonPath('formulario.ficha.agendamento_id', $agendamento->id)->json('proposta');
        $novoDia = $this->getJson('/agenda/disponibilidade?servico='.$servico->id.'&dia=2026-11-12&ignorar='.$agendamento->id)->assertOk();
        $novoHorario = collect($novoDia->json('horarios'))->first(fn ($h) => $h['vagas'] > 0);
        $valores = ['profissional_id' => (string) $vet->id, 'inicio' => $novoHorario['inicio']];
        $antigo = $this->postJson('/agente/revisar', ['proposta' => $remarcar, 'valores' => $valores])->assertOk()->json('token');
        $this->postJson('/agente/editar', ['token' => $antigo])->assertOk();
        $this->postJson('/agente/confirmar', ['token' => $antigo])->assertStatus(422);
        $novo = $this->postJson('/agente/revisar', ['proposta' => $remarcar, 'valores' => $valores])->assertOk()->json('token');
        $this->assertNotSame($antigo, $novo);
        $this->assertSame($horario['inicio'], $agendamento->fresh()->inicio->format('Y-m-d H:i'));
        $this->postJson('/agente/confirmar', ['token' => $novo])->assertOk();
        $this->assertDatabaseCount('agendamentos', 1);
        $this->assertSame($novoHorario['inicio'], $agendamento->fresh()->inicio->format('Y-m-d H:i'));
    }
}
