<?php

namespace Tests\Feature;

use App\Console\Commands\CriterioDaBateria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** sentinel:bateria faz chamadas reais (custa dinheiro): fora do SQLite database/bateria.sqlite, não roda. */
class BateriaAgenteTest extends TestCase
{
    use RefreshDatabase;

    public function test_fora_do_banco_da_bateria_recusa_sem_chamar_o_provedor(): void
    {
        Http::fake();

        $this->artisan('sentinel:bateria', ['--limite' => 1])
            ->expectsOutputToContain('database/bateria.sqlite')
            ->assertFailed();
        // Com o CSV e o tenant da clínica, o guarda continua antes de tudo.
        $this->artisan('sentinel:bateria', ['--limite' => 1, '--csv' => 'documentacao/testes/fase8/BATERIA_CLINICA.csv', '--tenant' => 3])
            ->expectsOutputToContain('database/bateria.sqlite')
            ->assertFailed();

        Http::assertNothingSent();
    }

    /** Revisão de 15/11 (I2): o caminho tem de ser exatamente o deste projeto, e um DB_URL (que manda na conexão) é recusado. */
    public function test_guarda_recusa_db_url_e_bateria_sqlite_fora_do_projeto(): void
    {
        Http::fake();
        $padrao = config('database.default');
        $original = config("database.connections.{$padrao}");

        try {
            config(["database.connections.{$padrao}.database" => database_path('bateria.sqlite'), "database.connections.{$padrao}.url" => 'mysql://root@127.0.0.1/sentinel']);
            $this->artisan('sentinel:bateria', ['--limite' => 1])->expectsOutputToContain('database/bateria.sqlite')->assertFailed();

            config(["database.connections.{$padrao}.url" => null, "database.connections.{$padrao}.database" => sys_get_temp_dir().'/outro/database/bateria.sqlite']);
            $this->artisan('sentinel:bateria', ['--limite' => 1])->expectsOutputToContain('database/bateria.sqlite')->assertFailed();
        } finally {
            config(["database.connections.{$padrao}" => $original]);
        }

        Http::assertNothingSent();
    }

    /** Bateria da clínica (15/11): CSV novo com o papel por linha; o original continua o mesmo (sem papel = operador). */
    public function test_csv_da_clinica_tem_papel_valido_em_toda_linha_e_o_original_continua_sem_papel(): void
    {
        $clinica = CriterioDaBateria::lerPedidos(base_path('documentacao/testes/fase8/BATERIA_CLINICA.csv'));
        $papeisDaClinica = ['atendente', 'vendedor', 'recepcao', 'veterinario', 'dono'];

        $this->assertGreaterThanOrEqual(40, count($clinica));
        $this->assertSame(range(1, count($clinica)), array_keys($clinica));
        foreach ($clinica as $p) {
            $this->assertContains($p['papel'], $papeisDaClinica, "pedido {$p['n']}");
            $this->assertNotSame('', $p['pedido'], "pedido {$p['n']}");
            if ($p['depende_de'] !== '') {
                $this->assertArrayHasKey((int) $p['depende_de'], $clinica, "pedido {$p['n']}");
                $this->assertSame($clinica[(int) $p['depende_de']]['papel'], $p['papel'], "a continuação {$p['n']} usa a sessão do mesmo usuário");
            }
        }
        // O texto do botão "Pedir ao agente" (com aspas) é lido inteiro.
        $this->assertStringContainsString('"Oi! Queria remarcar o banho do Thor para sábado, pode ser?"', $clinica[36]['pedido']);

        $original = CriterioDaBateria::lerPedidos(base_path('documentacao/testes/fase6/BATERIA_PEDIDOS.csv'));
        $this->assertCount(40, $original);
        $this->assertArrayNotHasKey('papel', $original[1]);
    }

    public function test_marcas_da_observacao(): void
    {
        $this->assertSame(['args' => ['id' => '4', 'modo' => 'itens'], 'posicao' => null, 'confirmar' => false, 'id_gravado' => null],
            CriterioDaBateria::marcas(['observacao' => 'arg:id=4 arg:modo=itens parcial 1 de 2']));
        $this->assertSame('ultima', CriterioDaBateria::marcas(['observacao' => 'posicao=ultima arg:novo_status=cancelado'])['posicao']);
        $this->assertTrue(CriterioDaBateria::marcas(['observacao' => 'confirmar base da continuacao 34'])['confirmar']);
        $this->assertSame('tutor_id', CriterioDaBateria::marcas(['observacao' => 'id_gravado:tutor_id esse mesmo'])['id_gravado']);
        $this->assertSame(['args' => [], 'posicao' => null, 'confirmar' => false, 'id_gravado' => null], CriterioDaBateria::marcas(['observacao' => 'completo']));
    }

