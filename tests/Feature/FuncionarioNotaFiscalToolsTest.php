<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\Fornecedor;
use App\Models\Funcionario;
use App\Models\Lancamento;
use App\Models\NotaFiscal;
use App\Services\AiAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Tools de Funcionario (criar = admin) e NotaFiscal (criar = operador, com FKs
 * que só aceitam dados do tenant atual). Frases de recusa sempre amigáveis.
 */
class FuncionarioNotaFiscalToolsTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararAgente();
    }

    private function funcionarioValido(array $sobrescrever = []): array
    {
        return array_merge(['nome' => 'Ana Souza', 'cpf' => '111.222.333-44', 'cargo' => 'Analista', 'salario' => 3200.50, 'data_admissao' => '2026-01-10'], $sobrescrever);
    }

    private function notaSaida(int $clienteId, array $sobrescrever = []): array
    {
        return array_merge(['numero' => 'NF-900', 'tipo' => 'saida', 'valor' => 1500, 'data_emissao' => '2026-09-01', 'cliente_id' => $clienteId], $sobrescrever);
    }

    private function notaEntrada(int $fornecedorId, array $sobrescrever = []): array
    {
        return array_merge(['numero' => 'NF-901', 'tipo' => 'entrada', 'valor' => 700, 'data_emissao' => '2026-09-02', 'fornecedor_id' => $fornecedorId], $sobrescrever);
    }

    /** Propõe, confirma e exige: recusa 422 com a frase esperada, sem termo técnico, auditada como 'recusado'. */
    private function recusar(string $tool, array $argumentos, string $esperada): void
    {
        $antes = AuditLog::count();
        $token = $this->propor($argumentos, $tool);

        $mensagem = $this->confirmar($token)
            ->assertStatus(422)
            ->assertJsonPath('tipo', 'erro')
            ->json('mensagem');

        $this->assertSame($esperada, $mensagem);
        foreach (['validation', 'The ', 'field', 'Exception', 'SQL', 'App\\', 'exists', 'prohibited', 'required', 'tenant'] as $termo) {
            $this->assertStringNotContainsString($termo, $mensagem, 'sem termo técnico');
        }

        $this->assertSame($antes + 1, AuditLog::count());
        $log = AuditLog::query()->latest('id')->first();
        $this->assertSame('recusado', $log->resultado);
        $this->assertTrue($log->permitido, 'recusa de regra de negócio não é negação de RBAC');
    }

    // ================= Funcionario =================

    public function test_consultar_funcionarios_filtra_nome_e_tenant_e_devolve_so_as_colunas(): void
    {
        Funcionario::create($this->funcionarioValido(['nome' => 'Ana Souza', 'tenant_id' => 1]));
        Funcionario::create($this->funcionarioValido(['nome' => 'Bruno Lima', 'tenant_id' => 1]));
        Funcionario::create($this->funcionarioValido(['nome' => 'Ana Alheia', 'tenant_id' => 2]));

        $colunas = ['id', 'nome', 'cargo', 'data_admissao', 'data_demissao'];
        $resposta = $this->comando('consultar_funcionarios', ['nome' => 'Ana'])
            ->assertOk()
            ->assertJsonCount(1, 'resultado')
            ->assertJsonPath('resultado.0.nome', 'Ana Souza')
            ->assertJsonPath('colunas', $colunas)
            // LGPD: a consulta é aberta ao papel "leitura", então CPF e salário nunca saem dela.
            ->assertJsonMissingPath('resultado.0.cpf')
            ->assertJsonMissingPath('resultado.0.salario');

        $linha = array_keys($resposta->json('resultado.0'));
        sort($linha);
        $esperado = $colunas;
        sort($esperado);
        $this->assertSame($esperado, $linha, 'só as colunas da tool — sem tenant_id nem timestamps');
    }

    public function test_leitura_consulta_funcionarios_e_a_consulta_e_auditada(): void
    {
        config(['sentinel.papel_atual' => 'leitura']);

        $this->comando('consultar_funcionarios')->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame('consultar_funcionarios', $log->tool);
        $this->assertSame('Funcionario', $log->entidade_tipo);
        $this->assertSame('sucesso', $log->resultado);
        $this->assertSame('leitura', $log->papel);
    }

    public function test_criar_funcionario_so_grava_apos_confirmar_com_tenant_do_servidor_e_audita(): void
    {
        config(['sentinel.tenant_atual' => 7]);

        $token = $this->propor($this->funcionarioValido(['data_demissao' => '2026-06-30', 'tenant_id' => 999]), 'criar_funcionario');
        $this->assertSame(0, Funcionario::count(), 'propor não grava');
        $this->assertSame(0, AuditLog::count(), 'propor não audita');

        $this->confirmar($token)->assertOk()->assertJsonPath('tipo', 'resultado_escrita');

        $f = Funcionario::query()->sole();
        $this->assertSame('Ana Souza', $f->nome);
        $this->assertSame('111.222.333-44', $f->cpf);
        $this->assertSame('Analista', $f->cargo);
        $this->assertSame('3200.50', $f->salario);
        $this->assertSame('2026-01-10', $f->data_admissao->toDateString());
        $this->assertSame('2026-06-30', $f->data_demissao->toDateString());
        $this->assertSame(7, $f->tenant_id);

        $log = AuditLog::query()->sole();
        $this->assertSame('criar_funcionario', $log->tool);
        $this->assertSame('criar', $log->acao);
        $this->assertSame('Funcionario', $log->entidade_tipo);
        $this->assertSame($f->id, $log->entidade_id);
        $this->assertSame('sucesso', $log->resultado);
        $this->assertSame(7, $log->tenant_id);
    }

    public function test_criar_funcionario_recusa_dado_invalido_com_frase_amigavel(): void
    {
        $casos = [
            [['nome' => null], 'Informe o nome.'],
            [['cpf' => null], 'Informe o CPF.'],
            [['cpf' => '111.222.333-44-99'], 'O CPF aceita no máximo 14 caracteres.'],
            [['salario' => 'muito'], 'O salário deve ser um número.'],
            [['salario' => -1], 'O salário deve ser no mínimo 0.'],
            [['salario' => null], 'Informe o salário.'],
            [['data_admissao' => null], 'Informe a data de admissão.'],
            [['data_admissao' => 'não é data'], 'A data de admissão deve ser uma data válida (AAAA-MM-DD).'],
            [['data_demissao' => '2025-12-31'], 'A data de demissão não pode ser anterior à data de admissão.'],
        ];

        foreach ($casos as [$alteracao, $esperada]) {
            $this->recusar('criar_funcionario', array_filter($this->funcionarioValido($alteracao), fn ($v) => $v !== null), $esperada);
        }

        $this->assertSame(0, Funcionario::count(), 'nada foi gravado');
    }

    public function test_criar_funcionario_exige_admin_operador_e_leitura_sao_negados_e_auditados(): void
    {
        foreach (['operador', 'leitura'] as $papel) {
            config(['sentinel.papel_atual' => $papel]);

            $this->comando('criar_funcionario', $this->funcionarioValido())
                ->assertStatus(403)
                ->assertJsonPath('tipo', 'negado')
                ->assertJsonMissingPath('token');

            $log = AuditLog::query()->latest('id')->first();
            $this->assertSame('criar_funcionario', $log->tool);
            $this->assertSame('negado', $log->resultado);
            $this->assertFalse($log->permitido);
            $this->assertSame($papel, $log->papel);
        }

        $this->assertSame(0, Funcionario::count());

        config(['sentinel.papel_atual' => 'admin']);
        $token = $this->propor($this->funcionarioValido(), 'criar_funcionario');
        $this->confirmar($token)->assertOk();
        $this->assertSame(1, Funcionario::count());
    }

    // ================= NotaFiscal =================

    public function test_consultar_notas_fiscais_filtra_tipo_e_tenant_e_devolve_so_as_colunas(): void
    {
        $cliente = Cliente::create(['nome' => 'C1', 'tenant_id' => 1]);
        $fornecedor = Fornecedor::create(['nome' => 'F1', 'tenant_id' => 1]);
        NotaFiscal::create($this->notaSaida($cliente->id) + ['tenant_id' => 1]);
        NotaFiscal::create($this->notaEntrada($fornecedor->id) + ['tenant_id' => 1]);
        $alheio = Cliente::create(['nome' => 'C2', 'tenant_id' => 2]);
        NotaFiscal::create($this->notaSaida($alheio->id, ['numero' => 'NF-ALHEIA']) + ['tenant_id' => 2]);

        $colunas = ['id', 'numero', 'tipo', 'valor', 'data_emissao', 'cliente_id', 'fornecedor_id', 'lancamento_id'];
        $resposta = $this->comando('consultar_notas_fiscais', ['tipo' => 'saida'])
            ->assertOk()
            ->assertJsonCount(1, 'resultado')
            ->assertJsonPath('resultado.0.numero', 'NF-900')
            ->assertJsonPath('colunas', $colunas);

        $linha = array_keys($resposta->json('resultado.0'));
        sort($linha);
        $esperado = $colunas;
        sort($esperado);
        $this->assertSame($esperado, $linha);

        $this->comando('consultar_notas_fiscais')->assertOk()->assertJsonCount(2, 'resultado'); // só o tenant 1
    }

    public function test_criar_nota_de_saida_com_cliente_do_tenant_grava_apos_confirmar_e_audita(): void
    {
        config(['sentinel.tenant_atual' => 7]);
        $cliente = Cliente::create(['nome' => 'Cliente do 7', 'tenant_id' => 7]);
        $lancamento = Lancamento::create(['descricao' => 'L', 'valor' => 1, 'data' => '2026-09-01', 'status' => 'pendente', 'tenant_id' => 7]);

        $token = $this->propor($this->notaSaida($cliente->id, ['lancamento_id' => $lancamento->id, 'tenant_id' => 999]), 'criar_nota_fiscal');
        $this->assertSame(0, NotaFiscal::count(), 'propor não grava');
        $this->assertSame(0, AuditLog::count(), 'propor não audita');

        $this->confirmar($token)->assertOk()->assertJsonPath('tipo', 'resultado_escrita');

        $nf = NotaFiscal::query()->sole();
        $this->assertSame('NF-900', $nf->numero);
        $this->assertSame('saida', $nf->tipo);
        $this->assertSame($cliente->id, $nf->cliente_id);
        $this->assertNull($nf->fornecedor_id);
        $this->assertSame($lancamento->id, $nf->lancamento_id);
        $this->assertSame(7, $nf->tenant_id);

        $log = AuditLog::query()->sole();
        $this->assertSame('criar_nota_fiscal', $log->tool);
        $this->assertSame('NotaFiscal', $log->entidade_tipo);
        $this->assertSame($nf->id, $log->entidade_id);
        $this->assertSame('sucesso', $log->resultado);
        $this->assertSame(7, $log->tenant_id);
    }

    public function test_criar_nota_de_entrada_com_fornecedor_do_tenant_grava(): void
    {
        $fornecedor = Fornecedor::create(['nome' => 'F', 'tenant_id' => 1]);

        $token = $this->propor($this->notaEntrada($fornecedor->id), 'criar_nota_fiscal');
        $this->confirmar($token)->assertOk();

        $nf = NotaFiscal::query()->sole();
        $this->assertSame('entrada', $nf->tipo);
        $this->assertSame($fornecedor->id, $nf->fornecedor_id);
        $this->assertNull($nf->cliente_id);
    }

    public function test_nota_fiscal_nao_aponta_para_cliente_fornecedor_ou_lancamento_de_outro_tenant_inexistente_ou_na_lixeira(): void
    {
        $clienteAlheio = Cliente::create(['nome' => 'Cliente de outro tenant', 'tenant_id' => 2]);
        $fornecedorAlheio = Fornecedor::create(['nome' => 'Fornecedor de outro tenant', 'tenant_id' => 2]);
        $lancamentoAlheio = Lancamento::create(['descricao' => 'L alheio', 'valor' => 1, 'data' => '2026-09-01', 'status' => 'pendente', 'tenant_id' => 2]);
        $clienteProprio = Cliente::create(['nome' => 'Cliente próprio', 'tenant_id' => 1]);
        $clienteNaLixeira = Cliente::create(['nome' => 'Cliente excluído', 'tenant_id' => 1]);
        $clienteNaLixeira->delete();

        $this->recusar('criar_nota_fiscal', $this->notaSaida($clienteAlheio->id), 'Não encontrei o cliente informado.');
        $this->recusar('criar_nota_fiscal', $this->notaSaida(999999), 'Não encontrei o cliente informado.');
        $this->recusar('criar_nota_fiscal', $this->notaSaida($clienteNaLixeira->id), 'Não encontrei o cliente informado.');
        $this->recusar('criar_nota_fiscal', $this->notaEntrada($fornecedorAlheio->id), 'Não encontrei o fornecedor informado.');
        $this->recusar('criar_nota_fiscal', $this->notaSaida($clienteProprio->id, ['lancamento_id' => $lancamentoAlheio->id]), 'Não encontrei o lançamento informado.');

        $this->assertSame(0, NotaFiscal::count(), 'nenhuma nota com vínculo indevido foi gravada');
    }

    public function test_nota_fiscal_exige_o_vinculo_certo_para_cada_tipo(): void
    {
        $cliente = Cliente::create(['nome' => 'C', 'tenant_id' => 1]);
        $fornecedor = Fornecedor::create(['nome' => 'F', 'tenant_id' => 1]);

        $semCliente = $this->notaSaida($cliente->id);
        unset($semCliente['cliente_id']);
        $semFornecedor = $this->notaEntrada($fornecedor->id);
        unset($semFornecedor['fornecedor_id']);

        $this->recusar('criar_nota_fiscal', $semCliente, 'Informe o cliente da nota fiscal de saída.');
        $this->recusar('criar_nota_fiscal', $semFornecedor, 'Informe o fornecedor da nota fiscal de entrada.');
        $this->recusar('criar_nota_fiscal', $this->notaEntrada($fornecedor->id, ['cliente_id' => $cliente->id]), 'Nota fiscal de entrada não leva cliente — informe o fornecedor.');
        $this->recusar('criar_nota_fiscal', $this->notaSaida($cliente->id, ['fornecedor_id' => $fornecedor->id]), 'Nota fiscal de saída não leva fornecedor — informe o cliente.');

        $this->assertSame(0, NotaFiscal::count());
    }

    public function test_criar_nota_fiscal_recusa_dado_invalido_com_frase_amigavel(): void
    {
        $cliente = Cliente::create(['nome' => 'C', 'tenant_id' => 1]);
        $base = $this->notaSaida($cliente->id);

        $casos = [
            [['numero' => null], 'Informe o número.'],
            [['numero' => str_repeat('9', 51)], 'O número aceita no máximo 50 caracteres.'],
            [['tipo' => 'devolucao'], 'O tipo deve ser "entrada" ou "saida".'],
            [['valor' => 'abc'], 'O valor deve ser um número.'],
            [['valor' => -5], 'O valor deve ser no mínimo 0.'],
            [['data_emissao' => null], 'Informe a data de emissão.'],
            [['data_emissao' => 'não é data'], 'A data de emissão deve ser uma data válida (AAAA-MM-DD).'],
        ];

        foreach ($casos as [$alteracao, $esperada]) {
            $this->recusar('criar_nota_fiscal', array_filter(array_merge($base, $alteracao), fn ($v) => $v !== null), $esperada);
        }

        $this->assertSame(0, NotaFiscal::count());
    }

    public function test_leitura_nao_cria_nota_fiscal_e_operador_pode(): void
    {
        $cliente = Cliente::create(['nome' => 'C', 'tenant_id' => 1]);

        config(['sentinel.papel_atual' => 'leitura']);
        $this->comando('criar_nota_fiscal', $this->notaSaida($cliente->id))
            ->assertStatus(403)
            ->assertJsonPath('tipo', 'negado')
            ->assertJsonMissingPath('token');
        $this->assertSame('negado', AuditLog::query()->sole()->resultado);
        $this->assertSame(0, NotaFiscal::count());

        config(['sentinel.papel_atual' => 'operador']);
        $token = $this->propor($this->notaSaida($cliente->id), 'criar_nota_fiscal');
        $this->confirmar($token)->assertOk();
        $this->assertSame(1, NotaFiscal::count());
    }

    // ================= Catálogo =================

    public function test_schema_das_tools_de_funcionario_e_nota_fiscal(): void
    {
        $tools = collect(app(AiAgentService::class)->tools())->keyBy('function.name');

        $esperado = [
            'consultar_funcionarios' => [['nome'], null],
            'criar_funcionario' => [['nome', 'cpf', 'cargo', 'salario', 'data_admissao', 'data_demissao'], ['nome', 'cpf', 'salario', 'data_admissao']],
            'consultar_notas_fiscais' => [['tipo'], null],
            'criar_nota_fiscal' => [['numero', 'tipo', 'valor', 'data_emissao', 'cliente_id', 'fornecedor_id', 'lancamento_id'], ['numero', 'tipo', 'valor', 'data_emissao']],
        ];

        foreach ($esperado as $nome => [$propriedades, $obrigatorios]) {
            $this->assertTrue($tools->has($nome), "{$nome} deve estar no catálogo");
            $params = $tools[$nome]['function']['parameters'];
            $this->assertSame($propriedades, array_keys($params['properties']), $nome);
            $this->assertSame($obrigatorios, $params['required'] ?? null, $nome);
            $this->assertArrayNotHasKey('tenant_id', $params['properties'], $nome);
        }

        $this->assertSame(['entrada', 'saida'], $tools['criar_nota_fiscal']['function']['parameters']['properties']['tipo']['enum']);
        $this->assertSame(['entrada', 'saida'], $tools['consultar_notas_fiscais']['function']['parameters']['properties']['tipo']['enum']);
    }
}
