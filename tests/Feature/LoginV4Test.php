<?php

namespace Tests\Feature;

use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class LoginV4Test extends TestCase
{
    public function test_layout_envia_credenciais_ao_login_real_com_csrf(): void
    {
        $response = $this->get('/login')->assertOk()->assertViewIs('login');

        $response->assertSee('method="POST"', false)
            ->assertSee('action="'.route('login').'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee('Clareza para', false)
            ->assertSee('id="art-stage"', false)
            ->assertDontSee('sem autenticação')
            ->assertDontSee('novalidate', false);
    }

    public function test_erros_e_email_sao_escapados_e_senha_nao_e_reexibida(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag([
            'email' => '<script>alert("erro")</script>',
        ]));
        $this->withSession([
            '_old_input' => ['email' => '"><img src=x onerror=alert(1)>', 'password' => 'segredo-nao-reexibir'],
        ]);
        $response = $this->view('login', ['errors' => $errors]);

        $response->assertSee('<script>alert("erro")</script>')
            ->assertDontSee('<script>alert("erro")</script>', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false)
            ->assertDontSee('segredo-nao-reexibir', false)
            ->assertSee('role="alert"', false)
            ->assertSee('aria-invalid="true"', false);
    }

    public function test_email_em_array_nao_quebra_a_renderizacao_apos_validacao(): void
    {
        $this->withSession(['_old_input' => ['email' => ['invalido']]])
            ->get('/login')->assertOk()->assertSee('value=""', false);
    }
}
