<?php

namespace Tests\Feature\Clinica;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Agendamento;
use App\Models\AuditLog;
use App\Models\Cobranca;
use App\Models\User;
use App\Services\AgendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\ProvaDeTravas;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Ficha de agendamento (decisão do Lucas, 15/11): só as travas de segurança e da tool nova (sem UI nem frase do prompt).
 * - O calendário (GET /agenda/disponibilidade) não devolve pet nem tutor e não enxerga outro tenant;
 * - vendedor não consulta a agenda; atendente não chega à da clínica (calendário nem troca de serviço);
 * - troca de serviço recusada com cobrança válida; a "versao" mudada recusa remarcar, trocar o serviço e mudar o status;
 * - a troca de serviço altera o MESMO agendamento (mesmo setor e troca de setor), nunca duplica.
 */
class FichaDeAgendamentoTest extends TestCase
{
    use CenarioClinica, ProvaDeTravas, RefreshDatabase, SimulaAgente;

    private AgendaService $agenda;

    private User $vet;

    private User $atendente;

    protected function setUp(): void
    {
        parent::setUp();
        // 10/11/2026 é uma terça; o 11/11 (quarta) é dia de expediente.
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->agenda = app(AgendaService::class);
        $this->vet = $this->usuarioClinica('veterinario');
        $this->atendente = $this->usuarioClinica('atendente');
        $this->prepararAgente();
    }

    private function banho(): \App\Models\CatalogoItem
    {
        return $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho porte pequeno']);
    }

