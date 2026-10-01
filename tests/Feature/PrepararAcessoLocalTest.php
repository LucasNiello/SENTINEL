<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PrepararAcessoLocalTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_admin_com_senha_aleatoria_valida_e_preserva_na_segunda_execucao(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $this->assertSame(0, Artisan::call('sentinel:preparar-acesso'));
        $output = Artisan::output();
        preg_match('/Senha temporária: (.+)/u', $output, $matches);
        $senha = trim($matches[1]);
        $usuario = User::query()->sole();

        $this->assertSame('admin@sentinel.local', $usuario->email);
        $this->assertSame('admin', $usuario->papel);
        $this->assertSame(1, $usuario->tenant_id);
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

    public function test_admin_apropriado_existente_e_preservado_sem_criar_outro(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $usuario = User::factory()->papel('admin')->doTenant(7)->create();
        $original = $usuario->fresh()->getAttributes();

        $this->artisan('sentinel:preparar-acesso')->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($original, $usuario->fresh()->getAttributes());
    }

    public function test_email_local_ocupado_nao_e_promovido_ou_redefinido(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $usuario = User::factory()->papel('leitura')->doTenant(2)->create(['email' => 'admin@sentinel.local']);
        $original = $usuario->fresh()->getAttributes();

        $this->artisan('sentinel:preparar-acesso')->assertFailed();

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($original, $usuario->fresh()->getAttributes());
    }

    public function test_exemplos_tem_senhas_distintas_e_preserva_todos_ao_repetir(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->assertSame(0, Artisan::call('sentinel:preparar-acesso', ['--exemplos' => true]));
        preg_match_all('/Senha temporária: (.+)/u', Artisan::output(), $matches);
        $senhas = array_map('trim', $matches[1]);
        $this->assertCount(4, array_unique($senhas));
        $usuarios = User::query()->orderBy('id')->get();
        foreach ($usuarios as $index => $usuario) {
            $this->assertTrue(Hash::check($senhas[$index], $usuario->password));
        }
        $original = $usuarios->map->getAttributes()->all();

        $this->assertSame(0, Artisan::call('sentinel:preparar-acesso', ['--exemplos' => true]));
        $this->assertStringNotContainsString('Senha temporária:', Artisan::output());
        $this->assertSame($original, User::query()->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_ambientes_nao_locais_nao_criam_usuarios(): void
    {
        foreach (['production', 'staging', 'testing'] as $ambiente) {
            $this->app->detectEnvironment(fn () => $ambiente);
            $this->artisan('sentinel:preparar-acesso', ['--exemplos' => true])->assertFailed();
        }

        $this->assertDatabaseEmpty('users');
    }
}
