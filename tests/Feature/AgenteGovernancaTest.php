<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Lancamento;
use App\Services\AiAgentService;
use App\Services\AuditoriaService;
use App\Services\ContextoUsuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Governança das tools do agente: auditoria real (RF06/RF07), RBAC placeholder,
 * tenant fixo no servidor (RNF01/RNF06) e whitelist única de tools.
 * A API do Foundry é sempre simulada com Http::fake.
 */
class AgenteGovernancaTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararAgente();
        $this->logarComo('admin', 1); // padrão: admin do tenant 1; cada teste troca quando precisa
    }

    // ---- RF06/RF07: auditoria real ----

    public function test_rf06_leitura_grava_audit_log_completo(): void
    {
        $this->comando('consultar_lancamentos', ['status' => 'pendente'])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('consultar_lancamentos', $log->tool);
        $this->assertSame('consultar', $log->acao);
        $this->assertSame('Lancamento', $log->entidade_tipo);
        $this->assertNull($log->entidade_id);
        $this->assertSame('sucesso', $log->resultado);
        $this->assertSame('admin', $log->papel);
        $this->assertTrue($log->permitido);
        $this->assertSame(1, $log->tenant_id);
        $this->assertSame('pendente', $log->parametros['status']);
    }

    public function test_rf07_escrita_so_e_auditada_quando_executada_e_aponta_a_entidade_criada(): void
    {
        $this->logarComo('admin', 7);

        $token = $this->propor(['descricao' => 'Café', 'valor' => 12.5, 'data' => '2026-09-21']);
        $this->assertSame(0, AuditLog::count(), 'propor não é executar: nada a auditar ainda');

        $this->confirmar($token)->assertOk();

        $lancamento = Lancamento::query()->sole();
        $log = AuditLog::query()->sole();
        $this->assertSame('criar_lancamento', $log->tool);
        $this->assertSame('criar', $log->acao);
        $this->assertSame('Lancamento', $log->entidade_tipo);
        $this->assertSame($lancamento->id, $log->entidade_id);
        $this->assertSame('sucesso', $log->resultado);
        $this->assertTrue($log->permitido);
        $this->assertSame(7, $log->tenant_id);
        $this->assertSame('Café', $log->parametros['descricao']);
    }

    public function test_falha_de_execucao_e_auditada_como_erro(): void
    {
        $token = $this->propor(['valor' => 1, 'data' => '2026-09-21']); // sem descrição

        $this->confirmar($token)->assertStatus(422);

        $log = AuditLog::query()->sole();
        $this->assertSame('erro', $log->resultado);
        $this->assertTrue($log->permitido);
        $this->assertSame('Não consegui criar o lançamento — verifique se descrição, valor e data foram informados.', $log->mensagem, 'a auditoria guarda a frase genérica, não a exceção técnica');
        $this->assertSame(0, Lancamento::count());
    }

    public function test_escrita_e_auditoria_sao_atomicas_se_o_audit_falha_a_escrita_e_desfeita(): void
    {
        // Falha só no registro de 'sucesso'; o registro de 'erro' que vem depois passa.
        $this->app->instance(AuditoriaService::class, new class(app(ContextoUsuario::class)) extends AuditoriaService
        {
            public function registrar(string $tool, string $acao, ?string $entidadeTipo, ?int $entidadeId, array $parametros, string $resultado, ?string $mensagem, string $papel, bool $permitido): AuditLog
            {
                if ($resultado === 'sucesso') {
                    throw new RuntimeException('audit_logs indisponível');
                }

                return parent::registrar($tool, $acao, $entidadeTipo, $entidadeId, $parametros, $resultado, $mensagem, $papel, $permitido);
            }
        });

        $token = $this->propor(['descricao' => 'Não pode ficar sem auditoria', 'valor' => 1, 'data' => '2026-09-21']);

        $this->confirmar($token)->assertStatus(422)->assertJsonPath('tipo', 'erro');

        $this->assertSame(0, Lancamento::count(), 'escrita sem auditoria não pode persistir');
        $this->assertSame('erro', AuditLog::query()->sole()->resultado);
    }

    // ---- RBAC placeholder ----

    public function test_rbac_leitura_nao_chega_a_propor_escrita_e_a_tentativa_negada_e_auditada(): void
    {
        $this->logarComo('leitura');

        $this->comando('criar_lancamento', ['descricao' => 'X', 'valor' => 1, 'data' => '2026-09-21'])
            ->assertStatus(403)
            ->assertJsonPath('tipo', 'negado')
            ->assertJsonMissingPath('token');

        $log = AuditLog::query()->sole();
        $this->assertSame('criar_lancamento', $log->tool);
        $this->assertSame('negado', $log->resultado);
        $this->assertFalse($log->permitido);
        $this->assertSame('leitura', $log->papel);
        $this->assertSame(0, Lancamento::count());
    }

    public function test_rbac_hierarquia_leitura_operador_admin(): void
    {
        // [papel => [pode consultar, pode criar]]
        $matriz = ['leitura' => [true, false], 'operador' => [true, true], 'admin' => [true, true]];

        foreach ($matriz as $papel => [$podeConsultar, $podeCriar]) {
            $this->logarComo($papel);

            $consulta = $this->comando('consultar_lancamentos');
            $this->assertSame($podeConsultar ? 200 : 403, $consulta->status(), "consultar como {$papel}");

            $escrita = $this->comando('criar_lancamento', ['descricao' => 'X', 'valor' => 1, 'data' => '2026-09-21']);
            $this->assertSame($podeCriar ? 200 : 403, $escrita->status(), "propor criar como {$papel}");
        }
    }

    public function test_rbac_e_rechecado_na_confirmacao(): void
    {
        $token = $this->propor(['descricao' => 'X', 'valor' => 1, 'data' => '2026-09-21']);

        auth()->user()->update(['papel' => 'leitura']); // papel rebaixado entre propor e confirmar

        $this->confirmar($token)->assertStatus(403)->assertJsonPath('tipo', 'negado');

        $this->assertSame(0, Lancamento::count());
        $log = AuditLog::query()->sole();
        $this->assertSame('negado', $log->resultado);
        $this->assertFalse($log->permitido);
    }

    public function test_rbac_papel_desconhecido_nega_tudo(): void
    {
        $this->logarComo('root');

        $this->comando('consultar_lancamentos')->assertStatus(403)->assertJsonPath('tipo', 'negado');
    }

    // ---- Tenant fixo no servidor ----

    public function test_tenant_enviado_pelo_modelo_e_ignorado(): void
    {
        $this->logarComo('admin', 7);

        $token = (string) $this->comando('criar_lancamento', ['descricao' => 'X', 'valor' => 1, 'data' => '2026-09-21', 'tenant_id' => 999])
            ->assertJsonMissingPath('argumentos.tenant_id')
            ->json('token');

        $this->confirmar($token)->assertOk();

        $this->assertSame(7, Lancamento::query()->sole()->tenant_id);
    }

    public function test_executar_tool_sobrescreve_tenant_mesmo_quando_chamada_direto(): void
    {
        // Defesa em profundidade: mesmo que um tenant_id chegue até o executor, o servidor prevalece.
        $this->logarComo('admin', 7);

        app(AiAgentService::class)->executarTool('criar_lancamento', [
            'descricao' => 'X', 'valor' => 1, 'data' => '2026-09-21', 'tenant_id' => 999,
        ]);

        $this->assertSame(7, Lancamento::query()->sole()->tenant_id);
        $this->assertSame(7, AuditLog::query()->sole()->parametros['tenant_id']);
    }

    public function test_tool_nao_expoe_tenant_id_ao_modelo(): void
    {
        foreach (app(AiAgentService::class)->tools() as $tool) {
            $this->assertArrayNotHasKey('tenant_id', $tool['function']['parameters']['properties'], $tool['function']['name']);
        }
    }

    public function test_consulta_so_enxerga_o_tenant_atual(): void
    {
        Lancamento::create(['descricao' => 'do tenant 1', 'valor' => 1, 'data' => '2026-09-21', 'status' => 'pendente', 'tenant_id' => 1]);
        Lancamento::create(['descricao' => 'do tenant 2', 'valor' => 1, 'data' => '2026-09-21', 'status' => 'pendente', 'tenant_id' => 2]);

        $this->comando('consultar_lancamentos')
            ->assertOk()
            ->assertJsonCount(1, 'resultado')
            ->assertJsonPath('resultado.0.descricao', 'do tenant 1');
    }

    // ---- Whitelist única ----

    public function test_executar_tool_recusa_nome_fora_do_catalogo_e_audita(): void
    {
        try {
            app(AiAgentService::class)->executarTool('apagar_tudo', []);
            $this->fail('Deveria ter lançado InvalidArgumentException');
        } catch (InvalidArgumentException) {
            // esperado
        }

        $log = AuditLog::query()->sole();
        $this->assertSame('apagar_tudo', $log->tool);
        $this->assertSame('negado', $log->resultado);
        $this->assertFalse($log->permitido);
    }

    public function test_tool_inventada_pelo_modelo_nao_executa_nada(): void
    {
        $this->comando('apagar_tudo')->assertOk()->assertJsonPath('tipo', 'erro');

        $this->assertSame(0, Lancamento::count());
        $this->assertSame('negado', AuditLog::query()->sole()->resultado);
    }

    public function test_toda_tool_exposta_ao_modelo_e_executavel(): void
    {
        // tools() e executarTool() derivam do mesmo catálogo: nenhuma tool anunciada pode ser "desconhecida".
        foreach (app(AiAgentService::class)->tools() as $tool) {
            $resultado = app(AiAgentService::class)->executarTool($tool['function']['name'], []);

            $this->assertContains($resultado['tipo'], ['resultado_leitura', 'resultado_escrita', 'erro'], $tool['function']['name']);
        }
    }
}
