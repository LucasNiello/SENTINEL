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

    /** sha256 das duas mensagens system da contabilidade, gravado antes do item G (13/11, 01:15); regravado em 13/11 à tarde (pendência 2: dado faltando = chamar a tool, o card marca o campo; só a frase de dado faltando mudou, no principal e no lembrete). */
    private const HASH_PRINCIPAL_CONTABILIDADE = 'cf8a162f36fdaba5fd351535454c486fbfbb88d2a71ecb82f7c5783b700f30e1'; // regravado na tarde 2 (B1): 3 exemplos de pedido incompleto no principal

    private const HASH_LEMBRETE_CONTABILIDADE = '6aea2f6124c382c6f3581fa16bc7b1d16fb2f1c7d926ca80c573c337ad62d9ec';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['api.anthropic.com/*' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Ok.'], 'finish_reason' => 'stop']]])]);
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
        // O adaptador junta as mensagens system no campo system; a do meio entra com este prefixo.
        $partes = explode("

Regra para o pedido atual (a última mensagem do usuário; o \"histórico\" são as mensagens anteriores a ela):
", $corpo['system'], 2);

        return [$partes[0], $partes[1] ?? null];
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

    /** Pendência 2 (RF04): dado obrigatório faltando não vira pergunta em texto; a tool é chamada e o card marca o campo. */
    #[\PHPUnit\Framework\Attributes\DataProvider('dominios')]
    public function test_dado_faltando_manda_chamar_a_tool_e_nao_perguntar_em_texto(string $papel, int $tenant): void
    {
        [$principal, $lembrete] = $this->sistemas($papel, $tenant, true);

        $this->assertStringContainsString('chame a tool com o que tiver, sem inventar o que falta; o card marca o campo que falta', $principal);
        foreach ([$principal, $lembrete] as $texto) {
            $this->assertStringNotContainsString('pergunte', $texto);
        }
        $this->assertStringContainsString('chame a tool com o que tiver', $lembrete);
        $this->assertStringContainsString('Nunca invente dados', $principal);
    }

    /** B1 (tarde 2): exemplos curtos do fluxo RF04 no prompt principal, nos dois domínios. */
    #[\PHPUnit\Framework\Attributes\DataProvider('dominios')]
    public function test_prompt_traz_exemplos_de_pedido_incompleto_que_chama_a_tool(string $papel, int $tenant): void
    {
        [$principal] = $this->sistemas($papel, $tenant, false);

        $this->assertStringContainsString('Exemplos: "', $principal);
        $this->assertStringContainsString('sem argumentos', $principal);
        $this->assertStringContainsString('Nunca responda esses pedidos só com uma pergunta em texto', $principal);
        $this->assertStringContainsString($papel === 'operador' ? 'chame criar_nota_fiscal só com o valor 1200' : 'chame criar_pet só com o nome Rex', $principal);
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
