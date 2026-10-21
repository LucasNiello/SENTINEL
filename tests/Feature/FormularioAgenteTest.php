<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\Funcionario;
use App\Models\Lancamento;
use App\Services\AiAgentService;
use App\Services\AuditoriaService;
use App\Services\ConfirmacoesPendentes;
use App\Services\ContextoUsuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
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

    /** Bloco 2b: o agente propõe e a proposta fica em rascunho (o formulário do card). Devolve o id da proposta. */
    private function rascunho(array $argumentos, string $tool): string
    {
        $this->freezeTime();

        return (string) $this->comando($tool, $argumentos)
            ->assertOk()
            ->assertJsonPath('tipo', 'confirmacao_pendente')
            ->json('proposta');
    }

    private function revisar(string $proposta, mixed $valores, array $corpoExtra = [])
    {
        return $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => $valores] + $corpoExtra);
    }

    /** Revisa com os valores editados e, se a revisão passar, segura (2 s) e confirma com o token dela. */
    private function revisarEConfirmar(string $proposta, array $valores, array $corpoExtra = [])
    {
        $revisao = $this->revisar($proposta, $valores, $corpoExtra);

        if ($revisao->status() !== 200) {
            return $revisao;
        }

        $this->travel(2)->seconds();

        return $this->postJson('/agente/confirmar', ['token' => $revisao->json('token')] + $corpoExtra);
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
        $this->assertSame('123.456.789-01', session('confirmacoes.'.$resposta->json('proposta').'.argumentos.documento'));
        $this->assertNull($resposta->json('token'), 'o token de segurar só sai no Revisar');
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

    // ---- revisar com valores editados (Bloco 2b) e confirmar ----

    public function test_editar_um_campo_e_confirmar_grava_o_editado_e_audita_proposto_e_confirmado(): void
    {
        $proposta = $this->rascunho(['nome' => 'João'], 'criar_cliente');

        $this->revisarEConfirmar($proposta, ['nome' => 'João da Silva Jr.', 'email' => 'joao@exemplo.com', 'documento' => '', 'telefone' => ''])
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
        $this->assertEmpty(session('confirmacoes', []), 'rascunho e token saem juntos no desfecho');
    }

    public function test_campo_sensivel_vazio_mantem_o_da_proposta_e_preenchido_substitui(): void
    {
        $this->logarComo('admin', 1);

        $proposta = $this->rascunho(self::FUNCIONARIO, 'criar_funcionario');
        $this->revisarEConfirmar($proposta, ['nome' => 'Ana Souza', 'cpf' => '', 'salario' => ''])->assertOk();

        $proposta = $this->rascunho(self::FUNCIONARIO, 'criar_funcionario');
        $this->revisarEConfirmar($proposta, ['cpf' => '999.888.777-66', 'salario' => '5000'])->assertOk();

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
        $proposta = $this->rascunho(['nome' => 'Loja'], 'criar_cliente');

        $this->revisarEConfirmar($proposta, [
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

    public function test_rbac_e_reaplicado_no_revisar_e_de_novo_na_confirmacao(): void
    {
        // Papel rebaixado entre propor e revisar: o Revisar nega, e a negação é o desfecho da proposta.
        $proposta = $this->rascunho(['nome' => 'Loja'], 'criar_cliente');
        $this->logarComo('leitura', 1);
        $this->revisar($proposta, ['nome' => 'Loja'])
            ->assertStatus(403)
            ->assertJsonPath('tipo', 'negado');
        $this->assertNull(session("confirmacoes.{$proposta}"), 'proposta negada sai da sessão');

        // Papel rebaixado entre revisar e confirmar: a confirmação nega de novo.
        $this->logarComo('operador', 1);
        $proposta = $this->rascunho(['nome' => 'Loja'], 'criar_cliente');
        $token = $this->revisar($proposta, ['nome' => 'Loja'])->assertOk()->json('token');
        $this->logarComo('leitura', 1);
        $this->travel(2)->seconds();
        $this->postJson('/agente/confirmar', ['token' => $token])
            ->assertStatus(403)
            ->assertJsonPath('tipo', 'negado');

        $this->assertSame(0, Cliente::count());
        $logs = AuditLog::query()->orderBy('id')->get();
        $this->assertSame(['negado', 'negado'], $logs->pluck('resultado')->all());
        $this->assertSame(['nome' => 'Loja'], $logs[0]->parametros_propostos);
        $this->assertSame(['nome' => 'Loja'], $logs[1]->parametros_propostos);
    }

    public function test_revisar_com_erro_devolve_o_formulario_com_frase_por_campo_sem_token(): void
    {
        $proposta = $this->rascunho(['nome' => 'Loja'], 'criar_cliente');

        $resposta = $this->revisar($proposta, ['nome' => '', 'email' => 'nao-e-email'])
            ->assertStatus(422)
            ->assertJsonPath('tipo', 'erro')
            ->assertJsonPath('codigo', 'AI_TOOL_REFUSED')
            ->assertJsonPath('erros.nome', 'Informe o nome.')
            ->assertJsonPath('erros.email', 'O e-mail informado não é válido.')
            ->assertJsonPath('risco', 'escrita')
            ->assertJsonPath('proposta', $proposta)
            ->assertJsonMissingPath('token');

        $campos = $this->campos($resposta);
        $this->assertSame('Informe o nome.', $campos['nome']['erro']);
        $this->assertSame('nao-e-email', $campos['email']['valor'], 'o formulário volta com o que o humano digitou');
        $this->assertSame('rascunho', session("confirmacoes.{$proposta}.estado"), 'a proposta segue em rascunho');

        $this->revisarEConfirmar($proposta, ['nome' => 'Loja Certa', 'email' => 'loja@exemplo.com'])->assertOk();

        $this->assertSame('Loja Certa', Cliente::query()->sole()->nome);
        $this->assertSame(['recusado', 'sucesso'], AuditLog::query()->orderBy('id')->pluck('resultado')->all());
        $this->assertSame(['nome' => 'Loja'], AuditLog::query()->latest('id')->first()->parametros_propostos, 'o proposto continua sendo o da IA');
    }

    public function test_revisar_sem_erro_emite_o_token_e_guarda_os_valores_revisados(): void
    {
        $proposta = $this->rascunho(['nome' => 'Loja'], 'criar_cliente');

        $token = $this->revisar($proposta, ['nome' => 'Loja Revisada'])
            ->assertOk()
            ->assertJsonPath('tipo', 'revisada')
            ->assertJsonPath('risco', 'escrita')
            ->json('token');

        $this->assertNotNull($token);
        $this->assertNotSame($proposta, $token);
        $this->assertSame('revisada', session("confirmacoes.{$token}.estado"));
        $this->assertSame('Loja Revisada', session("confirmacoes.{$token}.argumentos.nome"));
        $this->assertSame(['nome' => 'Loja'], session("confirmacoes.{$token}.propostos"));
        $this->assertSame('rascunho', session("confirmacoes.{$proposta}.estado"), 'o rascunho continua para o "Editar"');
        $this->assertSame(0, AuditLog::count(), 'revisar sem erro não grava nem audita');
    }

    public function test_valor_alterado_no_navegador_depois_do_revisar_e_ignorado(): void
    {
        $proposta = $this->rascunho(['nome' => 'Loja'], 'criar_cliente');
        $token = $this->revisar($proposta, ['nome' => 'Revisado'])->assertOk()->json('token');
        $this->travel(2)->seconds();

        $this->postJson('/agente/confirmar', ['token' => $token, 'valores' => ['nome' => 'Trocado depois']])->assertOk();

        $this->assertSame('Revisado', Cliente::query()->sole()->nome);
        $this->assertSame('Revisado', AuditLog::query()->sole()->parametros['nome']);
    }

    public function test_confirmar_proposta_nao_revisada_e_recusado(): void
    {
        $proposta = $this->rascunho(['nome' => 'Loja'], 'criar_cliente');
        $this->travel(2)->seconds();

        $this->postJson('/agente/confirmar', ['token' => $proposta])
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'AI_CONFIRMATION_NOT_REVIEWED')
            ->assertJsonPath('mensagem', 'Revise a proposta antes de confirmar.');

        $this->assertSame(0, Cliente::count());
        $this->assertSame(0, AuditLog::count());
        $this->assertSame('rascunho', session("confirmacoes.{$proposta}.estado"), 'o rascunho continua');
        $this->revisarEConfirmar($proposta, [])->assertOk();
    }

    public function test_valores_em_formato_errado_sao_recusados_sem_perder_a_proposta(): void
    {
        $proposta = $this->rascunho(['nome' => 'Loja'], 'criar_cliente');

        $this->revisar($proposta, 'nome=X')
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'AI_VALIDATION_ERROR')
            ->assertJsonPath('http_status', 422);

        $this->revisarEConfirmar($proposta, ['nome' => 'Loja'])->assertOk();
    }

    public function test_editar_invalida_o_token_sem_auditoria_e_o_rascunho_continua(): void
    {
        $proposta = $this->rascunho(['nome' => 'Loja'], 'criar_cliente');
        $token = $this->revisar($proposta, ['nome' => 'Primeira'])->assertOk()->json('token');

        $this->postJson('/agente/editar', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('tipo', 'rascunho')
            ->assertJsonPath('proposta', $proposta);

        $this->travel(2)->seconds();
        $this->postJson('/agente/confirmar', ['token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Confirmação inválida ou já utilizada.');
        $this->assertSame(0, AuditLog::count(), 'o token só morre: nada a auditar');

        $this->revisarEConfirmar($proposta, ['nome' => 'Segunda'])->assertOk();
        $this->assertSame('Segunda', Cliente::query()->sole()->nome);
    }

    public function test_revisar_de_novo_mata_o_token_anterior(): void
    {
        $proposta = $this->rascunho(['nome' => 'Loja'], 'criar_cliente');
        $primeiro = $this->revisar($proposta, ['nome' => 'Primeira'])->assertOk()->json('token');
        $segundo = $this->revisar($proposta, ['nome' => 'Segunda'])->assertOk()->json('token');
        $this->travel(2)->seconds();

        $this->postJson('/agente/confirmar', ['token' => $primeiro])->assertStatus(422);
        $this->postJson('/agente/confirmar', ['token' => $segundo])->assertOk();

        $this->assertSame('Segunda', Cliente::query()->sole()->nome);
    }

    public function test_rascunho_expira_em_5_minutos_e_e_auditado(): void
    {
        $proposta = $this->rascunho(['nome' => 'Esquecida'], 'criar_cliente');
        $this->travel(ConfirmacoesPendentes::PRAZO_RASCUNHO_S - 1)->seconds();
        $this->get('/agente')->assertOk();
        $this->assertNotNull(session("confirmacoes.{$proposta}"), 'antes dos 5 min o rascunho fica');

        $this->travel(2)->seconds();
        $this->get('/agente')->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('expirado', $log->resultado);
        $this->assertSame(['nome' => 'Esquecida'], $log->parametros_propostos);
        $this->assertEmpty(session('confirmacoes', []));
    }

    public function test_prazo_do_rascunho_nao_reinicia_no_editar(): void
    {
        $proposta = $this->rascunho(['nome' => 'Loja'], 'criar_cliente');
        $this->travel(200)->seconds();
        $token = $this->revisar($proposta, ['nome' => 'Loja'])->assertOk()->json('token');
        $this->postJson('/agente/editar', ['token' => $token])->assertOk();
        $this->travel(101)->seconds(); // 301 s desde a proposta

        $this->revisar($proposta, ['nome' => 'Loja'])
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'AI_PROPOSAL_EXPIRED');

        $this->assertSame(['expirado'], AuditLog::query()->pluck('resultado')->all(), 'a varredura deu o desfecho');
        $this->assertSame(0, Cliente::count());
    }

    public function test_cancelar_ainda_no_formulario_usa_a_proposta(): void
    {
        $proposta = $this->rascunho(['nome' => 'Loja'], 'criar_cliente');
        $this->travel(30)->seconds();

        $this->postJson('/agente/cancelar', ['proposta' => $proposta])
            ->assertOk()
            ->assertJsonPath('tipo', 'cancelado');

        $this->assertSame(['nome' => 'Loja'], AuditLog::query()->sole()->parametros_propostos);
        $this->assertEmpty(session('confirmacoes', []));
    }

    public function test_depois_de_revisar_com_erro_o_sensivel_editado_vale_mesmo_deixado_em_branco(): void
    {
        $proposta = $this->rascunho(['nome' => 'Loja', 'documento' => '123.456.789-01'], 'criar_cliente');

        $resposta = $this->revisar($proposta, ['documento' => '999.888.777-66', 'email' => 'nao-e-email'])->assertStatus(422);
        $this->assertSame('***.***.777-66', $this->campos($resposta)['documento']['valor'], 'o card mostra o editado');

        // Corrige o e-mail e deixa o documento em branco ("manter"): vale o que o humano editou, não o da IA.
        $this->revisarEConfirmar($proposta, ['documento' => '', 'email' => 'loja@exemplo.com'])->assertOk();

        $this->assertSame('999.888.777-66', Cliente::query()->sole()->documento);
        $sucesso = AuditLog::query()->where('resultado', 'sucesso')->sole();
        $this->assertSame('***.***.789-01', $sucesso->parametros_propostos['documento'], 'o proposto continua sendo o da IA');
        $this->assertSame('***.***.777-66', $sucesso->parametros['documento']);
    }

    public function test_os_5_minutos_do_rascunho_valem_tambem_para_o_token_de_segurar(): void
    {
        $proposta = $this->rascunho(['nome' => 'Loja'], 'criar_cliente');
        $this->travel(295)->seconds();
        $token = $this->revisar($proposta, ['nome' => 'Loja'])->assertOk()->json('token');
        $this->travel(6)->seconds(); // token com 6 s, proposta com 301 s

        $this->postJson('/agente/confirmar', ['token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Confirmação fora do prazo. Refaça o comando.');

        $this->assertSame(0, Cliente::count());
        $this->assertSame(['expirado'], AuditLog::query()->pluck('resultado')->all());
    }

    public function test_cancelar_ou_sair_com_rascunho_decide_pelo_prazo_de_5_minutos(): void
    {
        $vencido = $this->rascunho(['nome' => 'Vencida'], 'criar_cliente');
        $this->travel(301)->seconds();
        $this->postJson('/agente/cancelar', ['proposta' => $vencido])->assertOk()->assertJsonPath('tipo', 'expirado');

        $this->rascunho(['nome' => 'Aberta'], 'criar_cliente');
        $this->travel(60)->seconds();
        $this->post('/logout')->assertRedirect('/login');

        $this->assertSame(['expirado', 'cancelado'], AuditLog::query()->orderBy('id')->pluck('resultado')->all());
    }

    public function test_editar_ou_revisar_com_token_vencido_nao_ressuscita_a_proposta(): void
    {
        $proposta = $this->rascunho(['nome' => 'Loja'], 'criar_cliente');
        $token = $this->revisar($proposta, ['nome' => 'Loja'])->assertOk()->json('token');
        $this->travel(13)->seconds();

        $this->postJson('/agente/editar', ['token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'AI_PROPOSAL_EXPIRED');

        $outra = $this->rascunho(['nome' => 'Outra'], 'criar_cliente');
        $this->revisar($outra, ['nome' => 'Outra'])->assertOk();
        $this->travel(13)->seconds();
        $this->revisar($outra, ['nome' => 'Outra'])
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'AI_PROPOSAL_EXPIRED');

        $this->assertSame(['expirado', 'expirado'], AuditLog::query()->pluck('resultado')->all(), 'um desfecho por proposta');
        $this->assertEmpty(session('confirmacoes', []));
    }

    public function test_confirmar_reaplica_a_validacao_quando_o_dado_muda_depois_do_revisar(): void
    {
        $cliente = Cliente::create(['nome' => 'Mercado', 'tenant_id' => 1]);
        $proposta = $this->rascunho(['numero' => 'NF-1', 'tipo' => 'saida', 'valor' => 10, 'data_emissao' => '2026-10-01', 'cliente_id' => $cliente->id], 'criar_nota_fiscal');
        $token = $this->revisar($proposta, [])->assertOk()->json('token');

        $cliente->delete(); // foi para a lixeira entre o Revisar e o segurar
        $this->travel(2)->seconds();

        $this->postJson('/agente/confirmar', ['token' => $token])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Não encontrei o cliente informado.');
        $this->assertSame(0, \App\Models\NotaFiscal::count());
        $this->assertSame('recusado', AuditLog::query()->sole()->resultado);
    }

    public function test_revisar_com_array_forjado_nao_vira_500(): void
    {
        $proposta = $this->rascunho(['numero' => 'NF-1', 'tipo' => 'saida', 'valor' => 10, 'data_emissao' => '2026-10-01'], 'criar_nota_fiscal');

        $this->revisar($proposta, ['tipo' => ['x'], 'cliente_id' => ['x']])
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'AI_TOOL_REFUSED');
    }

    public function test_falha_ao_auditar_no_revisar_responde_no_contrato_sem_vazar_dados(): void
    {
        $this->app->instance(AuditoriaService::class, new class(app(ContextoUsuario::class)) extends AuditoriaService
        {
            public function registrar(string $tool, string $acao, ?string $entidadeTipo, ?int $entidadeId, array $parametros, string $resultado, ?string $mensagem, string $papel, bool $permitido): AuditLog
            {
                throw new RuntimeException('insert into audit_logs values (joao@exemplo.com)');
            }
        });
        $proposta = $this->rascunho(['nome' => 'Loja'], 'criar_cliente');
        Log::spy();

        $resposta = $this->revisar($proposta, ['nome' => '', 'email' => 'joao@exemplo.com'])
            ->assertStatus(500)
            ->assertJsonPath('codigo', 'AI_TOOL_ERROR');

        $this->assertStringNotContainsString('insert into', $resposta->getContent());
        $this->assertStringNotContainsString('joao@exemplo.com', $resposta->getContent());
        // AI-003: o log leva só metadados, nunca a mensagem da exceção (que traz o SQL com os valores).
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($mensagem, $contexto) => $mensagem === 'AiAgentService: falha controlada'
            && $contexto['categoria'] === 'tool_error'
            && isset($contexto['correlation_id'])
            && ! str_contains(json_encode($contexto), 'insert into')
            && ! str_contains(json_encode($contexto), 'joao@exemplo.com'));
    }

    public function test_desfecho_depois_de_revisao_com_edicao_audita_o_proposto_da_ia_e_nao_a_edicao(): void
    {
        // Cancelado no segurar, depois de revisar com valores editados.
        $proposta = $this->rascunho(['nome' => 'Loja'], 'criar_cliente');
        $token = $this->revisar($proposta, ['nome' => 'Editado'])->assertOk()->json('token');
        $this->travel(1)->seconds();
        $this->postJson('/agente/cancelar', ['token' => $token])->assertOk();

        // Esquecido no segurar (a varredura audita como expirado).
        $proposta = $this->rascunho(['nome' => 'Outra'], 'criar_cliente');
        $this->revisar($proposta, ['nome' => 'Também editado'])->assertOk();
        $this->travel(13)->seconds();
        $this->get('/agente')->assertOk();

        $desfechos = AuditLog::query()->whereIn('resultado', ['cancelado', 'expirado'])->orderBy('id')->get();
        $this->assertSame(['cancelado', 'expirado'], $desfechos->pluck('resultado')->all());
        $this->assertSame(['nome' => 'Loja'], $desfechos[0]->parametros_propostos);
        $this->assertSame(['nome' => 'Outra'], $desfechos[1]->parametros_propostos);
        $this->assertEmpty(session('confirmacoes', []));
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

        $proposta = $this->rascunho(['id' => $lancamento->id, 'novo_status' => 'conciliado'], 'atualizar_status_lancamento');
        $resposta = $this->revisar($proposta, ['id' => ''])
            ->assertStatus(422)
            ->assertJsonPath('erros.id', 'Informe qual lançamento deve ser atualizado.')
            ->assertJsonMissingPath('token');
        $this->assertNotNull($resposta->json('formulario'), 'o formulário volta para corrigir');

        // Corpo forjado: um array ou um booleano no id não pode virar o lançamento #1.
        $this->revisar($proposta, ['id' => ['x']])->assertStatus(422);
        $this->revisar($proposta, ['id' => true])->assertStatus(422);

        $this->assertSame('pendente', $primeiro->fresh()->status);
        $this->assertSame('pendente', $lancamento->fresh()->status);
    }

    // ---- salário no card (decisão de 20/10) ----

    public function test_salario_aparece_como_informado_sem_valor_para_quem_nao_e_admin(): void
    {
        // Só o admin chega ao formulário de funcionário pelo agente (RBAC); a regra para os outros
        // papéis é conferida no próprio montador do formulário, com um operador logado.
        $campos = collect(app(AiAgentService::class)->formulario('criar_funcionario', self::FUNCIONARIO)['campos'])->keyBy('nome');

        $this->assertTrue($campos['salario']['informado']);
        $this->assertNull($campos['salario']['valor'], 'a máscara do salário esconde tudo: nada para mostrar');
        $this->assertTrue($campos['cpf']['informado']);
        $this->assertSame('***.***.333-44', $campos['cpf']['valor']);
    }

    public function test_admin_ve_o_salario_formatado_em_reais_so_no_card(): void
    {
        $this->logarComo('admin', 1);

        $resposta = $this->comando('criar_funcionario', self::FUNCIONARIO)->assertOk();
        $campos = $this->campos($resposta);
        $this->assertSame('R$ 4.567,89', $campos['salario']['valor']);
        $this->assertTrue($campos['salario']['informado']);
        $this->assertSame('***.***.333-44', $campos['cpf']['valor'], 'CPF continua mascarado até para o admin');
        $this->assertSame('[redigido]', $resposta->json('argumentos.salario'), 'fora do card, mascarado');

        // "4.567,89" digitado no card não some calado: volta como erro do campo, e o formulário mantém o formato.
        $proposta = $resposta->json('proposta');
        $erro = $this->revisar($proposta, ['salario' => '4.567,89'])
            ->assertStatus(422)
            ->assertJsonPath('erros.salario', 'O salário deve ser um número.');
        $this->assertSame(0, Funcionario::count());

        // Corrigido e gravado: auditoria, histórico e resposta continuam mascarados.
        $this->revisarEConfirmar($proposta, ['salario' => '4567.89'])->assertOk()->assertJsonPath('resultado.salario', '[redigido]');
        $this->assertSame('[redigido]', AuditLog::query()->where('resultado', 'sucesso')->sole()->parametros['salario']);
        $this->assertStringNotContainsString('4.567', json_encode(session('agente_conversa')));
        $this->assertStringNotContainsString('4567', json_encode(session('agente_conversa')));
        $this->assertStringNotContainsString('4567', (string) $erro->json('mensagem'));
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

    public function test_historico_esconde_o_valor_do_salario_e_nao_mexe_em_outros_valores(): void
    {
        $this->respostaFoundry = ['choices' => [['message' => ['role' => 'assistant', 'content' => 'Certo.']]]];

        $casos = [
            'cadastre a Ana com salário de R$ 4.567,89' => 'cadastre a Ana com salário de [valor omitido]',
            'Ana, analista, salário: 3200.50, admitida hoje' => 'Ana, analista, salário: [valor omitido], admitida hoje',
            'remuneração 5000 por mês' => 'remuneração [valor omitido] por mês',
            'remunerações de 2.100,00' => 'remunerações de [valor omitido]',
            'crie um lançamento de 150 reais' => 'crie um lançamento de 150 reais',
            'boleto com vencimento em 15/11/2026' => 'boleto com vencimento em 15/11/2026',
        ];

        foreach ($casos as $digitado => $guardado) {
            $this->postJson('/agente/comando', ['mensagem' => $digitado])->assertOk();

            $mensagens = session('agente_conversa.mensagens');
            $this->assertSame($guardado, $mensagens[count($mensagens) - 2]['content'], $digitado);
        }
    }

    public function test_tela_monta_o_card_como_formulario_sem_json_nem_nome_de_tool(): void
    {
        // Só confere o que a tela entrega; o comportamento no navegador fica para o teste manual.
        $this->get('/agente')
            ->assertOk()
            ->assertSee('function montarCampo(campo, prefixo)', false)
            ->assertSee('valores: coletarValores(form, formulario.campos)', false)
            ->assertSee("fetch('/agente/revisar'", false)
            ->assertSee("fetch('/agente/editar'", false)
            ->assertSee('const PRAZO_RASCUNHO_MS = 300000;', false)
            ->assertSee('Confira os dados e clique em Revisar.')
            ->assertSee('.campo[hidden] { display: none; }', false)
            ->assertSee("entrada.inputMode = campo.inteiro ? 'numeric' : 'decimal';", false)
            ->assertSee('Informado (oculto por segurança). Deixe em branco para manter.')
            ->assertSee('Só números, com ponto nos centavos (ex.: 4567.89).')
            ->assertSee("campo.erro ? 'Digite o valor de novo.'", false)
            ->assertDontSee('card-argumentos', false)
            ->assertDontSee('JSON.stringify(argumentos', false);
    }
}
