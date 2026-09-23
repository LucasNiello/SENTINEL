<?php

namespace Database\Seeders;

use App\Models\User;
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
        // Usuários de desenvolvimento com senha conhecida (ver README): nunca em produção.
        if (app()->isProduction()) {
            throw new \RuntimeException('DatabaseSeeder cria usuários com senha de desenvolvimento e não roda em produção.');
        }

        // RF10: um usuário por papel no tenant 1 e um admin no tenant 2 (prova de isolamento).
        $usuarios = [
            ['name' => 'Admin (tenant 1)', 'email' => 'admin@sentinel.local', 'papel' => 'admin', 'tenant_id' => 1],
            ['name' => 'Operador (tenant 1)', 'email' => 'operador@sentinel.local', 'papel' => 'operador', 'tenant_id' => 1],
            ['name' => 'Leitura (tenant 1)', 'email' => 'leitura@sentinel.local', 'papel' => 'leitura', 'tenant_id' => 1],
            ['name' => 'Admin (tenant 2)', 'email' => 'admin.t2@sentinel.local', 'papel' => 'admin', 'tenant_id' => 2],
        ];

        foreach ($usuarios as $usuario) {
            User::factory()
                ->papel($usuario['papel'])
                ->doTenant($usuario['tenant_id'])
                ->create([
                    'name' => $usuario['name'],
                    'email' => $usuario['email'],
                    'password' => 'sentinel123',
                ]);
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
