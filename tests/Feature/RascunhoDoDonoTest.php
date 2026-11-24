<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Lancamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * R5 (24/11): o rascunho (e o token dele) pertence a quem o abriu. Outro usuário na MESMA sessão, ou o mesmo usuário
 * movido de tenant, recebe a mesma resposta de um id que nunca existiu, em cada caminho, sem gravar, sem auditar e
 * sem consumir nada: depois, o dono ainda confirma.
 */
class RascunhoDoDonoTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAgente();
    }

    private function propor(): string
    {
        return (string) $this->comando('criar_lancamento', ['descricao' => 'Café', 'valor' => 12.5, 'data' => '2026-09-21'])
            ->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente')->json('proposta');
    }

    /** Resposta para um id que nunca existiu, no mesmo caminho: é a que o intruso tem de receber, campo a campo. */
    private function igualAoIdInexistente(string $metodo, string $url, array $corpo, string $campo, string $real): void
    {
        $intruso = $this->{$metodo}($url, [...$corpo, $campo => $real]);
        $inexistente = $this->{$metodo}($url, [...$corpo, $campo => (string) Str::uuid()]);

        $this->assertSame($inexistente->getStatusCode(), $intruso->getStatusCode());
        $this->assertSame($inexistente->json(), $intruso->json(), 'o intruso não pode distinguir "não é meu" de "não existe"');
    }

    public function test_outro_usuario_com_token_emitido_e_recusado_e_o_dono_ainda_confirma(): void
    {
        $dono = $this->logarComo('admin', 1);
        $proposta = $this->propor();
        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->assertOk()->json('token');
        $logsAntes = AuditLog::count();

        $this->logarComo('admin', 1); // outro usuário, mesmo tenant, mesma sessão

        $this->igualAoIdInexistente('postJson', '/agente/confirmar', [], 'token', $token);
        $this->igualAoIdInexistente('postJson', '/agente/cancelar', [], 'token', $token);
        $this->igualAoIdInexistente('postJson', '/agente/editar', [], 'token', $token);
        $this->igualAoIdInexistente('postJson', '/agente/revisar', ['valores' => []], 'proposta', $proposta);
        $this->assertSame(0, Lancamento::count());
        $this->assertSame($logsAntes, AuditLog::count(), 'o intruso não gera auditoria nem desfecho');

        $this->actingAs($dono); // volta o dono: o token não foi consumido
        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();
        $this->assertSame(1, Lancamento::count());
    }

    public function test_editar_de_intruso_com_token_vencido_nao_revela_que_o_token_existe(): void
    {
        $this->logarComo('admin', 1);
        $proposta = $this->propor();
        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->assertOk()->json('token');
        $this->travel(\App\Services\ConfirmacoesPendentes::PRAZO_RASCUNHO_S + 1)->seconds();

        $this->logarComo('admin', 1);

        $this->igualAoIdInexistente('postJson', '/agente/editar', [], 'token', $token);
    }

    public function test_outro_usuario_com_rascunho_e_recusado_na_previa_no_passo_unico_e_no_token(): void
    {
        $dono = $this->logarComo('admin', 1);
        $proposta = $this->propor();
        $logsAntes = AuditLog::count();

        $this->logarComo('admin', 1);

        $this->igualAoIdInexistente('postJson', '/agente/revisar?previa=1', ['valores' => []], 'proposta', $proposta);
        $this->igualAoIdInexistente('postJson', '/agente/confirmar', ['valores' => []], 'proposta', $proposta);
        $this->igualAoIdInexistente('postJson', '/agente/confirmar', [], 'token', $proposta); // rascunho no caminho {token}
        $this->igualAoIdInexistente('postJson', '/agente/cancelar', [], 'proposta', $proposta);
        $this->assertSame(0, Lancamento::count());
        $this->assertSame($logsAntes, AuditLog::count());

        $this->actingAs($dono);
        $this->postJson('/agente/confirmar', ['proposta' => $proposta])->assertOk();
        $this->assertSame(1, Lancamento::count());
    }

    public function test_mesmo_usuario_continua_funcionando_na_previa_e_no_passo_unico(): void
    {
        $this->logarComo('admin', 1);
        $proposta = $this->propor();

        $this->postJson('/agente/revisar?previa=1', ['proposta' => $proposta, 'valores' => []])->assertOk()->assertJsonPath('tipo', 'previa');
        $this->postJson('/agente/confirmar', ['proposta' => $proposta])->assertOk();

        $this->assertSame(1, Lancamento::count());
    }

    public function test_usuario_movido_de_tenant_no_meio_da_sessao_e_recusado(): void
    {
        $dono = $this->logarComo('admin', 1);
        $proposta = $this->propor();
        $logsAntes = AuditLog::count();

        $dono->forceFill(['tenant_id' => 2])->save(); // mesmo usuário, outro tenant

        $this->igualAoIdInexistente('postJson', '/agente/confirmar', ['valores' => []], 'proposta', $proposta);
        $this->igualAoIdInexistente('postJson', '/agente/revisar?previa=1', ['valores' => []], 'proposta', $proposta);
        $this->assertSame(0, Lancamento::count());
        $this->assertSame($logsAntes, AuditLog::count());

        $dono->forceFill(['tenant_id' => 1])->save(); // de volta: o rascunho continua vivo
        $this->postJson('/agente/confirmar', ['proposta' => $proposta])->assertOk();
        $this->assertSame(1, Lancamento::count());
    }

    public function test_rascunho_sem_dono_gravado_falha_fechado(): void
    {
        $this->logarComo('admin', 1);
        $proposta = $this->propor();
        $logsAntes = AuditLog::count();

        $rascunho = session("confirmacoes.{$proposta}");
        unset($rascunho['dono_user_id'], $rascunho['dono_tenant_id']); // legado: montado sem dono
        session(["confirmacoes.{$proposta}" => $rascunho]);

        $this->igualAoIdInexistente('postJson', '/agente/confirmar', ['valores' => []], 'proposta', $proposta);
        $this->igualAoIdInexistente('postJson', '/agente/revisar?previa=1', ['valores' => []], 'proposta', $proposta);
        $this->assertSame(0, Lancamento::count());
        $this->assertSame($logsAntes, AuditLog::count());
        $this->assertNotNull(Auth::user());
    }
}
