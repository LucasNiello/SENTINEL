<?php

namespace Tests\Feature;

use App\Models\Cliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * 23/11: "o que há na lixeira do Fábio Rocha?" devolvia todos os clientes excluídos, porque consultar_lixeira só filtrava
 * por tipo e situação. Agora aceita "nome" (parte do nome), no tenant do usuário e só no que está na lixeira.
 */
class LixeiraFiltroPorNomeTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAgente();
        $this->logarComo('admin', 1);
    }

    private function naLixeira(string $nome, int $tenant = 1): Cliente
    {
        $cliente = Cliente::create(['nome' => $nome, 'tenant_id' => $tenant]);
        $cliente->delete();

        return $cliente;
    }

    public function test_a_consulta_da_lixeira_filtra_pelo_nome_pedido(): void
    {
        $fabio = $this->naLixeira('Fábio Rocha');
        $this->naLixeira('Maria Souza');
        $this->naLixeira('Fábio Rocha Filho', 2); // outro tenant: nunca aparece
        Cliente::create(['nome' => 'Fábio Rocha Ativo', 'tenant_id' => 1]); // fora da lixeira: não é da lixeira

        $nomes = $this->comando('consultar_lixeira', ['tipo' => 'cliente', 'nome' => 'Fábio Rocha'])
            ->assertOk()->assertJsonPath('tipo', 'resultado_leitura')->json('resultado.*.nome');

        $this->assertSame(['Fábio Rocha'], $nomes);

        $this->comando('consultar_lixeira', ['tipo' => 'cliente'])->assertOk()->assertJsonCount(2, 'resultado');
        $this->assertSame($fabio->id, $this->comando('consultar_lixeira', ['nome' => 'rocha'])->json('resultado.0.id'));
    }

    public function test_coringas_do_like_no_nome_nao_casam_tudo(): void
    {
        $this->naLixeira('Fábio Rocha');

        $this->comando('consultar_lixeira', ['nome' => '%'])->assertOk()->assertJsonCount(0, 'resultado');
        $this->comando('consultar_lixeira', ['nome' => '_____'])->assertOk()->assertJsonCount(0, 'resultado');
    }
}
