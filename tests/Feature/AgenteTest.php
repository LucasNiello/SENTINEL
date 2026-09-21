<?php

namespace Tests\Feature;

use App\Models\Lancamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fluxo do agente (/agente): as 2 tools existentes, confirmação humana antes
 * de qualquer escrita (RNF02) e degradação graciosa se o Foundry falhar (RNF05).
 * A API do Foundry é sempre simulada com Http::fake — nenhum teste chama a rede.
 */
class AgenteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.azure_foundry' => ['endpoint' => 'https://foundry.test', 'api_key' => 'k', 'deployment' => 'd']]);
    }

    private function respostaComTool(string $tool, array $argumentos): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => $tool, 'arguments' => json_encode($argumentos)]],
        ]]]]];
    }

    private function lancamento(string $status): Lancamento
    {
        return Lancamento::create(['descricao' => "L {$status}", 'valor' => 1, 'data' => '2026-09-21', 'status' => $status, 'tenant_id' => 1]);
    }

    public function test_tela_do_agente_carrega(): void
    {
        $this->get('/agente')->assertOk();
    }

    public function test_leitura_filtra_por_status_e_executa_direto(): void
    {
        $this->lancamento('pendente');
        $this->lancamento('conciliado');
        Http::fake(['*' => Http::response($this->respostaComTool('consultar_lancamentos', ['status' => 'pendente']))]);

        $resposta = $this->postJson('/agente/comando', ['mensagem' => 'pendentes?'])->assertOk();

        $resposta->assertJsonPath('tipo', 'resultado_leitura')->assertJsonCount(1, 'resultado');
        Http::assertSentCount(1);
    }

    public function test_escrita_so_devolve_confirmacao_pendente_e_nao_grava(): void
    {
        Http::fake(['*' => Http::response($this->respostaComTool('criar_lancamento', ['descricao' => 'X', 'valor' => 5, 'data' => '2026-09-21', 'tenant_id' => 1]))]);

        $this->postJson('/agente/comando', ['mensagem' => 'crie um lançamento'])
            ->assertOk()
            ->assertJsonPath('tipo', 'confirmacao_pendente')
            ->assertJsonPath('tool', 'criar_lancamento');

        $this->assertSame(0, Lancamento::count());
    }

    /**
     * Passo 1 do fluxo de escrita: o agente propõe criar_lancamento e o servidor
     * devolve o token de confirmação (RNF02). O tempo é congelado aqui para que
     * o travel() dos testes seja exato, sem depender do relógio real.
     */
    private function propor(array $argumentos): string
    {
        $this->freezeTime();
        Http::fake(['*' => Http::response($this->respostaComTool('criar_lancamento', $argumentos))]);

        return (string) $this->postJson('/agente/comando', ['mensagem' => 'crie um lançamento'])
            ->assertOk()
            ->assertJsonPath('tipo', 'confirmacao_pendente')
            ->assertJsonStructure(['token'])
            ->json('token');
    }

    public function test_confirmar_grava_o_lancamento_como_pendente(): void
    {
        $token = $this->propor(['descricao' => 'Café', 'valor' => 12.5, 'data' => '2026-09-21', 'tenant_id' => 1]);
        $this->travel(2)->seconds();

        $this->postJson('/agente/confirmar', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('tipo', 'resultado_escrita');

        $this->assertDatabaseHas('lancamentos', ['descricao' => 'Café', 'status' => 'pendente', 'tenant_id' => 1]);
    }

    public function test_confirmar_sem_tenant_devolve_erro_tratado(): void
    {
        $token = $this->propor(['descricao' => 'Sem empresa', 'valor' => 1, 'data' => '2026-09-21']);
        $this->travel(2)->seconds();

        $this->postJson('/agente/confirmar', ['token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('tipo', 'erro');

        $this->assertSame(0, Lancamento::count());
    }

    public function test_confirmar_tool_fora_da_whitelist_e_recusada_sem_estourar_500(): void
    {
        $this->postJson('/agente/confirmar', ['tool' => 'apagar_tudo', 'argumentos' => []])
            ->assertStatus(422)
            ->assertJsonPath('tipo', 'erro');
    }

    public function test_confirmar_usa_tool_e_argumentos_da_sessao_e_ignora_o_corpo(): void
    {
        $token = $this->propor(['descricao' => 'Proposto pelo agente', 'valor' => 10, 'data' => '2026-09-21', 'tenant_id' => 1]);
        $this->travel(2)->seconds();

        $this->postJson('/agente/confirmar', [
            'token' => $token,
            'tool' => 'criar_lancamento',
            'argumentos' => ['descricao' => 'Forjado pelo cliente', 'valor' => 99999, 'data' => '2026-09-21', 'tenant_id' => 1],
        ])->assertOk();

        $this->assertDatabaseHas('lancamentos', ['descricao' => 'Proposto pelo agente', 'valor' => 10]);
        $this->assertDatabaseMissing('lancamentos', ['descricao' => 'Forjado pelo cliente']);
    }

    public function test_rnf02_confirmar_sem_token_e_recusado_e_nao_grava(): void
    {
        $corpoForjado = [
            'tool' => 'criar_lancamento',
            'argumentos' => ['descricao' => 'Sem passar pelo agente', 'valor' => 1, 'data' => '2026-09-21', 'tenant_id' => 1],
        ];

        $this->postJson('/agente/confirmar', $corpoForjado)
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Confirmação inválida ou já utilizada.');

        $this->postJson('/agente/confirmar', $corpoForjado + ['token' => 'token-inventado'])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Confirmação inválida ou já utilizada.');

        $this->assertSame(0, Lancamento::count());
    }

    public function test_rnf02_confirmar_antes_de_2s_e_recusado_e_nao_grava(): void
    {
        $token = $this->propor(['descricao' => 'Cedo demais', 'valor' => 1, 'data' => '2026-09-21', 'tenant_id' => 1]);
        $this->travel(1)->seconds();

        $this->postJson('/agente/confirmar', ['token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Confirmação fora do prazo. Refaça o comando.');

        $this->assertSame(0, Lancamento::count());
    }

    public function test_rnf02_token_so_vale_uma_vez(): void
    {
        $token = $this->propor(['descricao' => 'Uma vez só', 'valor' => 1, 'data' => '2026-09-21', 'tenant_id' => 1]);
        $this->travel(2)->seconds();

        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();

        $this->postJson('/agente/confirmar', ['token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Confirmação inválida ou já utilizada.');

        $this->assertSame(1, Lancamento::count());
    }

    public function test_rnf02_token_expirado_apos_12s_e_recusado_e_nao_grava(): void
    {
        $token = $this->propor(['descricao' => 'Expirado', 'valor' => 1, 'data' => '2026-09-21', 'tenant_id' => 1]);
        $this->travel(13)->seconds();

        $this->postJson('/agente/confirmar', ['token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Confirmação fora do prazo. Refaça o comando.');

        $this->assertSame(0, Lancamento::count());
    }

    public function test_falha_do_foundry_degrada_para_erro_amigavel(): void
    {
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        $this->postJson('/agente/comando', ['mensagem' => 'oi'])->assertOk()->assertJsonPath('tipo', 'erro');
    }

    public function test_sem_credencial_do_foundry_devolve_erro_sem_chamar_a_rede(): void
    {
        config(['services.azure_foundry.api_key' => '']);
        Http::fake();

        $this->postJson('/agente/comando', ['mensagem' => 'oi'])->assertOk()->assertJsonPath('tipo', 'erro');

        Http::assertNothingSent();
    }

    public function test_requisicao_ao_foundry_envia_api_key_no_header_e_expoe_so_as_2_tools(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'oi']]]])]);

        $this->postJson('/agente/comando', ['mensagem' => 'oi'])->assertOk()->assertJsonPath('tipo', 'texto');

        Http::assertSent(fn ($req) => $req->hasHeader('api-key', 'k')
            && $req->url() === 'https://foundry.test/openai/v1/chat/completions'
            && collect($req['tools'])->pluck('function.name')->all() === ['consultar_lancamentos', 'criar_lancamento']);
    }
}
