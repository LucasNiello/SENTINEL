<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Front de 14/11 (F3 e F4): o que a revisão visual fixou — tamanho do texto em 3 níveis (preferência no navegador, por
 * usuário), sugestões da /agente no domínio certo, login sem fonte de CDN e com contraste AA, borda de risco de 2 px no
 * card e nenhum texto abaixo de 12 px na bancada (skill sentinel-visual §3, §5 e §9).
 */
class FrontAcessibilidadeTest extends TestCase
{
    use RefreshDatabase;

    public function test_layout_tem_o_tamanho_do_texto_em_tres_niveis_guardado_por_usuario(): void
    {
        $usuario = $this->logarComo('recepcao', 3);

        $this->get('/agente')->assertOk()
            ->assertSee('<html lang="pt-BR" data-usuario="'.$usuario->id.'">', false)
            ->assertSee("localStorage.getItem('sentinel:texto:{$usuario->id}')", false)
            ->assertSee('aria-labelledby="rotulo-texto"', false)
            ->assertSeeInOrder(['data-texto="1" aria-pressed="true" aria-label="Texto normal"', 'data-texto="2" aria-pressed="false" aria-label="Texto grande"',
                'data-texto="3" aria-pressed="false" aria-label="Texto maior"'], false);

        // A preferência é de quem está logado: outro usuário lê outra chave.
        $outro = $this->logarComo('dono', 3);
        $this->get('/agenda')->assertOk()
            ->assertSee("localStorage.getItem('sentinel:texto:{$outro->id}')", false)
            ->assertDontSee("sentinel:texto:{$usuario->id}'", false);

        $css = (string) file_get_contents(resource_path('css/workspace.css'));
        $this->assertStringContainsString('html[data-texto="2"] {zoom:1.125}', $css);
        $this->assertStringContainsString('html[data-texto="3"] {zoom:1.25}', $css);
        $js = (string) file_get_contents(resource_path('js/workspace.js'));
        $this->assertStringContainsString("'sentinel:texto:' + (raiz.dataset.usuario || '0')", $js);
    }

    public function test_pagina_de_erro_sem_usuario_nao_tem_o_controle_nem_a_chave(): void
    {
        $html = view('errors.404')->render();

        $this->assertStringNotContainsString('texto-tamanho', $html);
        $this->assertStringNotContainsString('sentinel:texto:', $html);
        $this->assertStringNotContainsString('data-usuario', $html);
    }

    public function test_sugestoes_da_agente_sao_as_do_dominio_e_do_papel(): void
    {
        $this->logarComo('recepcao', 3);
        $this->get('/agente')->assertOk()
            ->assertSee('data-suggestion="mostre a agenda de hoje"', false)
            ->assertSee('data-suggestion="mostre as mensagens novas da caixa de entrada"', false)
            ->assertSee('data-suggestion="agende uma consulta para o pet "', false)
            ->assertSee('placeholder="Peça ou pergunte…"', false)
            ->assertDontSee('Liste meus clientes')->assertDontSee('Lançamentos pendentes');

        // O vendedor não agenda: a sugestão de agendar não aparece para ele.
        $this->logarComo('vendedor', 3);
        $this->get('/agente')->assertOk()
            ->assertSee('data-suggestion="registre uma venda de "', false)
            ->assertDontSee('data-suggestion="agende uma consulta para o pet "', false);

        // Fora da clínica, as sugestões do domínio antigo continuam.
        $this->logarComo('operador', 1);
        $this->get('/agente')->assertOk()->assertSee('Liste meus clientes')->assertDontSee('Agenda de hoje');
    }

    public function test_login_nao_busca_fonte_em_cdn_e_usa_contorno_e_texto_com_contraste(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('fonts.googleapis.com', false)->assertDontSee('fonts.gstatic.com', false);

        $css = (string) file_get_contents(resource_path('css/login.css'));
        $this->assertStringContainsString("@font-face{font-family:'Sora'", $css);
        $this->assertStringContainsString("@font-face{font-family:'IBM Plex Sans'", $css);
        $this->assertStringContainsString('--muted:#5B6570', $css);
        $this->assertStringContainsString('.input-wrap{display:flex;align-items:center;min-width:0;min-height:54px;border:1px solid var(--muted)', $css);
        // Os tons fracos de antes (1,5:1 no contorno; 3,2:1 no texto) não voltam.
        foreach (['#CFCBBB', '#899098', '#87909A', '#6E7882'] as $fraco) {
            $this->assertStringNotContainsString($fraco, $css);
        }
    }

    public function test_bancada_tem_borda_de_risco_de_2px_e_nenhum_texto_abaixo_de_12px(): void
    {
        $css = (string) file_get_contents(resource_path('css/workspace.css'));

        $this->assertStringContainsString('.card-confirmacao {--cor-risco:var(--color-aviso);--cor-risco-faixa:var(--color-aviso-faixa);border:2px solid var(--cor-risco);', $css);
        $this->assertStringContainsString('body {margin:0;', $css);
        $this->assertSame(1, preg_match('/^body \{[^}]*font:16px\/1\.55 var\(--fonte-corpo\)/m', $css));
        // Qualquer tamanho declarado em px (font-size ou o atalho font) é de 12 px para cima.
        preg_match_all('/font(?:-size)?:(?:[^;}]*?\s)?(\d+(?:\.\d+)?)px/', $css, $tamanhos);
        $this->assertNotEmpty($tamanhos[1]);
        $this->assertSame([], array_values(array_filter($tamanhos[1], fn (string $px) => (float) $px < 12)));
    }
}
