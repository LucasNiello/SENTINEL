<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Lancamento;
use App\Services\AiAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Decisão B do Lucas (13/11): o servidor não tem mais janela de 2 a 12 s. O /agente/revisar diz à tela se o Confirmar
 * pede o gesto de segurar 2 s ("segurar"), que é só visual: true nas ações destrutivas (excluir para a lixeira, excluir
 * de vez, cancelar cobrança, estornar, conciliação em lote, cancelar agendamento); false nas demais escritas. O que o
 * servidor garante continua igual: token de uso único e execução só com os valores revisados da sessão.
 */
class SegurarSoNasDestrutivasTest extends TestCase
{
    use CenarioClinica, RefreshDatabase, SimulaAgente;

    /** Propõe pela IA simulada e revisa com os valores dados; devolve a resposta do /agente/revisar. */
    private function revisarProposta(string $tool, array $argumentos, array $valores = [])
    {
        $proposta = $this->comando($tool, $argumentos)->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente')->json('proposta');

        return $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => $valores])->assertOk()->assertJsonPath('tipo', 'revisada');
    }

    public function test_cadastro_e_alteracao_confirmam_com_um_clique_e_exclusao_pede_segurar(): void
    {
        $this->prepararAgente();
        $this->logarComo('admin', 1);
        $cliente = Cliente::create(['nome' => 'Mercado Central', 'tenant_id' => 1]);

        $this->revisarProposta('criar_lancamento', ['descricao' => 'Aluguel', 'valor' => 10, 'data' => '2026-09-21'])->assertJsonPath('segurar', false);
        $this->revisarProposta('atualizar_cliente', ['id' => $cliente->id, 'email' => 'a@exemplo.com'])->assertJsonPath('segurar', false);
        $this->revisarProposta('excluir_cliente', ['id' => $cliente->id])->assertJsonPath('segurar', true);
    }

    public function test_conciliacao_em_lote_pede_segurar(): void
    {
        $this->prepararAgente();
        $this->logarComo('operador', 1);
        Lancamento::create(['descricao' => 'Conta', 'valor' => 10, 'data' => '2026-09-05', 'status' => 'pendente', 'tenant_id' => 1]);

        $this->revisarProposta('conciliar_lancamentos_em_lote', ['data_inicio' => '2026-09-01', 'data_fim' => '2026-09-30'])->assertJsonPath('segurar', true);
    }

    public function test_cancelar_agendamento_pede_segurar_pelo_valor_revisado_e_confirmar_nao(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->prepararAgente();
        $this->logarComo('recepcao', self::T);
        $agendamento = $this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item(),
            ['inicio' => '2026-11-11 09:00', 'fim' => '2026-11-11 09:30', 'status' => 'agendado']);

        // A IA propôs "confirmado", mas o humano trocou para "cancelado" no card: vale o revisado.
        $this->revisarProposta('alterar_status_agendamento', ['id' => $agendamento->id, 'novo_status' => 'confirmado'], ['novo_status' => 'cancelado'])
            ->assertJsonPath('segurar', true);
        $this->revisarProposta('alterar_status_agendamento', ['id' => $agendamento->id, 'novo_status' => 'cancelado'], ['novo_status' => 'confirmado'])
            ->assertJsonPath('segurar', false);
    }

    public function test_tools_destrutivas_da_clinica_e_tool_desconhecida_pedem_segurar(): void
    {
        $this->logarComo('dono', self::T);
        $agente = app(AiAgentService::class);

        foreach (['cancelar_cobranca', 'estornar_cobranca', 'excluir_tutor', 'excluir_pet', 'excluir_item_catalogo', 'excluir_definitivo_tutor', 'tool_que_nao_existe'] as $tool) {
            $this->assertTrue($agente->exigeSegurar($tool, []), $tool);
        }
        foreach (['criar_tutor', 'criar_pet', 'agendar_atendimento', 'receber_cobranca', 'registrar_venda', 'cobrar_atendimento', 'restaurar_tutor', 'marcar_mensagem_tratada'] as $tool) {
            $this->assertFalse($agente->exigeSegurar($tool, []), $tool);
        }
        $this->assertFalse($agente->exigeSegurar('alterar_status_agendamento', ['novo_status' => 'faltou']));
    }

    public function test_sem_espera_o_servidor_executa_so_os_valores_revisados_da_sessao(): void
    {
        $this->prepararAgente();
        $this->logarComo('operador', 1);
        $proposta = $this->comando('criar_lancamento', ['descricao' => 'Da IA', 'valor' => 10, 'data' => '2026-09-21'])->json('proposta');
        $token = $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => ['descricao' => 'Revisado']])->assertOk()->json('token');

        // Confirma no mesmo instante, tentando forjar outros valores e o "segurar": nada disso entra.
        $this->postJson('/agente/confirmar', ['token' => $token, 'valores' => ['descricao' => 'Forjado'], 'descricao' => 'Forjado', 'segurar' => false])->assertOk();

        $this->assertSame(['Revisado'], Lancamento::query()->pluck('descricao')->all());
        $this->postJson('/agente/confirmar', ['token' => $token])->assertStatus(422);
        $this->assertSame(1, Lancamento::count());
    }
}
