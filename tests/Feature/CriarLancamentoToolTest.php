<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Lancamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * criar_lancamento passa por validar() com o LancamentoRequest, como as demais
 * tools de escrita: dado inválido é recusado com frase amigável e nada é gravado.
 */
class CriarLancamentoToolTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararAgente();
        $this->logarComo('admin', 1);
    }

    private function valido(array $sobrescrever = []): array
    {
        return array_merge(['descricao' => 'Café', 'valor' => 12.5, 'data' => '2026-09-21'], $sobrescrever);
    }

    public function test_criar_lancamento_recusa_dado_invalido_com_frase_amigavel_e_nao_grava(): void
    {
        $casos = [
            [['descricao' => null], 'Informe a descrição.'],
            [['descricao' => ''], 'Informe a descrição.'],
            [['descricao' => str_repeat('a', 256)], 'A descrição aceita no máximo 255 caracteres.'],
            [['valor' => -250], 'O valor deve ser maior que zero.'],
            [['valor' => 0], 'O valor deve ser maior que zero.'],
            [['valor' => 'abc'], 'O valor deve ser um número.'],
            [['valor' => 100000000], 'O valor deve ser no máximo 99999999.99.'],
            [['valor' => null], 'Informe o valor.'],
            [['data' => null], 'Informe a data.'],
            [['data' => '21/09/2026'], 'A data deve ser uma data válida (AAAA-MM-DD).'],
        ];

        foreach ($casos as $i => [$alteracao, $esperada]) {
            $argumentos = array_filter($this->valido($alteracao), fn ($v) => $v !== null);
            $token = $this->propor($argumentos);

            $mensagem = $this->confirmar($token)
                ->assertStatus(422)
                ->assertJsonPath('tipo', 'erro')
                ->json('mensagem');

            $this->assertSame($esperada, $mensagem, json_encode($alteracao));
            $this->assertSame($i + 1, AuditLog::count());
            $log = AuditLog::query()->latest('id')->first();
            $this->assertSame('recusado', $log->resultado);
            $this->assertTrue($log->permitido, 'recusa de regra de negócio não é negação de RBAC');
        }

        $this->assertSame(0, Lancamento::count(), 'nada foi gravado');
    }

    public function test_criar_lancamento_valido_continua_gravando_como_pendente(): void
    {
        $token = $this->propor($this->valido(['valor' => 99999999.99, 'status' => 'conciliado']));

        $this->confirmar($token)->assertOk()->assertJsonPath('tipo', 'resultado_escrita');

        $lancamento = Lancamento::query()->sole();
        $this->assertSame('99999999.99', $lancamento->valor);
        $this->assertSame('pendente', $lancamento->status, 'status não vem do modelo');
        $this->assertSame('2026-09-21', $lancamento->data->toDateString());
    }
}
