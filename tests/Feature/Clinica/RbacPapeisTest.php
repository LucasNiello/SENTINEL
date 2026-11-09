<?php

namespace Tests\Feature\Clinica;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\User;
use App\Services\AiAgentService;
use App\Services\ClienteService;
use App\Services\ReautenticacaoAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Clínica vet, Fase A: RBAC por lista de papéis. Mapeamento provisório: dono = admin nas tools do
 * domínio antigo; atendente, vendedor, recepcao e veterinario sem acesso a elas até a Fase B.
 */
class RbacPapeisTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    private const PAPEIS_NOVOS_SEM_ACESSO = ['atendente', 'vendedor', 'recepcao', 'veterinario'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAgente();
    }

    public function test_dono_tem_nas_tools_antigas_o_mesmo_acesso_do_admin(): void
    {
        foreach (['admin', 'dono'] as $papel) {
            $this->logarComo($papel);

            $this->comando('consultar_lancamentos')->assertOk();
            $this->comando('criar_lancamento', ['descricao' => 'X', 'valor' => 1, 'data' => '2026-11-09'])
                ->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente');
            // Tool que hoje é só do admin.
            $this->comando('criar_funcionario', ['nome' => 'Bia', 'cpf' => '529.982.247-25', 'salario' => 1000, 'data_admissao' => '2026-11-01'])
                ->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente');
            $this->assertTrue(app(AiAgentService::class)->podeExecutar('excluir_definitivo_cliente'), $papel);
        }
    }

    public function test_papeis_novos_nao_acessam_as_tools_antigas_e_a_negacao_e_auditada(): void
    {
        foreach (self::PAPEIS_NOVOS_SEM_ACESSO as $papel) {
            $this->logarComo($papel);

            $this->comando('consultar_lancamentos')->assertStatus(403)->assertJsonPath('tipo', 'negado');

            $log = AuditLog::query()->latest('id')->first();
            $this->assertSame(['consultar_lancamentos', 'negado', false, $papel], [$log->tool, $log->resultado, $log->permitido, $log->papel]);
            $this->assertSame("Papel {$papel} sem permissão para esta ação.", $log->mensagem);

            $this->assertFalse(app(AiAgentService::class)->podeExecutar('consultar_clientes'), $papel);
        }
    }

    public function test_lista_de_papeis_com_papel_desconhecido_quebra_na_definicao(): void
    {
        // Simula um papel que some do config (ou um erro de digitação numa lista): o catálogo não é montado.
        config(['sentinel.papeis' => ['leitura', 'operador', 'admin']]);
        $this->logarComo('admin');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('papel desconhecido (dono)');

        app(AiAgentService::class)->tools();
    }

    public function test_papel_fora_do_config_nega_mesmo_estando_numa_lista(): void
    {
        // "dono" continua nas listas das tools, mas deixa de ser papel válido: nega (falha fechada).
        $this->logarComo('dono');
        config(['sentinel.papeis' => ['leitura', 'operador', 'admin', 'dono']]);
        $agente = app(AiAgentService::class);
        $agente->tools(); // catálogo montado com "dono" válido
        config(['sentinel.papeis' => ['leitura', 'operador', 'admin']]);

        $this->assertFalse($agente->podeExecutar('consultar_lancamentos'));
    }

    public function test_senha_do_dono_autoriza_restaurar_e_a_da_recepcao_nao(): void
    {
        $this->logarComo('operador');
        User::factory()->papel('dono')->doTenant(1)->create(['email' => 'dono@clinica.local', 'password' => Hash::make('senha-do-dono-123')]);
        User::factory()->papel('recepcao')->doTenant(1)->create(['email' => 'recepcao@clinica.local', 'password' => Hash::make('senha-da-recepcao')]);
        User::factory()->papel('dono')->doTenant(2)->create(['email' => 'dono2@clinica.local', 'password' => Hash::make('senha-do-dono-123')]);

        $reautenticacao = app(ReautenticacaoAdmin::class);

        $this->assertTrue($reautenticacao->confirmar('dono@clinica.local', 'senha-do-dono-123'));
        $this->assertFalse($reautenticacao->confirmar('recepcao@clinica.local', 'senha-da-recepcao'));
        $this->assertFalse($reautenticacao->confirmar('dono2@clinica.local', 'senha-do-dono-123'), 'dono de outro tenant');
    }

    public function test_dono_do_tenant_autoriza_a_exclusao_definitiva_e_a_recepcao_nao(): void
    {
        $dono = User::factory()->papel('dono')->doTenant(1)->create();
        $recepcao = User::factory()->papel('recepcao')->doTenant(1)->create();
        $cliente = Cliente::create(['nome' => 'Arquivado', 'tenant_id' => 1]);
        $cliente->delete();
        $cliente->arquivar();

        $this->assertFalse(app(ClienteService::class)->excluirDefinitivo($cliente->id, 1, $recepcao->id)['excluido']);
        $this->assertTrue(app(ClienteService::class)->excluirDefinitivo($cliente->id, 1, $dono->id)['excluido']);
        $this->assertSame(0, Cliente::withTrashed()->count());
    }

    public function test_menu_e_rotulos_seguem_os_papeis_novos(): void
    {
        $this->logarComo('dono');
        $this->get('/agente')->assertOk()
            ->assertSee('<span class="sr-only">Papel: </span>Dono', false)
            ->assertSee('href="/lixeira"', false)
            ->assertSee('href="/auditoria"', false);
        $this->getJson('/auditoria/registros')->assertOk();

        $this->logarComo('recepcao');
        $this->get('/agente')->assertOk()
            ->assertSee('<span class="sr-only">Papel: </span>Recepção', false)
            ->assertDontSee('href="/lixeira"', false)
            ->assertDontSee('href="/auditoria"', false);
        $this->getJson('/auditoria/registros')->assertForbidden();
        $this->get('/lixeira')->assertForbidden();
    }

    public function test_papeis_novos_entram_no_login(): void
    {
        foreach (['atendente', 'vendedor', 'recepcao', 'veterinario', 'dono'] as $papel) {
            User::factory()->papel($papel)->doTenant(3)->create(['email' => "{$papel}@clinica.local", 'password' => Hash::make('senha-forte-123')]);

            $this->post('/login', ['email' => "{$papel}@clinica.local", 'password' => 'senha-forte-123'])->assertRedirect();
            $this->assertAuthenticated();
            $this->post('/logout');
        }
    }
}
