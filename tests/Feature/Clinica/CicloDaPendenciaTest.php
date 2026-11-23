<?php

namespace Tests\Feature\Clinica;

use App\Models\AuditLog;
use App\Models\CatalogoItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Ciclo de vida da proposta (23/11): uma pendência nunca sobrevive a um novo pedido nem aparece para um pedido diferente
 * do que a gerou. O histórico enviado à IA sempre alterna usuário/assistente: a proposta fecha o turno com uma fala do
 * servidor ("[Proposta: … — substituída por novo pedido]"), então o pedido antigo não vira "o pedido atual" da IA.
 * Bug de 23/11: depois da 1ª proposta, todo pedido seguinte voltava a abrir o card do 1º.
 */
class CicloDaPendenciaTest extends TestCase
{
    use CenarioClinica, RefreshDatabase, SimulaAgente;

    private User $dono;

    private const BANHO = ['tipo' => 'servico', 'setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho premium',
        'preco_centavos' => 8000, 'cobranca_por' => 'unidade', 'controla_estoque' => 'nao', 'exige_receita' => 'nao'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAgente();
        $this->dono = $this->logarComo('dono', self::T);
    }

    /** Um pedido com texto próprio: o "modelo" falso responde chamando $tool. */
    private function pedido(string $mensagem, string $tool, array $argumentos = [])
    {
        $this->respostaIa = $this->respostaComTool($tool, $argumentos);

        return $this->postJson('/agente/comando', ['mensagem' => $mensagem]);
    }

    /** As mensagens (user/assistant) que foram para a Anthropic no último pedido. */
    private function mensagensEnviadas(): array
    {
        return json_decode((string) Http::recorded()->last()[0]->body(), true)['messages'];
    }

    private function sistemaEnviado(): string
    {
        return (string) json_decode((string) Http::recorded()->last()[0]->body(), true)['system'];
    }

    private function alternam(array $mensagens): bool
    {
        foreach ($mensagens as $i => $m) {
            if ($i > 0 && $m['role'] === $mensagens[$i - 1]['role']) {
                return false;
            }
        }

        return $mensagens !== [] && $mensagens[0]['role'] === 'user' && end($mensagens)['role'] === 'user';
    }

    public function test_depois_de_uma_proposta_o_historico_alterna_e_o_pedido_novo_e_o_atual(): void
    {
        $this->pedido('Cadastre um item no catalogo: Banho Premium, petshop, R$80', 'criar_item_catalogo', self::BANHO)->assertOk();
        $this->respostaIa = ['choices' => [['message' => ['role' => 'assistant', 'content' => 'Esta é a lixeira.'], 'finish_reason' => 'stop']]];
        $this->postJson('/agente/comando', ['mensagem' => 'deixe-me ver a lixeira'])->assertOk();

        $mensagens = $this->mensagensEnviadas();
        $this->assertTrue($this->alternam($mensagens), 'user, assistant, user: nunca dois turnos do mesmo papel');
        $this->assertSame('deixe-me ver a lixeira', end($mensagens)['content']);
        $this->assertSame('assistant', $mensagens[1]['role']);
        $this->assertStringContainsString('[Proposta:', $mensagens[1]['content']);
        $this->assertStringContainsString('substituída por novo pedido', $mensagens[1]['content']);
        $this->assertStringNotContainsString('Proposta pendente de confirmação humana', json_encode($mensagens));
    }

