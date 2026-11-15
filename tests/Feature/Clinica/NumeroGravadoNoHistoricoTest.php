<?php

namespace Tests\Feature\Clinica;

use App\Models\Tutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Bateria da clínica com a IA real (15/11): "esse mesmo" depois de gravar. Na clínica, a escrita confirmada entra no
 * histórico com o NÚMERO do registro gravado (só o id, nenhum valor), e esse histórico vai para a IA no pedido seguinte.
 * Na contabilidade, a frase continua a mesma (FormularioAgenteTest).
 */
class NumeroGravadoNoHistoricoTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAgente();
    }

    public function test_tutor_confirmado_entra_no_historico_com_o_numero_e_vai_para_a_ia_no_pedido_seguinte(): void
    {
        $this->logarComo('recepcao', 3);

        $this->confirmar($this->propor(['nome' => 'Marcos Pereira', 'telefone' => '19 99333-1212'], 'criar_tutor'))->assertOk();
        $tutor = Tutor::query()->where('nome', 'Marcos Pereira')->sole();

        $mensagens = session('agente_conversa.mensagens');
        $this->assertSame(['role' => 'user', 'content' => 'Confirmei a proposta no card.'], $mensagens[count($mensagens) - 2]);
        $this->assertStringEndsWith(' Número do registro gravado: '.$tutor->id.'.', end($mensagens)['content']);
        // Nenhum valor do registro entra no histórico, só o número.
        $this->assertStringNotContainsString('99333', json_encode(session('agente_conversa')));

        $this->comando('consultar_tutores')->assertOk();
        $enviadas = json_encode(Http::recorded()[count(Http::recorded()) - 1][0]['messages'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Número do registro gravado: '.$tutor->id.'.', $enviadas);
    }

    public function test_sem_numero_ou_fora_da_clinica_a_frase_nao_muda(): void
    {
        $conversa = app(\App\Services\ConversaAgente::class);

        $this->logarComo('recepcao', 3);
        $conversa->registrarResultado(session()->driver(), 'Pet cadastrado.');
        $this->assertSame('Pet cadastrado.', collect(session('agente_conversa.mensagens'))->last()['content']);
        $conversa->registrarResultado(session()->driver(), 'Pet cadastrado.', 0);
        $this->assertSame('Pet cadastrado.', collect(session('agente_conversa.mensagens'))->last()['content']);

        session()->flush();
        $this->logarComo('operador', 1);
        $conversa->registrarResultado(session()->driver(), 'Cliente cadastrado.', 12);
        $this->assertSame('Cliente cadastrado.', collect(session('agente_conversa.mensagens'))->last()['content']);
    }
}
