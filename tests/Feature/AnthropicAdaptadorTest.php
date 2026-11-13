<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Cliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Adaptador da Anthropic (Messages API) dentro do AiAgentService: com SENTINEL_IA_PROVEDOR=anthropic
 * o pedido sai no formato da Anthropic e a resposta volta ao MESMO formato interno do Azure, de modo
 * que RBAC, Revisar -> segurar -> Confirmar, auditoria e descarte de tenant_id seguem iguais.
 */
class AnthropicAdaptadorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ia_provedor' => 'anthropic',
            'services.anthropic' => ['api_key' => 'chave-teste-anthropic', 'model' => 'claude-sonnet-5-5', 'versao' => '2026-10-21', 'max_tokens' => 8000, 'effort' => 'low'],
            'services.azure_foundry' => ['endpoint' => 'https://foundry.test', 'api_key' => 'k', 'deployment' => 'd'],
        ]);
        Http::preventStrayRequests();
        $this->logarComo('operador', 1);
    }

    private function resposta(array $conteudo, ?string $fim = 'tool_use'): array
    {
        return ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
            'content' => $conteudo, 'stop_reason' => $fim, 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]];
    }

    private function toolUse(string $nome, array|object $entrada): array
    {
        return ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => $nome, 'input' => $entrada];
    }

    private function fingir(array $corpo, int $status = 200): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($corpo, $status)]);
    }

    public function test_tool_use_de_leitura_com_entrada_vazia_vira_resultado_e_o_pedido_sai_no_formato_da_anthropic(): void
    {
        Cliente::create(['nome' => 'Padaria Pão Dourado', 'tenant_id' => 1]);
        $this->fingir($this->resposta([['type' => 'text', 'text' => 'Vou consultar.'], $this->toolUse('consultar_clientes', (object) [])]));

        $this->postJson('/agente/comando', ['mensagem' => 'liste meus clientes'])
            ->assertOk()->assertJsonPath('tipo', 'resultado_leitura');

        Http::assertSent(function (Request $r) {
            $corpo = json_decode($r->body());

            return $r->url() === 'https://api.anthropic.com/v1/messages'
                && $r->hasHeader('x-api-key', 'chave-teste-anthropic')
                && $r->hasHeader('anthropic-version', '2026-10-21')
                && ! $r->hasHeader('api-key')
                && $corpo->model === 'claude-sonnet-5-5'
                && $corpo->max_tokens === 8000
                && $corpo->output_config->effort === 'low'
                && $corpo->tool_choice->type === 'auto'
                && ! property_exists($corpo, 'temperature')
                && str_contains($corpo->system, 'Você é o assistente do Sentinel')
                && $corpo->messages == [(object) ['role' => 'user', 'content' => 'liste meus clientes']]
                && collect($corpo->tools)->every(fn ($t) => is_string($t->name) && ! property_exists($t, 'function')
                    && $t->input_schema->type === 'object' && $t->input_schema->properties instanceof \stdClass);
        });
        $this->assertNotNull(AuditLog::query()->sole()->tempo_ia_ms, 'tempo_ia_ms gravado também neste provedor');
    }

    public function test_resposta_so_de_texto_vira_texto(): void
    {
        $this->fingir($this->resposta([['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => 'De qual cliente?']], 'end_turn'));

        $this->postJson('/agente/comando', ['mensagem' => 'altera o telefone'])
            ->assertOk()->assertJsonPath('tipo', 'texto')->assertJsonPath('mensagem', 'De qual cliente?');
    }

    public static function falhasHttp(): array
    {
        return [
            '401' => [401, 502, 'AI_PROVIDER_UNAVAILABLE', 'provider_authentication_error'],
            '402' => [402, 502, 'AI_PROVIDER_UNAVAILABLE', 'provider_billing_error'],
            '429' => [429, 503, 'AI_PROVIDER_UNAVAILABLE', 'provider_rate_limit'],
            '529' => [529, 503, 'AI_PROVIDER_UNAVAILABLE', 'provider_overloaded'],
        ];
    }

    #[DataProvider('falhasHttp')]
    public function test_falha_http_do_provedor_vira_erro_controlado_sem_auditoria(int $status, int $local, string $codigo, string $categoria): void
    {
        Log::spy();
        $this->fingir(['type' => 'error', 'error' => ['type' => 'x', 'message' => 'corpo-confidencial']], $status);

        $this->postJson('/agente/comando', ['mensagem' => 'liste meus clientes'])
            ->assertStatus($local)->assertJsonPath('codigo', $codigo)->assertJsonMissingPath('tool');

        $this->assertSame(0, AuditLog::count());
        Log::shouldHaveReceived('warning')->with('AiAgentService: falha controlada', Mockery::on(
            fn (array $c) => $c['categoria'] === $categoria && $c['provider'] === 'anthropic' && $c['status'] === $status
                && is_int($c['tempo_ia_ms']) && ! str_contains(json_encode($c), 'corpo-confidencial') && ! str_contains(json_encode($c), 'chave-teste'),
        ));
    }

    public function test_timeout_vira_504(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30000 milliseconds'));

        $this->postJson('/agente/comando', ['mensagem' => 'liste meus clientes'])
            ->assertStatus(504)->assertJsonPath('codigo', 'AI_PROVIDER_TIMEOUT');
    }

    public function test_tool_solicitada_vai_com_tool_choice_auto_so_essa_tool_e_a_instrucao_no_system(): void
    {
        $this->logarComo('admin', 1);
        $cliente = Cliente::create(['nome' => 'Mercado Horizonte', 'tenant_id' => 1]);
        $this->fingir($this->resposta([$this->toolUse('excluir_cliente', ['busca' => 'Mercado Horizonte'])]));

        $this->postJson('/agente/comando', ['mensagem' => 'exclua o cliente Mercado Horizonte'])
            ->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente')->assertJsonPath('tool', 'excluir_cliente');

        Http::assertSent(fn (Request $r) => $r['tool_choice'] === ['type' => 'auto']
            && array_column($r['tools'], 'name') === ['excluir_cliente']
            && str_contains($r['system'], 'use a ferramenta excluir_cliente'));
        $this->assertDatabaseHas('clientes', ['id' => $cliente->id, 'deleted_at' => null]);
    }

    public function test_end_turn_com_tool_use_na_tool_solicitada_e_fora_do_formato(): void
    {
        // No Azure, "stop + tool_calls" só é aceito na seleção nominal (comportamento observado lá). Na Anthropic
        // o tool_use legítimo sempre vem com stop_reason tool_use: end_turn com tool_use não pode herdar a exceção.
        $this->logarComo('admin', 1);
        Cliente::create(['nome' => 'Mercado Horizonte', 'tenant_id' => 1]);
        $this->fingir($this->resposta([$this->toolUse('excluir_cliente', ['busca' => 'Mercado Horizonte'])], 'end_turn'));

        $this->postJson('/agente/comando', ['mensagem' => 'exclua o cliente Mercado Horizonte'])
            ->assertStatus(502)->assertJsonPath('codigo', 'AI_PROVIDER_RESPONSE_INVALID');
        $this->assertEmpty(session('confirmacoes', []));
    }

    public function test_tool_solicitada_trocada_pela_ia_e_recusada_como_divergencia(): void
    {
        $this->logarComo('admin', 1);
        $this->fingir($this->resposta([$this->toolUse('consultar_clientes', (object) [])]));

        $this->postJson('/agente/comando', ['mensagem' => 'exclua o cliente Horizonte'])
            ->assertStatus(422)->assertJsonPath('codigo', 'AI_INTENT_MISMATCH');

        $this->assertSame(['negado', false], [AuditLog::sole()->resultado, (bool) AuditLog::sole()->permitido]);
    }

    public function test_tenant_id_vindo_da_ia_e_descartado_tambem_neste_provedor(): void
    {
        $this->freezeTime();
        $this->fingir($this->resposta([$this->toolUse('criar_cliente', ['nome' => 'Padaria Sol', 'tenant_id' => 2])]));

        $resposta = $this->postJson('/agente/comando', ['mensagem' => 'cadastre o cliente Padaria Sol'])
            ->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente');
        $token = $this->postJson('/agente/revisar', ['proposta' => $resposta->json('proposta'), 'valores' => []])->assertOk()->json('token');
        $this->travel(2)->seconds();
        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();

        $this->assertDatabaseHas('clientes', ['nome' => 'Padaria Sol', 'tenant_id' => 1]);
        $this->assertDatabaseMissing('clientes', ['tenant_id' => 2]);
        $this->assertStringNotContainsString('tenant_id', (string) json_encode(AuditLog::query()->where('resultado', 'sucesso')->sole()->parametros_propostos));
    }

    public function test_historico_vai_so_com_role_e_texto_e_os_system_vao_para_o_campo_system(): void
    {
        $this->fingir($this->resposta([['type' => 'text', 'text' => 'Ok.']], 'end_turn'));

        $this->withSession(['agente_conversa' => ['identidade' => auth()->id().':1', 'mensagens' => [
            ['role' => 'assistant', 'content' => 'Olá, sou o agente.'],
            ['role' => 'user', 'content' => 'liste meus clientes', 'extra' => 'nao-enviar'],
            ['role' => 'assistant', 'content' => ''],
            ['role' => 'assistant', 'content' => 'Mostrei os clientes.'],
        ]]])->postJson('/agente/comando', ['mensagem' => 'obrigado'])->assertOk();

        Http::assertSent(fn (Request $r) => $r['messages'] === [
            ['role' => 'user', 'content' => 'liste meus clientes'],
            ['role' => 'assistant', 'content' => 'Mostrei os clientes.'],
            ['role' => 'user', 'content' => 'obrigado'],
        ] && str_contains($r['system'], 'O histórico acima é apenas contexto') && ! str_contains($r->body(), 'nao-enviar'));
    }

    public static function foraDoFormato(): array
    {
        $toolUse = ['type' => 'tool_use', 'id' => 't', 'name' => 'consultar_clientes', 'input' => new \stdClass];

        return [
            'pause_turn' => [['type' => 'message', 'content' => [$toolUse], 'stop_reason' => 'pause_turn'], 'AI_PROVIDER_RESPONSE_INVALID'],
            'sem stop_reason' => [['type' => 'message', 'content' => [['type' => 'text', 'text' => 'oi']]], 'AI_PROVIDER_RESPONSE_INVALID'],
            'stop_reason null' => [['type' => 'message', 'content' => [['type' => 'text', 'text' => 'oi']], 'stop_reason' => null], 'AI_PROVIDER_RESPONSE_INVALID'],
            'end_turn com tool_use' => [['type' => 'message', 'content' => [$toolUse], 'stop_reason' => 'end_turn'], 'AI_PROVIDER_RESPONSE_INVALID'],
            'content não é lista' => [['type' => 'message', 'content' => 'oi', 'stop_reason' => 'end_turn'], 'AI_PROVIDER_RESPONSE_INVALID'],
            'type não é message' => [['type' => 'outro', 'content' => [], 'stop_reason' => 'end_turn'], 'AI_PROVIDER_RESPONSE_INVALID'],
            'input que é lista' => [['type' => 'message', 'content' => [[...$toolUse, 'input' => [1, 2]]], 'stop_reason' => 'tool_use'], 'AI_PROVIDER_RESPONSE_INVALID'],
            'input fora do esquema' => [['type' => 'message', 'content' => [[...$toolUse, 'input' => ['inventado' => 1]]], 'stop_reason' => 'tool_use'], 'AI_PROVIDER_RESPONSE_INVALID'],
            'nome que não é texto' => [['type' => 'message', 'content' => [[...$toolUse, 'name' => 7]], 'stop_reason' => 'tool_use'], 'AI_PROVIDER_RESPONSE_INVALID'],
            'max_tokens' => [['type' => 'message', 'content' => [['type' => 'text', 'text' => 'mei']], 'stop_reason' => 'max_tokens'], 'AI_RESPONSE_TRUNCATED'],
            'refusal' => [['type' => 'message', 'content' => [], 'stop_reason' => 'refusal'], 'AI_CONTENT_FILTERED'],
        ];
    }

    #[DataProvider('foraDoFormato')]
    public function test_resposta_fora_do_formato_vira_erro_amigavel_sem_executar(array $corpo, string $codigo): void
    {
        $this->fingir($corpo);

        $this->postJson('/agente/comando', ['mensagem' => 'liste meus clientes'])
            ->assertStatus(502)->assertJsonPath('codigo', $codigo);

        $this->assertSame(0, AuditLog::count());
    }

    public function test_sem_configuracao_o_provedor_continua_o_azure(): void
    {
        config(['services.ia_provedor' => null]);
        Http::fake(['foundry.test/*' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => 'oi'], 'finish_reason' => 'stop']]])]);

        $this->postJson('/agente/comando', ['mensagem' => 'oi'])->assertOk()->assertJsonPath('tipo', 'texto');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://foundry.test/openai/v1/chat/completions' && $r->hasHeader('api-key', 'k'));
    }

    public static function semConfiguracao(): array
    {
        return [
            'provedor desconhecido' => [['services.ia_provedor' => 'antropic']],
            'sem chave' => [['services.anthropic.api_key' => '']],
            'sem modelo' => [['services.anthropic.model' => '']],
        ];
    }

    #[DataProvider('semConfiguracao')]
    public function test_configuracao_invalida_falha_fechado_sem_chamar_ninguem(array $config): void
    {
        config($config);
        Http::fake();

        $this->postJson('/agente/comando', ['mensagem' => 'liste meus clientes'])
            ->assertStatus(503)->assertJsonPath('codigo', 'AI_PROVIDER_UNAVAILABLE');

        Http::assertNothingSent();
    }

    public function test_chave_da_anthropic_nao_fica_no_historico(): void
    {
        $this->fingir($this->resposta([['type' => 'text', 'text' => 'Ok.']], 'end_turn'));

        $this->postJson('/agente/comando', ['mensagem' => 'guarde isto: chave-teste-anthropic'])->assertOk();

        $historico = json_encode(session('agente_conversa.mensagens'));
        $this->assertStringNotContainsString('chave-teste-anthropic', $historico);
        $this->assertStringContainsString('[credencial omitida]', $historico);
    }
}
