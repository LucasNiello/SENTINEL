<?php

namespace Tests\Feature;

use App\Models\Cliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PermissaoExclusaoLogicaTest extends TestCase
{
    use RefreshDatabase;

    public static function papeis(): array
    {
        return [['leitura', 403], ['operador', 403], ['admin', 200]];
    }

    #[DataProvider('papeis')]
    public function test_exclusao_logica_preserva_matriz_documentada_por_papel(string $papel, int $status): void
    {
        $this->logarComo($papel, 1);
        $cliente = Cliente::create(['nome' => 'QA Cliente descartável', 'tenant_id' => 1]);
        $r = $this->postJson('/agente/formulario', ['tool' => 'excluir_cliente', 'id' => $cliente->id])->assertStatus($status);

        $this->assertDatabaseHas('clientes', ['id' => $cliente->id, 'deleted_at' => null]);
        if ($status === 403) {
            $r->assertJsonPath('tipo', 'negado');
            $this->assertDatabaseHas('audit_logs', ['tool' => 'excluir_cliente', 'papel' => $papel, 'resultado' => 'negado']);
        } else {
            $r->assertJsonPath('tipo', 'confirmacao_pendente');
        }
    }
}
