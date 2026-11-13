<?php

namespace Tests\Feature\Clinica;

use App\Models\Agendamento;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * F (P-C4): agendar_atendimento e registrar_encaixe_emergencia pelo fluxo do agente. A origem é fixada pelo servidor;
 * a regra é a do AgendaService (já testada lá), conferida no Revisar e de novo no Confirmar; papel, tenant e auditoria.
 */
class ToolsDeAgendaDaClinicaTest extends TestCase
{
    use CenarioClinica, RefreshDatabase, SimulaAgente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->prepararAgente();
        $this->logarComo('recepcao', self::T);
    }

    private function dados(array $extra = []): array
    {
        return [
            'pet_id' => $this->pet()->id, 'catalogo_item_id' => $this->item()->id, 'profissional_id' => $this->usuarioClinica('veterinario')->id,
            'inicio' => '2026-11-11 09:00', 'fim' => '2026-11-11 09:30', ...$extra,
        ];
    }

    private function recusadaNoRevisar(string $tool, array $argumentos, string $trecho): void
    {
        $proposta = (string) $this->comando($tool, $argumentos)->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente')->json('proposta');

        $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])
            ->assertStatus(422)->assertJsonMissingPath('token')
            ->assertJsonPath('mensagem', fn (string $m) => str_contains($m, $trecho));
    }

    public function test_agendar_grava_so_depois_de_confirmar_com_origem_normal_tenant_do_servidor_e_audita(): void
    {
        $dados = $this->dados();
        $token = $this->propor($dados, 'agendar_atendimento');
        $this->assertSame(0, Agendamento::count(), 'nada antes de confirmar');

        $this->confirmar($token)->assertOk();

        $agendamento = Agendamento::sole();
        $this->assertSame(['normal', 'agendado', self::T, 'clinica', $dados['pet_id']],
            [$agendamento->origem, $agendamento->status, (int) $agendamento->tenant_id, $agendamento->setor, (int) $agendamento->pet_id]);
        $log = AuditLog::query()->where('tool', 'agendar_atendimento')->where('resultado', 'sucesso')->sole();
        $this->assertSame([auth()->id(), self::T], [(int) $log->user_id, (int) $log->tenant_id]);
    }

    public function test_regra_da_agenda_recusa_no_revisar_e_nada_e_gravado(): void
    {
        $dados = $this->dados();
        $this->agendamentoDireto(\App\Models\Pet::find($dados['pet_id']), \App\Models\User::find($dados['profissional_id']), \App\Models\CatalogoItem::find($dados['catalogo_item_id']),
            ['inicio' => '2026-11-11 09:15', 'fim' => '2026-11-11 09:45', 'status' => 'agendado']);

        $this->recusadaNoRevisar('agendar_atendimento', $dados, 'já tem um agendamento nesse horário');
        $this->recusadaNoRevisar('agendar_atendimento', [...$dados, 'inicio' => '2026-11-09 09:00', 'fim' => '2026-11-09 09:30'], 'no passado');
        $this->recusadaNoRevisar('agendar_atendimento', [...$dados, 'fim' => '2026-11-11 18:00'], 'no máximo');
        $this->recusadaNoRevisar('agendar_atendimento', [...$dados, 'profissional_id' => $this->usuarioClinica('recepcao')->id], 'veterinário');
        $this->recusadaNoRevisar('agendar_atendimento', [...$dados, 'catalogo_item_id' => $this->produto()->id], 'é um produto');

        $this->assertSame(1, Agendamento::count(), 'só o que já existia');
    }

    public function test_pet_servico_ou_profissional_de_outro_tenant_nao_agenda(): void
    {
        $dados = $this->dados();
        $deFora = [
            'pet_id' => $this->pet(null, self::OUTRO_TENANT)->id,
            'catalogo_item_id' => $this->item([], self::OUTRO_TENANT)->id,
            'profissional_id' => $this->usuarioClinica('veterinario', self::OUTRO_TENANT)->id,
        ];

        foreach ($deFora as $campo => $id) {
            $proposta = (string) $this->comando('agendar_atendimento', [...$dados, $campo => $id])->assertOk()->json('proposta');
            $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->assertStatus(422)->assertJsonMissingPath('token');
        }
        $this->assertSame(0, Agendamento::count());
    }

    public function test_a_ia_nao_escolhe_origem_status_nem_tenant(): void
    {
        foreach (['origem' => 'encaixe_emergencia', 'status' => 'realizado', 'tenant_id' => self::OUTRO_TENANT] as $campo => $valor) {
            $resposta = $this->comando('agendar_atendimento', [...$this->dados(), $campo => $valor]);
            $campo === 'tenant_id'
                ? $resposta->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente')->assertJsonMissingPath('argumentos.tenant_id')
                : $resposta->assertStatus(502)->assertJsonPath('codigo', 'AI_PROVIDER_RESPONSE_INVALID');
        }
        $this->assertSame(0, Agendamento::count());
    }

    public function test_conflito_que_entra_entre_o_revisar_e_o_confirmar_e_recusado(): void
    {
        $dados = $this->dados();
        $token = $this->propor($dados, 'agendar_atendimento');
        $this->agendamentoDireto(\App\Models\Pet::find($dados['pet_id']), \App\Models\User::find($dados['profissional_id']), \App\Models\CatalogoItem::find($dados['catalogo_item_id']),
            ['inicio' => '2026-11-11 09:00', 'fim' => '2026-11-11 09:30', 'status' => 'agendado']);

        $this->confirmar($token)->assertStatus(422);

        $this->assertSame(1, Agendamento::count());
        $this->assertSame('recusado', AuditLog::query()->where('tool', 'agendar_atendimento')->latest('id')->value('resultado'));
    }

    public function test_papeis_de_quem_agenda_e_de_quem_registra_encaixe(): void
    {
        $this->logarComo('vendedor', self::T);
        $this->comando('agendar_atendimento', $this->dados())->assertStatus(403)->assertJsonPath('tipo', 'negado');

        $this->logarComo('atendente', self::T);
        $this->comando('registrar_encaixe_emergencia', $this->dados(['inicio' => '2026-11-10 09:30', 'fim' => '2026-11-10 10:30']))
            ->assertStatus(403)->assertJsonPath('tipo', 'negado');

        $this->assertSame(['negado', 'negado'], AuditLog::query()->orderBy('id')->pluck('resultado')->all());
        $this->assertSame(0, Agendamento::count());
    }

    /** P-C1 (achado do reviewer): cobrar_atendimento acha pelo id do ATENDIMENTO; a posição vale para a agenda, não para as cobranças. */
    public function test_cobrar_pela_posicao_usa_a_tabela_da_agenda_e_recusa_a_de_cobrancas(): void
    {
        $agendamento = $this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item());
        $this->assertSame([$agendamento->id], array_column($this->comando('consultar_agenda')->assertOk()->json('resultado'), 'id'));

        $this->comando('cobrar_atendimento', ['posicao' => 1])->assertJsonPath('mensagem', fn ($m) => ! str_contains((string) $m, 'outro tipo de registro'));

        app(\App\Services\CobrancaService::class)->abrir($agendamento->id, self::T);
        $this->comando('consultar_cobrancas')->assertOk();
        $this->comando('cobrar_atendimento', ['posicao' => 1])
            ->assertStatus(422)->assertJsonPath('mensagem', fn ($m) => str_contains($m, 'outro tipo de registro'));
    }

    public function test_encaixe_registra_emergencia_ja_comecada_mesmo_em_horario_ocupado(): void
    {
        $dados = $this->dados(['inicio' => '2026-11-10 09:30', 'fim' => '2026-11-10 10:30']);
        $this->agendamentoDireto(\App\Models\Pet::find($dados['pet_id']), \App\Models\User::find($dados['profissional_id']), \App\Models\CatalogoItem::find($dados['catalogo_item_id']),
            ['inicio' => '2026-11-10 09:00', 'fim' => '2026-11-10 10:00', 'status' => 'agendado']);

        $this->confirmar($this->propor($dados, 'registrar_encaixe_emergencia'))->assertOk();

        $this->assertSame('encaixe_emergencia', Agendamento::query()->latest('id')->value('origem'));
        $this->recusadaNoRevisar('registrar_encaixe_emergencia', $this->dados(['inicio' => '2026-11-10 11:00', 'fim' => '2026-11-10 11:30']), 'não pode estar no futuro');
        $this->recusadaNoRevisar('registrar_encaixe_emergencia', $this->dados(['catalogo_item_id' => $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho'])->id,
            'profissional_id' => $this->usuarioClinica('atendente')->id, 'inicio' => '2026-11-10 09:00', 'fim' => '2026-11-10 09:30']), 'só da clínica');
    }
}
