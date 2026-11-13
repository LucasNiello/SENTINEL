<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\Fornecedor;
use App\Models\Lancamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * P-C1: memória da última lista. "Edite o segundo" vira a posição 2 da última tabela mostrada; o id sai da tabela
 * congelada na sessão (deste usuário, neste tenant, há menos de 30 min), nunca da IA.
 */
class MemoriaListaTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAgente();
        $this->logarComo('operador', 1);
        foreach (['Alfa Ltda', 'Beta Comércio', 'Gama Serviços'] as $nome) {
            Cliente::create(['nome' => $nome, 'tenant_id' => 1]);
        }
    }

    /** Mostra a tabela de clientes e devolve os ids na ordem em que o usuário viu. */
    private function listarClientes(): array
    {
        $ids = array_column($this->comando('consultar_clientes')->assertOk()->assertJsonMissingPath('lista')->json('resultado'), 'id');
        $this->assertCount(3, $ids);

        return $ids;
    }

    public function test_o_segundo_vira_o_id_da_segunda_linha_da_tabela_mostrada(): void
    {
        $ids = $this->listarClientes();

        $this->comando('atualizar_cliente', ['posicao' => 2, 'telefone' => '19 3441-2200'])
            ->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente')->assertJsonPath('argumentos.id', $ids[1])
            ->assertJsonPath('argumentos.telefone', '19 3441-2200');

        // O que foi para a IA no 2º pedido: a frase com a quantidade, sem id nem nome das linhas.
        $corpo = (string) Http::recorded()->last()[0]->body();
        $this->assertStringContainsString('Mostrei ao usuário uma tabela com 3 linhas (consultar_clientes)', json_decode($corpo, true)['messages'][2]['content'] ?? '');
        foreach (['Alfa Ltda', 'Beta Comércio', 'Gama Serviços'] as $nome) {
            $this->assertStringNotContainsString($nome, $corpo);
        }
    }

    public function test_a_ultima_e_o_id_da_ia_junto_com_a_posicao_e_ignorado(): void
    {
        $ids = $this->listarClientes();
        $deOutroTenant = Cliente::create(['nome' => 'Outro', 'tenant_id' => 2]);

        $this->comando('atualizar_cliente', ['posicao' => -1, 'telefone' => '1'])->assertOk()->assertJsonPath('argumentos.id', $ids[2]);
        $this->comando('atualizar_cliente', ['posicao' => 1, 'id' => $deOutroTenant->id, 'busca' => 'Gama', 'telefone' => '1'])
            ->assertOk()->assertJsonPath('argumentos.id', $ids[0]);
    }

    public function test_marca_o_terceiro_como_conciliado_ate_confirmar(): void
    {
        foreach (['Luz', 'Água', 'Tarifa'] as $i => $descricao) {
            Lancamento::create(['descricao' => $descricao, 'valor' => 10 + $i, 'status' => 'pendente', 'data' => '2026-11-0'.($i + 1), 'tenant_id' => 1]);
        }
        $ids = array_column($this->comando('consultar_lancamentos')->assertOk()->json('resultado'), 'id');

        $token = $this->propor(['posicao' => 3, 'novo_status' => 'conciliado'], 'atualizar_status_lancamento');
        $this->confirmar($token)->assertOk();

        $this->assertSame('conciliado', Lancamento::find($ids[2])->status);
        $this->assertSame(2, Lancamento::where('status', 'pendente')->count());
        $log = AuditLog::query()->where('tool', 'atualizar_status_lancamento')->where('resultado', 'sucesso')->sole();
        $this->assertSame($ids[2], (int) $log->entidade_id);
        $this->assertSame(3, $log->parametros_propostos['posicao'] ?? null, 'a auditoria mostra o que a IA pediu');
    }

    public function test_tabela_de_outra_entidade_nao_vale(): void
    {
        Fornecedor::create(['nome' => 'Papelaria', 'tenant_id' => 1]);
        $this->comando('consultar_fornecedores')->assertOk();

        $this->comando('atualizar_cliente', ['posicao' => 1, 'telefone' => '1'])
            ->assertStatus(422)->assertJsonPath('codigo', 'AI_TOOL_REFUSED')->assertJsonPath('mensagem', fn ($m) => str_contains($m, 'outro tipo de registro'));
        $this->assertEmpty(session('confirmacoes', []));
    }

    public function test_posicao_fora_da_tabela_sem_tabela_ou_tabela_vencida_e_recusada(): void
    {
        $this->comando('atualizar_cliente', ['posicao' => 1, 'telefone' => '1'])
            ->assertStatus(422)->assertJsonPath('mensagem', fn ($m) => str_contains($m, 'Não tenho uma tabela recente'));

        $this->listarClientes();
        foreach ([4, 0, -2] as $posicao) {
            $this->comando('atualizar_cliente', ['posicao' => $posicao, 'telefone' => '1'])
                ->assertStatus(422)->assertJsonPath('mensagem', fn ($m) => str_contains($m, 'A última tabela tem 3 linhas'));
        }

        $this->travel(31)->minutes();
        $this->comando('atualizar_cliente', ['posicao' => 1, 'telefone' => '1'])
            ->assertStatus(422)->assertJsonPath('mensagem', fn ($m) => str_contains($m, 'Não tenho uma tabela recente'));
        $this->assertEmpty(session('confirmacoes', []));
    }

    public function test_tabela_de_outro_usuario_ou_de_outro_tenant_nao_vale(): void
    {
        $deOutroTenant = Cliente::create(['nome' => 'Outro', 'tenant_id' => 2]);

        // Lista guardada por outra identidade (outro usuário/tenant na mesma sessão): some.
        $this->withSession(['agente_ultima_lista' => ['identidade' => '999:2', 'em' => now()->getTimestamp(), 'entidade' => 'Cliente', 'linhas' => [$deOutroTenant->id]]]);
        $this->comando('atualizar_cliente', ['posicao' => 1, 'telefone' => '1'])
            ->assertStatus(422)->assertJsonPath('mensagem', fn ($m) => str_contains($m, 'Não tenho uma tabela recente'));

        // Mesmo com a identidade certa, um id de outro tenant na lista não é achado (o localizar é do tenant).
        $this->withSession(['agente_ultima_lista' => ['identidade' => auth()->id().':1', 'em' => now()->getTimestamp(), 'entidade' => 'Cliente', 'linhas' => [$deOutroTenant->id]]]);
        $this->comando('atualizar_cliente', ['posicao' => 1, 'telefone' => '1'])
            ->assertStatus(422)->assertJsonPath('mensagem', fn ($m) => str_contains($m, 'Não encontrei'));
        $this->assertNull($deOutroTenant->fresh()->telefone);
        $this->assertEmpty(session('confirmacoes', []));
    }

    public function test_sem_permissao_nega_antes_de_olhar_a_tabela(): void
    {
        $this->listarClientes();
        $this->logarComo('leitura', 1);

        $this->comando('atualizar_cliente', ['posicao' => 1, 'telefone' => '1'])->assertStatus(403)->assertJsonPath('tipo', 'negado');
    }

    public function test_posicao_que_nao_e_inteiro_e_recusada(): void
    {
        $this->listarClientes();

        foreach (['2', 'segundo', [2]] as $posicao) {
            $this->comando('atualizar_cliente', ['posicao' => $posicao, 'telefone' => '1'])
                ->assertStatus(422)->assertJsonPath('mensagem', fn ($m) => str_contains($m, 'essa posição não existe'));
        }
    }

    public function test_a_lista_de_varios_encontrados_tambem_vale_para_a_posicao(): void
    {
        Cliente::create(['nome' => 'Beta Filial', 'tenant_id' => 1]);

        $ids = array_column($this->comando('atualizar_cliente', ['busca' => 'Beta', 'telefone' => '1'])
            ->assertOk()->assertJsonPath('tipo', 'resultado_leitura')->json('resultado'), 'id');
        $this->assertCount(2, $ids);

        $this->comando('atualizar_cliente', ['posicao' => 2, 'telefone' => '2'])->assertOk()->assertJsonPath('argumentos.id', $ids[1]);
    }

    public function test_pela_anthropic_a_posicao_resolve_igual(): void
    {
        $ids = $this->listarClientes();
        config(['services.ia_provedor' => 'anthropic', 'services.anthropic' => ['api_key' => 'x', 'model' => 'claude-haiku-5-5', 'versao' => '2026-10-21', 'max_tokens' => 8000, 'effort' => 'low']]);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'message', 'stop_reason' => 'tool_use', 'content' => [
            ['type' => 'tool_use', 'id' => 't', 'name' => 'atualizar_cliente', 'input' => ['posicao' => 2, 'telefone' => '1']],
        ]])]);

        $this->postJson('/agente/comando', ['mensagem' => 'edita o segundo'])->assertOk()->assertJsonPath('argumentos.id', $ids[1]);
        Http::assertSent(fn (Request $r) => str_contains($r['system'], 'Você é o assistente do Sentinel')
            && collect($r['messages'])->contains(fn ($m) => str_contains($m['content'], 'Mostrei ao usuário uma tabela com 3 linhas')));
    }
}
