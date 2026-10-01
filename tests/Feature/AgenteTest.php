<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Lancamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->logarComo('admin', 1);
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
        Http::fake(['*' => Http::response($this->respostaComTool('criar_lancamento', ['descricao' => 'X', 'valor' => 5, 'data' => '2026-09-21']))]);

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
        $token = $this->propor(['descricao' => 'Café', 'valor' => 12.5, 'data' => '2026-09-21']);
        $this->travel(2)->seconds();

        $this->postJson('/agente/confirmar', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('tipo', 'resultado_escrita');

        $this->assertDatabaseHas('lancamentos', ['descricao' => 'Café', 'status' => 'pendente', 'tenant_id' => 1]);
    }

    public function test_confirmar_com_dados_incompletos_devolve_erro_tratado(): void
    {
        // Sem descrição: o modelo propôs argumentos incompletos. O tenant já não é problema — vem do servidor.
        $token = $this->propor(['valor' => 1, 'data' => '2026-09-21']);
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
        $token = $this->propor(['descricao' => 'Proposto pelo agente', 'valor' => 10, 'data' => '2026-09-21']);
        $this->travel(2)->seconds();

        $this->postJson('/agente/confirmar', [
            'token' => $token,
            'tool' => 'criar_lancamento',
            'argumentos' => ['descricao' => 'Forjado pelo cliente', 'valor' => 99999, 'data' => '2026-09-21'],
        ])->assertOk();

        $this->assertDatabaseHas('lancamentos', ['descricao' => 'Proposto pelo agente', 'valor' => 10]);
        $this->assertDatabaseMissing('lancamentos', ['descricao' => 'Forjado pelo cliente']);
    }

    public function test_rnf02_confirmar_sem_token_e_recusado_e_nao_grava(): void
    {
        $corpoForjado = [
            'tool' => 'criar_lancamento',
            'argumentos' => ['descricao' => 'Sem passar pelo agente', 'valor' => 1, 'data' => '2026-09-21'],
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
        $token = $this->propor(['descricao' => 'Cedo demais', 'valor' => 1, 'data' => '2026-09-21']);
        $this->travel(1)->seconds();

        $this->postJson('/agente/confirmar', ['token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Confirmação fora do prazo. Refaça o comando.');

        $this->assertSame(0, Lancamento::count());
    }

    public function test_rnf02_token_so_vale_uma_vez(): void
    {
        $token = $this->propor(['descricao' => 'Uma vez só', 'valor' => 1, 'data' => '2026-09-21']);
        $this->travel(2)->seconds();

        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();

        $this->postJson('/agente/confirmar', ['token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Confirmação inválida ou já utilizada.');

        $this->assertSame(1, Lancamento::count());
    }

    public function test_rnf02_token_expirado_apos_12s_e_recusado_e_nao_grava(): void
    {
        $token = $this->propor(['descricao' => 'Expirado', 'valor' => 1, 'data' => '2026-09-21']);
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

    public function test_data_de_hoje_no_prompt_usa_o_fuso_de_brasilia(): void
    {
        // 29/09 02:30 em UTC ainda é 28/09 23:30 em Brasília: o modelo tem que receber o dia 28.
        $this->travelTo(Carbon::parse('2026-09-29 02:30:00', 'UTC'));
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'oi']]]])]);

        $this->postJson('/agente/comando', ['mensagem' => 'que dia é hoje?'])->assertOk();

        Http::assertSent(fn ($req) => str_contains($req['messages'][0]['content'], 'A data de hoje é 2026-09-28'));
    }

    /** @param  array<int, array{string, array}>  $chamadas  [tool, argumentos] na ordem em que o modelo pediu */
    private function respostaComVariasTools(array $chamadas): array
    {
        return ['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => array_map(
            fn (array $c, int $i) => ['id' => "call_{$i}", 'type' => 'function', 'function' => ['name' => $c[0], 'arguments' => json_encode($c[1])]],
            $chamadas,
            array_keys($chamadas),
        )]]]];
    }

    public function test_varios_pedidos_numa_mensagem_so_o_primeiro_e_feito_e_a_resposta_avisa(): void
    {
        $this->lancamento('pendente');
        Http::fake(['*' => Http::response($this->respostaComVariasTools([
            ['consultar_lancamentos', []],
            ['consultar_clientes', []],
            ['consultar_fornecedores', []],
        ]))]);

        $this->postJson('/agente/comando', ['mensagem' => 'lançamentos, clientes e fornecedores'])
            ->assertOk()
            ->assertJsonPath('tipo', 'resultado_leitura')
            ->assertJsonPath('tool', 'consultar_lancamentos')
            ->assertJsonPath('aviso', 'Havia 3 pedidos nesta mensagem e só o primeiro foi atendido. Peça os outros em seguida, um de cada vez.');

        $this->assertSame(['consultar_lancamentos'], AuditLog::query()->pluck('tool')->all(), 'só o primeiro pedido foi executado');
    }

    public function test_escrita_proposta_junto_com_outro_pedido_tambem_leva_o_aviso(): void
    {
        Http::fake(['*' => Http::response($this->respostaComVariasTools([
            ['criar_lancamento', ['descricao' => 'X', 'valor' => 5, 'data' => '2026-09-21']],
            ['consultar_clientes', []],
        ]))]);

        $this->postJson('/agente/comando', ['mensagem' => 'crie e liste'])
            ->assertOk()
            ->assertJsonPath('tipo', 'confirmacao_pendente')
            ->assertJsonPath('tool', 'criar_lancamento')
            ->assertJsonStructure(['token'])
            ->assertJsonPath('aviso', 'Havia 2 pedidos nesta mensagem e só o primeiro foi atendido. Peça os outros em seguida, um de cada vez.');

        $this->assertSame(0, Lancamento::count());
        $this->assertSame(0, AuditLog::count(), 'o segundo pedido não foi executado');
    }

    public function test_um_pedido_so_nao_leva_aviso(): void
    {
        Http::fake(['*' => Http::response($this->respostaComTool('consultar_lancamentos', []))]);

        $this->postJson('/agente/comando', ['mensagem' => 'lançamentos'])
            ->assertOk()
            ->assertJsonPath('tipo', 'resultado_leitura')
            ->assertJsonMissingPath('aviso');
    }

    // ---- Lote 2: entrada validada (N2/B4), consumo atômico (M1) e limite de taxa (RNF10) ----

    public function test_m1_confirmacao_simultanea_com_o_mesmo_token_grava_uma_vez_so(): void
    {
        $token = $this->propor(['descricao' => 'Corrida', 'valor' => 1, 'data' => '2026-09-21']);
        $this->travel(2)->seconds();
        $entrada = session("confirmacoes.{$token}");

        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();

        // Intercalação: a segunda requisição leu a sessão antes de a primeira consumir o token.
        $this->withSession(['confirmacoes' => [$token => $entrada]])
            ->postJson('/agente/confirmar', ['token' => $token])
            ->assertStatus(409)
            ->assertJsonPath('tipo', 'erro')
            ->assertJsonPath('mensagem', 'Esta confirmação já foi usada.');

        $this->assertSame(1, Lancamento::count());
    }

    public function test_token_em_array_ou_fora_do_formato_uuid_da_422_e_nao_500(): void
    {
        foreach ([['token' => ['x']], ['token' => 'nao-e-uuid'], ['token' => 'a.b'], ['token' => 123]] as $corpo) {
            $this->postJson('/agente/confirmar', $corpo)
                ->assertStatus(422)
                ->assertJsonPath('tipo', 'erro')
                ->assertJsonPath('mensagem', 'Confirmação inválida ou já utilizada.');
        }

        $this->assertSame(0, Lancamento::count());
    }

    public function test_mensagem_em_array_da_422_e_nao_chama_o_foundry(): void
    {
        Http::fake();

        $this->postJson('/agente/comando', ['mensagem' => ['oi']])
            ->assertStatus(422)
            ->assertJsonPath('tipo', 'erro')
            ->assertJsonPath('mensagem', 'O pedido deve ser um texto.');

        Http::assertNothingSent();
    }

    public function test_mensagem_acima_de_1000_caracteres_da_422_e_nao_chama_o_foundry(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'oi']]]])]);

        $this->postJson('/agente/comando', ['mensagem' => str_repeat('a', 1001)])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'O pedido aceita no máximo 1000 caracteres.');
        Http::assertNothingSent();

        $this->postJson('/agente/comando', ['mensagem' => str_repeat('a', 1000)])->assertOk();
    }

    public function test_rnf10_a_11a_chamada_no_mesmo_minuto_recebe_429(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'oi']]]])]);

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/agente/comando', ['mensagem' => 'oi'])->assertOk();
        }

        $this->postJson('/agente/comando', ['mensagem' => 'oi'])
            ->assertStatus(429)
            ->assertJsonPath('tipo', 'erro')
            ->assertJsonPath('mensagem', 'Muitos pedidos em pouco tempo. Aguarde um minuto.');

        Http::assertSentCount(10);
    }

    // ---- Tarefa B: classe de risco no card (skill sentinel-visual, seção 5) ----

    public static function toolsDeEscrita(): array
    {
        return [
            'criar_lancamento' => ['criar_lancamento'],
            'atualizar_status_lancamento' => ['atualizar_status_lancamento'],
            'criar_cliente' => ['criar_cliente'],
            'criar_fornecedor' => ['criar_fornecedor'],
            'criar_funcionario' => ['criar_funcionario'],
            'criar_nota_fiscal' => ['criar_nota_fiscal'],
            'criar_categoria_lancamento' => ['criar_categoria_lancamento'],
        ];
    }

    #[DataProvider('toolsDeEscrita')]
    public function test_confirmacao_pendente_traz_o_risco_escrita_da_tool(string $tool): void
    {
        Http::fake(['*' => Http::response($this->respostaComTool($tool, []))]);

        $this->postJson('/agente/comando', ['mensagem' => 'faça'])
            ->assertOk()
            ->assertJsonPath('tipo', 'confirmacao_pendente')
            ->assertJsonPath('tool', $tool)
            ->assertJsonPath('risco', 'escrita');
    }

    public function test_requisicao_ao_foundry_envia_api_key_no_header_e_expoe_so_as_tools_do_catalogo(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'oi']]]])]);

        $this->postJson('/agente/comando', ['mensagem' => 'oi'])->assertOk()->assertJsonPath('tipo', 'texto');

        Http::assertSent(fn ($req) => $req->hasHeader('api-key', 'k')
            && $req->url() === 'https://foundry.test/openai/v1/chat/completions'
            && collect($req['tools'])->pluck('function.name')->all() === ['consultar_lancamentos', 'criar_lancamento', 'atualizar_status_lancamento', 'consultar_clientes', 'criar_cliente', 'consultar_fornecedores', 'criar_fornecedor', 'consultar_funcionarios', 'criar_funcionario', 'consultar_notas_fiscais', 'criar_nota_fiscal', 'consultar_categorias_lancamento', 'criar_categoria_lancamento']);
    }
}
