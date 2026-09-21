<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CategoriaLancamento;
use App\Services\AiAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Tools de CategoriaLancamento: consultar (leitura, tenant filtrado, filtro por tipo)
 * e criar (operador + confirmação + auditoria + frase amigável — inclusive o
 * vocabulário próprio do campo "tipo": receita/despesa, não entrada/saida).
 */
class CategoriaLancamentoToolsTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararAgente();
    }

    // ---- consultar_categorias_lancamento ----

    public function test_consultar_filtra_tipo_e_tenant_e_devolve_so_as_colunas(): void
    {
        CategoriaLancamento::create(['nome' => 'Vendas', 'tipo' => 'receita', 'tenant_id' => 1]);
        CategoriaLancamento::create(['nome' => 'Aluguel', 'tipo' => 'despesa', 'tenant_id' => 1]);
        CategoriaLancamento::create(['nome' => 'Vendas alheias', 'tipo' => 'receita', 'tenant_id' => 2]);

        $resposta = $this->comando('consultar_categorias_lancamento', ['tipo' => 'receita'])
            ->assertOk()
            ->assertJsonPath('tipo', 'resultado_leitura')
            ->assertJsonCount(1, 'resultado')
            ->assertJsonPath('resultado.0.nome', 'Vendas')
            ->assertJsonPath('colunas', ['id', 'nome', 'tipo']);

        $linha = array_keys($resposta->json('resultado.0'));
        sort($linha);
        $this->assertSame(['id', 'nome', 'tipo'], $linha, 'sem tenant_id nem timestamps');

        $this->comando('consultar_categorias_lancamento')->assertOk()->assertJsonCount(2, 'resultado'); // só o tenant 1
    }

    public function test_papel_leitura_consulta_e_a_consulta_e_auditada(): void
    {
        config(['sentinel.papel_atual' => 'leitura']);

        $this->comando('consultar_categorias_lancamento')->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('consultar_categorias_lancamento', $log->tool);
        $this->assertSame('consultar', $log->acao);
        $this->assertSame('CategoriaLancamento', $log->entidade_tipo);
        $this->assertSame('sucesso', $log->resultado);
        $this->assertSame('leitura', $log->papel);
    }

    // ---- criar_categoria_lancamento ----

    public function test_criar_so_grava_apos_confirmar_com_tenant_do_servidor_e_audita(): void
    {
        config(['sentinel.tenant_atual' => 7]);

        $token = $this->propor(['nome' => 'Serviços extras', 'tipo' => 'receita', 'tenant_id' => 999], 'criar_categoria_lancamento');
        $this->assertSame(0, CategoriaLancamento::count(), 'propor não grava');
        $this->assertSame(0, AuditLog::count(), 'propor não audita');

        $this->confirmar($token)->assertOk()->assertJsonPath('tipo', 'resultado_escrita');

        $categoria = CategoriaLancamento::query()->sole();
        $this->assertSame('Serviços extras', $categoria->nome);
        $this->assertSame('receita', $categoria->tipo);
        $this->assertSame(7, $categoria->tenant_id);

        $log = AuditLog::query()->sole();
        $this->assertSame('criar_categoria_lancamento', $log->tool);
        $this->assertSame('criar', $log->acao);
        $this->assertSame('CategoriaLancamento', $log->entidade_tipo);
        $this->assertSame($categoria->id, $log->entidade_id);
        $this->assertSame('sucesso', $log->resultado);
        $this->assertSame(7, $log->tenant_id);
    }

    public function test_criar_recusa_dado_invalido_com_frase_amigavel_no_vocabulario_de_categoria(): void
    {
        $casos = [
            [['tipo' => 'receita'], 'Informe o nome.'],
            [['nome' => 'X'], 'Informe o tipo.'],
            [['nome' => str_repeat('a', 256), 'tipo' => 'receita'], 'O nome aceita no máximo 255 caracteres.'],
            [['nome' => 'X', 'tipo' => 'entrada'], 'O tipo deve ser "receita" ou "despesa".'], // não vaza o vocabulário da nota fiscal
            [['nome' => ['lista'], 'tipo' => 'receita'], 'O nome deve ser um texto.'],
        ];

        foreach ($casos as $i => [$argumentos, $esperada]) {
            $token = $this->propor($argumentos, 'criar_categoria_lancamento');

            $mensagem = $this->confirmar($token)
                ->assertStatus(422)
                ->assertJsonPath('tipo', 'erro')
                ->json('mensagem');

            $this->assertSame($esperada, $mensagem);
            foreach (['validation', 'The ', 'field', 'Exception', 'SQL', 'App\\', 'required', 'tenant'] as $termo) {
                $this->assertStringNotContainsString($termo, $mensagem, 'sem termo técnico');
            }

            $this->assertSame($i + 1, AuditLog::count());
            $log = AuditLog::query()->latest('id')->first();
            $this->assertSame('recusado', $log->resultado);
            $this->assertTrue($log->permitido, 'recusa de regra de negócio não é negação de RBAC');
        }

        $this->assertSame(0, CategoriaLancamento::count(), 'nada foi gravado');
    }

    public function test_frase_de_tipo_da_nota_fiscal_nao_foi_afetada(): void
    {
        // A mesma chave "tipo.in" tem vocabulário diferente por entidade: cada tool leva a sua frase.
        $token = $this->propor(['numero' => 'NF-1', 'tipo' => 'receita', 'valor' => 1, 'data_emissao' => '2026-09-01'], 'criar_nota_fiscal');

        $this->confirmar($token)
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'O tipo deve ser "entrada" ou "saida".');
    }

    // ---- RBAC ----

    public function test_leitura_nao_cria_categoria_e_a_tentativa_negada_e_auditada(): void
    {
        config(['sentinel.papel_atual' => 'leitura']);

        $this->comando('criar_categoria_lancamento', ['nome' => 'X', 'tipo' => 'receita'])
            ->assertStatus(403)
            ->assertJsonPath('tipo', 'negado')
            ->assertJsonMissingPath('token');

        $log = AuditLog::query()->sole();
        $this->assertSame('criar_categoria_lancamento', $log->tool);
        $this->assertSame('negado', $log->resultado);
        $this->assertFalse($log->permitido);
        $this->assertSame('leitura', $log->papel);
        $this->assertSame(0, CategoriaLancamento::count());
    }

    public function test_operador_pode_criar_categoria(): void
    {
        config(['sentinel.papel_atual' => 'operador']);

        $token = $this->propor(['nome' => 'Feita pelo operador', 'tipo' => 'despesa'], 'criar_categoria_lancamento');
        $this->confirmar($token)->assertOk();

        $this->assertSame(1, CategoriaLancamento::count());
        $this->assertSame('operador', AuditLog::query()->sole()->papel);
    }

    // ---- Catálogo ----

    public function test_schema_das_tools_de_categoria(): void
    {
        $tools = collect(app(AiAgentService::class)->tools())->keyBy('function.name');

        $consulta = $tools['consultar_categorias_lancamento']['function']['parameters'] ?? null;
        $criacao = $tools['criar_categoria_lancamento']['function']['parameters'] ?? null;
        $this->assertNotNull($consulta, 'consultar_categorias_lancamento deve estar no catálogo');
        $this->assertNotNull($criacao, 'criar_categoria_lancamento deve estar no catálogo');

        $this->assertSame(['tipo'], array_keys($consulta['properties']));
        $this->assertSame(['receita', 'despesa'], $consulta['properties']['tipo']['enum']);
        $this->assertArrayNotHasKey('required', $consulta);

        $this->assertSame(['nome', 'tipo'], array_keys($criacao['properties']));
        $this->assertSame(['receita', 'despesa'], $criacao['properties']['tipo']['enum']);
        $this->assertSame(['nome', 'tipo'], $criacao['required']);
        $this->assertArrayNotHasKey('tenant_id', $criacao['properties']);
    }
}
