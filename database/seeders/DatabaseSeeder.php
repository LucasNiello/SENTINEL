<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seed do Sentinel (17/11): um único tenant, o da clínica veterinária + petshop (ClinicaSeeder::TENANT).
 * php artisan migrate:fresh --seed planta as 11 contas @clinica.sentinel.br, todas com a DEMO_SENHA do .env,
 * e os cadastros de teste. Sem DEMO_SENHA, só avisa e não grava nada (o instalar.bat roda o seed antes de a
 * senha existir e cria o acesso pelo sentinel:preparar-acesso); com DEMO_SENHA fraca, o ClinicaSeeder recusa.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new \RuntimeException('DatabaseSeeder só pode ser executado em ambiente local.');
        }

        if ((string) env('DEMO_SENHA', '') === '') {
            $this->command?->warn('DEMO_SENHA não está definida: nada foi plantado. Defina-a no .env e rode php artisan db:seed.');

            return;
        }

        $this->call(ClinicaSeeder::class);
        $this->call(MovimentoDemoSeeder::class); // 26/11: agenda, atendimentos e vendas de novembro e dezembro (aditivo)
    }
}
