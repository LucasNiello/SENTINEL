<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
