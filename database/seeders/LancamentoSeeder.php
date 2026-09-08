<?php

namespace Database\Seeders;

use App\Models\Lancamento;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class LancamentoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // tenant_id fixo em 1: ainda não existe tabela de tenants no projeto.
        $lancamentos = [
            ['descricao' => 'Pagamento de fornecedor - Papelaria Central', 'valor' => 480.50, 'status' => 'pendente', 'data' => '2026-08-05'],
            ['descricao' => 'Recebimento de honorários - Cliente ABC Ltda', 'valor' => 2500.00, 'status' => 'conciliado', 'data' => '2026-08-07'],
            ['descricao' => 'Tarifa bancária - conta corrente', 'valor' => 32.90, 'status' => 'conciliado', 'data' => '2026-08-10'],
            ['descricao' => 'Pagamento de guia de DAS - Simples Nacional', 'valor' => 610.75, 'status' => 'pendente', 'data' => '2026-08-15'],
            ['descricao' => 'Recebimento de honorários - Cliente XYZ Comércio', 'valor' => 1800.00, 'status' => 'pendente', 'data' => '2026-08-18'],
            ['descricao' => 'Pagamento de folha - pró-labore sócio', 'valor' => 3200.00, 'status' => 'conciliado', 'data' => '2026-08-20'],
            ['descricao' => 'Reembolso de despesa de cartório', 'valor' => 145.30, 'status' => 'pendente', 'data' => '2026-08-25'],
        ];

        foreach ($lancamentos as $lancamento) {
            Lancamento::create([
                ...$lancamento,
                'tenant_id' => 1,
            ]);
        }
    }
}
