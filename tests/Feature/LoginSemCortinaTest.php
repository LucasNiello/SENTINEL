<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A cortina de carregamento do login não repete quando a página volta de um POST com erro. */
class LoginSemCortinaTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_limpo_nao_traz_sem_cortina(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('data-revelacao', false)
            ->assertDontSee('sem-cortina', false);
    }

    public function test_pagina_que_volta_de_senha_errada_traz_sem_cortina(): void
    {
        $usuario = User::factory()->create(['papel' => 'admin', 'tenant_id' => 1, 'password' => 'senha-de-teste']);

        $this->from('/login')->post('/login', ['email' => $usuario->email, 'password' => 'errada']);

        $this->get('/login')->assertOk()->assertSee('<html lang="pt-BR" class="sem-cortina">', false);
    }
}
