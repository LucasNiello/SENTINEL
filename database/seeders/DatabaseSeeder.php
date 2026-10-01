<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new \RuntimeException('DatabaseSeeder só pode ser executado em ambiente local.');
        }

        // RF10: um usuário por papel no tenant 1 e um admin no tenant 2 (prova de isolamento).
        if ($this->command->call('sentinel:preparar-acesso', ['--exemplos' => true]) !== 0) {
            throw new \RuntimeException('Não foi possível preparar os usuários locais.');
        }

        $this->call([
            LancamentoSeeder::class,
            CategoriaLancamentoSeeder::class,
            ClienteSeeder::class,
            FornecedorSeeder::class,
            FuncionarioSeeder::class,
            NotaFiscalSeeder::class,
        ]);
    }
}
