<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Bloco 2c — casca da bancada: layout comum com o papel visível, menu com itens
 * "em breve", agente herdando do layout, acessibilidade e telas de erro em
 * português sem detalhe técnico. O comportamento visual (375 px, foco) fica
 * para o teste manual; aqui só se confere o que a tela entrega.
 */
class CascaTest extends TestCase
{
    use RefreshDatabase;

    public function test_agente_herda_a_casca_com_usuario_papel_menu_e_sair(): void
    {
        $usuario = $this->logarComo('operador', 1);

        $this->get('/agente')
            ->assertOk()
            ->assertSee('class="topo"', false)
            ->assertSee($usuario->name)
            ->assertSee('<span class="sr-only">Papel: </span>Operador', false)
            ->assertSee('aria-label="Principal"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('href="/cadastros"', false)
            ->assertSee('href="/lixeira"', false)
            ->assertDontSee('href="/auditoria"', false)->assertDontSee('em breve')
            ->assertSee('<form method="POST" action="/logout">', false)
            ->assertSee('Pular para o conteúdo')
            ->assertSee('<main id="conteudo" tabindex="-1">', false);
    }

    public function test_admin_ve_o_item_de_auditoria_e_o_papel_por_extenso(): void
    {
        $this->logarComo('admin', 1);

        $this->get('/agente')
            ->assertOk()
            ->assertSee('<span class="sr-only">Papel: </span>Administrador', false)
            ->assertSee('href="/auditoria"', false);
    }

    public function test_campo_de_comando_tem_rotulo_e_campos_tem_contraste_de_borda(): void
    {
        $this->logarComo('operador', 1);
        $this->get('/agente')->assertOk()
            ->assertSee('<label for="campo-mensagem" class="sr-only">Seu pedido ao agente</label>', false)
            ->assertSee('<meta name="viewport" content="width=device-width, initial-scale=1">', false)
            ->assertSee("rolagem.className = 'tabela-rolagem';", false);
        // O visual é compartilhado no entrypoint; não depende de CSS duplicado no HTML.
        $css = file_get_contents(resource_path('css/workspace.css'));
        $this->assertStringContainsString(':focus-visible {outline:2px solid var(--foco)', $css);
        $this->assertStringContainsString('--color-borda-campo:#5B6570;', $css);
        $this->assertStringContainsString('border:1px solid var(--color-borda-campo)', $css);
        $this->assertStringContainsString('overflow-x:auto', $css);
        $this->assertStringContainsString('prefers-reduced-motion:reduce', $css);
    }

    public function test_pagina_inexistente_mostra_a_tela_404_em_portugues_no_layout(): void
    {
        $this->get('/pagina-que-nao-existe')
            ->assertNotFound()
            ->assertSee('Página não encontrada')
            ->assertSee('Erro 404')
            ->assertSee('class="topo"', false)
            ->assertSee('href="/login" class="guest-access">Entrar</a>', false)
            ->assertDontSee('aria-label="Principal"', false);
    }

    public function test_telas_de_erro_419_429_403_404_em_portugues_e_com_o_usuario_logado(): void
    {
        $usuario = $this->logarComo('operador', 1);
        Route::middleware('web')->get('/_teste/erro/{codigo}', fn (int $codigo) => abort($codigo));
        Route::middleware('web')->get('/_teste/sessao-expirada', fn () => throw new TokenMismatchException('CSRF token mismatch.'));

        $esperado = [
            401 => 'Entrada necessária',
            403 => 'Acesso negado',
            404 => 'Página não encontrada',
            429 => 'Muitos pedidos em pouco tempo',
            503 => 'Sistema em manutenção',
        ];
        foreach ($esperado as $codigo => $titulo) {
            $this->get("/_teste/erro/{$codigo}")
                ->assertStatus($codigo)
                ->assertSee($titulo)
                ->assertSee("Erro {$codigo}")
                ->assertSee($usuario->name);
        }

        $this->get('/_teste/sessao-expirada')
            ->assertStatus(419)
            ->assertSee('Sessão expirada')
            ->assertSee('Nada foi gravado.')
            ->assertDontSee('CSRF token mismatch');
    }

    public function test_erro_500_nao_mostra_detalhe_tecnico(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/_teste/quebra', fn () => throw new RuntimeException('SQLSTATE[HY000] select * from users where senha = segredo'));

        $this->get('/_teste/quebra')
            ->assertStatus(500)
            ->assertSee('Algo deu errado')
            ->assertSee('class="topo"', false)
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('segredo')
            ->assertDontSee('RuntimeException');
    }

    public function test_api_do_agente_continua_respondendo_erro_em_json(): void
    {
        $this->logarComo('operador', 1);

        $this->postJson('/agente/rota-que-nao-existe')
            ->assertNotFound()
            ->assertJsonStructure(['message'])
            ->assertDontSee('class="topo"', false);
    }
}
