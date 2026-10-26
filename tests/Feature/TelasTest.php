<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B3 (Bloco 7) — telas reais da bancada: Cadastros, Lixeira e Auditoria dentro da
 * casca, o menu sem "em breve" e o botão Exportar. A página só mostra o que o papel
 * pode usar; os dados vêm das rotas JSON, que conferem tudo de novo no servidor.
 */
class TelasTest extends TestCase
{
    use RefreshDatabase;

    public function test_menu_mostra_cada_tela_so_para_o_papel_que_pode_usar(): void
    {
        $this->logarComo('leitura', 1);
        $this->get('/agente')->assertOk()
            ->assertSee('href="/cadastros"', false)
            ->assertDontSee('href="/lixeira"', false)
            ->assertDontSee('href="/auditoria"', false)
            ->assertDontSee('em breve');

        $this->logarComo('operador', 1);
        $this->get('/cadastros')->assertOk()
            ->assertSee('href="/lixeira"', false)
            ->assertDontSee('href="/auditoria"', false)
            ->assertSee('<a href="/cadastros"  aria-current="page" >Cadastros</a>', false);

        $this->logarComo('admin', 1);
        $this->get('/agente')->assertOk()->assertSee('href="/lixeira"', false)->assertSee('href="/auditoria"', false);
    }

    public function test_telas_exigem_login(): void
    {
        foreach (['/cadastros', '/lixeira', '/auditoria'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_cadastros_usa_a_consulta_sem_ia_e_so_oferece_novo_a_quem_pode_cadastrar(): void
    {
        $this->logarComo('leitura', 1);
        $resposta = $this->get('/cadastros')->assertOk()
            ->assertSee("fetch('/agente/consultar'", false)
            ->assertSee("fetch('/agente/formulario'", false)
            ->assertSee("fetch('/agente/exportar'", false);
        $this->assertSame([], array_filter(array_column($resposta->viewData('entidades'), 'criar')), 'leitura não cadastra nada');

        $this->logarComo('operador', 1);
        $entidades = $this->get('/cadastros')->assertOk()->viewData('entidades');
        $this->assertSame('criar_cliente', $entidades['clientes']['criar']);
        $this->assertSame('criar_lancamento', $entidades['lancamentos']['criar']);
        $this->assertNull($entidades['funcionarios']['criar'], 'funcionário só o admin cadastra');
        $this->assertSame('consultar_funcionarios', $entidades['funcionarios']['consultar']);

        $this->logarComo('admin', 1);
        $this->assertSame('criar_funcionario', $this->get('/cadastros')->viewData('entidades')['funcionarios']['criar']);
    }

    public function test_lixeira_so_para_operador_ou_admin(): void
    {
        $this->logarComo('leitura', 1);
        $this->get('/lixeira')->assertForbidden();

        $this->logarComo('operador', 1);
        $entidades = $this->get('/lixeira')->assertOk()->assertSee('<h1>Lixeira</h1>', false)->viewData('entidades');
        $this->assertSame('consultar_lixeira', $entidades['lixeira']['consultar']);
        $this->assertNull($entidades['lixeira']['criar']);
    }

    public function test_auditoria_so_admin_nega_auditando_e_lista_so_usuarios_do_tenant(): void
    {
        $operador = $this->logarComo('operador', 1);
        $this->get('/auditoria')->assertForbidden();
        $negado = AuditLog::query()->sole();
        $this->assertSame(['consultar_auditoria', 'negado', false, $operador->id], [$negado->tool, $negado->resultado, $negado->permitido, $negado->user_id]);

        User::factory()->doTenant(1)->create(['name' => '<b>Ana</b>']);
        User::factory()->doTenant(2)->create(['name' => 'Alheio']);
        $this->logarComo('admin', 1);

        $this->get('/auditoria')->assertOk()
            ->assertSee("fetch(url, { headers: { 'Accept': 'application/json' } })", false)
            ->assertSee('&lt;b&gt;Ana&lt;/b&gt;', false)
            ->assertDontSee('<b>Ana</b>', false)
            ->assertDontSee('Alheio')
            ->assertSee('<option value="consultar_clientes">Consultar clientes</option>', false);
    }

    public function test_agente_tem_o_botao_exportar_e_o_foco_volta_ao_campo(): void
    {
        $this->logarComo('leitura', 1);
        $this->get('/agente')->assertOk()
            ->assertSee("botaoExportar.textContent = 'Exportar CSV';", false)
            ->assertSee('id="campo-mensagem" data-foco', false);
    }
}
