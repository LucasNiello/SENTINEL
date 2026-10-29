<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        // Executa antes dos traits de teste, inclusive RefreshDatabase.
        // Recusar uma configuração insegura é preferível a apagar dados locais.
        if (! $app->environment('testing')
            || $app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Testes exigem APP_ENV=testing e SQLite :memory:. Nenhum banco local pode ser usado.');
        }

        return $app;
    }

    /**
     * Teste não depende de build: sem isto, telas com @vite (vitrine, login v4)
     * quebram quando public/build/manifest.json não existe.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Loga um usuário com o papel e o tenant dados (RF10: papel e tenant vêm
     * do usuário logado, não mais de valores fixos em config/sentinel.php).
     */
    protected function logarComo(string $papel = 'admin', int $tenantId = 1): User
    {
        $usuario = User::factory()->papel($papel)->doTenant($tenantId)->create();

        $this->actingAs($usuario);

        return $usuario;
    }
}
