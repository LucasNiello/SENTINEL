<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\Funcionario;
use App\Models\Lancamento;
use App\Models\NotaFiscal;
use App\Services\AuditoriaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Etapa A2 — a auditoria das atualizações guarda o "antes" dos campos que mudaram,
 * com a mesma máscara LGPD; e a tool fora do catálogo não grava valores.
 */
class ValorAnteriorTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararAgente();
        $this->logarComo('admin', 1);
    }

    private function sucesso(): AuditLog
    {
        return AuditLog::query()->where('resultado', 'sucesso')->sole();
    }

    /** Nenhuma coluna de nenhuma linha de audit_logs contém os valores brutos. */
    private function assertAuditoriaSemValores(array $brutos): void
    {
        $tudo = json_encode(DB::table('audit_logs')->get()->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($brutos as $bruto) {
            $this->assertStringNotContainsString($bruto, $tudo, "valor em claro na auditoria: {$bruto}");
        }
    }

    public function test_atualizar_guarda_o_antes_so_dos_campos_que_mudaram(): void
    {
        $cliente = Cliente::create(['nome' => 'Mercado Central', 'email' => 'velho@exemplo.com', 'telefone' => '1999', 'tenant_id' => 1]);

        $this->confirmar($this->propor(['id' => $cliente->id, 'email' => 'novo@exemplo.com', 'telefone' => '1888'], 'atualizar_cliente'))->assertOk();

        $log = $this->sucesso();
        $this->assertSame(['email' => 'velho@exemplo.com', 'telefone' => '1999'], $log->valores_anteriores);
        $this->assertSame('novo@exemplo.com', $log->parametros['email'], 'o depois continua em parametros');
    }

    public function test_antes_de_cpf_e_salario_fica_mascarado_e_nunca_em_claro(): void
    {
        $funcionario = Funcionario::create(['nome' => 'Ana', 'cpf' => '111.222.333-44', 'salario' => 4567.89, 'data_admissao' => '2026-01-10', 'tenant_id' => 1]);

        $this->freezeTime();
        $proposta = $this->comando('atualizar_funcionario', ['id' => $funcionario->id])->json('proposta');
        $token = $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => ['cpf' => '555.666.777-88', 'salario' => '6100.50', 'cargo' => 'Chefe']])
            ->assertOk()->json('token');
        $this->confirmar($token)->assertOk();

        $this->assertSame('555.666.777-88', $funcionario->refresh()->cpf);

        $anteriores = $this->sucesso()->valores_anteriores;
        $this->assertSame('***.***.333-44', $anteriores['cpf']);
        $this->assertSame(AuditoriaService::REDIGIDO, $anteriores['salario']);
        $this->assertArrayHasKey('cargo', $anteriores);
        $this->assertArrayNotHasKey('nome', $anteriores, 'o que não mudou não entra');
        $this->assertArrayNotHasKey('updated_at', $anteriores);

        $this->assertAuditoriaSemValores(['111.222.333-44', '11122233344', '4567', '555.666.777-88', '6100']);
    }

    public function test_status_do_lancamento_guarda_o_status_anterior(): void
    {
        $lancamento = Lancamento::create(['descricao' => 'Aluguel', 'valor' => 1, 'data' => '2026-09-21', 'status' => 'pendente', 'tenant_id' => 1]);

        $this->confirmar($this->propor(['id' => $lancamento->id, 'novo_status' => 'conciliado'], 'atualizar_status_lancamento'))->assertOk();

        $this->assertSame(['status' => 'pendente'], $this->sucesso()->valores_anteriores);
    }

    public function test_cadastro_recusa_e_atualizacao_sem_mudanca_nao_inventam_antes(): void
    {
        $this->confirmar($this->propor(['nome' => 'Novo'], 'criar_cliente'))->assertOk();
        $this->assertNull($this->sucesso()->valores_anteriores, 'cadastro');

        $cliente = Cliente::create(['nome' => 'Igual', 'email' => 'a@exemplo.com', 'tenant_id' => 1]);
        AuditLog::query()->delete();
        $this->confirmar($this->propor(['id' => $cliente->id, 'email' => 'a@exemplo.com'], 'atualizar_cliente'))->assertOk();
        $this->assertSame([], $this->sucesso()->valores_anteriores, 'nada mudou');

        // Recusada na execução (alterado por outra pessoa): sem "antes", porque nada foi gravado.
        AuditLog::query()->delete();
        $token = $this->propor(['id' => $cliente->id, 'email' => 'b@exemplo.com'], 'atualizar_cliente');
        $this->travel(1)->seconds();
        $cliente->update(['telefone' => '2222']);
        $this->confirmar($token)->assertStatus(422);
        $this->assertNull(AuditLog::query()->where('resultado', 'recusado')->sole()->valores_anteriores);
    }

    public function test_documento_com_cpf_e_nota_fiscal_tambem_guardam_o_antes(): void
    {
        $cliente = Cliente::create(['nome' => 'Pessoa', 'documento' => '111.222.333-44', 'tenant_id' => 1]);
        $this->confirmar($this->propor(['id' => $cliente->id, 'documento' => '12.345.678/0001-90'], 'atualizar_cliente'))->assertOk();
        $this->assertSame(['documento' => '***.***.333-44'], $this->sucesso()->valores_anteriores);
        $this->assertAuditoriaSemValores(['111.222.333-44', '11122233344']);

        AuditLog::query()->delete();
        $nota = NotaFiscal::create(['numero' => 'NF-1', 'tipo' => 'saida', 'valor' => 100.5, 'data_emissao' => '2026-09-01', 'cliente_id' => $cliente->id, 'tenant_id' => 1]);
        $this->confirmar($this->propor(['id' => $nota->id, 'valor' => 250], 'atualizar_nota_fiscal'))->assertOk();
        $this->assertSame(['valor'], array_keys($this->sucesso()->valores_anteriores), 'só o valor mudou (data e número não)');
        $this->assertEquals(100.5, $this->sucesso()->valores_anteriores['valor']);
    }

    public function test_antes_anotado_some_se_a_gravacao_falhar_depois(): void
    {
        $cliente = Cliente::create(['nome' => 'Loja', 'email' => 'velho@exemplo.com', 'tenant_id' => 1]);
        // A auditoria de sucesso falha DEPOIS da anotação: a transação desfaz e o registro de erro não leva o "antes".
        AuditLog::creating(function (AuditLog $log) {
            if ($log->resultado === 'sucesso') {
                throw new \RuntimeException('falha simulada');
            }
        });

        $this->confirmar($this->propor(['id' => $cliente->id, 'email' => 'novo@exemplo.com'], 'atualizar_cliente'))->assertStatus(500);

        $this->assertSame('velho@exemplo.com', $cliente->refresh()->email, 'nada gravado');
        $this->assertNull(AuditLog::query()->where('resultado', 'erro')->sole()->valores_anteriores);
    }

    public function test_detalhe_da_auditoria_mostra_o_antes_mascarado(): void
    {
        $funcionario = Funcionario::create(['nome' => 'Ana', 'cpf' => '111.222.333-44', 'salario' => 4567.89, 'data_admissao' => '2026-01-10', 'tenant_id' => 1]);
        $this->confirmar($this->propor(['id' => $funcionario->id, 'nome' => 'Ana Paula'], 'atualizar_funcionario'))->assertOk();

        $this->getJson('/auditoria/registros/'.$this->sucesso()->id)->assertOk()
            ->assertJsonPath('anterior', ['nome' => 'Ana']);

        // Registro antigo gravado sem máscara: a leitura mascara o "antes" de novo.
        $antigo = AuditLog::create(['user_id' => auth()->id(), 'tool' => 'atualizar_funcionario', 'acao' => 'atualizar', 'parametros' => [],
            'valores_anteriores' => ['cpf' => '111.222.333-44', 'salario' => '4567.89'], 'resultado' => 'sucesso', 'papel' => 'admin', 'permitido' => true, 'tenant_id' => 1]);
        $this->getJson("/auditoria/registros/{$antigo->id}")->assertOk()
            ->assertJsonPath('anterior', ['cpf' => '***.***.333-44', 'salario' => AuditoriaService::REDIGIDO])
            ->assertDontSee('111.222.333-44')->assertDontSee('4567');
    }

    public function test_status_conciliado_para_pendente_guarda_conciliado(): void
    {
        $lancamento = Lancamento::create(['descricao' => 'Aluguel', 'valor' => 1, 'data' => '2026-09-21', 'status' => 'conciliado', 'tenant_id' => 1]);

        $this->confirmar($this->propor(['id' => $lancamento->id, 'novo_status' => 'pendente'], 'atualizar_status_lancamento'))->assertOk();

        $this->assertSame(['status' => 'conciliado'], $this->sucesso()->valores_anteriores);
    }

    public function test_tool_que_saiu_do_catalogo_com_proposta_na_sessao_nao_grava_o_proposto(): void
    {
        $this->freezeTime();
        $proposta = $this->postJson('/agente/formulario', ['tool' => 'criar_cliente'])->assertOk()->json('proposta');
        // A tool some do catálogo com a proposta ainda na sessão (ex.: atualização do sistema).
        $rascunho = session("confirmacoes.{$proposta}");
        session(["confirmacoes.{$proposta}" => [...$rascunho, 'tool' => 'tool_removida', 'propostos' => ['cpf' => '123.456.789-01', 'obs' => 'x 98765432100']]]);
        $this->travel(\App\Services\ConfirmacoesPendentes::PRAZO_RASCUNHO_S + 1)->seconds();
        $this->get('/agente')->assertOk();

        $log = AuditLog::query()->where('resultado', 'expirado')->sole();
        $this->assertSame(['tool_removida', 'desconhecida'], [$log->tool, $log->acao]);
        $this->assertNull($log->parametros_propostos);
        $this->assertAuditoriaSemValores(['123.456.789-01', '98765432100']);
    }

    public function test_nome_de_tool_inventada_com_quebra_de_linha_e_redigido(): void
    {
        $this->comando("apagar\n", ['motivo' => 'x'])->assertStatus(422);

        $this->assertSame('[tool redigida]', AuditLog::query()->sole()->tool);
    }

    public function test_migration_do_valor_anterior_sobe_e_desce_sem_tocar_no_resto(): void
    {
        $migracao = require database_path('migrations/2026_10_22_120000_add_valores_anteriores_to_audit_logs_table.php');

        $this->assertTrue(Schema::hasColumn('audit_logs', 'valores_anteriores'));
        $migracao->down();
        $this->assertFalse(Schema::hasColumn('audit_logs', 'valores_anteriores'));
        $this->assertTrue(Schema::hasColumns('audit_logs', ['parametros', 'parametros_propostos', 'resultado', 'tenant_id']));
        $migracao->up();
        $this->assertTrue(Schema::hasColumn('audit_logs', 'valores_anteriores'));
    }

    // ---- tool fora do catálogo (pendência do A1) ----

    public function test_tool_inventada_com_cpf_e_salario_grava_so_os_nomes_dos_argumentos(): void
    {
        $this->comando('apagar_funcionario', [
            'cpf' => '123.456.789-01', 'salario' => 9876.54, 'observacao' => 'cpf 98765432100', 'dados' => ['cpf' => '22233344455'],
        ])->assertStatus(422)->assertJsonPath('codigo', 'AI_UNSUPPORTED_OPERATION');

        $log = AuditLog::query()->sole();
        $this->assertSame(['apagar_funcionario', 'desconhecida', 'negado', false], [$log->tool, $log->acao, $log->resultado, $log->permitido]);
        $this->assertSame(['cpf', 'salario', 'observacao', 'dados'], array_keys($log->parametros));
        $this->assertSame([AuditoriaService::REDIGIDO], array_values(array_unique($log->parametros)));

        $this->assertAuditoriaSemValores(['123.456.789-01', '12345678901', '9876', '98765432100', '22233344455']);
    }

    public function test_tool_inventada_nao_grava_dado_no_nome_dos_argumentos_nem_no_nome_da_tool(): void
    {
        $this->comando('buscar_12345678901', ['123.456.789-01' => true, 'cpf_98765432100' => 1, 'ana@exemplo.com' => 1, 'motivo' => 'x'])
            ->assertStatus(422);

        $log = AuditLog::query()->sole();
        $this->assertSame('[tool redigida]', $log->tool);
        $this->assertSame(['[chave redigida]', 'motivo'], array_keys($log->parametros));
        $this->assertAuditoriaSemValores(['12345678901', '123.456.789-01', '98765432100', 'ana@exemplo.com']);
    }
}
