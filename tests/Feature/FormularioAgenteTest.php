<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\Funcionario;
use App\Models\Lancamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Bloco 2 (Fase 4, Tarefa 1) — RF04: a proposta de escrita chega como formulário
 * pré-preenchido (o próprio card de confirmação), o humano edita e confirma, e a
 * governança é reaplicada sobre os valores editados. Foundry sempre simulado.
 */
class FormularioAgenteTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    private const FUNCIONARIO = ['nome' => 'Ana Souza', 'cpf' => '111.222.333-44', 'salario' => 4567.89, 'data_admissao' => '2026-01-10'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararAgente();
        $this->logarComo('operador', 1);
    }

    private function confirmarComValores(string $token, array $valores, array $corpoExtra = [])
    {
        $this->travel(2)->seconds();

        return $this->postJson('/agente/confirmar', ['token' => $token, 'valores' => $valores] + $corpoExtra);
    }

    /** @return \Illuminate\Support\Collection<string, array> */
    private function campos($resposta, string $caminho = 'formulario.campos')
    {
        return collect($resposta->json($caminho))->keyBy('nome');
    }

    // ---- o formulário ----

    public function test_proposta_de_cliente_chega_como_formulario_preenchido_com_o_documento_mascarado(): void
    {
        $resposta = $this->comando('criar_cliente', ['nome' => 'João da Silva', 'documento' => '123.456.789-01'])
            ->assertOk()
            ->assertJsonPath('tipo', 'confirmacao_pendente')
            ->assertJsonPath('formulario.titulo', 'Cadastrar cliente');

        $campos = $this->campos($resposta);
        $this->assertSame(['nome', 'documento', 'email', 'telefone'], $campos->keys()->all(), 'na ordem do Form Request e sem tenant_id');

        $this->assertSame('Nome', $campos['nome']['rotulo']);
        $this->assertSame('texto', $campos['nome']['tipo']);
        $this->assertTrue($campos['nome']['obrigatorio']);
        $this->assertEquals(255, $campos['nome']['max']);
        $this->assertSame('João da Silva', $campos['nome']['valor']);
        $this->assertFalse($campos['nome']['faltando']);

        $this->assertTrue($campos['documento']['sensivel']);
        $this->assertSame('***.***.789-01', $campos['documento']['valor']);
        $this->assertSame('email', $campos['email']['tipo']);
        $this->assertFalse($campos['email']['obrigatorio']);
        $this->assertNull($campos['email']['valor']);

        // LGPD: o front só vê a máscara; a sessão guarda o valor cru para a confirmação.
        $this->assertSame('***.***.789-01', $resposta->json('argumentos.documento'));
        $this->assertStringNotContainsString('123.456.789', $resposta->getContent());
        $this->assertSame('123.456.789-01', session('confirmacoes.'.$resposta->json('token').'.argumentos.documento'));
    }

    public function test_campos_obrigatorios_que_faltam_vem_marcados(): void
    {
        $this->logarComo('admin', 1);

        $campos = $this->campos($this->comando('criar_funcionario', ['nome' => 'Ana'])->assertOk());

        $this->assertFalse($campos['nome']['faltando']);
        foreach (['cpf', 'salario', 'data_admissao'] as $obrigatorio) {
            $this->assertTrue($campos[$obrigatorio]['faltando'], $obrigatorio);
        }
        $this->assertFalse($campos['cargo']['faltando'], 'opcional vazio não falta');
        $this->assertFalse($campos['data_demissao']['faltando']);

        $this->assertTrue($campos['salario']['sensivel']);
        $this->assertSame('numero', $campos['salario']['tipo']);
        $this->assertEquals(0, $campos['salario']['min']);
        $this->assertSame('data', $campos['data_admissao']['tipo']);
        $this->assertSame('Data de admissão', $campos['data_admissao']['rotulo']);
    }

    public function test_nota_fiscal_traz_opcoes_vinculos_so_do_tenant_e_condicoes(): void
    {
        $mercado = Cliente::create(['nome' => 'Mercado Central', 'tenant_id' => 1]);
        Cliente::create(['nome' => 'Na lixeira', 'tenant_id' => 1])->delete();
        Cliente::create(['nome' => 'De outro tenant', 'tenant_id' => 2]);

        $campos = $this->campos($this->comando('criar_nota_fiscal', [
            'numero' => 'NF-1', 'tipo' => 'saida', 'valor' => 100, 'data_emissao' => '2026-10-01',
        ])->assertOk());

        $this->assertSame([['valor' => 'entrada', 'rotulo' => 'Entrada'], ['valor' => 'saida', 'rotulo' => 'Saída']], $campos['tipo']['opcoes']);

        $this->assertSame('referencia', $campos['cliente_id']['tipo']);
        $this->assertSame([['valor' => $mercado->id, 'rotulo' => "#{$mercado->id} — Mercado Central"]], $campos['cliente_id']['opcoes']);
        $this->assertContains(['campo' => 'tipo', 'valor' => 'saida', 'efeito' => 'obrigatorio'], $campos['cliente_id']['condicoes']);
        $this->assertContains(['campo' => 'tipo', 'valor' => 'entrada', 'efeito' => 'proibido'], $campos['cliente_id']['condicoes']);
        $this->assertTrue($campos['cliente_id']['faltando'], 'nota de saída sem cliente');
        $this->assertFalse($campos['fornecedor_id']['faltando'], 'fornecedor é proibido na saída, não falta');
    }

    public function test_as_sete_escritas_trazem_formulario_sem_tenant(): void
    {
        $this->logarComo('admin', 1);
        $lancamento = Lancamento::create(['descricao' => 'L', 'valor' => 1, 'data' => '2026-10-01', 'status' => 'pendente', 'tenant_id' => 1]);

        $escritas = [
            'criar_lancamento' => ['descricao' => 'Café'],
            'atualizar_status_lancamento' => ['id' => $lancamento->id, 'novo_status' => 'conciliado'],
            'criar_cliente' => ['nome' => 'C'],
            'criar_fornecedor' => ['nome' => 'F'],
            'criar_funcionario' => ['nome' => 'Fu'],
            'criar_nota_fiscal' => ['numero' => 'N'],
            'criar_categoria_lancamento' => ['nome' => 'Cat'],
        ];

        foreach ($escritas as $tool => $argumentos) {
            $resposta = $this->comando($tool, $argumentos)->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente');

            $this->assertNotSame('', (string) $resposta->json('formulario.titulo'), $tool);
            $this->assertNotContains('tenant_id', $this->campos($resposta)->keys()->all(), $tool);
            $this->assertStringNotContainsString($tool, (string) $resposta->json('formulario.titulo'), 'título em português, não o nome interno');
        }

        $status = $this->campos($this->comando('atualizar_status_lancamento', ['id' => $lancamento->id, 'novo_status' => 'conciliado']));
        $this->assertSame(['pendente', 'conciliado'], array_column($status['novo_status']['opcoes'], 'valor'));
    }

    // ---- confirmar com valores editados ----

    public function test_editar_um_campo_e_confirmar_grava_o_editado_e_audita_proposto_e_confirmado(): void
    {
        $token = $this->propor(['nome' => 'João'], 'criar_cliente');

        $this->confirmarComValores($token, ['nome' => 'João da Silva Jr.', 'email' => 'joao@exemplo.com', 'documento' => '', 'telefone' => ''])
            ->assertOk()
            ->assertJsonPath('tipo', 'resultado_escrita')
            ->assertJsonPath('mensagem', 'Cliente cadastrado. Ficou sem documento e telefone.');

        $cliente = Cliente::query()->sole();
        $this->assertSame('João da Silva Jr.', $cliente->nome);
        $this->assertSame('joao@exemplo.com', $cliente->email);

        $log = AuditLog::query()->sole();
        $this->assertSame('sucesso', $log->resultado);
        $this->assertSame('João da Silva Jr.', $log->parametros['nome'], 'confirmado');
        $this->assertSame(['nome' => 'João'], $log->parametros_propostos, 'proposto pela IA');
    }

    public function test_campo_sensivel_vazio_mantem_o_da_proposta_e_preenchido_substitui(): void
    {
        $this->logarComo('admin', 1);

        $token = $this->propor(self::FUNCIONARIO, 'criar_funcionario');
        $this->confirmarComValores($token, ['nome' => 'Ana Souza', 'cpf' => '', 'salario' => ''])->assertOk();

        $token = $this->propor(self::FUNCIONARIO, 'criar_funcionario');
        $this->confirmarComValores($token, ['cpf' => '999.888.777-66', 'salario' => '5000'])->assertOk();

        [$mantido, $trocado] = Funcionario::query()->orderBy('id')->get()->all();
        $this->assertSame('111.222.333-44', $mantido->cpf);
        $this->assertEquals(4567.89, $mantido->salario);
        $this->assertSame('999.888.777-66', $trocado->cpf);
        $this->assertEquals(5000, $trocado->salario);

        $log = AuditLog::query()->latest('id')->first();
        $this->assertSame('***.***.777-66', $log->parametros['cpf']);
        $this->assertSame('[redigido]', $log->parametros['salario']);
        $this->assertSame('***.***.333-44', $log->parametros_propostos['cpf']);
        $this->assertSame('[redigido]', $log->parametros_propostos['salario']);
    }

    public function test_campos_fora_do_formulario_e_tool_do_corpo_sao_descartados(): void
    {
        $token = $this->propor(['nome' => 'Loja'], 'criar_cliente');

        $this->confirmarComValores($token, [
            'nome' => 'Loja Nova',
            'tenant_id' => 99,
            'papel' => 'admin',
            'salario' => 1,
            'campo_inventado' => 'x',
        ], ['tool' => 'criar_funcionario', 'argumentos' => ['nome' => 'Forjado']])->assertOk();

        $cliente = Cliente::query()->sole();
        $this->assertSame('Loja Nova', $cliente->nome);
        $this->assertSame(1, $cliente->tenant_id, 'tenant vem do usuário logado');
        $this->assertSame(0, Funcionario::count(), 'a tool vem da sessão, nunca do corpo');

        $parametros = AuditLog::query()->sole()->parametros;
        $this->assertSame(['nome', 'tenant_id'], array_keys($parametros));
        $this->assertSame(1, $parametros['tenant_id']);
    }

    public function test_rbac_e_reaplicado_na_confirmacao_com_valores(): void
    {
        $token = $this->propor(['nome' => 'Loja'], 'criar_cliente');
        $this->logarComo('leitura', 1); // papel rebaixado entre propor e confirmar

        $this->confirmarComValores($token, ['nome' => 'Loja'])
            ->assertStatus(403)
            ->assertJsonPath('tipo', 'negado');

        $this->assertSame(0, Cliente::count());
        $log = AuditLog::query()->sole();
        $this->assertSame('negado', $log->resultado);
        $this->assertSame(['nome' => 'Loja'], $log->parametros_propostos);
    }

    public function test_validacao_falha_devolve_o_formulario_com_frase_por_campo_e_token_novo(): void
    {
        $token = $this->propor(['nome' => 'Loja'], 'criar_cliente');

        $resposta = $this->confirmarComValores($token, ['nome' => '', 'email' => 'nao-e-email'])
            ->assertStatus(422)
            ->assertJsonPath('tipo', 'erro')
            ->assertJsonPath('codigo', 'AI_TOOL_REFUSED')
            ->assertJsonPath('erros.nome', 'Informe o nome.')
            ->assertJsonPath('erros.email', 'O e-mail informado não é válido.')
            ->assertJsonPath('risco', 'escrita');

        $campos = $this->campos($resposta);
        $this->assertSame('Informe o nome.', $campos['nome']['erro']);
        $this->assertSame('nao-e-email', $campos['email']['valor'], 'o formulário volta com o que o humano digitou');
        $novo = $resposta->json('token');
        $this->assertNotSame($token, $novo);

        $this->postJson('/agente/confirmar', ['token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Confirmação inválida ou já utilizada.');

        $this->confirmarComValores($novo, ['nome' => 'Loja Certa', 'email' => 'loja@exemplo.com'])->assertOk();

        $this->assertSame('Loja Certa', Cliente::query()->sole()->nome);
        $this->assertSame(['recusado', 'sucesso'], AuditLog::query()->orderBy('id')->pluck('resultado')->all());
        $this->assertSame(['nome' => 'Loja'], AuditLog::query()->latest('id')->first()->parametros_propostos, 'o proposto continua sendo o da IA');
    }

    public function test_confirmacao_sem_valores_mantem_o_contrato_antigo(): void
    {
        $token = $this->propor(['nome' => str_repeat('a', 300)], 'criar_cliente');
        $this->travel(2)->seconds();

        $this->postJson('/agente/confirmar', ['token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'O nome aceita no máximo 255 caracteres.')
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('formulario');
    }

    public function test_valores_em_formato_errado_sao_recusados_sem_queimar_o_token(): void
    {
        $token = $this->propor(['nome' => 'Loja'], 'criar_cliente');
        $this->travel(2)->seconds();

        $this->postJson('/agente/confirmar', ['token' => $token, 'valores' => 'nome=X'])
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'AI_VALIDATION_ERROR')
            ->assertJsonPath('http_status', 422);

        $this->postJson('/agente/confirmar', ['token' => $token, 'valores' => ['nome' => 'Loja']])->assertOk();
    }

    public function test_desfecho_depois_de_token_reemitido_audita_o_proposto_da_ia_e_nao_a_edicao(): void
    {
        // Cancelado depois de um erro de validação.
        $token = $this->propor(['nome' => 'Loja'], 'criar_cliente');
        $novo = $this->confirmarComValores($token, ['nome' => '', 'email' => 'nao-e-email'])->assertStatus(422)->json('token');
        $this->travel(1)->seconds();
        $this->postJson('/agente/cancelar', ['token' => $novo])->assertOk();

        // Esquecido depois de um erro de validação (a varredura audita como expirado).
        $token = $this->propor(['nome' => 'Outra'], 'criar_cliente');
        $this->confirmarComValores($token, ['nome' => ''])->assertStatus(422);
        $this->travel(13)->seconds();
        $this->get('/agente')->assertOk();

        $desfechos = AuditLog::query()->whereIn('resultado', ['cancelado', 'expirado'])->orderBy('id')->get();
        $this->assertSame(['cancelado', 'expirado'], $desfechos->pluck('resultado')->all());
        $this->assertSame(['nome' => 'Loja'], $desfechos[0]->parametros_propostos);
        $this->assertSame(['nome' => 'Outra'], $desfechos[1]->parametros_propostos);
    }

    public function test_vinculo_proposto_entra_na_lista_mesmo_fora_dos_50_mais_recentes(): void
    {
        $antigo = Cliente::create(['nome' => 'Cliente antigo', 'tenant_id' => 1]);
        for ($i = 0; $i < 55; $i++) {
            Cliente::create(['nome' => "Cliente {$i}", 'tenant_id' => 1]);
        }

        $campos = $this->campos($this->comando('criar_nota_fiscal', [
            'numero' => 'NF-1', 'tipo' => 'saida', 'valor' => 10, 'data_emissao' => '2026-10-01', 'cliente_id' => $antigo->id,
        ])->assertOk());

        $this->assertContains($antigo->id, array_column($campos['cliente_id']['opcoes'], 'valor'));
        $this->assertCount(51, $campos['cliente_id']['opcoes'], 'os 50 mais recentes + o proposto');
        $this->assertArrayNotHasKey('erro', $campos['cliente_id']);
    }

    public function test_vinculo_proposto_inexistente_na_lixeira_ou_de_outro_tenant_avisa_no_campo(): void
    {
        $lixeira = Cliente::create(['nome' => 'Na lixeira', 'tenant_id' => 1]);
        $lixeira->delete();
        $alheio = Cliente::create(['nome' => 'Alheio', 'tenant_id' => 2]);

        foreach ([99999, $lixeira->id, $alheio->id] as $id) {
            $campos = $this->campos($this->comando('criar_nota_fiscal', [
                'numero' => 'NF-1', 'tipo' => 'saida', 'valor' => 10, 'data_emissao' => '2026-10-01', 'cliente_id' => $id,
            ])->assertOk());

            $this->assertSame('Não encontrei o cliente informado.', $campos['cliente_id']['erro'], (string) $id);
            $this->assertNotContains($id, array_column($campos['cliente_id']['opcoes'], 'valor'));
        }
    }

    public function test_status_do_lancamento_tambem_valida_pelas_regras_do_formulario(): void
    {
        $lancamento = Lancamento::create(['descricao' => 'L', 'valor' => 1, 'data' => '2026-10-01', 'status' => 'pendente', 'tenant_id' => 1]);
        $primeiro = Lancamento::query()->orderBy('id')->first();

        $token = $this->propor(['id' => $lancamento->id, 'novo_status' => 'conciliado'], 'atualizar_status_lancamento');
        $resposta = $this->confirmarComValores($token, ['id' => ''])
            ->assertStatus(422)
            ->assertJsonPath('erros.id', 'Informe qual lançamento deve ser atualizado.');
        $this->assertNotNull($resposta->json('token'), 'o formulário volta com token novo');
        $this->assertNotNull($resposta->json('formulario'));

        // Corpo forjado: um array ou um booleano no id não pode virar o lançamento #1.
        $this->travel(1)->seconds();
        $outro = $this->confirmarComValores($resposta->json('token'), ['id' => ['x']])->assertStatus(422)->json('token');
        $this->travel(1)->seconds();
        $this->confirmarComValores($outro, ['id' => true])->assertStatus(422);

        $this->assertSame('pendente', $primeiro->fresh()->status);
        $this->assertSame('pendente', $lancamento->fresh()->status);
    }

    public function test_salario_aparece_como_informado_sem_valor_e_formato_errado_vira_erro_visivel(): void
    {
        $this->logarComo('admin', 1);

        $resposta = $this->comando('criar_funcionario', self::FUNCIONARIO)->assertOk();
        $campos = $this->campos($resposta);
        $this->assertTrue($campos['salario']['informado']);
        $this->assertNull($campos['salario']['valor'], 'a máscara do salário esconde tudo: nada para mostrar');
        $this->assertTrue($campos['cpf']['informado']);
        $this->assertSame('***.***.333-44', $campos['cpf']['valor']);

        // "4.567,89" digitado no card não some calado: volta como erro do campo.
        $this->confirmarComValores($resposta->json('token'), ['salario' => '4.567,89'])
            ->assertStatus(422)
            ->assertJsonPath('erros.salario', 'O salário deve ser um número.');
        $this->assertSame(0, Funcionario::count());
    }

    // ---- depois de gravar ----

    public function test_frase_diz_de_forma_deterministica_o_que_ficou_vazio(): void
    {
        $this->logarComo('admin', 1);
        $cliente = Cliente::create(['nome' => 'Mercado', 'tenant_id' => 1]);
        $lancamento = Lancamento::create(['descricao' => 'L', 'valor' => 1, 'data' => '2026-10-01', 'status' => 'pendente', 'tenant_id' => 1]);

        $casos = [
            ['criar_cliente', ['nome' => 'A', 'documento' => '12.345.678/0001-90', 'email' => 'a@exemplo.com', 'telefone' => '1'], 'Cliente cadastrado com todos os dados.'],
            ['criar_cliente', ['nome' => 'B'], 'Cliente cadastrado. Ficou sem documento, e-mail e telefone.'],
            ['criar_categoria_lancamento', ['nome' => 'Vendas', 'tipo' => 'receita'], 'Categoria cadastrada.'],
            ['criar_nota_fiscal', ['numero' => 'NF-1', 'tipo' => 'saida', 'valor' => 10, 'data_emissao' => '2026-10-01', 'cliente_id' => $cliente->id], 'Nota fiscal cadastrada. Ficou sem lançamento.'],
            ['criar_lancamento', ['descricao' => 'Café', 'valor' => 5, 'data' => '2026-10-01'], 'Lançamento criado.'],
            ['atualizar_status_lancamento', ['id' => $lancamento->id, 'novo_status' => 'conciliado'], 'Status do lançamento atualizado.'],
            ['criar_funcionario', self::FUNCIONARIO, 'Funcionário cadastrado. Ficou sem cargo e data de demissão.'],
        ];

        foreach ($casos as [$tool, $argumentos, $frase]) {
            $this->confirmar($this->propor($argumentos, $tool))->assertOk()->assertJsonPath('mensagem', $frase);
        }
    }

    public function test_escrita_confirmada_entra_no_historico_so_como_frase_e_o_agente_recebe_depois(): void
    {
        $this->logarComo('admin', 1);
        $this->confirmar($this->propor(self::FUNCIONARIO, 'criar_funcionario'))->assertOk();

        $mensagens = session('agente_conversa.mensagens');
        $this->assertSame(['role' => 'user', 'content' => 'Confirmei a proposta no card.'], $mensagens[count($mensagens) - 2]);
        $this->assertSame(['role' => 'assistant', 'content' => 'Funcionário cadastrado. Ficou sem cargo e data de demissão.'], end($mensagens));

        $historico = json_encode(session('agente_conversa'));
        $this->assertStringNotContainsString('111.222', $historico);
        $this->assertStringNotContainsString('4567', $historico);

        $this->comando('consultar_funcionarios')->assertOk();
        $enviadas = array_column(Http::recorded()[count(Http::recorded()) - 1][0]['messages'], 'content');
        $this->assertContains('Funcionário cadastrado. Ficou sem cargo e data de demissão.', $enviadas);
    }

    public function test_resultado_da_escrita_volta_ao_front_mascarado(): void
    {
        $this->logarComo('admin', 1);

        $resposta = $this->confirmar($this->propor(self::FUNCIONARIO, 'criar_funcionario'))->assertOk();

        $this->assertSame('***.***.333-44', $resposta->json('resultado.cpf'));
        $this->assertSame('[redigido]', $resposta->json('resultado.salario'));
        $this->assertStringNotContainsString('111.222', $resposta->getContent());
        $this->assertStringNotContainsString('4567', $resposta->getContent());
    }

    public function test_tela_monta_o_card_como_formulario_sem_json_nem_nome_de_tool(): void
    {
        // Só confere o que a tela entrega; o comportamento no navegador fica para o teste manual.
        $this->get('/agente')
            ->assertOk()
            ->assertSee('function montarCampo(campo, prefixo)', false)
            ->assertSee('valores: coletarValores(form, formulario.campos)', false)
            ->assertSee('.campo[hidden] { display: none; }', false)
            ->assertSee("entrada.inputMode = campo.inteiro ? 'numeric' : 'decimal';", false)
            ->assertSee('Informado (oculto por segurança). Deixe em branco para manter.')
            ->assertSee('Só números, com ponto nos centavos (ex.: 4567.89).')
            ->assertSee("campo.erro ? 'Digite o valor de novo.'", false)
            ->assertDontSee('card-argumentos', false)
            ->assertDontSee('JSON.stringify(argumentos', false);
    }
}
