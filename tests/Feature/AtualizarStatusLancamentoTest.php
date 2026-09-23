<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Lancamento;
use App\Services\AiAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Tool atualizar_status_lancamento (escrita): RBAC operador + confirmação
 * dupla (RNF02) + auditoria (RF06/RF07) + tenant fixo (RNF01), reaproveitando
 * marcarConciliado/desmarcarConciliado do LancamentoService.
 */
class AtualizarStatusLancamentoTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararAgente();
        $this->logarComo('admin', 1); // padrão: admin do tenant 1; cada teste troca quando precisa
    }

    private function lancamento(string $status, int $tenant = 1): Lancamento
    {
        return Lancamento::create(['descricao' => "L {$status}", 'valor' => 1, 'data' => '2026-09-21', 'status' => $status, 'tenant_id' => $tenant]);
    }

    private function proporAtualizacao(int $id, string $novoStatus): string
    {
        return $this->propor(['id' => $id, 'novo_status' => $novoStatus], 'atualizar_status_lancamento');
    }

    // ---- Fluxo feliz + confirmação (RNF02) ----

    public function test_propor_nao_altera_nada_nem_audita(): void
    {
        $lancamento = $this->lancamento('pendente');

        $this->comando('atualizar_status_lancamento', ['id' => $lancamento->id, 'novo_status' => 'conciliado'])
            ->assertOk()
            ->assertJsonPath('tipo', 'confirmacao_pendente')
            ->assertJsonPath('tool', 'atualizar_status_lancamento')
            ->assertJsonPath('argumentos.novo_status', 'conciliado')
            ->assertJsonStructure(['token']);

        $this->assertSame('pendente', $lancamento->fresh()->status);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_confirmar_concilia_um_lancamento_pendente_e_audita(): void
    {
        $lancamento = $this->lancamento('pendente');
        $token = $this->proporAtualizacao($lancamento->id, 'conciliado');

        $this->confirmar($token)->assertOk()->assertJsonPath('tipo', 'resultado_escrita');

        $this->assertSame('conciliado', $lancamento->fresh()->status);

        $log = AuditLog::query()->sole();
        $this->assertSame('atualizar_status_lancamento', $log->tool);
        $this->assertSame('atualizar', $log->acao);
        $this->assertSame('Lancamento', $log->entidade_tipo);
        $this->assertSame($lancamento->id, $log->entidade_id);
        $this->assertSame('sucesso', $log->resultado);
        $this->assertSame('admin', $log->papel);
        $this->assertTrue($log->permitido);
        $this->assertSame($lancamento->id, $log->parametros['id']);
        $this->assertSame('conciliado', $log->parametros['novo_status']);
    }

    public function test_confirmar_reverte_um_lancamento_conciliado_para_pendente(): void
    {
        $lancamento = $this->lancamento('conciliado');
        $token = $this->proporAtualizacao($lancamento->id, 'pendente');

        $this->confirmar($token)->assertOk();

        $this->assertSame('pendente', $lancamento->fresh()->status);
    }

    public function test_confirmar_cedo_demais_nao_altera_nada(): void
    {
        $lancamento = $this->lancamento('pendente');
        $token = $this->proporAtualizacao($lancamento->id, 'conciliado');
        $this->travel(1)->seconds();

        $this->postJson('/agente/confirmar', ['token' => $token])->assertStatus(422);

        $this->assertSame('pendente', $lancamento->fresh()->status);
    }

    // ---- Recusas de regra de negócio: frase amigável, sem termo técnico ----

    public function test_ja_no_status_pedido_e_recusado_com_frase_amigavel_e_auditado(): void
    {
        $lancamento = $this->lancamento('pendente');
        $token = $this->proporAtualizacao($lancamento->id, 'pendente');

        $this->confirmar($token)
            ->assertStatus(422)
            ->assertJsonPath('tipo', 'erro')
            ->assertJsonPath('mensagem', "O lançamento #{$lancamento->id} já está pendente.");

        $log = AuditLog::query()->sole();
        $this->assertSame('recusado', $log->resultado);
        $this->assertTrue($log->permitido, 'recusa de regra de negócio não é negação de RBAC');
    }

    public function test_lancamento_inexistente_e_recusado(): void
    {
        $token = $this->proporAtualizacao(999, 'conciliado');

        $this->confirmar($token)
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Não encontrei o lançamento #999.');
    }

    public function test_lancamento_de_outro_tenant_e_tratado_como_inexistente_e_nao_muda(): void
    {
        $alheio = $this->lancamento('pendente', tenant: 2);
        $token = $this->proporAtualizacao($alheio->id, 'conciliado');

        $this->confirmar($token)
            ->assertStatus(422)
            ->assertJsonPath('mensagem', "Não encontrei o lançamento #{$alheio->id}.");

        $this->assertSame('pendente', $alheio->fresh()->status, 'RNF01: outro tenant não pode ser alterado');
    }

    public function test_status_fora_do_enum_e_recusado_e_nao_altera(): void
    {
        $lancamento = $this->lancamento('pendente');
        $token = $this->proporAtualizacao($lancamento->id, 'cancelado');

        $this->confirmar($token)
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Status inválido: use "pendente" ou "conciliado".');

        $this->assertSame('pendente', $lancamento->fresh()->status);
    }

    public function test_sem_id_e_recusado(): void
    {
        $token = $this->propor(['novo_status' => 'conciliado'], 'atualizar_status_lancamento');

        $this->confirmar($token)
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Informe qual lançamento deve ser atualizado.');
    }

    public function test_mensagens_de_recusa_nao_vazam_termo_tecnico(): void
    {
        $token = $this->proporAtualizacao(999, 'conciliado');
        $mensagem = $this->confirmar($token)->json('mensagem');

        foreach (['Exception', 'SQL', 'lancamentos', 'Eloquent', 'App\\'] as $termo) {
            $this->assertStringNotContainsString($termo, $mensagem);
        }
    }

    // ---- RBAC (operador) ----

    public function test_leitura_nao_pode_atualizar_status_e_a_tentativa_e_auditada(): void
    {
        $this->logarComo('leitura');
        $lancamento = $this->lancamento('pendente');

        $this->comando('atualizar_status_lancamento', ['id' => $lancamento->id, 'novo_status' => 'conciliado'])
            ->assertStatus(403)
            ->assertJsonPath('tipo', 'negado')
            ->assertJsonMissingPath('token');

        $this->assertSame('pendente', $lancamento->fresh()->status);

        $log = AuditLog::query()->sole();
        $this->assertSame('atualizar_status_lancamento', $log->tool);
        $this->assertSame('negado', $log->resultado);
        $this->assertFalse($log->permitido);
        $this->assertSame('leitura', $log->papel);
    }

    public function test_operador_pode_atualizar_status(): void
    {
        $this->logarComo('operador');
        $lancamento = $this->lancamento('pendente');
        $token = $this->proporAtualizacao($lancamento->id, 'conciliado');

        $this->confirmar($token)->assertOk();

        $this->assertSame('conciliado', $lancamento->fresh()->status);
        $this->assertSame('operador', AuditLog::query()->sole()->papel);
    }

    public function test_rbac_e_rechecado_na_confirmacao(): void
    {
        $lancamento = $this->lancamento('pendente');
        $token = $this->proporAtualizacao($lancamento->id, 'conciliado');

        auth()->user()->update(['papel' => 'leitura']); // papel rebaixado entre propor e confirmar

        $this->confirmar($token)->assertStatus(403);

        $this->assertSame('pendente', $lancamento->fresh()->status);
    }

    // ---- Catálogo ----

    public function test_schema_da_tool_trava_status_pelo_enum_e_nao_expoe_tenant(): void
    {
        $tool = collect(app(AiAgentService::class)->tools())->firstWhere('function.name', 'atualizar_status_lancamento');

        $this->assertNotNull($tool, 'a tool deve estar no catálogo');
        $parametros = $tool['function']['parameters'];
        $this->assertSame(['pendente', 'conciliado'], $parametros['properties']['novo_status']['enum']);
        $this->assertSame(['id', 'novo_status'], $parametros['required']);
        $this->assertArrayNotHasKey('tenant_id', $parametros['properties']);
    }
}
