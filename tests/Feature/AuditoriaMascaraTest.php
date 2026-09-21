<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Funcionario;
use App\Services\AuditoriaService;
use App\Services\FuncionarioService;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * LGPD no log de auditoria: CPF é mascarado e salário é redigido em audit_logs.parametros,
 * em qualquer resultado; os demais campos passam intactos e a entidade guarda o valor real.
 */
class AuditoriaMascaraTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararAgente();
    }

    private function registrar(array $parametros): AuditLog
    {
        return app(AuditoriaService::class)->registrar('t', 'criar', 'Funcionario', null, $parametros, 'sucesso', null, 'admin', true)->fresh();
    }

    // ---- AuditoriaService direto ----

    public function test_cpf_formatado_e_mascarado_e_salario_e_redigido(): void
    {
        $log = $this->registrar(['nome' => 'Ana', 'cpf' => '111.222.333-44', 'salario' => 3200.5]);

        $this->assertSame('***.***.333-44', $log->parametros['cpf']);
        $this->assertSame('[redigido]', $log->parametros['salario']);
    }

    public function test_demais_campos_passam_intactos(): void
    {
        $log = $this->registrar(['nome' => 'Ana', 'cargo' => 'Analista', 'data_admissao' => '2026-01-10', 'tenant_id' => 1, 'cpf' => '111.222.333-44']);

        $this->assertSame('Ana', $log->parametros['nome']);
        $this->assertSame('Analista', $log->parametros['cargo']);
        $this->assertSame('2026-01-10', $log->parametros['data_admissao']);
        $this->assertSame(1, $log->parametros['tenant_id']);
    }

    public function test_so_mexe_nas_chaves_que_existem_e_nao_inventa_campos(): void
    {
        $this->assertSame(['nome' => 'Cliente X', 'email' => 'x@x.com'], $this->registrar(['nome' => 'Cliente X', 'email' => 'x@x.com'])->parametros);
        $this->assertSame([], $this->registrar([])->parametros);
    }

    public function test_cpf_sem_pontuacao_sai_no_mesmo_formato_mascarado(): void
    {
        $this->assertSame('***.***.333-44', $this->registrar(['cpf' => '11122233344'])->parametros['cpf']);
    }

    public function test_cpf_nunca_sai_em_claro_mesmo_com_tipo_ou_formato_inesperado(): void
    {
        // O modelo pode mandar número, lista ou lixo: nada disso pode ir bruto para o log.
        $this->assertSame('***.***.333-44', $this->registrar(['cpf' => 11122233344])->parametros['cpf'], 'número');
        $this->assertSame('[redigido]', $this->registrar(['cpf' => ['111.222.333-44']])->parametros['cpf'], 'lista');
        $this->assertSame('[redigido]', $this->registrar(['cpf' => '123'])->parametros['cpf'], 'curto demais');
        $this->assertSame('[redigido]', $this->registrar(['cpf' => 'abc'])->parametros['cpf'], 'sem dígitos');
        $this->assertSame('[redigido]', $this->registrar(['cpf' => true])->parametros['cpf'], 'booleano');
    }

    public function test_cpf_e_salario_nulos_continuam_nulos_e_a_mascara_e_idempotente(): void
    {
        $log = $this->registrar(['cpf' => null, 'salario' => null]);
        $this->assertNull($log->parametros['cpf']);
        $this->assertNull($log->parametros['salario']);

        $this->assertSame('***.***.333-44', $this->registrar(['cpf' => '***.***.333-44'])->parametros['cpf']);
    }

    public function test_salario_zero_tambem_e_redigido(): void
    {
        $this->assertSame('[redigido]', $this->registrar(['salario' => 0])->parametros['salario']);
    }

    // ---- Pelo fluxo real do agente (todos os resultados passam pelo mesmo ponto) ----

    private function funcionario(): array
    {
        return ['nome' => 'Ana Souza', 'cpf' => '111.222.333-44', 'cargo' => 'Analista', 'salario' => 3200.50, 'data_admissao' => '2026-01-10'];
    }

    public function test_criar_funcionario_grava_log_mascarado_e_o_registro_real_fica_intacto(): void
    {
        $token = $this->propor($this->funcionario(), 'criar_funcionario');
        $this->confirmar($token)->assertOk();

        $funcionario = Funcionario::query()->sole();
        $this->assertSame('111.222.333-44', $funcionario->cpf, 'o dado real fica na tabela da entidade');
        $this->assertSame('3200.50', $funcionario->salario);

        $log = AuditLog::query()->sole();
        $this->assertSame('sucesso', $log->resultado);
        $this->assertSame('***.***.333-44', $log->parametros['cpf']);
        $this->assertSame('[redigido]', $log->parametros['salario']);
        $this->assertSame('Ana Souza', $log->parametros['nome']);
        $this->assertSame($funcionario->id, $log->entidade_id);
    }

    public function test_tentativa_negada_tambem_nao_grava_cpf_nem_salario_em_claro(): void
    {
        config(['sentinel.papel_atual' => 'operador']); // criar_funcionario exige admin

        $this->comando('criar_funcionario', $this->funcionario())->assertStatus(403);

        $log = AuditLog::query()->sole();
        $this->assertSame('negado', $log->resultado);
        $this->assertSame('***.***.333-44', $log->parametros['cpf']);
        $this->assertSame('[redigido]', $log->parametros['salario']);
    }

    public function test_recusa_de_validacao_tambem_nao_grava_cpf_nem_salario_em_claro(): void
    {
        $token = $this->propor([...$this->funcionario(), 'data_demissao' => '2025-12-31'], 'criar_funcionario'); // demissão antes da admissão
        $this->confirmar($token)->assertStatus(422);

        $log = AuditLog::query()->sole();
        $this->assertSame('recusado', $log->resultado);
        $this->assertSame('***.***.333-44', $log->parametros['cpf']);
        $this->assertSame('[redigido]', $log->parametros['salario']);
    }

    public function test_mascara_e_reaproveitavel_fora_da_auditoria(): void
    {
        // Método público/estático: usado também pelo Log::error do AiAgentService.
        $this->assertSame(
            ['nome' => 'Ana', 'cpf' => '***.***.333-44', 'salario' => '[redigido]', 'cargo' => 'Analista'],
            AuditoriaService::mascararCamposSensiveis(['nome' => 'Ana', 'cpf' => '111.222.333-44', 'salario' => 3200.5, 'cargo' => 'Analista']),
        );
        $this->assertSame([], AuditoriaService::mascararCamposSensiveis([]));
    }

    // ---- Falha técnica: a mesma proteção por outra porta (mensagem da exceção e log de arquivo) ----

    public function test_falha_tecnica_de_banco_nao_vaza_cpf_nem_salario_no_audit_nem_no_log_de_arquivo(): void
    {
        $lancar = fn (array $d) => new QueryException(
            'mysql',
            'insert into funcionarios (nome, cpf, salario) values (?, ?, ?)',
            [$d['nome'], $d['cpf'], $d['salario']],
            new Exception('SQLSTATE[HY000]: General error'),
        );

        // Pré-condição: a exceção REALMENTE carrega o dado em claro — sem isso o teste seria vazio.
        $mensagemBruta = $lancar($this->funcionario())->getMessage();
        $this->assertStringContainsString('111.222.333-44', $mensagemBruta);
        $this->assertStringContainsString('3200.5', $mensagemBruta);

        // Um erro de banco no INSERT, como aconteceria de verdade.
        $this->app->instance(FuncionarioService::class, new class($lancar) extends FuncionarioService
        {
            public function __construct(private \Closure $lancar)
            {
            }

            public function criar(array $dados): Funcionario
            {
                throw ($this->lancar)($dados);
            }
        });
        Log::spy();

        $token = $this->propor($this->funcionario(), 'criar_funcionario');
        $frase = 'Não consegui cadastrar o funcionário. Tente novamente.';

        $this->confirmar($token)->assertStatus(422)->assertJsonPath('tipo', 'erro')->assertJsonPath('mensagem', $frase);
        $this->assertSame(0, Funcionario::count());

        // audit_logs: a mensagem é a frase genérica; nada de SQL, CPF ou salário em nenhuma coluna.
        $log = AuditLog::query()->sole();
        $this->assertSame('erro', $log->resultado);
        $this->assertSame($frase, $log->mensagem);
        $linha = json_encode($log->getAttributes(), JSON_UNESCAPED_UNICODE);
        foreach (['111.222.333-44', '3200.5', 'insert into', 'SQL:'] as $termo) {
            $this->assertStringNotContainsString($termo, $linha);
        }
        $this->assertSame('***.***.333-44', $log->parametros['cpf']);

        // Log de arquivo: registrou a falha (o teste não é vazio), com argumentos mascarados e sem a mensagem da exceção.
        Log::shouldHaveReceived('error')->once()->withArgs(function ($mensagem, $contexto) {
            $json = json_encode($contexto, JSON_UNESCAPED_UNICODE);

            return $mensagem === 'AiAgentService: falha ao executar tool'
                && $contexto['tool'] === 'criar_funcionario'
                && $contexto['excecao'] === QueryException::class
                && $contexto['argumentos']['cpf'] === '***.***.333-44'
                && $contexto['argumentos']['salario'] === '[redigido]'
                && $contexto['argumentos']['nome'] === 'Ana Souza'
                && ! str_contains($json, '111.222.333-44')
                && ! str_contains($json, '3200.5')
                && ! str_contains($json, 'insert into');
        });
    }

    public function test_nenhum_valor_bruto_de_cpf_ou_salario_aparece_em_nenhuma_coluna_do_log(): void
    {
        $token = $this->propor($this->funcionario(), 'criar_funcionario');
        $this->confirmar($token)->assertOk();

        $linha = json_encode(AuditLog::query()->sole()->getAttributes(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('111.222.333-44', $linha);
        $this->assertStringNotContainsString('111.222', $linha);
        $this->assertStringNotContainsString('3200', $linha);
    }
}
