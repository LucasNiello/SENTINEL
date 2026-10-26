<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * B1 (RNF04) — o tempo de cada chamada à IA vai para tempo_ia_ms na auditoria do que
 * ela causou: na hora (leitura, negado) ou no desfecho da proposta (confirmado,
 * cancelado, expirado, revisão com erro). Resposta só de texto: só no log, sem conteúdo.
 * Sem IA (formulário, modo manual): nulo. A IA simulada demora cerca de 40 ms.
 */
class TempoIaTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    private const ATRASO_MS = 40;

    /** O usleep do Windows é impreciso (40 ms podem virar 18): basta provar que o tempo foi medido, em ms. */
    private const MINIMO_MS = 10;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.azure_foundry' => ['endpoint' => 'https://foundry.test', 'api_key' => 'k', 'deployment' => 'd']]);
        Http::fake(function () {
            usleep(self::ATRASO_MS * 1000);

            return Http::response($this->respostaFoundry);
        });
        $this->logarComo('operador', 1);
    }

    private function assertTempoDaIa(?int $tempo): void
    {
        $this->assertNotNull($tempo, 'tempo_ia_ms gravado');
        $this->assertGreaterThanOrEqual(self::MINIMO_MS, $tempo);
        $this->assertLessThan(30000, $tempo, 'em milissegundos, não em micro/nanossegundos');
    }

    public function test_leitura_grava_o_tempo_na_mesma_requisicao(): void
    {
        $this->comando('consultar_clientes')->assertOk()->assertJsonMissingPath('tempo_ia_ms');

        $this->assertTempoDaIa(AuditLog::query()->sole()->tempo_ia_ms);
    }

    public function test_escrita_confirmada_leva_o_tempo_da_proposta_e_o_front_nao_recebe(): void
    {
        $this->freezeTime();
        $resposta = $this->comando('criar_cliente', ['nome' => 'Padaria'])->assertOk()->assertJsonMissingPath('tempo_ia_ms');
        $token = $this->postJson('/agente/revisar', ['proposta' => $resposta->json('proposta'), 'valores' => []])->assertOk()->json('token');
        $this->confirmar($token)->assertOk();

        $log = AuditLog::query()->where('resultado', 'sucesso')->sole();
        $this->assertSame('criar', $log->acao);
        $this->assertTempoDaIa($log->tempo_ia_ms);
    }

    public function test_cancelado_expirado_e_revisao_com_erro_levam_o_tempo(): void
    {
        $this->freezeTime();
        $cancelar = $this->comando('criar_cliente', ['nome' => 'A'])->json('proposta');
        $this->postJson('/agente/cancelar', ['proposta' => $cancelar])->assertOk()->assertJsonPath('tipo', 'cancelado');

        $comErro = $this->comando('criar_cliente', ['nome' => 'B'])->json('proposta');
        $this->postJson('/agente/revisar', ['proposta' => $comErro, 'valores' => ['nome' => '']])->assertStatus(422);

        $this->travel(301)->seconds();
        $this->get('/agente')->assertOk(); // a varredura audita o rascunho vencido

        foreach (['cancelado', 'revisao_com_erro', 'expirado'] as $resultado) {
            $this->assertTempoDaIa(AuditLog::query()->where('resultado', $resultado)->sole()->tempo_ia_ms);
        }
    }

    public function test_negado_pelo_papel_grava_o_tempo(): void
    {
        $this->logarComo('leitura', 1);

        $this->comando('criar_cliente', ['nome' => 'X'])->assertStatus(403);

        $this->assertTempoDaIa(AuditLog::query()->where('resultado', 'negado')->sole()->tempo_ia_ms);
    }

    public function test_resposta_em_texto_vai_so_para_o_log_sem_conteudo(): void
    {
        Log::spy();
        $this->respostaFoundry = ['choices' => [['message' => ['role' => 'assistant', 'content' => 'O CPF 123.456.789-01 é do José.'], 'finish_reason' => 'stop']]];

        $this->postJson('/agente/comando', ['mensagem' => 'oi'])->assertOk()->assertJsonPath('tipo', 'texto');

        $this->assertSame(0, AuditLog::query()->count());
        Log::shouldHaveReceived('info')->with('AiAgentService: resposta em texto', Mockery::on(
            fn (array $contexto) => array_keys($contexto) === ['tempo_ia_ms'] && $contexto['tempo_ia_ms'] >= self::MINIMO_MS,
        ));
    }

    public function test_falha_do_provedor_registra_o_tempo_no_log(): void
    {
        Log::spy();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn () => Http::response('', 500));

        $this->postJson('/agente/comando', ['mensagem' => 'oi'])->assertStatus(503);

        Log::shouldHaveReceived('warning')->with('AiAgentService: falha controlada', Mockery::on(
            fn (array $contexto) => is_int($contexto['tempo_ia_ms'] ?? null),
        ));
    }

    public function test_resposta_truncada_ou_fora_do_formato_registra_o_tempo_no_log(): void
    {
        Log::spy();

        $this->respostaFoundry = ['choices' => [['message' => ['role' => 'assistant', 'content' => 'meia'], 'finish_reason' => 'length']]];
        $this->postJson('/agente/comando', ['mensagem' => 'oi'])->assertStatus(502);
        $this->respostaFoundry = ['choices' => []];
        $this->postJson('/agente/comando', ['mensagem' => 'oi'])->assertStatus(502);

        Log::shouldHaveReceived('warning')->with('AiAgentService: falha controlada', Mockery::on(
            fn (array $contexto) => ($contexto['categoria'] ?? null) === 'provider_response_error' && ($contexto['tempo_ia_ms'] ?? 0) >= self::MINIMO_MS,
        ))->twice();
    }

    public function test_sem_ia_o_tempo_fica_nulo(): void
    {
        $this->postJson('/agente/consultar', ['tool' => 'consultar_clientes'])->assertOk();

        $this->freezeTime();
        $proposta = $this->postJson('/agente/formulario', ['tool' => 'criar_cliente'])->json('proposta');
        $token = $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => ['nome' => 'Sem IA']])->assertOk()->json('token');
        $this->confirmar($token)->assertOk();

        $this->assertSame([null, null], AuditLog::query()->orderBy('id')->pluck('tempo_ia_ms')->all());
        Http::assertNothingSent();
    }
}
