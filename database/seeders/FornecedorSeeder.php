<?php

namespace Database\Seeders;

use App\Models\Fornecedor;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class FornecedorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $fornecedores = [
            ['nome' => 'Papelaria Central', 'documento' => '11.222.333/0001-44', 'email' => 'vendas@papelariacentral.com.br', 'telefone' => '(19) 3244-1122'],
            ['nome' => 'Distribuidora Limeira Ltda', 'documento' => '22.333.444/0001-55', 'email' => 'compras@distlimeira.com.br', 'telefone' => '(19) 3241-9900'],
            ['nome' => 'Cartório do 2º Ofício', 'documento' => '33.444.555/0001-66', 'email' => null, 'telefone' => null],
        ];

        foreach ($fornecedores as $fornecedor) {
            Fornecedor::create([
                ...$fornecedor,
                'tenant_id' => 1,
            ]);
        }
    }
}
