<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\Fornecedor;
use App\Services\AiAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Tools de Cliente e Fornecedor (schema idêntico): consultar_* (leitura, tenant
 * filtrado) e criar_* (escrita: RBAC operador + confirmação + auditoria +
 * validação com frase amigável). Cada teste roda para as duas entidades.
 */
class ClienteFornecedorToolsTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararAgente();
        $this->logarComo('admin', 1); // padrão: admin do tenant 1; cada teste troca quando precisa
    }

    /** @return array<string, array{string, string, class-string, string}> [consultar, criar, model, entidade auditada] */
    public static function entidades(): array
    {
        return [
            'cliente' => ['consultar_clientes', 'criar_cliente', Cliente::class, 'Cliente'],
            'fornecedor' => ['consultar_fornecedores', 'criar_fornecedor', Fornecedor::class, 'Fornecedor'],
        ];
    }

    // ---- consultar_* ----

    #[DataProvider('entidades')]
    public function test_consultar_filtra_por_nome_e_so_enxerga_o_tenant_atual(string $consultar, string $criar, string $model, string $tipo): void
    {
        $model::create(['nome' => 'Alfa Ltda', 'tenant_id' => 1]);
        $model::create(['nome' => 'Beta ME', 'tenant_id' => 1]);
        $model::create(['nome' => 'Alfa Alheia', 'tenant_id' => 2]);

        $this->comando($consultar, ['nome' => 'Alfa'])
            ->assertOk()
            ->assertJsonPath('tipo', 'resultado_leitura')
            ->assertJsonCount(1, 'resultado')
            ->assertJsonPath('resultado.0.nome', 'Alfa Ltda')
            ->assertJsonPath('colunas', ['id', 'nome', 'documento', 'email', 'telefone']);

        $this->comando($consultar)->assertOk()->assertJsonCount(2, 'resultado'); // sem filtro: só o tenant 1
    }

    #[DataProvider('entidades')]
    public function test_papel_leitura_consulta_e_a_consulta_e_auditada(string $consultar, string $criar, string $model, string $tipo): void
    {
        $this->logarComo('leitura');

        $this->comando($consultar)->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame($consultar, $log->tool);
        $this->assertSame('consultar', $log->acao);
        $this->assertSame($tipo, $log->entidade_tipo);
        $this->assertSame('sucesso', $log->resultado);
        $this->assertSame('leitura', $log->papel);
        $this->assertTrue($log->permitido);
    }

    // ---- criar_* ----

    #[DataProvider('entidades')]
    public function test_criar_so_grava_apos_confirmar_com_tenant_do_servidor_e_audita(string $consultar, string $criar, string $model, string $tipo): void
    {
        $this->logarComo('admin', 7);

        $token = $this->propor([
            'nome' => 'Casa Nova Ltda', 'documento' => '12.345.678/0001-90', 'email' => 'a@casanova.com.br', 'telefone' => '(19) 3251-0000',
            'tenant_id' => 999, // o modelo tenta escolher o tenant: deve ser ignorado
        ], $criar);

        $this->assertSame(0, $model::count(), 'propor não grava');
        $this->assertSame(0, AuditLog::count(), 'propor não audita');

        $this->confirmar($token)->assertOk()->assertJsonPath('tipo', 'resultado_escrita');

        $registro = $model::query()->sole();
        $this->assertSame('Casa Nova Ltda', $registro->nome);
        $this->assertSame('12.345.678/0001-90', $registro->documento);
        $this->assertSame('a@casanova.com.br', $registro->email);
        $this->assertSame('(19) 3251-0000', $registro->telefone);
        $this->assertSame(7, $registro->tenant_id);

        $log = AuditLog::query()->sole();
        $this->assertSame($criar, $log->tool);
        $this->assertSame('criar', $log->acao);
        $this->assertSame($tipo, $log->entidade_tipo);
        $this->assertSame($registro->id, $log->entidade_id);
        $this->assertSame('sucesso', $log->resultado);
        $this->assertSame(7, $log->tenant_id);
        $this->assertSame('Casa Nova Ltda', $log->parametros['nome']);
    }

    #[DataProvider('entidades')]
    public function test_criar_so_com_nome_e_valido_e_os_opcionais_ficam_nulos(string $consultar, string $criar, string $model, string $tipo): void
    {
        $token = $this->propor(['nome' => 'Só o Nome'], $criar);

        $this->confirmar($token)->assertOk();

        $registro = $model::query()->sole();
        $this->assertNull($registro->documento);
        $this->assertNull($registro->email);
        $this->assertNull($registro->telefone);
    }

    #[DataProvider('entidades')]
    public function test_criar_recusa_dado_invalido_com_frase_amigavel_e_audita_como_recusado(string $consultar, string $criar, string $model, string $tipo): void
    {
        $casos = [
            [[], 'Informe o nome.'],
            [['nome' => str_repeat('a', 256)], 'O nome aceita no máximo 255 caracteres.'],
            [['nome' => 'X', 'email' => 'nao-e-email'], 'O e-mail informado não é válido.'],
            [['nome' => 'X', 'documento' => str_repeat('1', 33)], 'O documento aceita no máximo 32 caracteres.'],
            [['nome' => 'X', 'telefone' => str_repeat('9', 21)], 'O telefone aceita no máximo 20 caracteres.'],
            [['nome' => ['lista']], 'O nome deve ser um texto.'],
        ];

        foreach ($casos as $i => [$argumentos, $esperada]) {
            $token = $this->propor($argumentos, $criar);

            $mensagem = $this->confirmar($token)
                ->assertStatus(422)
                ->assertJsonPath('tipo', 'erro')
                ->json('mensagem');

            $this->assertSame($esperada, $mensagem);
            foreach (['validation', 'The ', 'field', 'Exception', 'SQL', 'App\\'] as $termo) {
                $this->assertStringNotContainsString($termo, $mensagem, 'sem termo técnico');
            }

            $this->assertSame($i + 1, AuditLog::count());
            $log = AuditLog::query()->latest('id')->first();
            $this->assertSame('recusado', $log->resultado);
            $this->assertTrue($log->permitido, 'recusa de regra de negócio não é negação de RBAC');
        }

        $this->assertSame(0, $model::count(), 'nada foi gravado');
    }

    // ---- RBAC ----

    #[DataProvider('entidades')]
    public function test_leitura_nao_cria_e_a_tentativa_negada_e_auditada(string $consultar, string $criar, string $model, string $tipo): void
    {
        $this->logarComo('leitura');

        $this->comando($criar, ['nome' => 'X'])
            ->assertStatus(403)
            ->assertJsonPath('tipo', 'negado')
            ->assertJsonMissingPath('token');

        $log = AuditLog::query()->sole();
        $this->assertSame($criar, $log->tool);
        $this->assertSame('negado', $log->resultado);
        $this->assertFalse($log->permitido);
        $this->assertSame('leitura', $log->papel);
        $this->assertSame(0, $model::count());
    }

    #[DataProvider('entidades')]
    public function test_operador_pode_criar(string $consultar, string $criar, string $model, string $tipo): void
    {
        $this->logarComo('operador');

        $token = $this->propor(['nome' => 'Feito pelo operador'], $criar);
        $this->confirmar($token)->assertOk();

        $this->assertSame(1, $model::count());
        $this->assertSame('operador', AuditLog::query()->sole()->papel);
    }

    // ---- Catálogo ----

    #[DataProvider('entidades')]
    public function test_schema_das_tools_da_entidade(string $consultar, string $criar, string $model, string $tipo): void
    {
        $tools = collect(app(AiAgentService::class)->tools())->keyBy('function.name');

        $this->assertTrue($tools->has($consultar) && $tools->has($criar), 'as duas tools devem estar no catálogo');

        $paramsConsulta = $tools[$consultar]['function']['parameters'];
        $this->assertSame(['nome'], array_keys($paramsConsulta['properties']));
        $this->assertArrayNotHasKey('required', $paramsConsulta, 'o filtro por nome é opcional');

        $paramsCriar = $tools[$criar]['function']['parameters'];
        $this->assertSame(['nome', 'documento', 'email', 'telefone'], array_keys($paramsCriar['properties']));
        $this->assertSame(['nome'], $paramsCriar['required']);
        $this->assertArrayNotHasKey('tenant_id', $paramsCriar['properties']);
    }
}
