<?php

namespace Database\Seeders;

use App\Models\Funcionario;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class FuncionarioSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $funcionarios = [
            ['nome' => 'Mariana Costa Ferreira', 'cpf' => '123.456.789-00', 'cargo' => 'Assistente contábil', 'salario' => 2200.00, 'data_admissao' => '2025-03-10', 'data_demissao' => null],
            ['nome' => 'Roberto Alves Pereira', 'cpf' => '987.654.321-00', 'cargo' => 'Sócio-administrador', 'salario' => 4500.00, 'data_admissao' => '2022-01-05', 'data_demissao' => null],
        ];

        foreach ($funcionarios as $funcionario) {
            Funcionario::create([
                ...$funcionario,
                'tenant_id' => 1,
            ]);
        }
    }
}
