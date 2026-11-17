<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ContextoUsuario;
use Database\Seeders\ClinicaSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Garante um acesso local (17/11: um único tenant, o da clínica): a conta Administração
 * (administracao@clinica.sentinel.br, papel admin). Se o tenant da clínica já tem um admin, nada muda.
 * A senha nova é aleatória e aparece só no console desta criação.
 */
class PrepararAcessoLocal extends Command
{
    protected $signature = 'sentinel:preparar-acesso';

    protected $description = 'Prepara o acesso local (conta Administração da clínica), preservando contas e senhas existentes';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Este comando só pode ser executado com APP_ENV=local.');

            return self::FAILURE;
        }

        $tenant = ClinicaSeeder::TENANT;
        // Falha fechado: fora de sentinel.tenants_clinica, a Administração viraria admin da contabilidade (com lixeira).
        if (! ContextoUsuario::tenantDaClinica($tenant)) {
            $this->error("O tenant {$tenant} não está em SENTINEL_TENANTS_CLINICA. Nenhuma conta foi criada.");

            return self::FAILURE;
        }

        $admin = User::query()->where('papel', 'admin')->where('tenant_id', $tenant)->orderBy('id')->first();

        if ($admin) {
            $this->info("Administrador existente preservado: {$admin->email}. Nenhuma senha foi alterada.");

            return self::SUCCESS;
        }

        $senha = Str::password(24);
        $usuario = User::query()->firstOrCreate(
            ['email' => 'administracao@'.ClinicaSeeder::DOMINIO_EMAIL],
            ['name' => 'Administração', 'papel' => 'admin', 'tenant_id' => $tenant, 'password' => Hash::make($senha)],
        );

        if (! $usuario->wasRecentlyCreated) {
            $this->error('O e-mail da Administração já existe sem perfil administrativo válido. Conta preservada; revise o papel e o tenant manualmente.');

            return self::FAILURE;
        }

        // Apenas no console desta criação: nunca enviar a senha ao logger.
        $this->line("E-mail: {$usuario->email}");
        $this->line('Senha temporária: '.OutputFormatter::escape($senha));
        $this->line("Perfil: {$usuario->papel}; tenant: {$usuario->tenant_id}");
        $this->warn('Guarde a senha agora; ela não será exibida novamente.');

        return self::SUCCESS;
    }
}
