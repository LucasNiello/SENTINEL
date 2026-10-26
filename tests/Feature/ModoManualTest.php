<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\Funcionario;
use App\Models\Lancamento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * B2 (RF05/RNF05) — modo manual sem IA: listar e buscar cada entidade pelo
 * /agente/consultar e abrir os formulários pelo /agente/formulario, com o mesmo
 * RBAC, tenant, máscara, Revisar → segurar e auditoria. A Azure simulada fica
 * FORA DO AR em todos os testes: nenhum passo do modo manual depende dela.
 */
class ModoManualTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.azure_foundry' => ['endpoint' => 'https://foundry.test', 'api_key' => 'k', 'deployment' => 'd']]);
        Http::fake(fn () => throw new ConnectionException('fora do ar'));
        $this->usuario = $this->logarComo('operador', 1);
    }

    private function consultar(string $tool, array $filtros = [])
    {
        return $this->postJson('/agente/consultar', ['tool' => $tool, 'filtros' => $filtros]);
    }

    public function test_com_a_azure_fora_do_ar_lista_busca_cadastra_e_exporta(): void
    {
        // A IA está mesmo fora: o agente responde com erro.
        $this->postJson('/agente/comando', ['mensagem' => 'liste os clientes'])->assertStatus(503)->assertJsonPath('tipo', 'erro');
        $chamadasAntes = count(Http::recorded());

        Cliente::create(['nome' => 'José da Silva', 'documento' => '123.456.789-01', 'tenant_id' => 1]);
        Cliente::create(['nome' => 'Mercado Ação', 'documento' => '12.345.678/0001-90', 'tenant_id' => 1]);

        $lista = $this->consultar('consultar_clientes')->assertOk()
            ->assertJsonPath('tipo', 'resultado_leitura')
            ->assertJsonPath('colunas', ['id', 'nome', 'documento', 'email', 'telefone'])
            ->assertJsonPath('editar', 'atualizar_cliente');
        $this->assertCount(2, $lista->json('resultado'));
        $this->assertArrayNotHasKey('consulta', $lista->json(), 'os filtros não voltam ao front');
        $this->assertStringNotContainsString('123.456.789-01', $lista->getContent());

        $busca = $this->consultar('consultar_clientes', ['nome' => 'silva'])->assertOk();
        $this->assertSame(['José da Silva'], array_column($busca->json('resultado'), 'nome'));
        $this->assertSame('***.***.789-01', $busca->json('resultado.0.documento'));

        // Cadastro pelo formulário, com o mesmo Revisar → segurar.
        $this->freezeTime();
        $proposta = $this->postJson('/agente/formulario', ['tool' => 'criar_cliente'])->assertOk()->json('proposta');
        $token = $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => ['nome' => 'Padaria Nova']])->assertOk()->json('token');
        $this->travel(2)->seconds();
        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk()->assertJsonPath('tipo', 'resultado_escrita');
        $this->assertDatabaseHas('clientes', ['nome' => 'Padaria Nova', 'tenant_id' => 1]);

        $csv = $this->post('/agente/exportar', ['consulta' => $busca->json('exportar')], ['Accept' => 'application/json'])->assertOk()->getContent();
        $this->assertStringContainsString('***.***.789-01', $csv);

        $this->assertCount($chamadasAntes, Http::recorded(), 'nenhum passo do modo manual chamou a IA');

        $this->assertSame(['manual', 'manual'], AuditLog::query()->where('acao', 'consultar')->pluck('origem')->all());
        $this->assertSame('manual', AuditLog::query()->where('acao', 'exportar')->sole()->origem);
        $this->assertSame('formulario', AuditLog::query()->where('acao', 'criar')->sole()->origem);
        $this->assertSame(['nome' => 'silva', 'tenant_id' => 1], AuditLog::query()->where('acao', 'consultar')->latest('id')->first()->parametros);
    }

    public function test_cada_entidade_lista_so_o_tenant_do_usuario(): void
    {
        Cliente::create(['nome' => 'Meu', 'tenant_id' => 1]);
        Cliente::create(['nome' => 'Alheio', 'tenant_id' => 2]);
        Lancamento::create(['descricao' => 'Aluguel', 'valor' => 10, 'data' => '2026-09-21', 'status' => 'pendente', 'tenant_id' => 1]);
        Lancamento::create(['descricao' => 'Outro', 'valor' => 10, 'data' => '2026-09-21', 'status' => 'pendente', 'tenant_id' => 2]);

        $this->assertSame(['Meu'], array_column($this->consultar('consultar_clientes')->assertOk()->json('resultado'), 'nome'));
        $this->assertSame(['Aluguel'], array_column($this->consultar('consultar_lancamentos', ['status' => 'pendente'])->assertOk()->json('resultado'), 'descricao'));

        foreach (['consultar_fornecedores', 'consultar_funcionarios', 'consultar_notas_fiscais', 'consultar_categorias_lancamento', 'consultar_lixeira'] as $tool) {
            $this->consultar($tool)->assertOk()->assertJsonPath('tipo', 'resultado_leitura');
        }
    }

    public function test_funcionarios_sem_cpf_nem_salario(): void
    {
        Funcionario::create(['nome' => 'Ana', 'cpf' => '111.222.333-44', 'salario' => 4567.89, 'cargo' => 'Analista', 'data_admissao' => '2026-01-10', 'tenant_id' => 1]);

        $resposta = $this->consultar('consultar_funcionarios', ['nome' => 'Ana'])->assertOk();

        $this->assertSame(['id', 'nome', 'cargo', 'data_admissao', 'data_demissao'], array_keys($resposta->json('resultado.0')));
        $this->assertStringNotContainsString('111.222.333-44', $resposta->getContent());
        $this->assertStringNotContainsString('4567', $resposta->getContent());
    }

    public function test_rbac_nega_e_audita_com_origem_manual(): void
    {
        $this->logarComo('leitura', 1);

        $this->consultar('consultar_lixeira')->assertStatus(403)->assertJsonPath('tipo', 'negado');
        $this->consultar('consultar_clientes')->assertOk()->assertJsonMissingPath('editar');

        $negado = AuditLog::query()->where('resultado', 'negado')->sole();
        $this->assertSame(['consultar_lixeira', 'manual', false], [$negado->tool, $negado->origem, $negado->permitido]);
    }

    public function test_so_aceita_consulta_do_catalogo_com_filtros_do_esquema(): void
    {
        $this->consultar('criar_cliente')->assertStatus(422)->assertJsonPath('codigo', 'AI_UNSUPPORTED_OPERATION');
        $this->consultar('apagar_tudo')->assertStatus(422)->assertJsonPath('codigo', 'AI_UNSUPPORTED_OPERATION');
        $this->postJson('/agente/consultar', ['tool' => ['x']])->assertStatus(422)->assertJsonPath('codigo', 'AI_VALIDATION_ERROR');
        $this->postJson('/agente/consultar', ['tool' => 'consultar_clientes', 'filtros' => 'x'])->assertStatus(422);

        // Tenant, chave fora do esquema, enum, data e tipo errados: recusa sem consultar.
        $this->consultar('consultar_clientes', ['tenant_id' => 2])->assertStatus(422)->assertJsonPath('codigo', 'AI_VALIDATION_ERROR');
        $this->consultar('consultar_clientes', ['cpf' => '1'])->assertStatus(422);
        $this->consultar('consultar_lancamentos', ['status' => 'apagado'])->assertStatus(422);
        $this->consultar('consultar_lancamentos', ['data_inicio' => '21/09/2026'])->assertStatus(422);
        $this->consultar('consultar_clientes', ['nome' => ['a']])->assertStatus(422);
        $this->consultar('consultar_clientes', ['nome' => str_repeat('a', 101)])->assertStatus(422)
            ->assertJsonPath('mensagem', 'Algum filtro está inválido. Confira e tente de novo.');

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_filtro_em_branco_lista_tudo(): void
    {
        Cliente::create(['nome' => 'A', 'tenant_id' => 1]);
        Cliente::create(['nome' => 'B', 'tenant_id' => 1]);

        $this->assertCount(2, $this->consultar('consultar_clientes', ['nome' => ''])->assertOk()->json('resultado'));
        $this->assertSame(['tenant_id' => 1], AuditLog::query()->sole()->parametros);
    }

    public function test_limite_por_minuto_e_login(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->consultar('consultar_clientes')->assertOk();
        }
        $this->consultar('consultar_clientes')->assertStatus(429)->assertJsonPath('codigo', 'AI_RATE_LIMIT');

        auth()->logout();
        $this->consultar('consultar_clientes')->assertStatus(401);
    }
}
