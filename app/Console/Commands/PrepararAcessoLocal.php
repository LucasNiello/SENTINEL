<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Console\Formatter\OutputFormatter;

class PrepararAcessoLocal extends Command
{
    protected $signature = 'sentinel:preparar-acesso {--exemplos : Inclui os quatro perfis de demonstração do seeder}';

    protected $description = 'Prepara o acesso local, preservando contas e senhas existentes';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Este comando só pode ser executado com APP_ENV=local.');

            return self::FAILURE;
        }

        if (! $this->option('exemplos')) {
            $admin = User::query()->where('papel', 'admin')->whereNotNull('tenant_id')->orderBy('id')->first();

            if ($admin) {
                $this->info("Administrador existente preservado: {$admin->email}. Nenhuma senha foi alterada.");

                return self::SUCCESS;
            }
        }

        $usuarios = [
            ['name' => 'Admin (tenant 1)', 'email' => 'admin@sentinel.local', 'papel' => 'admin', 'tenant_id' => 1],
        ];

        if ($this->option('exemplos')) {
            $usuarios = array_merge($usuarios, [
                ['name' => 'Operador (tenant 1)', 'email' => 'operador@sentinel.local', 'papel' => 'operador', 'tenant_id' => 1],
                ['name' => 'Leitura (tenant 1)', 'email' => 'leitura@sentinel.local', 'papel' => 'leitura', 'tenant_id' => 1],
                ['name' => 'Admin (tenant 2)', 'email' => 'admin.t2@sentinel.local', 'papel' => 'admin', 'tenant_id' => 2],
            ]);
        }

        foreach ($usuarios as $atributos) {
            $senha = Str::password(24);
            $usuario = User::query()->firstOrCreate(
                ['email' => $atributos['email']],
                [...$atributos, 'password' => Hash::make($senha)],
            );

            if ($usuario->wasRecentlyCreated) {
                // Apenas no console desta criação: nunca enviar a senha ao logger.
                $this->line("E-mail: {$usuario->email}");
                $this->line('Senha temporária: '.OutputFormatter::escape($senha));
                $this->line("Perfil: {$usuario->papel}; tenant: {$usuario->tenant_id}");
                $this->warn('Guarde a senha agora; ela não será exibida novamente.');
            } elseif (! $this->option('exemplos')) {
                $this->error('O e-mail local já existe sem perfil administrativo válido. Conta preservada; revise o papel e o tenant manualmente.');

                return self::FAILURE;
            } else {
                $this->info("Usuário existente preservado: {$usuario->email}.");
            }
        }

        return self::SUCCESS;
    }
}
