<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgenteCorrecoesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.azure_foundry' => ['endpoint' => 'https://foundry.test', 'api_key' => 'secret-marker', 'deployment' => 'd']]);
        $this->logarComo();
        Http::preventStrayRequests();
    }

    public static function errosHttp(): array
    {
        return [[401, 502, 'provider_authentication_error'], [403, 502, 'provider_authorization_error'],
            [404, 502, 'provider_http_error'], [429, 503, 'provider_rate_limit'], [500, 503, 'provider_http_error'],
            [504, 504, 'provider_timeout']];
    }

    #[DataProvider('errosHttp')]
    public function test_erro_externo_tem_status_e_log_sem_body(int $externo, int $local, string $categoria): void
    {
        Log::spy();
        Http::fake(['*' => Http::response(['error' => ['code' => 'secret-marker', 'message' => 'BODY-CONFIDENCIAL']], $externo)]);
        $r = $this->postJson('/agente/comando', ['mensagem' => 'PROMPT-CONFIDENCIAL'])->assertStatus($local)
            ->assertJsonPath('categoria', $categoria)->assertJsonStructure(['codigo', 'correlation_id']);
        $id = $r->json('correlation_id');
        Log::shouldHaveReceived('warning')->once()->with(Mockery::any(), Mockery::on(function ($c) use ($id, $externo) {
            $s = json_encode($c);
            return $c['correlation_id'] === $id && $c['status'] === $externo && !str_contains($s, 'CONFIDENCIAL') && !str_contains($s, 'secret-marker');
        }));
        $this->assertSame(0, AuditLog::count());
        $this->assertStringNotContainsString('CONFIDENCIAL', $r->getContent());
    }

    public static function transporte(): array { return [[7, 503, 'provider_connection_error'], [28, 504, 'provider_timeout'], [60, 503, 'provider_connection_error']]; }

    #[DataProvider('transporte')]
    public function test_transporte_classificado_sem_exception_bruta(int $errno, int $status, string $categoria): void
    {
        Log::spy();
        Http::fake(function () use ($errno) {
            $cause = $errno === 28
                ? new \GuzzleHttp\Exception\NetworkTimeoutException('secret-marker', new Request('POST', 'https://foundry.test'))
                : new ConnectException('secret-marker', new Request('POST', 'https://foundry.test'));
            throw new ConnectionException('BODY-CONFIDENCIAL', 0, $cause);
        });
        $this->postJson('/agente/comando', ['mensagem' => 'oi'])->assertStatus($status)->assertJsonPath('categoria', $categoria);
        Log::shouldHaveReceived('warning')->once()->with(Mockery::any(), Mockery::on(fn ($c) => isset($c['classe'], $c['arquivo'], $c['linha'], $c['correlation_id']) && !str_contains(json_encode($c), 'CONFIDENCIAL')));
    }

    public static function argumentosInvalidos(): array
    {
        return [['{abc'], [null], ['[]'], ['"texto"'], ['null'], ['{"desconhecida":1}'], [42], [['nome' => 'X']]];
    }

    #[DataProvider('argumentosInvalidos')]
    public function test_argumentos_invalidos_nao_executam_tool(mixed $args): void
    {
        Http::fake(['*' => Http::response(['choices' => [['finish_reason' => 'tool_calls', 'message' => ['tool_calls' => [
            ['function' => ['name' => 'consultar_clientes', 'arguments' => $args]],
        ]]]]])]);
        $this->postJson('/agente/comando', ['mensagem' => 'liste'])->assertStatus(502)->assertJsonPath('codigo', 'AI_PROVIDER_RESPONSE_INVALID');
        $this->assertSame(0, AuditLog::count());
        $this->assertEmpty(session('confirmacoes', []));
    }

    public static function argumentosValidos(): array { return [['{}'], ['{"nome":"Mercado"}']]; }

    #[DataProvider('argumentosValidos')]
    public function test_objeto_json_valido_executa_consulta(string $args): void
    {
        Http::fake(['*' => Http::response(['choices' => [['finish_reason' => 'tool_calls', 'message' => ['tool_calls' => [
            ['function' => ['name' => 'consultar_clientes', 'arguments' => $args]],
        ]]]]])]);
        $this->postJson('/agente/comando', ['mensagem' => 'liste'])->assertOk()->assertJsonPath('tipo', 'resultado_leitura');
        $this->assertSame(1, AuditLog::count());
    }

    public static function finaisInvalidos(): array { return [['length', 'AI_RESPONSE_TRUNCATED'], ['content_filter', 'AI_CONTENT_FILTERED'], ['outro', 'AI_PROVIDER_RESPONSE_INVALID']]; }

    #[DataProvider('finaisInvalidos')]
    public function test_resposta_incompleta_nao_executa_escrita(string $fim, string $codigo): void
    {
        Http::fake(['*' => Http::response(['choices' => [['finish_reason' => $fim, 'message' => ['tool_calls' => [
            ['function' => ['name' => 'criar_cliente', 'arguments' => '{"nome":"X"}']],
        ]]]]])]);
        $this->postJson('/agente/comando', ['mensagem' => 'cadastre'])->assertStatus(502)->assertJsonPath('codigo', $codigo);
        $this->assertEmpty(session('confirmacoes', []));
        $this->assertSame(0, AuditLog::count());
    }

    public function test_complementacao_envia_contexto_anterior_sem_escrever(): void
    {
        Http::fakeSequence()->push(['choices' => [['message' => ['content' => 'Qual o nome?']]]])
            ->push(['choices' => [['message' => ['tool_calls' => [
                ['function' => ['name' => 'criar_cliente', 'arguments' => '{"nome":"Mercado Central"}']],
            ]]]]]);
        $this->postJson('/agente/comando', ['mensagem' => 'cadastre um cliente'])->assertOk();
        $this->postJson('/agente/comando', ['mensagem' => 'Mercado Central'])->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente');
        $requests = Http::recorded();
        $messages = $requests[1][0]['messages'];
        $this->assertSame('cadastre um cliente', $messages[1]['content']);
        $this->assertSame('Qual o nome?', $messages[2]['content']);
        $this->assertSame('Mercado Central', $messages[3]['content']);
        $this->assertSame(0, \App\Models\Cliente::count());
        $this->assertStringNotContainsString('token', json_encode(session('agente_conversa')));
    }

    public function test_historico_isolado_por_usuario_e_tenant(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'Qual o nome?']]]])]);
        $this->postJson('/agente/comando', ['mensagem' => 'usuario A'])->assertOk();
        $user = $this->logarComo('admin', 2);
        $this->postJson('/agente/comando', ['mensagem' => 'usuario B'])->assertOk();
        $user->tenant_id = 3;
        $user->save();
        $this->postJson('/agente/comando', ['mensagem' => 'tenant C'])->assertOk();
        $requests = Http::recorded();
        $this->assertCount(2, $requests[1][0]['messages']);
        $this->assertCount(2, $requests[2][0]['messages']);
    }

    public function test_historico_limitado_e_logout_limpa_contexto(): void
    {
        $conversa = app(\App\Services\ConversaAgente::class);
        for ($i = 0; $i < 20; $i++) $conversa->guardar(session()->driver(), 'Pedido '.$i, ['tipo' => 'texto', 'mensagem' => 'Qual o nome?']);
        $this->assertCount(16, $conversa->ler(session()->driver()));
        for ($i = 0; $i < 8; $i++) $conversa->guardar(session()->driver(), str_repeat('a', 1000), ['tipo' => 'texto', 'mensagem' => str_repeat('b', 1000)]);
        $this->assertLessThanOrEqual(8000, array_sum(array_map(fn ($m) => mb_strlen($m['content']), $conversa->ler(session()->driver()))));
        $this->post('/logout');
        $this->assertNull(session('agente_conversa'));
    }

    public function test_historico_omite_credenciais_e_erros(): void
    {
        $conversa = app(\App\Services\ConversaAgente::class);
        $conversa->guardar(session()->driver(), 'senha secret-marker', ['tipo' => 'texto', 'mensagem' => 'Qual o nome?']);
        $this->assertStringNotContainsString('secret-marker', json_encode($conversa->ler(session()->driver())));
        $antes = $conversa->ler(session()->driver());
        $conversa->guardar(session()->driver(), 'erro', ['tipo' => 'erro']);
        $this->assertSame($antes, $conversa->ler(session()->driver()));
    }

    public function test_operacao_nao_suportada_orientada_no_prompt_sem_proposta(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Cadastro de usuários não está disponível.']]]])]);
        $this->postJson('/agente/comando', ['mensagem' => 'cadastre um usuario para mim'])->assertOk()->assertJsonPath('tipo', 'texto');
        Http::assertSent(fn ($r) => str_contains($r['messages'][0]['content'], 'usuário e funcionário são conceitos diferentes')
            && str_contains($r['messages'][0]['content'], 'operação não é suportada'));
        $this->assertEmpty(session('confirmacoes', []));
        $this->assertSame(0, AuditLog::count());
    }

    public function test_transporte_mantem_verificacao_tls_e_timeout(): void
    {
        Http::fake(function ($request, $options) {
            $this->assertNotSame(false, $options['verify'] ?? true);
            $this->assertSame(30, $options['timeout']);
            return Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'OK']]]]);
        });
        $this->postJson('/agente/comando', ['mensagem' => 'oi'])->assertOk();
        foreach (['curl.cainfo', 'openssl.cafile'] as $setting) {
            $file = ini_get($setting);
            if ($file !== '') {
                $this->assertFileIsReadable($file);
                $this->assertNotFalse(openssl_x509_read(file_get_contents($file)));
            }
        }
    }

    public function test_falha_total_de_auditoria_nao_vaza_exception(): void
    {
        $audit = Mockery::mock(\App\Services\AuditoriaService::class);
        $audit->shouldReceive('registrar')->andThrow(new \RuntimeException('BODY-CONFIDENCIAL secret-marker'));
        $this->app->instance(\App\Services\AuditoriaService::class, $audit);
        Http::fake(['*' => Http::response(['choices' => [['finish_reason' => 'tool_calls', 'message' => ['tool_calls' => [
            ['function' => ['name' => 'consultar_clientes', 'arguments' => '{}']],
        ]]]]])]);
        $r = $this->postJson('/agente/comando', ['mensagem' => 'liste'])->assertStatus(500)->assertJsonPath('codigo', 'AI_TOOL_ERROR');
        $this->assertStringNotContainsString('CONFIDENCIAL', $r->getContent());
        $this->assertSame(0, AuditLog::count());
    }
}
