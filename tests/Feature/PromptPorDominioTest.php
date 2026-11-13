<?php

namespace Tests\Feature;

use App\Services\AiAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Item G (P-C3): o system prompt segue o domínio do tenant (ContextoUsuario::dominio()). O de contabilidade é o
 * MESMO de antes, byte a byte (a bateria de 40 pedidos foi medida com ele); o da clínica não fala de contabilidade.
 */
class PromptPorDominioTest extends TestCase
{
    use RefreshDatabase;

    private const CLINICA = 3;

    /** sha256 das duas mensagens system da contabilidade, gravado com o código de antes do item G (13/11, 01:15). */
    private const HASH_PRINCIPAL_CONTABILIDADE = '26908d970879b2cae30ed78b89a0abb9f3a30b481ceff9d59321c2d6db86f8be';

    private const HASH_LEMBRETE_CONTABILIDADE = '3410670cfd08a3049138696a434d2f81ea4cf30481b6fcea6c23bc44dc6df264';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.azure_foundry' => ['endpoint' => 'https://foundry.test', 'api_key' => 'k', 'deployment' => 'd']]);
        Http::preventStrayRequests();
        Http::fake(['foundry.test/*' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Ok.'], 'finish_reason' => 'stop']]])]);
        Carbon::setTestNow('2026-11-13 10:00:00');
    }

    /** @return array{0: string, 1: ?string} a system principal e a do meio (null sem histórico) do último pedido enviado */
    private function sistemas(string $papel, int $tenant, bool $comHistorico): array
    {
        $usuario = $this->logarComo($papel, $tenant);
        $historico = $comHistorico ? ['agente_conversa' => ['identidade' => $usuario->id.':'.$tenant, 'mensagens' => [
            ['role' => 'user', 'content' => 'oi'], ['role' => 'assistant', 'content' => 'Olá.'],
        ]]] : [];

        $this->withSession($historico)->postJson('/agente/comando', ['mensagem' => 'obrigado'])->assertOk();

        $corpo = Http::recorded()->last()[0]->data();
        $sistemas = array_values(array_filter($corpo['messages'], fn ($m) => $m['role'] === 'system'));

        return [$sistemas[0]['content'], $sistemas[1]['content'] ?? null];
    }

    public function test_contabilidade_continua_com_o_mesmo_prompt_byte_a_byte(): void
    {
        [$principal, $semLembrete] = $this->sistemas('operador', 1, false);
        $this->assertNull($semLembrete);
        $this->assertSame(self::HASH_PRINCIPAL_CONTABILIDADE, hash('sha256', $principal), 'principal da contabilidade mudou');

        [, $lembrete] = $this->sistemas('operador', 1, true);
        $this->assertSame(self::HASH_LEMBRETE_CONTABILIDADE, hash('sha256', (string) $lembrete), 'lembrete da contabilidade mudou');
    }

    public function test_clinica_tem_o_seu_prompt_sem_contabilidade_e_sem_dado_do_usuario(): void
    {
        [$principal, $lembrete] = $this->sistemas('recepcao', self::CLINICA, true);
        $usuario = auth()->user();

        foreach ([$principal, $lembrete] as $texto) {
            foreach (['contabilidade', 'lançamento', 'fornecedor', 'funcionário', 'conciliar'] as $termo) {
                $this->assertStringNotContainsStringIgnoringCase($termo, $texto);
            }
            $this->assertStringNotContainsString($usuario->email, $texto);
        }
        // As regras de segurança do prompt de contabilidade também estão no da clínica.
        foreach (['clínica veterinária', 'tutor é a pessoa responsável e pet é o animal', 'nunca peça nem aceite senha no chat',
            'Preserve a operação explicitamente solicitada', 'devem ser recusadas educadamente', 'somente a confirmação humana autoriza a execução',
            'cada novo cadastro ou edição completo exige uma nova chamada da tool', 'Nunca invente dados', 'centavos inteiros', 'gramas inteiros',
            'nunca escreva texto clínico', 'nunca presuma "nao"'] as $trecho) {
            $this->assertStringContainsString($trecho, $principal);
        }
        $this->assertStringContainsString('Papel autenticado do usuário: "recepcao"', $principal);
        $this->assertStringContainsString('A data de hoje é 2026-11-13', $principal);
        $this->assertStringStartsWith('O catálogo não inclui perguntas de conhecimento geral nem orientação clínica', (string) $lembrete);
    }

    public function test_clinica_na_anthropic_leva_o_mesmo_texto_no_campo_system(): void
    {
        config(['services.ia_provedor' => 'anthropic', 'services.anthropic' => ['api_key' => 'x', 'model' => 'claude-haiku-5-5', 'versao' => '2026-10-21', 'max_tokens' => 8000, 'effort' => 'low']]);
        Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'message', 'content' => [['type' => 'text', 'text' => 'Ok.']], 'stop_reason' => 'end_turn'])]);
        $usuario = $this->logarComo('recepcao', self::CLINICA);

        $this->withSession(['agente_conversa' => ['identidade' => $usuario->id.':'.self::CLINICA, 'mensagens' => [
            ['role' => 'user', 'content' => 'oi'], ['role' => 'assistant', 'content' => 'Olá.'],
        ]]])->postJson('/agente/comando', ['mensagem' => 'obrigado'])->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r['system'], 'clínica veterinária')
            && str_contains($r['system'], "Regra para o pedido atual (a última mensagem do usuário; o \"histórico\" são as mensagens anteriores a ela):\nO catálogo não inclui perguntas de conhecimento geral nem orientação clínica")
            && stripos($r['system'], 'contabilidade') === false && ! str_contains($r['system'], 'conciliar_lancamentos_em_lote'));
    }

    /** @return array<string, array{string, int}> */
    public static function dominios(): array
    {
        return ['contabilidade' => ['operador', 1], 'clinica' => ['recepcao', self::CLINICA]];
    }

    /** Toda tool citada no prompt existe no catálogo do domínio (pega nome renomeado ou trocado de domínio). */
    #[\PHPUnit\Framework\Attributes\DataProvider('dominios')]
    public function test_toda_tool_citada_no_prompt_existe_no_catalogo_do_dominio(string $papel, int $tenant): void
    {
        [$principal, $lembrete] = $this->sistemas($papel, $tenant, true);
        $nomes = array_column(array_column(app(AiAgentService::class)->tools(), 'function'), 'name');

        preg_match_all('/\b(?:consultar|criar|atualizar|alterar|cobrar|receber|cancelar|registrar|marcar|excluir|restaurar|conciliar)_[a-z_]+\b/', $principal.' '.$lembrete, $citadas);
        $this->assertNotEmpty($citadas[0]);
        $this->assertSame([], array_values(array_diff(array_unique($citadas[0]), $nomes)));
    }
}