    public function test_os_pedidos_que_falharam_nao_reabrem_o_cadastro_de_banho_premium(): void
    {
        CatalogoItem::query()->delete();
        $vacina = $this->item(['nome_comercial' => 'Vacina V10', 'categoria' => 'vacina', 'preco_centavos' => 9000]);
        $primeira = (string) $this->pedido('Cadastre um item no catalogo: Banho Premium, petshop, R$80', 'criar_item_catalogo', self::BANHO)->assertOk()->json('proposta');

        // 2º pedido: alterar preço. O card é o de ALTERAR, do item certo, e não o de cadastrar o banho.
        $segunda = $this->pedido('Mude o preço da vacina V10 para R$ 120', 'atualizar_item_catalogo', ['id' => $vacina->id, 'preco_centavos' => 12000])
            ->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente')->assertJsonPath('tool', 'atualizar_item_catalogo');
        $this->assertStringContainsString('Alterar item', $segunda->json('formulario.titulo'));
        $this->assertStringNotContainsString('Banho premium', $segunda->getContent());
        $this->assertNotSame($primeira, $segunda->json('proposta'));

        // 3º pedido: leitura. Responde sem card de escrita.
        $leitura = $this->pedido('deixe-me ver a lixeira', 'consultar_lixeira')->assertOk();
        $this->assertSame('resultado_leitura', $leitura->json('tipo'));
        $this->assertArrayNotHasKey('formulario', $leitura->json());
        $this->assertArrayNotHasKey('proposta', $leitura->json());
    }

    public function test_a_pendencia_anterior_e_descartada_e_auditada_como_cancelada(): void
    {
        $primeira = (string) $this->pedido('Cadastre um item no catalogo: Banho Premium, petshop, R$80', 'criar_item_catalogo', self::BANHO)->assertOk()->json('proposta');
        $this->pedido('deixe-me ver a lixeira', 'consultar_lixeira')->assertOk();

        // O card antigo, se alguém clicar: indisponível. Nada é gravado.
        $this->postJson('/agente/revisar', ['proposta' => $primeira, 'valores' => []])->assertStatus(422);
        $this->assertSame(0, CatalogoItem::count());

        $log = AuditLog::query()->where('tool', 'criar_item_catalogo')->where('resultado', 'cancelado')->sole();
        $this->assertStringContainsString('Substituída por novo pedido', (string) $log->mensagem);
    }

    public function test_o_descarte_e_auditado_uma_vez_so_mesmo_com_varios_pedidos_seguidos(): void
    {
        $this->pedido('Cadastre um item no catalogo: Banho Premium, petshop, R$80', 'criar_item_catalogo', self::BANHO)->assertOk();
        $this->pedido('deixe-me ver a lixeira', 'consultar_lixeira')->assertOk();
        $this->pedido('mostre o catálogo', 'consultar_catalogo')->assertOk();

        $this->assertSame(1, AuditLog::query()->where('tool', 'criar_item_catalogo')->where('resultado', 'cancelado')->count());
    }