    public function test_criterio_confere_tool_tipo_argumentos_posicao_e_id_gravado(): void
    {
        $pedido = fn (array $extra) => ['n' => 1, 'grupo' => 'estorno', 'esperado_tool' => 'estornar_venda', 'esperado_tipo' => 'confirmacao_pendente', 'observacao' => '', ...$extra];
        $proposta = ['tipo' => 'confirmacao_pendente', 'argumentos' => ['id' => 4, 'modo' => 'itens']];

        $this->assertTrue(CriterioDaBateria::acertou($pedido(['observacao' => 'arg:id=4 arg:modo=itens']), 'confirmacao_pendente', 'estornar_venda', $proposta, null));
        $this->assertFalse(CriterioDaBateria::acertou($pedido(['observacao' => 'arg:id=4 arg:modo=tudo']), 'confirmacao_pendente', 'estornar_venda', $proposta, null));
        $this->assertFalse(CriterioDaBateria::acertou($pedido(['observacao' => 'arg:linhas=1']), 'confirmacao_pendente', 'estornar_venda', $proposta, null));
        $this->assertFalse(CriterioDaBateria::acertou($pedido([]), 'confirmacao_pendente', 'estornar_cobranca', $proposta, null));
        $this->assertFalse(CriterioDaBateria::acertou($pedido([]), 'texto', 'estornar_venda', $proposta, null));
        // Alternativas de tool e de tipo.
        $this->assertTrue(CriterioDaBateria::acertou($pedido(['esperado_tool' => 'cobrar_atendimento|receber_cobranca', 'esperado_tipo' => 'confirmacao_pendente|erro']),
            'erro', 'receber_cobranca', ['tipo' => 'erro'], null));

        // Continuação pela posição na consulta da qual depende.
        $consulta = ['resultado' => [['id' => 7], ['id' => 3], ['id' => 9]]];
        $continuacao = fn (string $obs, int $id) => CriterioDaBateria::acertou(['n' => 28, 'grupo' => 'continuacao', 'esperado_tool' => 'atualizar_tutor',
            'esperado_tipo' => 'confirmacao_pendente', 'observacao' => $obs], 'confirmacao_pendente', 'atualizar_tutor', ['argumentos' => ['id' => $id]], $consulta);
        $this->assertTrue($continuacao('posicao=2', 3));
        $this->assertFalse($continuacao('posicao=2', 7));
        $this->assertTrue($continuacao('posicao=ultima', 9));
        $this->assertFalse($continuacao('posicao=ultima', 3));

        // "Esse mesmo" depois de gravar: o campo tem de ser o id que a confirmação da dependência gravou.
        $mesmo = ['n' => 34, 'grupo' => 'continuacao', 'esperado_tool' => 'criar_pet', 'esperado_tipo' => 'confirmacao_pendente', 'observacao' => 'id_gravado:tutor_id'];
        $this->assertTrue(CriterioDaBateria::acertou($mesmo, 'confirmacao_pendente', 'criar_pet', ['argumentos' => ['tutor_id' => 21]], null, 21));
        $this->assertFalse(CriterioDaBateria::acertou($mesmo, 'confirmacao_pendente', 'criar_pet', ['argumentos' => ['nome' => 'Mimi']], null, 21));
        $this->assertFalse(CriterioDaBateria::acertou($mesmo, 'confirmacao_pendente', 'criar_pet', ['argumentos' => ['tutor_id' => 21]], null, null));
    }

    public function test_ambiguo_exige_pergunta_e_recusa_so_texto_sem_tool(): void
    {
        $ambiguo = ['n' => 40, 'grupo' => 'ambiguo', 'esperado_tool' => '-', 'esperado_tipo' => 'texto', 'observacao' => ''];
        $recusa = [...$ambiguo, 'grupo' => 'recusa'];

        $this->assertTrue(CriterioDaBateria::acertou($ambiguo, 'texto', null, ['mensagem' => 'Qual agendamento?'], null));
        $this->assertFalse(CriterioDaBateria::acertou($ambiguo, 'texto', null, ['mensagem' => 'Não sei.'], null));
        $this->assertTrue(CriterioDaBateria::acertou($recusa, 'texto', null, ['mensagem' => 'Não tenho acesso ao prontuário.'], null));
        $this->assertFalse(CriterioDaBateria::acertou($recusa, 'resultado_leitura', 'consultar_pets', ['resultado' => []], null));
        $this->assertFalse(CriterioDaBateria::acertou($recusa, 'texto', 'consultar_pets', ['mensagem' => 'ok'], null));
    }

    /** O critério do CSV original (05/11) não mudou: as posições fixas dos pedidos 27 a 30. */
    public function test_continuacao_do_csv_original_continua_pelas_posicoes_fixas(): void
    {
        $consulta = ['resultado' => [['id' => 7], ['id' => 3], ['id' => 9]]];
        $linha = fn (int $n) => ['n' => $n, 'grupo' => 'continuacao', 'esperado_tool' => 'atualizar_cliente', 'esperado_tipo' => 'confirmacao_pendente', 'observacao' => 'id = 2ª linha'];

        $this->assertTrue(CriterioDaBateria::acertou($linha(27), 'confirmacao_pendente', 'atualizar_cliente', ['argumentos' => ['id' => 3]], $consulta));
        $this->assertTrue(CriterioDaBateria::acertou($linha(28), 'confirmacao_pendente', 'atualizar_cliente', ['argumentos' => ['id' => 7]], $consulta));
        $this->assertTrue(CriterioDaBateria::acertou($linha(30), 'confirmacao_pendente', 'atualizar_cliente', ['argumentos' => ['id' => 9]], $consulta));
        $this->assertFalse(CriterioDaBateria::acertou($linha(29), 'confirmacao_pendente', 'atualizar_cliente', ['argumentos' => ['id' => 3]], $consulta));
    }
}
