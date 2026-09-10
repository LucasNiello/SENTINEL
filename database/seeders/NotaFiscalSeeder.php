<?php

namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\Fornecedor;
use App\Models\NotaFiscal;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class NotaFiscalSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $notasFiscais = [
            ['numero' => 'NF-000123', 'tipo' => 'saida', 'valor' => 1200.00, 'data_emissao' => '2026-08-10', 'cliente' => 'ABC Comércio de Materiais Ltda', 'fornecedor' => null],
            ['numero' => 'NF-000124', 'tipo' => 'saida', 'valor' => 850.00, 'data_emissao' => '2026-08-14', 'cliente' => 'Padaria Pão Dourado', 'fornecedor' => null],
            ['numero' => 'NF-000456', 'tipo' => 'entrada', 'valor' => 480.50, 'data_emissao' => '2026-08-05', 'cliente' => null, 'fornecedor' => 'Papelaria Central'],
            ['numero' => 'NF-000457', 'tipo' => 'entrada', 'valor' => 610.75, 'data_emissao' => '2026-08-15', 'cliente' => null, 'fornecedor' => 'Distribuidora Limeira Ltda'],
        ];

        foreach ($notasFiscais as $notaFiscal) {
            $clienteId = $notaFiscal['cliente'] !== null
                ? Cliente::where('nome', $notaFiscal['cliente'])->first()?->id
                : null;

            $fornecedorId = $notaFiscal['fornecedor'] !== null
                ? Fornecedor::where('nome', $notaFiscal['fornecedor'])->first()?->id
                : null;

            NotaFiscal::create([
                'numero' => $notaFiscal['numero'],
                'tipo' => $notaFiscal['tipo'],
                'valor' => $notaFiscal['valor'],
                'data_emissao' => $notaFiscal['data_emissao'],
                'cliente_id' => $clienteId,
                'fornecedor_id' => $fornecedorId,
                'lancamento_id' => null,
                'tenant_id' => 1,
            ]);
        }
    }
}
