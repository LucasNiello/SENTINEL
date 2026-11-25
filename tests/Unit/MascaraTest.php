<?php

namespace Tests\Unit;

use App\Support\Mascara;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Máscara única de CPF e telefone (23/11): 3 primeiros e 3 últimos dígitos. */
class MascaraTest extends TestCase
{
    /** @return array<string, array{0: mixed, 1: mixed}> */
    public static function cpfs(): array
    {
        return [
            'formatado' => ['123.456.789-00', '123.***.**9-00'],
            'só dígitos' => ['12345678900', '123.***.**9-00'],
            'número inteiro' => [11122233344, '111.***.**3-44'],
            'com espaços' => [' 111.222.333-44 ', '111.***.**3-44'],
            'nulo' => [null, null],
            'vazio' => ['', ''],
            'já mascarado' => ['123.***.**9-00', '123.***.**9-00'],
            'mascarado no formato antigo' => ['***.***.789-00', '***.***.789-00'],
            'curto demais' => ['1234', '[redigido]'],
            'sem dígito' => ['abc', '[redigido]'],
            'lista' => [['123.456.789-00'], '[redigido]'],
            'tamanho estranho: 3 e 3, o resto escondido' => ['1234567890', '123****890'],
        ];
    }

    #[DataProvider('cpfs')]
    public function test_cpf(mixed $entrada, mixed $esperado): void
    {
        $this->assertSame($esperado, Mascara::cpf($entrada));
    }

    /** @return array<string, array{0: mixed, 1: mixed}> */
    public static function telefones(): array
    {
        return [
            'celular formatado' => ['(19) 98765-4321', '(19) 9****-*321'],
            'celular só dígitos' => ['19987654321', '(19) 9****-*321'],
            'celular com espaço e hífen' => ['19 98765-4321', '(19) 9****-*321'],
            'fixo formatado' => ['(19) 3456-7890', '(19) 3***-*890'],
            'fixo só dígitos' => ['1934567890', '(19) 3***-*890'],
            'com +55' => ['+55 (19) 98765-4321', '(19) 9****-*321'],
            'com 55 colado' => ['5519987654321', '(19) 9****-*321'],
            'nulo' => [null, null],
            'vazio' => ['', ''],
            'já mascarado' => ['(19) 9****-*321', '(19) 9****-*321'],
            'sem DDD (8 dígitos): 3 e 3' => ['98765432', '987**432'],
            'curto demais' => ['12345', '[redigido]'],
            'sem dígito' => ['sem telefone', '[redigido]'],
            'lista' => [['19987654321'], '[redigido]'],
        ];
    }

    #[DataProvider('telefones')]
    public function test_telefone(mixed $entrada, mixed $esperado): void
    {
        $this->assertSame($esperado, Mascara::telefone($entrada));
    }

    public function test_nunca_devolve_o_valor_completo_nem_o_miolo(): void
    {
        $this->assertStringNotContainsString('456', (string) Mascara::cpf('123.456.789-00'));
        $this->assertStringNotContainsString('8765', (string) Mascara::telefone('(19) 98765-4321'));
    }
}
