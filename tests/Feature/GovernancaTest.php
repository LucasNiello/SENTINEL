<?php

namespace Tests\Feature;

use App\Http\Requests\NotaFiscalRequest;
use App\Models\CategoriaLancamento;
use App\Models\Cliente;
use App\Models\Fornecedor;
use App\Models\Funcionario;
use App\Models\Lancamento;
use App\Models\NotaFiscal;
use App\Services\CategoriaLancamentoService;
use App\Services\ClienteService;
use App\Services\FornecedorService;
use App\Services\FuncionarioService;
use App\Services\LancamentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * RNF09: garantias de governança (bloqueio por dependência RF08, lixeira RF09,
 * restauração RF10, arquivamento RF11, exclusão física manual RF12, validação
 * no backend RNF03) cobertas por testes automatizados.
 */
class GovernancaTest extends TestCase
{
    use RefreshDatabase;

    private function cliente(string $nome = 'Cliente'): Cliente
    {
        return Cliente::create(['nome' => $nome, 'tenant_id' => 1]);
    }

    private function lancamento(array $extra = []): Lancamento
    {
        return Lancamento::create([
            'descricao' => 'Lançamento', 'valor' => 10, 'data' => '2026-09-21', 'status' => 'pendente', 'tenant_id' => 1,
            ...$extra,
        ]);
    }

    public function test_rf08_cliente_com_lancamento_nao_pode_ser_excluido(): void
    {
        $cliente = $this->cliente();
        $this->lancamento(['cliente_id' => $cliente->id]);

        $resultado = app(ClienteService::class)->excluir($cliente->id);

        $this->assertTrue($resultado['bloqueado']);
        $this->assertStringContainsString('lançamentos vinculados', $resultado['motivo']);
        $this->assertNotNull(Cliente::find($cliente->id));
    }

    public function test_rf08_fornecedor_funcionario_e_categoria_com_lancamento_sao_bloqueados(): void
    {
        $fornecedor = Fornecedor::create(['nome' => 'F', 'tenant_id' => 1]);
        $funcionario = Funcionario::create(['nome' => 'Fn', 'cpf' => '1', 'salario' => 1000, 'data_admissao' => '2026-01-01', 'tenant_id' => 1]);
        $categoria = CategoriaLancamento::create(['nome' => 'C', 'tipo' => 'despesa', 'tenant_id' => 1]);
        $this->lancamento([
            'fornecedor_id' => $fornecedor->id,
            'funcionario_id' => $funcionario->id,
            'categoria_lancamento_id' => $categoria->id,
        ]);

        $this->assertTrue(app(FornecedorService::class)->excluir($fornecedor->id)['bloqueado']);
        $this->assertTrue(app(FuncionarioService::class)->excluir($funcionario->id)['bloqueado']);
        $this->assertTrue(app(CategoriaLancamentoService::class)->excluir($categoria->id)['bloqueado']);
    }

    public function test_rf08_lancamento_com_nota_fiscal_e_bloqueado_com_motivo_em_linguagem_simples(): void
    {
        $fornecedor = Fornecedor::create(['nome' => 'F', 'tenant_id' => 1]);
        $lancamento = $this->lancamento();
        NotaFiscal::create([
            'numero' => 'NF-1', 'tipo' => 'entrada', 'valor' => 10, 'data_emissao' => '2026-09-21',
            'fornecedor_id' => $fornecedor->id, 'lancamento_id' => $lancamento->id, 'tenant_id' => 1,
        ]);

        $resultado = app(LancamentoService::class)->excluir($lancamento->id);

        $this->assertTrue($resultado['bloqueado']);
        $this->assertStringContainsString('notas fiscais vinculadas', $resultado['motivo']);
        $this->assertStringNotContainsString('notasFiscais', $resultado['motivo']);
    }

    public function test_rf09_exclusao_sem_dependencia_vai_para_a_lixeira_sem_remocao_fisica(): void
    {
        $cliente = $this->cliente();

        $resultado = app(ClienteService::class)->excluir($cliente->id);

        $this->assertFalse($resultado['bloqueado']);
        $this->assertNull(Cliente::find($cliente->id));
        $this->assertTrue(DB::table('clientes')->where('id', $cliente->id)->exists());
        $this->assertTrue(Cliente::naLixeira()->where('id', $cliente->id)->exists());
    }

    public function test_rf10_restauracao_exige_reautenticacao_de_administrador(): void
    {
        $cliente = $this->cliente();
        app(ClienteService::class)->excluir($cliente->id);

        $negado = app(ClienteService::class)->restaurarDaLixeira($cliente->id, false);
        $this->assertFalse($negado['restaurado']);
        $this->assertNull(Cliente::find($cliente->id));

        $permitido = app(ClienteService::class)->restaurarDaLixeira($cliente->id, true);
        $this->assertTrue($permitido['restaurado']);
        $this->assertNotNull(Cliente::find($cliente->id));
    }

    public function test_rf11_rf12_prazo_expirado_arquiva_sem_excluir_fisicamente(): void
    {
        $vencido = $this->cliente('Vencido');
        $recente = $this->cliente('Recente');
        app(ClienteService::class)->excluir($vencido->id);
        app(ClienteService::class)->excluir($recente->id);
        $dias = (int) config('sentinel.retencao_lixeira_dias');
        DB::table('clientes')->where('id', $vencido->id)->update(['deleted_at' => now()->subDays($dias + 1)]);
        $fisicosAntes = DB::table('clientes')->count();

        Artisan::call('sentinel:arquivar-expirados');

        $this->assertNotNull(Cliente::withTrashed()->find($vencido->id)->arquivado_em);
        $this->assertNull(Cliente::withTrashed()->find($recente->id)->arquivado_em);
        $this->assertSame($fisicosAntes, DB::table('clientes')->count(), 'RF12: nada é removido fisicamente automaticamente');
        $this->assertFalse(Cliente::naLixeira()->where('id', $vencido->id)->exists());
        $this->assertFalse(app(ClienteService::class)->restaurarDaLixeira($vencido->id, true)['restaurado']);
    }

    public function test_rnf03_validacao_de_nota_fiscal_no_backend(): void
    {
        $regras = (new NotaFiscalRequest)->rules();
        $base = ['numero' => '1', 'tipo' => 'saida', 'valor' => 1, 'data_emissao' => '2026-01-01', 'tenant_id' => 1];

        $this->assertTrue(Validator::make($base, $regras)->fails(), 'saída exige cliente');
        $this->assertTrue(Validator::make([...$base, 'tipo' => 'entrada', 'cliente_id' => 1], $regras)->fails(), 'entrada proíbe cliente');
        $this->assertTrue(Validator::make([...$base, 'tipo' => 'outro'], $regras)->fails(), 'tipo fora do enum');
    }
}
