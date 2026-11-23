<?php

namespace Tests\Unit;

use App\Services\PlanilhaCsv;
use App\Support\Dinheiro;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Dinheiro em reais (R$ 0,00) na tela; centavos inteiros no banco (23/11). */
class DinheiroTest extends TestCase
{
    /** @return array<string, array{0: mixed, 1: ?int}> */
    public static function textos(): array
    {
        return [
            'inteiro' => ['80', 8000],
            'com vírgula' => ['80,50', 8050],
            'uma casa' => ['80,5', 8050],
            'zero' => ['0,00', 0],
            'só centavos' => ['0,99', 99],
            'milhar com ponto e vírgula decimal' => ['1.250,50', 125050],
            'ponto decimal' => ['1250.50', 125050],
            'ponto de milhar sem decimais' => ['1.250', 125000],
            'com R$ e espaço' => ['R$ 45,90', 4590],
            'espaços nas pontas' => ['  45,90 ', 4590],
            'três casas' => ['45,999', null],
            'negativo' => ['-5,00', null],
            'letras' => ['abc', null],
            'misturado' => ['12a', null],
            'duas vírgulas' => ['1,2,3', null],
            'vazio' => ['', null],
            'não é texto' => [8000, null],
            'nulo' => [null, null],
            'grande demais' => [str_repeat('9', 13), null],
        ];
    }

    #[DataProvider('textos')]
    public function test_texto_vira_centavos(mixed $entrada, ?int $esperado): void
    {
        $this->assertSame($esperado, Dinheiro::paraCentavos($entrada));
    }

    public function test_centavos_viram_reais_com_duas_casas(): void
    {
        $this->assertSame('80,00', Dinheiro::emReais(8000));
        $this->assertSame('45,90', Dinheiro::emReais(4590));
        $this->assertSame('0,05', Dinheiro::emReais(5));
        $this->assertSame('1250,50', Dinheiro::emReais(125050));
        $this->assertSame('0,00', Dinheiro::emReais(0));
    }

    public function test_ida_e_volta_nao_perde_centavo(): void
    {
        foreach ([0, 1, 99, 100, 4590, 8000, 125050, 100_000_000] as $centavos) {
            $this->assertSame($centavos, Dinheiro::paraCentavos(Dinheiro::emReais($centavos)));
        }
    }

    public function test_o_csv_sai_em_reais_com_virgula(): void
    {
        $csv = PlanilhaCsv::gerar(['nome', 'preco_centavos'], [['nome' => 'Banho', 'preco_centavos' => 8000]]);

        $this->assertStringContainsString("nome;preco_reais\n", $csv);
        $this->assertStringContainsString("Banho;80,00\n", $csv);
        $this->assertStringNotContainsString('8000', $csv);
    }
}
