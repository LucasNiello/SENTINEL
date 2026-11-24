<?php

namespace Tests\Feature\Clinica;

use App\Models\AuditLog;
use App\Models\Cobranca;
use App\Models\Mensagem;
use App\Services\AiAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

/**
 * Painel lateral do /agente (24/11): GET /agente/painel. Só leitura, com escopo de tenant, papel e usuário. Pendentes do
 * negócio (cobranças, mensagens novas, agenda de hoje) e as ações recentes do PRÓPRIO usuário, sem payload nem dado de tutor.
 */
class AgentePainelTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
    }

    private function cobranca(int $tenant, string $status, \App\Models\Agendamento $agendamento): void
    {
        Cobranca::create(['agendamento_id' => $agendamento->id, 'setor' => 'clinica', 'status' => $status, 'total_centavos' => 1000, 'tenant_id' => $tenant]);
    }

    private function agendamentoDoDia(int $tenant, string $inicio, string $setor = 'clinica', string $status = 'agendado'): \App\Models\Agendamento
    {
        $item = $this->item(['setor' => $setor, 'categoria' => $setor === 'petshop' ? 'banho' : 'consulta'], $tenant);

        return $this->agendamentoDireto($this->pet(null, $tenant), $this->usuarioClinica('veterinario', $tenant), $item,
            ['inicio' => $inicio, 'fim' => Carbon::parse($inicio)->addMinutes(30)->format('Y-m-d H:i'), 'status' => $status]);
    }

    private function pendentes(): array
    {
        $resposta = $this->getJson('/agente/painel')->assertOk()->json('pendentes');

        return array_column($resposta, 'total', 'chave');
    }

    private function log(int $tenant, int $user, string $tool, string $acao, string $resultado = 'sucesso', string $mensagem = 'segredo do tutor'): void
    {
        AuditLog::create(['user_id' => $user, 'tool' => $tool, 'acao' => $acao, 'resultado' => $resultado, 'mensagem' => $mensagem,
            'parametros' => ['nome' => 'Ana Souza', 'cpf' => '123.456.789-00'], 'papel' => 'recepcao', 'permitido' => true, 'tenant_id' => $tenant]);
    }

    public function test_exige_login(): void
    {
        $this->getJson('/agente/painel')->assertUnauthorized();
    }

    public function test_conta_so_o_pendente_do_proprio_tenant(): void
    {
        $this->logarComo('dono', self::T);
        $a = $this->agendamentoDoDia(self::T, '2026-11-10 14:00');
        $this->agendamentoDoDia(self::T, '2026-11-11 09:00');                 // amanhã: não conta
        $this->agendamentoDoDia(self::T, '2026-11-10 15:00', 'clinica', 'cancelado'); // cancelado: não conta
        $this->cobranca(self::T, 'aberta', $a);
        $this->cobranca(self::T, 'fechada', $a);
        $this->cobranca(self::T, 'paga', $a);                                  // paga: não conta
        Mensagem::create(['tutor_id' => $this->tutor()->id, 'remetente' => 'tutor', 'texto' => 'oi', 'status' => 'nova', 'tenant_id' => self::T]);
        Mensagem::create(['tutor_id' => $this->tutor()->id, 'remetente' => 'tutor', 'texto' => 'oi', 'status' => 'tratada', 'tenant_id' => self::T]);

        // Outro tenant: nada disso pode entrar na conta.
        $b = $this->agendamentoDoDia(self::OUTRO_TENANT, '2026-11-10 14:00');
        $this->cobranca(self::OUTRO_TENANT, 'aberta', $b);
        Mensagem::create(['tutor_id' => $this->tutor(self::OUTRO_TENANT)->id, 'remetente' => 'tutor', 'texto' => 'oi', 'status' => 'nova', 'tenant_id' => self::OUTRO_TENANT]);

        $this->assertSame(['cobrancas' => 2, 'mensagens' => 1, 'agenda' => 1], $this->pendentes());
    }

    public function test_usuario_de_outro_tenant_nao_ve_nada_do_primeiro(): void
    {
        $a = $this->agendamentoDoDia(self::T, '2026-11-10 14:00');
        $this->cobranca(self::T, 'aberta', $a);
        Mensagem::create(['tutor_id' => $this->tutor()->id, 'remetente' => 'tutor', 'texto' => 'oi', 'status' => 'nova', 'tenant_id' => self::T]);
        $dono = $this->usuarioClinica('dono');
        $this->log(self::T, $dono->id, 'criar_tutor', 'criar');

        config(['sentinel.tenants_clinica' => [self::T, self::OUTRO_TENANT]]); // o outro tenant também é uma clínica
        $this->logarComo('dono', self::OUTRO_TENANT);
        $resposta = $this->getJson('/agente/painel')->assertOk();

        $this->assertSame(['cobrancas' => 0, 'mensagens' => 0, 'agenda' => 0], array_column($resposta->json('pendentes'), 'total', 'chave'));
        $this->assertSame([], $resposta->json('recentes'));
    }

    public function test_recentes_sao_so_do_proprio_usuario_sem_consultas_e_sem_payload(): void
    {
        $eu = $this->logarComo('recepcao', self::T);
        $outro = $this->usuarioClinica('recepcao');
        $this->log(self::T, $eu->id, 'criar_tutor', 'criar');
        $this->log(self::T, $eu->id, 'consultar_agenda', 'consultar');        // leitura: fica de fora
        $this->log(self::T, $outro->id, 'criar_pet', 'criar');                // de outro usuário do mesmo tenant
        $this->log(self::OUTRO_TENANT, $eu->id, 'criar_pet', 'criar');        // mesmo user_id, outro tenant
        $this->log(self::T, $eu->id, 'agendar_atendimento', 'criar', 'negado');

        $resposta = $this->getJson('/agente/painel')->assertOk();

        $titulos = app(AiAgentService::class);
        $this->assertSame([$titulos->tituloDaTool('agendar_atendimento'), $titulos->tituloDaTool('criar_tutor')], array_column($resposta->json('recentes'), 'acao'));
        $this->assertStringNotContainsString('_', implode('', array_column($resposta->json('recentes'), 'acao')), 'nunca o identificador interno da tool');
        $this->assertSame(['Negada', 'Concluída'], array_column($resposta->json('recentes'), 'resultado'));
        foreach ($resposta->json('recentes') as $linha) {
            $this->assertEqualsCanonicalizing(['acao', 'quando', 'resultado'], array_keys($linha));
        }
        $texto = $resposta->getContent();
        $this->assertStringNotContainsString('segredo do tutor', $texto);
        $this->assertStringNotContainsString('Ana Souza', $texto);
        $this->assertStringNotContainsString('123.456', $texto);
    }

    public function test_recentes_tem_teto_de_dez(): void
    {
        $eu = $this->logarComo('dono', self::T);
        for ($i = 0; $i < 14; $i++) {
            $this->log(self::T, $eu->id, 'criar_tutor', 'criar');
        }

        $this->assertCount(10, $this->getJson('/agente/painel')->json('recentes'));
    }

    public function test_agenda_conta_agendado_e_confirmado_e_nao_conta_realizado_nem_faltou(): void
    {
        foreach (['agendado', 'confirmado', 'realizado', 'faltou', 'cancelado'] as $i => $status) {
            $this->agendamentoDoDia(self::T, sprintf('2026-11-10 %02d:00', 11 + $i), 'clinica', $status);
        }

        $this->logarComo('dono', self::T);
        $this->assertSame(2, $this->pendentes()['agenda']);
    }

    public function test_veterinario_ve_cobrancas_pendentes(): void
    {
        $this->cobranca(self::T, 'fechada', $this->agendamentoDoDia(self::T, '2026-11-10 14:00'));

        $this->logarComo('veterinario', self::T);
        $this->assertSame(1, $this->pendentes()['cobrancas']);
    }

    public function test_papel_sem_permissao_de_leitura_nao_ganha_o_item(): void
    {
        $a = $this->agendamentoDoDia(self::T, '2026-11-10 14:00');
        $this->cobranca(self::T, 'aberta', $a);

        $this->logarComo('vendedor', self::T);
        $this->assertArrayNotHasKey('agenda', $this->pendentes());
    }

    public function test_atendente_so_conta_a_agenda_do_banho_e_tosa(): void
    {
        $this->agendamentoDoDia(self::T, '2026-11-10 14:00', 'clinica');
        $this->agendamentoDoDia(self::T, '2026-11-10 15:00', 'petshop');

        $this->logarComo('atendente', self::T);
        $this->assertSame(1, $this->pendentes()['agenda']);
    }

    public function test_administrador_da_clinica_leva_403(): void
    {
        $this->logarComo('admin', self::T);

        $this->getJson('/agente/painel')->assertForbidden();
    }

    public function test_fora_da_clinica_nao_ha_pendentes_so_recentes_do_proprio_usuario(): void
    {
        $eu = $this->logarComo('admin', 1);
        $this->log(1, $eu->id, 'criar_cliente', 'criar');

        $resposta = $this->getJson('/agente/painel')->assertOk();
        $this->assertSame([], $resposta->json('pendentes'));
        $this->assertSame([app(AiAgentService::class)->tituloDaTool('criar_cliente')], array_column($resposta->json('recentes'), 'acao'));
    }

    public function test_so_responde_a_get(): void
    {
        $this->logarComo('dono', self::T);

        $this->postJson('/agente/painel')->assertStatus(405);
    }
}
