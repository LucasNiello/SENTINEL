<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ClinicaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PrepararAcessoLocalTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_a_administracao_da_clinica_com_senha_aleatoria_e_preserva_na_segunda_execucao(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $this->assertSame(0, Artisan::call('sentinel:preparar-acesso'));
        preg_match('/Senha temporária: (.+)/u', Artisan::output(), $matches);
        $senha = trim($matches[1]);
        $usuario = User::query()->sole();

        $this->assertSame('administracao@clinica.sentinel.br', $usuario->email);
        $this->assertSame('admin', $usuario->papel);
        $this->assertSame(ClinicaSeeder::TENANT, $usuario->tenant_id);
        $this->assertSame(24, strlen($senha));
        $this->assertNotSame($senha, $usuario->password);
        $this->assertTrue(Hash::check($senha, $usuario->password));
        $this->get('/login')->assertOk();
        $this->post('/login', ['_token' => session()->token(), 'email' => $usuario->email, 'password' => $senha])->assertRedirect('/agente');
        $this->assertAuthenticatedAs($usuario);

        $original = $usuario->fresh()->getAttributes();
        $this->assertSame(0, Artisan::call('sentinel:preparar-acesso'));
        $this->assertStringNotContainsString($senha, Artisan::output());
        $this->assertStringNotContainsString('Senha temporária:', Artisan::output());
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($original, $usuario->fresh()->getAttributes());
    }

    public function test_admin_da_clinica_existente_e_preservado_sem_criar_outro(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $usuario = User::factory()->papel('admin')->doTenant(ClinicaSeeder::TENANT)->create();
        $original = $usuario->fresh()->getAttributes();

        $this->artisan('sentinel:preparar-acesso')->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($original, $usuario->fresh()->getAttributes());
    }

    public function test_admin_de_outro_tenant_nao_conta(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        User::factory()->papel('admin')->doTenant(7)->create();

        $this->artisan('sentinel:preparar-acesso')->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'administracao@clinica.sentinel.br', 'papel' => 'admin', 'tenant_id' => ClinicaSeeder::TENANT]);
    }

    public function test_email_ocupado_nao_e_promovido_ou_redefinido(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $usuario = User::factory()->papel('leitura')->doTenant(2)->create(['email' => 'administracao@clinica.sentinel.br']);
        $original = $usuario->fresh()->getAttributes();

        $this->artisan('sentinel:preparar-acesso')->assertFailed();

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($original, $usuario->fresh()->getAttributes());
    }

    public function test_tenant_fora_da_config_da_clinica_falha_fechado(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['sentinel.tenants_clinica' => [4]]);

        $this->artisan('sentinel:preparar-acesso')->assertFailed();

        $this->assertDatabaseEmpty('users');
    }

    public function test_ambientes_nao_locais_nao_criam_usuarios(): void
    {
        foreach (['production', 'staging', 'testing'] as $ambiente) {
            $this->app->detectEnvironment(fn () => $ambiente);
            $this->artisan('sentinel:preparar-acesso')->assertFailed();
        }

        $this->assertDatabaseEmpty('users');
    }
}
