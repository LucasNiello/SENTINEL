<?php

namespace Database\Seeders;

use App\Models\CategoriaLancamento;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CategoriaLancamentoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categorias = [
            ['nome' => 'Serviços de contabilidade', 'tipo' => 'receita'],
            ['nome' => 'Honorários avulsos', 'tipo' => 'receita'],
            ['nome' => 'Despesas administrativas', 'tipo' => 'despesa'],
            ['nome' => 'Impostos e taxas', 'tipo' => 'despesa'],
        ];

        foreach ($categorias as $categoria) {
            CategoriaLancamento::create([
                ...$categoria,
                'tenant_id' => 1,
            ]);
        }
    }
}
