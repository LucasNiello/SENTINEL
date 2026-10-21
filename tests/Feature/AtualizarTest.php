<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CategoriaLancamento;
use App\Models\Cliente;
use App\Models\Funcionario;
use App\Models\NotaFiscal;
use App\Services\ClienteService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Bloco 3 — o "U" do CRUD: atualizar_* pelo agente (registro achado por id ou
 * nome no SERVIDOR, dentro do tenant) e o formulário aberto sem IA
 * (/agente/formulario, "Editar" numa linha de tabela). Foundry sempre simulado.
 */
class AtualizarTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararAgente();
        $this->logarComo('operador', 1);
    }

    private function cliente(string $nome, int $tenant = 1, array $extra = []): Cliente
    {
        return Cliente::create(['nome' => $nome, 'tenant_id' => $tenant, ...$extra]);
    }

    /** @return \Illuminate\Support\Collection<string, array> */
    private function campos($resposta)
    {
        return collect($resposta->json('formulario.campos'))->keyBy('nome');
    }

    // ---- pelo agente ----

    public function test_atualizar_por_nome_traz_os_valores_atuais_mais_a_mudanca_da_ia_e_grava(): void
    {
        $cliente = $this->cliente('Mercado Central', 1, ['documento' => '123.456.789-01', 'email' => 'velho@exemplo.com', 'telefone' => '1999']);

        $resposta = $this->comando('atualizar_cliente', ['busca' => 'mercado', 'email' => 'novo@exemplo.com'])
            ->assertOk()
            ->assertJsonPath('tipo', 'confirmacao_pendente')
            ->assertJsonPath('formulario.titulo', "Alterar cliente #{$cliente->id}");

        $campos = $this->campos($resposta);
        $this->assertSame('Mercado Central', $campos['nome']['valor'], 'valor atual');
        $this->assertSame('novo@exemplo.com', $campos['email']['valor'], 'o que a IA mudou');
        $this->assertSame('***.***.789-01', $campos['documento']['valor'], 'sensível continua mascarado');
        $this->assertArrayNotHasKey('id', $campos->all(), 'o id não é campo do formulário');

        $this->confirmar($this->propor(['busca' => 'mercado', 'email' => 'novo@exemplo.com'], 'atualizar_cliente'))
            ->assertOk()
            ->assertJsonPath('mensagem', 'Cliente atualizado com todos os dados.');

        $cliente->refresh();
        $this->assertSame('novo@exemplo.com', $cliente->email);
        $this->assertSame('Mercado Central', $cliente->nome);
        $this->assertSame('123.456.789-01', $cliente->documento, 'sensível não editado fica como estava');

        $log = AuditLog::query()->where('resultado', 'sucesso')->sole();
        $this->assertSame('atualizar', $log->acao);
        $this->assertSame($cliente->id, $log->entidade_id);
        // O proposto é o que a IA pediu de fato: como achar o registro e só o campo que muda.
        $this->assertSame(['busca' => 'mercado', 'email' => 'novo@exemplo.com'], $log->parametros_propostos);
        $this->assertSame('agente', $log->origem);
        $this->assertSame('novo@exemplo.com', $log->parametros['email'], 'confirmado');
    }

    public function test_atualizar_por_id(): void
    {
        $cliente = $this->cliente('Loja');

        $this->confirmar($this->propor(['id' => $cliente->id, 'nome' => 'Loja Nova'], 'atualizar_cliente'))->assertOk();

        $this->assertSame('Loja Nova', $cliente->fresh()->nome);
    }

    public function test_nome_ambiguo_devolve_a_lista_para_escolher_sem_formulario(): void
    {
        $um = $this->cliente('Mercado Central');
        $outro = $this->cliente('Mercado Norte');

        $this->comando('atualizar_cliente', ['busca' => 'mercado', 'email' => 'x@exemplo.com'])
            ->assertOk()
            ->assertJsonPath('tipo', 'resultado_leitura')
            ->assertJsonPath('editar', 'atualizar_cliente')
            ->assertJsonPath('colunas', ['id', 'nome'])
            ->assertJsonCount(2, 'resultado')
            ->assertJsonMissingPath('formulario')
            ->assertJsonMissingPath('proposta');

        $this->assertEmpty(session('confirmacoes', []));
        $this->assertSame(0, AuditLog::count());
        $this->assertSame([null, null], [$um->fresh()->email, $outro->fresh()->email]);
    }

    public function test_nome_ou_id_inexistente_responde_nao_encontrei(): void
    {
        $this->cliente('Mercado Central');

        $this->comando('atualizar_cliente', ['busca' => 'padaria'])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Não encontrei o cliente com esse nome.');

        $this->comando('atualizar_cliente', ['id' => 99999])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Não encontrei o cliente com esse número.');

        $this->comando('atualizar_cliente', ['email' => 'x@exemplo.com'])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Informe qual cliente deve ser alterado: o número ou o nome.');
    }

    public function test_registro_de_outro_tenant_ou_na_lixeira_responde_igual_a_inexistente(): void
    {
        $alheio = $this->cliente('Alheio', 2);
        $lixeira = $this->cliente('Na lixeira');
        $lixeira->delete();

        foreach ([$alheio->id, $lixeira->id] as $id) {
            $this->comando('atualizar_cliente', ['id' => $id, 'nome' => 'Invasão'])
                ->assertStatus(422)
                ->assertJsonPath('mensagem', 'Não encontrei o cliente com esse número.');
        }
        $this->comando('atualizar_cliente', ['busca' => 'Alheio'])->assertStatus(422);

        $this->assertSame('Alheio', $alheio->fresh()->nome);
        $this->assertSame('Na lixeira', Cliente::withTrashed()->find($lixeira->id)->nome);
    }

    public function test_registro_que_vai_para_a_lixeira_depois_do_revisar_nao_e_alterado(): void
    {
        $cliente = $this->cliente('Loja');
        $token = $this->propor(['id' => $cliente->id, 'nome' => 'Loja Nova'], 'atualizar_cliente');
        $cliente->delete();

        $this->confirmar($token)
            ->assertStatus(422)
            ->assertJsonPath('mensagem', "Não encontrei o cliente #{$cliente->id}.");

        $this->assertSame('Loja', Cliente::withTrashed()->find($cliente->id)->nome);
        $this->assertSame('recusado', AuditLog::query()->sole()->resultado);
    }

    public function test_papel_sem_permissao_e_negado(): void
    {
        $cliente = $this->cliente('Loja');
        Funcionario::create(['nome' => 'Ana', 'cpf' => '111.222.333-44', 'salario' => 1000, 'data_admissao' => '2026-01-10', 'tenant_id' => 1]);

        $this->logarComo('leitura', 1);
        $this->comando('atualizar_cliente', ['id' => $cliente->id, 'nome' => 'X'])->assertStatus(403)->assertJsonPath('tipo', 'negado');

        $this->logarComo('operador', 1);
        $this->comando('atualizar_funcionario', ['busca' => 'Ana', 'cargo' => 'Chefe'])->assertStatus(403);

        $this->assertSame('Loja', $cliente->fresh()->nome);
        $this->assertSame(['negado', 'negado'], AuditLog::query()->pluck('resultado')->all());
    }

    public function test_o_id_nao_pode_ser_trocado_no_formulario(): void
    {
        $alvo = $this->cliente('Alvo');
        $outro = $this->cliente('Outro');
        $this->freezeTime();
        $proposta = $this->comando('atualizar_cliente', ['id' => $alvo->id, 'nome' => 'Alvo Novo'])->json('proposta');

        $token = $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => ['id' => $outro->id, 'nome' => 'Alvo Novo']])
            ->assertOk()->json('token');
        $this->confirmar($token)->assertOk();

        $this->assertSame('Alvo Novo', $alvo->fresh()->nome);
        $this->assertSame('Outro', $outro->fresh()->nome);
    }

    public function test_nota_fiscal_e_achada_pelo_numero(): void
    {
        $cliente = $this->cliente('Mercado');
        $nota = NotaFiscal::create(['numero' => 'NF-77', 'tipo' => 'saida', 'valor' => 10, 'data_emissao' => '2026-10-01', 'cliente_id' => $cliente->id, 'tenant_id' => 1]);

        $campos = $this->campos($this->comando('atualizar_nota_fiscal', ['busca' => 'NF-77', 'valor' => 25])->assertOk());
        $this->assertSame('2026-10-01', $campos['data_emissao']['valor'], 'data no formato do formulário');
        $this->assertEquals(25, $campos['valor']['valor']);

        $this->confirmar($this->propor(['busca' => 'NF-77', 'valor' => 25], 'atualizar_nota_fiscal'))->assertOk();
        $this->assertEquals(25, $nota->fresh()->valor);

        $this->comando('atualizar_nota_fiscal', ['busca' => 'NF-7'])->assertStatus(422); // número é exato
    }

    public function test_funcionario_mantem_cpf_e_salario_quando_ficam_em_branco(): void
    {
        $this->logarComo('admin', 1);
        $funcionario = Funcionario::create(['nome' => 'Ana', 'cpf' => '111.222.333-44', 'salario' => 4567.89, 'data_admissao' => '2026-01-10', 'tenant_id' => 1]);

        $this->freezeTime();
        $proposta = $this->comando('atualizar_funcionario', ['busca' => 'Ana', 'cargo' => 'Chefe'])->json('proposta');
        $token = $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => ['cpf' => '', 'salario' => '', 'cargo' => 'Chefe']])->assertOk()->json('token');
        $this->confirmar($token)->assertOk();

        $funcionario->refresh();
        $this->assertSame('Chefe', $funcionario->cargo);
        $this->assertSame('111.222.333-44', $funcionario->cpf);
        $this->assertEquals(4567.89, $funcionario->salario);
        $this->assertSame('2026-01-10', $funcionario->data_admissao->format('Y-m-d'));
    }

    public function test_alteracao_de_outra_pessoa_depois_de_abrir_o_formulario_nao_e_desfeita(): void
    {
        $cliente = $this->cliente('Loja', 1, ['telefone' => '1111']);
        $token = $this->propor(['id' => $cliente->id, 'email' => 'a@exemplo.com'], 'atualizar_cliente');

        // Outro operador muda o telefone enquanto o formulário está aberto.
        $this->travel(1)->seconds();
        $cliente->update(['telefone' => '2222']);

        $this->confirmar($token)
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Este registro foi alterado por outra pessoa depois que o formulário foi aberto. Nada foi gravado. Abra de novo para ver os dados atuais.');

        $cliente->refresh();
        $this->assertSame('2222', $cliente->telefone, 'a mudança da outra pessoa ficou');
        $this->assertNull($cliente->email);
    }

    public function test_id_zero_nao_vira_busca_por_todos(): void
    {
        $this->cliente('Única');

        $this->comando('atualizar_cliente', ['id' => 0])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Informe qual cliente deve ser alterado: o número ou o nome.');
    }

    public function test_service_atualizar_trata_outro_tenant_e_lixeira_como_inexistente(): void
    {
        $alheio = $this->cliente('Alheio', 2);

        $this->expectException(ModelNotFoundException::class);
        app(ClienteService::class)->atualizar($alheio->id, ['nome' => 'X'], 1);
    }

    // ---- sem IA: /agente/formulario e a linha editável ----

    public function test_formulario_sem_ia_abre_preenchido_e_segue_revisar_e_segurar(): void
    {
        $categoria = CategoriaLancamento::create(['nome' => 'Vendas', 'tipo' => 'receita', 'tenant_id' => 1]);
        Http::fake();
        $this->freezeTime();

        $resposta = $this->postJson('/agente/formulario', ['tool' => 'atualizar_categoria_lancamento', 'id' => $categoria->id])
            ->assertOk()
            ->assertJsonPath('tipo', 'confirmacao_pendente')
            ->assertJsonPath('formulario.titulo', "Alterar categoria de lançamento #{$categoria->id}");
        $this->assertSame('Vendas', $this->campos($resposta)['nome']['valor']);

        $token = $this->postJson('/agente/revisar', ['proposta' => $resposta->json('proposta'), 'valores' => ['nome' => 'Vendas à vista']])
            ->assertOk()->json('token');
        $this->confirmar($token)->assertOk()->assertJsonPath('mensagem', 'Categoria atualizada.');

        $this->assertSame('Vendas à vista', $categoria->fresh()->nome);
        Http::assertNothingSent();

        $log = AuditLog::query()->sole();
        $this->assertSame('formulario', $log->origem);
        $this->assertSame([], $log->parametros_propostos, 'sem IA: nada foi proposto');
    }

    public function test_formulario_sem_ia_aplica_rbac_tenant_e_so_aceita_escrita(): void
    {
        $alheio = $this->cliente('Alheio', 2);

        $this->postJson('/agente/formulario', ['tool' => 'atualizar_cliente', 'id' => $alheio->id])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Não encontrei o cliente com esse número.');
        $this->postJson('/agente/formulario', ['tool' => 'consultar_clientes'])->assertStatus(422);
        $this->postJson('/agente/formulario', ['tool' => 'apagar_tudo'])->assertStatus(422);
        $this->postJson('/agente/formulario', ['tool' => ['x']])->assertStatus(422)->assertJsonPath('codigo', 'AI_VALIDATION_ERROR');

        $this->logarComo('leitura', 1);
        $this->postJson('/agente/formulario', ['tool' => 'atualizar_cliente', 'id' => $this->cliente('Meu')->id])
            ->assertStatus(403)
            ->assertJsonPath('tipo', 'negado');

        $this->assertEmpty(session('confirmacoes', []));
    }

    public function test_formulario_de_cadastro_sem_ia_abre_em_branco(): void
    {
        $resposta = $this->postJson('/agente/formulario', ['tool' => 'criar_cliente'])->assertOk();

        $this->assertNull($this->campos($resposta)['nome']['valor']);
        $this->assertTrue($this->campos($resposta)['nome']['faltando']);
    }

    public function test_consulta_traz_a_tool_de_editar_so_para_quem_pode_atualizar(): void
    {
        $this->cliente('Loja');

        $this->comando('consultar_clientes')->assertOk()->assertJsonPath('editar', 'atualizar_cliente');

        $this->logarComo('leitura', 1);
        $this->comando('consultar_clientes')->assertOk()->assertJsonMissingPath('editar');

        $this->logarComo('operador', 1);
        $this->comando('consultar_funcionarios')->assertOk()->assertJsonMissingPath('editar'); // atualizar_funcionario é só admin
    }

    public function test_tela_tem_o_botao_editar_nas_linhas(): void
    {
        // Só confere o que a tela entrega; o clique no navegador fica para o teste manual.
        $this->get('/agente')
            ->assertOk()
            ->assertSee("fetch('/agente/formulario'", false)
            ->assertSee("botao.textContent = 'Editar';", false)
            ->assertSee('adicionarTabela(dados.resultado || [], dados.colunas, dados.editar);', false);
    }
}
