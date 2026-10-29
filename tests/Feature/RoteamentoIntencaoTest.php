<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\AuditLog;
use App\Services\OperacaoSolicitada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoteamentoIntencaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.azure_foundry' => ['endpoint' => 'https://foundry.test', 'api_key' => 'fake', 'deployment' => 'test']]);
        Http::preventStrayRequests();
    }

    private function respostaTool(string $tool, array $args = []): array
    {
        return ['choices' => [['finish_reason' => 'tool_calls', 'message' => ['tool_calls' => [
            ['function' => ['name' => $tool, 'arguments' => json_encode((object) $args)]],
        ]]]]];
    }

    public static function exclusoesProibidas(): iterable
    {
        foreach (['leitura', 'operador'] as $papel) {
            foreach (['exclua', 'delete', 'remova', 'quero excluir', 'apague'] as $verbo) {
                yield $papel.' '.$verbo => [$papel, $verbo.' o cliente Mercado Horizonte'];
            }
        }
    }

    #[DataProvider('exclusoesProibidas')]
    public function test_intencao_negada_antes_da_ia_nao_pode_virar_consulta(string $papel, string $pedido): void
    {
        $this->logarComo($papel);
        $cliente = Cliente::create(['nome' => 'Mercado Horizonte', 'tenant_id' => 1]);
        // Reproduz a resposta incorreta real: ela não deve sequer ser solicitada nesse papel.
        Http::fake(fn () => Http::response($this->respostaTool('consultar_clientes')));
        $this->withSession(['agente_conversa' => ['identidade' => auth()->id().':1', 'mensagens' => [
            ['role' => 'user', 'content' => 'liste meus clientes'], ['role' => 'assistant', 'content' => 'Mostrei os clientes.'],
        ]]])->postJson('/agente/comando', ['mensagem' => $pedido])->assertForbidden()->assertJsonPath('tipo', 'negado');
        Http::assertNothingSent();
        $this->assertDatabaseHas('audit_logs', ['tool' => 'excluir_cliente', 'acao' => 'excluir', 'papel' => $papel, 'resultado' => 'negado', 'tenant_id' => 1]);
        $this->assertSame(1, AuditLog::count());
        $this->assertDatabaseHas('clientes', ['id' => $cliente->id, 'deleted_at' => null]);
        $this->assertEmpty(session('confirmacoes', []));
    }

    public function test_negativa_independe_de_azure_configurado_e_nao_revela_outro_tenant(): void
    {
        $this->logarComo('operador');
        config(['services.azure_foundry.api_key' => '']);
        $cliente = Cliente::create(['nome' => 'Outro tenant', 'tenant_id' => 2]);
        $this->postJson('/agente/comando', ['mensagem' => 'exclua o cliente Outro tenant'])->assertForbidden();
        Http::assertNothingSent();
        $this->assertDatabaseHas('clientes', ['id' => $cliente->id, 'tenant_id' => 2, 'deleted_at' => null]);
    }

    public static function finaisAzure(): array
    {
        return [['tool_calls'], ['stop']];
    }

    #[DataProvider('finaisAzure')]
    public function test_admin_recebe_apenas_a_tool_de_exclusao_e_propoe_sem_executar(string $fim): void
    {
        $this->logarComo('admin');
        $cliente = Cliente::create(['nome' => 'Mercado Horizonte', 'tenant_id' => 1]);
        $body = $this->respostaTool('excluir_cliente', ['busca' => $cliente->nome]);
        $body['choices'][0]['finish_reason'] = $fim;
        Http::fake(fn () => Http::response($body));
        $this->postJson('/agente/comando', ['mensagem' => 'Por favor, quero excluir o cliente Mercado Horizonte'])
            ->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente')->assertJsonPath('tool', 'excluir_cliente');
        Http::assertSent(fn ($r) => array_column(array_column($r['tools'], 'function'), 'name') === ['excluir_cliente']
            && $r['tool_choice']['function']['name'] === 'excluir_cliente');
        $this->assertDatabaseHas('clientes', ['id' => $cliente->id, 'deleted_at' => null]);
    }

    public static function argumentosForcadosInvalidos(): array
    {
        return [['{invalido'], ['{"campo_fora_schema":1}'], ['[]']];
    }

    #[DataProvider('argumentosForcadosInvalidos')]
    public function test_stop_na_tool_forcada_nao_afrouxa_schema_ou_json(string $args): void
    {
        $this->logarComo('admin');
        $body = $this->respostaTool('excluir_cliente');
        $body['choices'][0]['finish_reason'] = 'stop';
        $body['choices'][0]['message']['tool_calls'][0]['function']['arguments'] = $args;
        Http::fake(fn () => Http::response($body));
        $this->postJson('/agente/comando', ['mensagem' => 'exclua o cliente Horizonte'])->assertStatus(502);
        $this->assertSame(0, AuditLog::count());
    }

    public static function escritas(): array
    {
        return [['exclua o cliente Horizonte'], ['altere o cliente Horizonte'], ['cadastre o cliente Horizonte'], ['restaure o cliente Horizonte']];
    }

    #[DataProvider('escritas')]
    public function test_resposta_incompativel_do_provedor_nao_executa_consulta(string $pedido): void
    {
        $this->logarComo('admin');
        Http::fake(fn () => Http::response($this->respostaTool('consultar_clientes')));
        $this->postJson('/agente/comando', ['mensagem' => $pedido])->assertStatus(422)->assertJsonPath('codigo', 'AI_INTENT_MISMATCH');
        $this->assertSame(0, AuditLog::count());
        $this->assertSame(0, Cliente::count());
        $this->assertEmpty(session('confirmacoes', []));
    }

    public function test_nao_basta_uma_primeira_tool_correta_para_aceitar_segunda_incompativel(): void
    {
        $this->logarComo('admin');
        $body = $this->respostaTool('excluir_cliente', ['id' => 1]);
        $body['choices'][0]['message']['tool_calls'][] = ['function' => ['name' => 'consultar_fornecedores', 'arguments' => '{}']];
        Http::fake(fn () => Http::response($body));
        $this->postJson('/agente/comando', ['mensagem' => 'exclua o cliente Horizonte'])->assertStatus(422);
        $this->assertSame(0, AuditLog::count());
    }

    public static function consultas(): array
    {
        return [['liste meus clientes'], ['mostre o cliente Horizonte'], ['mostre o cliente Exclua'], ['como excluir o cliente?'], ['não exclua o cliente Horizonte']];
    }

    #[DataProvider('consultas')]
    public function test_consultas_e_citacoes_nao_sao_classificadas_como_ordem_de_escrita(string $pedido): void
    {
        $this->logarComo('operador');
        Http::fake(fn () => Http::response($this->respostaTool('consultar_clientes')));
        $this->postJson('/agente/comando', ['mensagem' => $pedido])->assertOk()->assertJsonPath('tipo', 'resultado_leitura');
        Http::assertSent(fn ($r) => $r['tool_choice'] === 'auto');
        $this->assertDatabaseHas('audit_logs', ['tool' => 'consultar_clientes', 'resultado' => 'sucesso']);
    }

    public function test_mesma_politica_para_outros_objetos_sem_nome_ou_id_fixo(): void
    {
        $this->logarComo('operador');
        foreach (['fornecedor ABC', 'funcionário João', 'nota fiscal 123', 'categoria de lançamento Taxas', 'lançamento de ontem'] as $objeto) {
            $this->postJson('/agente/comando', ['mensagem' => 'remova o '.$objeto])->assertForbidden();
        }
        Http::assertNothingSent();
        $this->assertSame(5, AuditLog::where('resultado', 'negado')->count());
    }

    public function test_exclusao_definitiva_nao_vira_exclusao_logica(): void
    {
        $intencao = app(OperacaoSolicitada::class);
        foreach (['exclua de vez o cliente X', 'exclua definitivamente o cliente X', 'exclua o cliente de vez X'] as $pedido) {
            $this->assertSame(['acao' => 'excluir_definitivo', 'entidade' => 'Cliente'], $intencao->reconhecer($pedido));
        }
    }
}