    public function test_correcao_pelo_chat_gera_nova_proposta_com_o_valor_corrigido_e_a_ia_ve_a_anterior(): void
    {
        $this->pedido('Cadastre um item no catalogo: Banho Premium, petshop, R$80', 'criar_item_catalogo', self::BANHO)->assertOk();

        $nova = $this->pedido('na verdade, coloca R$ 90', 'criar_item_catalogo', [...self::BANHO, 'preco_centavos' => 9000])
            ->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente');

        // A IA recebeu o contexto da proposta anterior (nome e preço), para poder corrigi-la.
        $anterior = $this->mensagensEnviadas()[1]['content'];
        $this->assertStringContainsString('nome_comercial: Banho premium', $anterior);
        $this->assertStringContainsString('preco_centavos: 8000', $anterior);
        $this->assertTrue($this->alternam($this->mensagensEnviadas()));

        // A nova proposta tem o valor corrigido e é a única pendente.
        $campos = collect($nova->json('formulario.campos'))->keyBy('nome');
        $this->assertSame('90,00', $campos['preco_centavos']['valor']);
        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $nova->json('proposta'), 'valores' => []])->assertOk()->json('token');
        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();
        $this->assertSame([9000], CatalogoItem::query()->pluck('preco_centavos')->all());
    }

    public function test_proposta_cancelada_nao_ressuscita_no_pedido_seguinte(): void
    {
        $proposta = (string) $this->pedido('Cadastre um item no catalogo: Banho Premium, petshop, R$80', 'criar_item_catalogo', self::BANHO)->assertOk()->json('proposta');
        $this->postJson('/agente/cancelar', ['proposta' => $proposta])->assertOk()->assertJsonPath('tipo', 'cancelado');

        $leitura = $this->pedido('deixe-me ver a lixeira', 'consultar_lixeira')->assertOk();

        $this->assertSame('resultado_leitura', $leitura->json('tipo'));
        $mensagens = $this->mensagensEnviadas();
        $this->assertTrue($this->alternam($mensagens));
        $this->assertStringContainsString('cancelada pelo usuário. Nada foi gravado', $mensagens[1]['content']);
        $this->assertSame(0, CatalogoItem::count());
        // Cancelada pelo usuário: nada foi "substituído" depois (não há pendência para descartar).
        $this->assertSame(1, AuditLog::query()->where('tool', 'criar_item_catalogo')->where('resultado', 'cancelado')->count());
    }

    public function test_confirmar_continua_alimentando_o_historico_para_a_continuidade(): void
    {
        $proposta = (string) $this->pedido('Cadastre um item no catalogo: Banho Premium, petshop, R$80', 'criar_item_catalogo', self::BANHO)->assertOk()->json('proposta');
        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->assertOk()->json('token');
        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();

        $this->respostaIa = ['choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']]];
        $this->postJson('/agente/comando', ['mensagem' => 'obrigado'])->assertOk();

        $mensagens = $this->mensagensEnviadas();
        $this->assertTrue($this->alternam($mensagens));
        $texto = json_encode($mensagens, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('confirmada e executada', $texto);
        $this->assertStringContainsString('Confirmei a proposta no card.', $texto);
        $this->assertSame(1, CatalogoItem::count());
    }

    public function test_o_resumo_da_proposta_no_historico_nao_leva_dado_sensivel(): void
    {
        $this->pedido('Cadastre a tutora Helena Prado', 'criar_tutor', ['nome' => 'Helena Prado', 'telefone' => '(11) 98888-7777', 'documento' => '529.982.247-25', 'email' => 'helena@exemplo.com'])->assertOk();
        $this->pedido('deixe-me ver a lixeira', 'consultar_lixeira')->assertOk();

        $enviado = json_encode($this->mensagensEnviadas(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('nome: Helena Prado', $enviado);
        $this->assertStringNotContainsString('98888', $enviado);
        $this->assertStringNotContainsString('529.982', $enviado);
        $this->assertStringNotContainsString('52998224725', $enviado);
    }

    public function test_rede_de_seguranca_descarta_o_turno_repetido_e_registra_no_log(): void
    {
        Log::spy();
        // Sessão já quebrada (como a que gerou o bug): dois pedidos do usuário seguidos, sem fala do assistente.
        $this->withSession(['agente_conversa' => ['identidade' => $this->dono->getKey().':'.self::T, 'mensagens' => [
            ['role' => 'user', 'content' => 'Cadastre um item no catalogo: Banho Premium, petshop, R$80'],
            ['role' => 'user', 'content' => 'Mude o preço da vacina V10 para R$ 120'],
        ]]]);

        $this->respostaIa = ['choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']]];
        $this->postJson('/agente/comando', ['mensagem' => 'deixe-me ver a lixeira'])->assertOk();

        $mensagens = $this->mensagensEnviadas();
        $this->assertTrue($this->alternam($mensagens));
        $this->assertSame(['deixe-me ver a lixeira'], array_column($mensagens, 'content'), 'só o pedido atual sobra: o antigo não vira o pedido');
        Log::shouldHaveReceived('warning')->withArgs(fn ($mensagem) => str_contains((string) $mensagem, 'dois turnos seguidos do mesmo papel'))->atLeast()->once();
    }

    public function test_o_prompt_e_as_tools_nao_mudaram_o_sistema_continua_o_mesmo(): void
    {
        $this->pedido('deixe-me ver a lixeira', 'consultar_lixeira')->assertOk();

        $this->assertStringContainsString('Papel autenticado do usuário', $this->sistemaEnviado());
    }
}