    private function recusa(callable $acao, string $frase): void
    {
        try {
            $acao();
            $this->fail("Esperava a recusa: {$frase}");
        } catch (RegraDeNegocioException $e) {
            $this->assertStringContainsString($frase, $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- calendário: sem dado de pet/tutor, sem outro tenant

    public function test_disponibilidade_nao_devolve_pet_nem_tutor_e_nao_enxerga_outro_tenant(): void
    {
        $this->logarComo('recepcao', self::T);
        $consulta = $this->item();
        $this->agendamentoDireto($this->pet($this->tutor(self::T, 'Carla Nogueira'), self::T, ['nome' => 'Luna']), $this->vet, $consulta,
            ['inicio' => '2026-11-11 14:00', 'fim' => '2026-11-11 15:00', 'status' => 'agendado']);

        // Outro tenant: o mesmo veterinário (dado inconsistente de propósito), o serviço e um veterinário de lá.
        $outroItem = $this->item([], self::OUTRO_TENANT);
        $outroVet = $this->usuarioClinica('veterinario', self::OUTRO_TENANT);
        $deLa = $this->agendamentoDireto($this->pet(null, self::OUTRO_TENANT, ['nome' => 'Segredo']), $this->vet, $outroItem,
            ['inicio' => '2026-11-11 14:00', 'fim' => '2026-11-11 15:00', 'status' => 'agendado', 'tenant_id' => self::OUTRO_TENANT]);

        $resposta = $this->getJson('/agenda/disponibilidade?servico='.$consulta->id.'&dia=2026-11-11')->assertOk();
        $corpo = $resposta->getContent();
        foreach (['Luna', 'Carla Nogueira', 'Segredo', $outroVet->name] as $proibido) {
            $this->assertStringNotContainsString($proibido, $corpo, "o calendário não pode mostrar {$proibido}");
        }
        $this->assertSame([['id' => $this->vet->id, 'nome' => $this->vet->name]], $resposta->json('profissionais'), 'só os veterinários do tenant');
        $quatorze = collect($resposta->json('horarios'))->firstWhere('hora', '14:00');
        $this->assertSame(3, $quatorze['vagas'], 'o agendamento do outro tenant não conta');
        $this->assertSame(['hora', 'inicio', 'vagas', 'profissionais'], array_keys($quatorze), 'só hora, vagas e quem tem vaga');

        // Serviço e profissional de outro tenant: tratados como inexistentes. "ignorar" de outro tenant não muda nada.
        $this->getJson('/agenda/disponibilidade?servico='.$outroItem->id.'&dia=2026-11-11')->assertStatus(422)->assertJsonPath('mensagem', 'Não encontrei o serviço.');
        $this->getJson('/agenda/disponibilidade?servico='.$consulta->id.'&dia=2026-11-11&profissional='.$outroVet->id)->assertStatus(422)
            ->assertJsonPath('mensagem', 'Não encontrei o profissional.');
        $this->getJson('/agenda/disponibilidade?servico='.$consulta->id.'&mes=2026-11&ignorar='.$deLa->id)->assertOk()
            ->assertDontSee('Segredo');
    }

    // ---------------------------------------------------------------- papéis: vendedor e atendente

    public function test_vendedor_e_atendente_nao_chegam_a_agenda_da_clinica(): void
    {
        $consulta = $this->item();
        $banho = $this->banho();
        $doPetshop = $this->agendamentoDireto($this->pet(null, self::T, ['nome' => 'Bidu']), $this->atendente, $banho,
            ['inicio' => '2026-11-11 09:00', 'fim' => '2026-11-11 10:00', 'status' => 'agendado']);

        $this->logarComo('vendedor', self::T);
        $this->getJson('/agenda/disponibilidade?servico='.$banho->id.'&dia=2026-11-11')->assertStatus(403);
        $this->comando('alterar_servico_agendamento', ['id' => $doPetshop->id, 'catalogo_item_id' => $consulta->id])->assertStatus(403)->assertJsonPath('tipo', 'negado');

        $this->logarComo('atendente', self::T);
        $this->getJson('/agenda/disponibilidade?servico='.$consulta->id.'&dia=2026-11-11')->assertStatus(422)->assertJsonPath('mensagem', 'Não encontrei o serviço.');
        $this->getJson('/agenda/disponibilidade?servico='.$banho->id.'&dia=2026-11-11')->assertOk()->assertJsonPath('setor', 'petshop');

        // O atendente não leva o banho para a clínica: o Revisar recusa e nada muda.
        $proposta = (string) $this->comando('alterar_servico_agendamento', ['id' => $doPetshop->id, 'catalogo_item_id' => $consulta->id])->assertOk()->json('proposta');
        $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => ['profissional_id' => $this->vet->id, 'inicio' => '2026-11-11 14:00']])
            ->assertStatus(422)->assertJsonMissingPath('token');
        $this->assertSame([$banho->id, 'petshop'], [$doPetshop->fresh()->catalogo_item_id, $doPetshop->fresh()->setor]);
    }

    // ---------------------------------------------------------------- troca de serviço: cobrança válida

    public function test_troca_de_servico_recusada_com_cobranca_valida_e_liberada_com_a_cancelada(): void
    {
        $tosa = $this->item(['setor' => 'petshop', 'categoria' => 'tosa', 'nome_comercial' => 'Tosa completa']);
        $ag = $this->agendamentoDireto($this->pet(), $this->atendente, $this->banho(), ['inicio' => '2026-11-11 09:00', 'fim' => '2026-11-11 10:00', 'status' => 'agendado']);
        $cobranca = Cobranca::create(['agendamento_id' => $ag->id, 'setor' => 'petshop', 'status' => 'aberta', 'total_centavos' => 5000, 'tenant_id' => self::T]);

        $this->recusa(fn () => $this->agenda->trocarServico($ag->id, $tosa->id, $this->atendente->id, '2026-11-11 09:00', self::T), 'já tem cobrança');
        $this->assertNotNull($this->agenda->motivoParaNaoTrocarServico($ag->id, $tosa->id, $this->atendente->id, '2026-11-11 09:00', self::T));

        $cobranca->update(['status' => 'cancelada']);
        $this->agenda->trocarServico($ag->id, $tosa->id, $this->atendente->id, '2026-11-11 09:00', self::T);
        $this->assertSame($tosa->id, $ag->fresh()->catalogo_item_id);
    }

    // ---------------------------------------------------------------- versão

    public function test_versao_mudada_recusa_remarcar_trocar_servico_e_mudar_status_no_service(): void
    {
        $tosa = $this->item(['setor' => 'petshop', 'categoria' => 'tosa', 'nome_comercial' => 'Tosa completa']);
        $ag = $this->agendamentoDireto($this->pet(), $this->atendente, $this->banho(), ['inicio' => '2026-11-11 09:00', 'fim' => '2026-11-11 10:00', 'status' => 'agendado']);
        $antiga = (string) $ag->getRawOriginal('updated_at');
        $this->travel(5)->seconds();
        $ag->update(['status' => 'confirmado']); // outra pessoa mexeu depois que a ficha abriu

        $this->recusa(fn () => $this->agenda->remarcar($ag->id, '2026-11-12 09:00', self::T, null, $antiga), AgendaService::RECUSA_VERSAO);
        $this->recusa(fn () => $this->agenda->trocarServico($ag->id, $tosa->id, $this->atendente->id, '2026-11-11 09:00', self::T, $antiga), AgendaService::RECUSA_VERSAO);
        $this->recusa(fn () => $this->agenda->alterarStatus($ag->id, 'realizado', self::T, $antiga), AgendaService::RECUSA_VERSAO);
        $this->assertSame(AgendaService::RECUSA_VERSAO, $this->agenda->motivoParaNaoRemarcar($ag->id, '2026-11-12 09:00', self::T, null, $antiga));

        $ag->refresh();
        $this->assertSame(['2026-11-11 09:00', 'confirmado', 'petshop'], [$ag->inicio->format('Y-m-d H:i'), $ag->status, $ag->setor]);
        // Com a versão atual, passa.
        $this->agenda->remarcar($ag->id, '2026-11-12 09:00', self::T, null, (string) $ag->getRawOriginal('updated_at'));
        $this->assertSame('2026-11-12 09:00', $ag->fresh()->inicio->format('Y-m-d H:i'));
    }

    public function test_versao_mudada_entre_o_revisar_e_o_confirmar_recusa_e_nada_e_gravado(): void
    {
        $this->logarComo('recepcao', self::T);
        $ag = $this->agendamentoDireto($this->pet(), $this->vet, $this->item(), ['inicio' => '2026-11-11 09:00', 'fim' => '2026-11-11 10:00', 'status' => 'agendado']);

        $token = $this->propor(['id' => $ag->id, 'inicio' => '2026-11-12 10:00'], 'remarcar_agendamento');
        $this->travel(5)->seconds();
        $ag->update(['status' => 'confirmado']);

        $resposta = $this->confirmar($token);
        $this->assertStringContainsString(AgendaService::RECUSA_VERSAO, (string) $resposta->json('mensagem'));
        $this->assertSame('2026-11-11 09:00', $ag->fresh()->inicio->format('Y-m-d H:i'));
    }

    // ---------------------------------------------------------------- leitura travada (achado 2 do reviewer)

    /**
     * O SQLite ignora FOR UPDATE: fica provado que a vaga que decide o remarcar e a troca de serviço é a leitura TRAVADA
     * dos agendamentos do profissional (o "inicio" < ? da janela do dia), dentro da transação que trava o profissional,
     * e, na troca, a cobrança também é lida travada. Trocar travar: true por false no conferirHorario quebra este teste.
     */
    public function test_remarcar_e_trocar_servico_conferem_a_vaga_com_leitura_travada(): void
    {
        $tosa = $this->item(['setor' => 'petshop', 'categoria' => 'tosa', 'nome_comercial' => 'Tosa completa']);
        $ag = $this->agendamentoDireto($this->pet(), $this->atendente, $this->banho(), ['inicio' => '2026-11-11 09:00', 'fim' => '2026-11-11 10:00', 'status' => 'agendado']);
        $this->mostrarTravas();

        $remarcar = $this->leiturasTravadas(fn () => $this->agenda->remarcar($ag->id, '2026-11-11 11:00', self::T));
        $this->assertTravouLeitura($remarcar, 'users');
        $this->assertTravouLeitura($remarcar, 'agendamentos', '"inicio" < ?');

        $trocar = $this->leiturasTravadas(fn () => $this->agenda->trocarServico($ag->id, $tosa->id, $this->atendente->id, '2026-11-11 11:00', self::T));
        $this->assertTravouLeitura($trocar, 'users');
        $this->assertTravouLeitura($trocar, 'agendamentos', '"inicio" < ?');
        $this->assertTravouLeitura($trocar, 'cobrancas', '"status" != ?');
        $this->assertSame($tosa->id, $ag->fresh()->catalogo_item_id);
    }

    // ---------------------------------------------------------------- tool nova: altera, não duplica

    public function test_troca_de_servico_no_mesmo_setor_mantem_profissional_e_horario_e_nao_duplica(): void
    {
        $this->logarComo('recepcao', self::T);
        $banho = $this->banho();
        $tosa = $this->item(['setor' => 'petshop', 'categoria' => 'tosa', 'nome_comercial' => 'Tosa completa']);
        $ag = $this->agendamentoDireto($this->pet(), $this->atendente, $banho, ['inicio' => '2026-11-11 09:00', 'fim' => '2026-11-11 10:00', 'status' => 'agendado']);

        $campos = collect($this->comando('alterar_servico_agendamento', ['busca' => 'thor', 'catalogo_item_id' => $tosa->id])->assertOk()
            ->assertJsonPath('tipo', 'confirmacao_pendente')->json('formulario.campos'))->pluck('valor', 'nome');
        $this->assertEquals([$tosa->id, $this->atendente->id, '2026-11-11 09:00'], [$campos['catalogo_item_id'], $campos['profissional_id'], $campos['inicio']]);

        $this->confirmar($this->propor(['busca' => 'thor', 'catalogo_item_id' => $tosa->id], 'alterar_servico_agendamento'))->assertOk();

        $this->assertSame(1, Agendamento::count(), 'nunca duplica');
        $ag->refresh();
        $this->assertSame([$tosa->id, $this->atendente->id, '2026-11-11 09:00', '2026-11-11 10:30'],
            [$ag->catalogo_item_id, $ag->profissional_id, $ag->inicio->format('Y-m-d H:i'), $ag->fim->format('Y-m-d H:i')], 'o fim recalcula (tosa = 90 min)');
        $log = AuditLog::query()->where('tool', 'alterar_servico_agendamento')->where('resultado', 'sucesso')->sole();
        $this->assertEquals($banho->id, $log->valores_anteriores['catalogo_item_id'] ?? null, 'a auditoria guarda o serviço de antes');
    }

    public function test_troca_de_setor_altera_o_mesmo_agendamento_com_o_veterinario_escolhido(): void
    {
        $this->logarComo('recepcao', self::T);
        $consulta = $this->item();
        $ag = $this->agendamentoDireto($this->pet(null, self::T, ['nome' => 'Bidu']), $this->atendente, $this->banho(),
            ['inicio' => '2026-11-11 09:00', 'fim' => '2026-11-11 10:00', 'status' => 'agendado']);

        $resposta = $this->comando('alterar_servico_agendamento', ['busca' => 'bidu', 'catalogo_item_id' => $consulta->id])->assertOk();
        $campos = collect($resposta->json('formulario.campos'))->pluck('valor', 'nome');
        $this->assertNull($campos['profissional_id'], 'outro setor: o profissional fica em branco (a ficha mostra os veterinários)');
        $this->assertSame('clinica', $resposta->json('formulario.ficha.setor'));

        $revisao = $this->postJson('/agente/revisar', ['proposta' => (string) $resposta->json('proposta'),
            'valores' => ['profissional_id' => (string) $this->vet->id, 'inicio' => '2026-11-11 14:00']])->assertOk();
        $this->confirmar((string) $revisao->json('token'))->assertOk();

        $this->assertSame(1, Agendamento::count(), 'nunca duplica');
        $ag->refresh();
        $this->assertSame([$consulta->id, 'clinica', $this->vet->id, '2026-11-11 14:00', '2026-11-11 15:00'],
            [$ag->catalogo_item_id, $ag->setor, $ag->profissional_id, $ag->inicio->format('Y-m-d H:i'), $ag->fim->format('Y-m-d H:i')]);
    }
}
