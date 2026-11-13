<?php

namespace Tests\Feature\Clinica;

use App\Models\AuditLog;
use App\Models\CatalogoItem;
use App\Models\Pet;
use App\Models\Tutor;
use App\Services\AiAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * P-B7: os testes padrão de tool, rodados para CADA entidade que nasce de definirCadastro (tutor, pet, item do
 * catálogo). Entidade nova da clínica entra aqui com uma linha em entidades() e uma em ficha(). Cobre: consulta
 * (tenant, auditoria), criar (confirmação, tenant do servidor, validação com frase amigável, auditoria), atualizar,
 * RBAC por lista de papéis e o esquema enviado à IA. Foundry sempre simulado.
 */
class ToolsDeCadastroDaClinicaTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    private const T = 3;

    /** Os campos do catálogo que mudam regra de negócio: obrigatórios, sem padrão. */
    private const FLAGS = ['cobranca_por' => 'unidade', 'controla_estoque' => 'nao', 'exige_receita' => 'nao'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAgente();
        $this->logarComo('dono', self::T);
    }

    /** @return array<string, array{string}> */
    public static function entidades(): array
    {
        return ['tutor' => ['tutor'], 'pet' => ['pet'], 'catalogo' => ['catalogo']];
    }

    /**
     * O que muda de uma entidade para outra. "criar" é o conjunto de dados válido; "muda" é o campo que o teste de
     * atualizar altera; "invalidos" são pares [argumentos, trecho da frase amigável]; "propriedades" é o esquema da IA.
     */
    private function ficha(string $chave): array
    {
        $tutor = fn () => Tutor::query()->where('tenant_id', self::T)->first() ?? Tutor::create(['nome' => 'Tutor da Casa', 'tenant_id' => self::T]);

        return match ($chave) {
            'tutor' => [
                'model' => Tutor::class, 'tipo' => 'Tutor', 'campoNome' => 'nome',
                'consultar' => 'consultar_tutores', 'criar' => 'criar_tutor', 'atualizar' => 'atualizar_tutor',
                'colunas' => ['id', 'nome', 'documento', 'email', 'telefone', 'endereco'],
                'criarDados' => fn () => ['nome' => 'Ana Souza', 'documento' => '12.345.678/0001-90', 'email' => 'ana@exemplo.com', 'telefone' => '(19) 99812-3344', 'endereco' => 'Rua das Acácias, 120'],
                'semNada' => ['nome' => 'Só o Nome'],
                'cria' => fn () => Tutor::create(['nome' => 'Alfa Silva', 'tenant_id' => self::T]),
                'criaDeFora' => fn () => Tutor::create(['nome' => 'Alfa Alheio', 'tenant_id' => 4]),
                'muda' => ['telefone' => '(19) 3441-2200'],
                'invalidos' => [
                    [[], 'Informe o nome.'],
                    [['nome' => str_repeat('a', 256)], 'O nome aceita no máximo 255 caracteres.'],
                    [['nome' => 'X', 'email' => 'nao-e-email'], 'O e-mail informado não é válido.'],
                    [['nome' => 'X', 'telefone' => str_repeat('9', 21)], 'O telefone aceita no máximo 20 caracteres.'],
                    [['nome' => ['lista']], 'O nome deve ser um texto.'],
                ],
                'propriedadesCriar' => ['nome', 'documento', 'email', 'telefone', 'endereco'], 'obrigatorios' => ['nome'],
                'papelSemEscrita' => null,
            ],
            'pet' => [
                'model' => Pet::class, 'tipo' => 'Pet', 'campoNome' => 'nome',
                'consultar' => 'consultar_pets', 'criar' => 'criar_pet', 'atualizar' => 'atualizar_pet',
                'colunas' => ['id', 'nome', 'especie', 'raca', 'sexo', 'data_nascimento', 'peso_gramas', 'tutor_id', 'alertas'],
                'criarDados' => fn () => ['tutor_id' => $tutor()->id, 'nome' => 'Thor', 'especie' => 'cao', 'raca' => 'Labrador', 'sexo' => 'macho',
                    'data_nascimento' => '2019-03-10', 'peso_gramas' => 32000, 'microchip' => '985112003456781', 'alertas' => 'Puxa forte na guia'],
                'semNada' => fn () => ['tutor_id' => $tutor()->id, 'nome' => 'Só o Básico', 'especie' => 'gato'],
                'cria' => fn () => Pet::create(['tutor_id' => $tutor()->id, 'nome' => 'Alfa Pet', 'especie' => 'cao', 'tenant_id' => self::T]),
                'criaDeFora' => fn () => Pet::create(['tutor_id' => Tutor::create(['nome' => 'Dono de fora', 'tenant_id' => 4])->id, 'nome' => 'Alfa Alheio', 'especie' => 'cao', 'tenant_id' => 4]),
                'muda' => ['alertas' => 'Medo de secador'],
                'invalidos' => [
                    [[], 'Informe o tutor.'],
                    [fn () => ['tutor_id' => $tutor()->id, 'nome' => 'Rex', 'especie' => 'dragao'], 'espécie'],
                    [fn () => ['tutor_id' => $tutor()->id, 'nome' => 'Rex', 'especie' => 'cao', 'peso_gramas' => 'pesado'], 'peso'],
                    [fn () => ['tutor_id' => Tutor::create(['nome' => 'Dono de fora', 'tenant_id' => 4])->id, 'nome' => 'Rex', 'especie' => 'cao'], 'Não encontrei o tutor informado.'],
                    [fn () => ['tutor_id' => $tutor()->id, 'nome' => 'Rex', 'especie' => 'cao', 'data_nascimento' => '10/03/2019'], 'data de nascimento'],
                ],
                'propriedadesCriar' => ['tutor_id', 'nome', 'especie', 'raca', 'sexo', 'data_nascimento', 'peso_gramas', 'microchip', 'alertas'], 'obrigatorios' => ['tutor_id', 'nome', 'especie'],
                'papelSemEscrita' => null,
            ],
            'catalogo' => [
                'model' => CatalogoItem::class, 'tipo' => 'CatalogoItem', 'campoNome' => 'nome_comercial',
                'consultar' => 'consultar_catalogo', 'criar' => 'criar_item_catalogo', 'atualizar' => 'atualizar_item_catalogo',
                'colunas' => ['id', 'nome_comercial', 'setor', 'categoria', 'tipo', 'preco_centavos', 'estoque', 'controla_estoque', 'exige_receita', 'alerta'],
                'criarDados' => fn () => ['tipo' => 'produto', 'setor' => 'petshop', 'categoria' => 'racao', 'nome_comercial' => 'Ração premium 10 kg',
                    'preco_centavos' => 21000, 'cobranca_por' => 'unidade', 'controla_estoque' => 'sim', 'estoque' => 15, 'exige_receita' => 'nao'],
                'semNada' => ['tipo' => 'servico', 'setor' => 'clinica', 'categoria' => 'consulta', 'nome_comercial' => 'Consulta simples', 'preco_centavos' => 15000,
                    'cobranca_por' => 'unidade', 'controla_estoque' => 'nao', 'exige_receita' => 'nao'],
                'cria' => fn () => CatalogoItem::create(['tipo' => 'servico', 'setor' => 'clinica', 'categoria' => 'consulta', 'nome_comercial' => 'Alfa consulta', 'preco_centavos' => 100, 'tenant_id' => self::T]),
                'criaDeFora' => fn () => CatalogoItem::create(['tipo' => 'servico', 'setor' => 'clinica', 'categoria' => 'consulta', 'nome_comercial' => 'Alfa alheia', 'preco_centavos' => 100, 'tenant_id' => 4]),
                'muda' => ['preco_centavos' => 9900],
                'invalidos' => [
                    [[], 'Informe o tipo.'],
                    [['tipo' => 'produto', 'setor' => 'petshop', 'categoria' => 'racao', 'nome_comercial' => 'X', 'preco_centavos' => 'caro', ...self::FLAGS], 'preço'],
                    [['tipo' => 'produto', 'setor' => 'petshop', 'categoria' => 'racao', 'nome_comercial' => 'X', 'preco_centavos' => -5, ...self::FLAGS], 'preço'],
                    [['tipo' => 'brinde', 'setor' => 'petshop', 'categoria' => 'racao', 'nome_comercial' => 'X', 'preco_centavos' => 100, ...self::FLAGS], 'tipo'],
                ],
                'propriedadesCriar' => ['tipo', 'setor', 'categoria', 'nome_comercial', 'preco_centavos', 'cobranca_por', 'controla_estoque', 'estoque', 'exige_receita'],
                'obrigatorios' => ['tipo', 'setor', 'categoria', 'nome_comercial', 'preco_centavos', 'cobranca_por', 'controla_estoque', 'exige_receita'],
                'papelSemEscrita' => 'recepcao', // o catálogo (preços) é só do dono
            ],
        };
    }

    private function valor(mixed $dado): array
    {
        return is_callable($dado) ? $dado() : $dado;
    }

    // ---- consultar_* ----

    #[DataProvider('entidades')]
    public function test_consultar_so_enxerga_o_tenant_atual_e_devolve_as_colunas_da_tool(string $chave): void
    {
        $f = $this->ficha($chave);
        $f['cria']();
        $f['criaDeFora']();

        $this->comando($f['consultar'])
            ->assertOk()
            ->assertJsonPath('tipo', 'resultado_leitura')
            ->assertJsonCount(1, 'resultado')
            ->assertJsonPath('colunas', $f['colunas'])
            ->assertJsonPath('editar', $f['atualizar']);
    }

    #[DataProvider('entidades')]
    public function test_a_consulta_e_auditada_com_o_papel_e_o_tenant(string $chave): void
    {
        $f = $this->ficha($chave);
        $this->logarComo('recepcao', self::T);

        $this->comando($f['consultar'])->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame([$f['consultar'], 'consultar', $f['tipo'], 'sucesso', 'recepcao', true, self::T],
            [$log->tool, $log->acao, $log->entidade_tipo, $log->resultado, $log->papel, $log->permitido, $log->tenant_id]);
    }

    // ---- criar_* ----

    #[DataProvider('entidades')]
    public function test_criar_so_grava_depois_de_confirmar_com_tenant_do_servidor_e_audita(string $chave): void
    {
        $f = $this->ficha($chave);
        $dados = $this->valor($f['criarDados']);

        $token = $this->propor([...$dados, 'tenant_id' => 999], $f['criar']); // o modelo tenta escolher o tenant: ignorado

        $this->assertSame(0, $f['model']::count(), 'propor não grava');
        $this->assertSame(0, AuditLog::count(), 'propor não audita');

        $this->confirmar($token)->assertOk()->assertJsonPath('tipo', 'resultado_escrita');

        $registro = $f['model']::query()->where('tenant_id', self::T)->latest('id')->first();
        $this->assertSame($dados[$f['campoNome']], $registro->{$f['campoNome']});
        $this->assertSame(self::T, $registro->tenant_id);
        $this->assertSame(0, $f['model']::where('tenant_id', 999)->count());

        $log = AuditLog::query()->sole();
        $this->assertSame([$f['criar'], 'criar', $f['tipo'], 'sucesso', 'dono', self::T], [$log->tool, $log->acao, $log->entidade_tipo, $log->resultado, $log->papel, $log->tenant_id]);
        $this->assertSame($registro->id, $log->entidade_id);
    }

    #[DataProvider('entidades')]
    public function test_criar_so_com_o_obrigatorio_e_valido(string $chave): void
    {
        $f = $this->ficha($chave);

        $token = $this->propor($this->valor($f['semNada']), $f['criar']);
        $this->confirmar($token)->assertOk();

        $this->assertSame(1, $f['model']::where('tenant_id', self::T)->count());
    }

    #[DataProvider('entidades')]
    public function test_criar_recusa_dado_invalido_com_frase_amigavel_e_nada_e_gravado(string $chave): void
    {
        $f = $this->ficha($chave);

        foreach ($f['invalidos'] as $i => [$argumentos, $trecho]) {
            $token = $this->propor($this->valor($argumentos), $f['criar']);

            $mensagem = $this->confirmar($token)->assertStatus(422)->assertJsonPath('tipo', 'erro')->json('mensagem');

            $this->assertStringContainsString($trecho, $mensagem);
            foreach (['validation', 'The ', 'field', 'Exception', 'SQL', 'App\\'] as $termo) {
                $this->assertStringNotContainsString($termo, $mensagem, 'sem termo técnico');
            }
            $this->assertSame('revisao_com_erro', AuditLog::query()->latest('id')->first()->resultado);
        }

        $this->assertSame(0, $f['model']::count(), 'nada foi gravado');
    }

    // ---- atualizar_* ----

    #[DataProvider('entidades')]
    public function test_atualizar_acha_pelo_nome_no_servidor_grava_so_o_que_mudou_e_guarda_o_antes(string $chave): void
    {
        $f = $this->ficha($chave);
        $registro = $f['cria']();
        $f['criaDeFora']();
        $antes = $registro->getAttributes();

        $resposta = $this->comando($f['atualizar'], ['busca' => 'Alfa', ...$f['muda']])
            ->assertOk()
            ->assertJsonPath('tipo', 'confirmacao_pendente');
        $token = $this->revisarEPegarToken($resposta->json('proposta'));
        $this->confirmar($token)->assertOk()->assertJsonPath('tipo', 'resultado_escrita');

        $depois = $registro->fresh();
        foreach ($f['muda'] as $campo => $valor) {
            $this->assertEquals($valor, $depois->{$campo});
            $this->assertNotEquals($valor, $antes[$campo] ?? null);
        }
        $this->assertSame($antes[$f['campoNome']], $depois->{$f['campoNome']}, 'o que não mudou continua igual');

        $log = AuditLog::query()->latest('id')->first();
        $this->assertSame([$f['atualizar'], 'atualizar', $registro->id, 'sucesso'], [$log->tool, $log->acao, $log->entidade_id, $log->resultado]);
        $this->assertSame(array_keys($f['muda']), array_keys($log->valores_anteriores), 'a auditoria guarda o valor de antes dos campos que mudaram');
    }

    #[DataProvider('entidades')]
    public function test_atualizar_nao_enxerga_registro_de_outro_tenant(string $chave): void
    {
        $f = $this->ficha($chave);
        $f['criaDeFora']();

        $this->comando($f['atualizar'], ['busca' => 'Alfa', ...$f['muda']])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', fn (string $m) => str_starts_with($m, 'Não encontrei'));
    }

    // ---- RBAC ----

    #[DataProvider('entidades')]
    public function test_papel_antigo_ou_desconhecido_nao_usa_nenhuma_tool_da_clinica_e_a_negacao_e_auditada(string $chave): void
    {
        $f = $this->ficha($chave);

        foreach (['leitura', 'operador', 'admin'] as $papel) {
            $this->logarComo($papel, self::T);

            $this->comando($f['criar'], $this->valor($f['semNada']))->assertStatus(403)->assertJsonPath('tipo', 'negado');
            $this->comando($f['consultar'])->assertStatus(403)->assertJsonPath('tipo', 'negado');
        }

        $this->assertSame(0, $f['model']::count());
        $this->assertSame('negado', AuditLog::query()->latest('id')->first()->resultado);
    }

    #[DataProvider('entidades')]
    public function test_quem_pode_criar_cria_e_quem_nao_pode_e_negado(string $chave): void
    {
        $f = $this->ficha($chave);
        $permitido = $f['papelSemEscrita'] === null ? 'veterinario' : 'dono';

        $this->logarComo($permitido, self::T);
        $this->confirmar($this->propor($this->valor($f['semNada']), $f['criar']))->assertOk();
        $this->assertSame(1, $f['model']::count());

        if ($f['papelSemEscrita'] !== null) {
            $this->logarComo($f['papelSemEscrita'], self::T);
            $this->comando($f['criar'], $this->valor($f['semNada']))->assertStatus(403)->assertJsonPath('tipo', 'negado')->assertJsonMissingPath('token');
            $this->comando($f['consultar'])->assertOk(); // o papel continua consultando

            $log = AuditLog::query()->where('resultado', 'negado')->latest('id')->first();
            $this->assertSame([$f['criar'], false, $f['papelSemEscrita']], [$log->tool, $log->permitido, $log->papel]);
            $this->assertSame(1, $f['model']::count());
        }
    }

    // ---- esquema enviado à IA ----

    #[DataProvider('entidades')]
    public function test_esquema_das_tools_da_entidade_nunca_tem_tenant_nem_usuario(string $chave): void
    {
        $f = $this->ficha($chave);
        $tools = collect(app(AiAgentService::class)->tools())->keyBy('function.name');

        foreach ([$f['consultar'], $f['criar'], $f['atualizar']] as $nome) {
            $this->assertTrue($tools->has($nome), "{$nome} deve estar no catálogo da clínica");
            $propriedades = array_keys($tools[$nome]['function']['parameters']['properties']);
            $this->assertEmpty(array_intersect(['tenant_id', 'usuario_id', 'user_id', 'autor_id'], $propriedades), "{$nome}: nada de tenant ou usuário no esquema");
        }

        $criar = $tools[$f['criar']]['function']['parameters'];
        $this->assertSame($f['propriedadesCriar'], array_keys($criar['properties']));
        $this->assertSame($f['obrigatorios'], $criar['required']);
        $this->assertArrayNotHasKey('required', $tools[$f['consultar']]['function']['parameters'], 'os filtros da consulta são opcionais');
        $this->assertArrayNotHasKey('required', $tools[$f['atualizar']]['function']['parameters'], 'o servidor acha o registro por id ou busca');
    }

    // ---- domínio do tenant ----

    public function test_tenant_da_clinica_nao_ve_as_tools_de_contabilidade_e_o_contrario(): void
    {
        $nomesDaClinica = collect(app(AiAgentService::class)->tools())->pluck('function.name');
        $this->assertTrue($nomesDaClinica->contains('consultar_tutores'));
        $this->assertFalse($nomesDaClinica->contains('consultar_lancamentos'));
        $this->assertFalse($nomesDaClinica->contains('criar_cliente'));

        $this->logarComo('admin', 1);
        $nomesAntigos = collect(app(AiAgentService::class)->tools())->pluck('function.name');
        $this->assertTrue($nomesAntigos->contains('consultar_lancamentos'));
        $this->assertFalse($nomesAntigos->contains('consultar_tutores'), 'tenant que não é da clínica nunca vê as tools da clínica');
        $this->assertSame(38, $nomesAntigos->count());
    }

    public function test_pedir_tool_do_outro_dominio_e_recusado_e_auditado_como_fora_do_catalogo(): void
    {
        $this->comando('consultar_lancamentos')->assertStatus(422)->assertJsonPath('codigo', 'AI_UNSUPPORTED_OPERATION');
        $this->assertSame(0, Tutor::count());

        $this->logarComo('admin', 1);
        $this->comando('criar_tutor', ['nome' => 'Intruso'])->assertStatus(422)->assertJsonPath('codigo', 'AI_UNSUPPORTED_OPERATION');
        $this->assertSame(0, Tutor::count(), 'a tool da clínica não existe para o tenant de contabilidade');
    }

    public function test_tenant_so_entra_no_dominio_da_clinica_pela_configuracao(): void
    {
        $this->logarComo('dono', 9);
        $this->assertFalse(collect(app(AiAgentService::class)->tools())->pluck('function.name')->contains('consultar_tutores'));

        config(['sentinel.tenants_clinica' => [3, 9]]);
        $this->assertTrue(collect(app(AiAgentService::class)->tools())->pluck('function.name')->contains('consultar_tutores'));
    }

    // ---- catálogo: campos sim/não ----

    /** @return array<string, array{mixed}> */
    public static function simOuNaoInvalidos(): array
    {
        return ['true' => [true], 'false' => [false], '1' => [1], '"1"' => ['1'], '"Sim "' => ['Sim '], '"SIM"' => ['SIM'],
            '"s"' => ['s'], 'vazio' => [''], 'espaço' => [' '], '"não" com acento' => ['não'], 'lista' => [['sim']]];
    }

    #[DataProvider('simOuNaoInvalidos')]
    public function test_campo_sim_ou_nao_do_catalogo_so_aceita_sim_ou_nao_exatos(mixed $valor): void
    {
        foreach (['controla_estoque', 'exige_receita'] as $campo) {
            $token = $this->propor(['tipo' => 'produto', 'setor' => 'clinica', 'categoria' => 'farmacia', 'nome_comercial' => 'Vermífugo',
                'preco_centavos' => 3500, ...self::FLAGS, $campo => $valor], 'criar_item_catalogo');

            $mensagem = $this->confirmar($token)->assertStatus(422)->assertJsonPath('tipo', 'erro')->json('mensagem');

            // Vazio e espaço caem no "obrigatório" ("Informe…"); o resto, na regra estrita.
            $this->assertMatchesRegularExpression('/^((O controle de estoque|A exigência de receita) (deve ser "sim" ou "nao"|informad[oa] não é válid[oa])|Informe (o controle de estoque|a exigência de receita))\./u', $mensagem);
            foreach (['validation', 'The ', 'field', 'boolean'] as $termo) {
                $this->assertStringNotContainsString($termo, $mensagem, 'sem termo técnico');
            }
        }

        $this->assertSame(0, CatalogoItem::count(), 'nada foi gravado');
    }

    public function test_sim_e_nao_exatos_viram_verdadeiro_e_falso(): void
    {
        $base = ['tipo' => 'produto', 'setor' => 'clinica', 'categoria' => 'farmacia', 'preco_centavos' => 3500, 'cobranca_por' => 'unidade'];

        $this->confirmar($this->propor([...$base, 'nome_comercial' => 'Com tudo', 'controla_estoque' => 'sim', 'estoque' => 4, 'exige_receita' => 'sim'], 'criar_item_catalogo'))->assertOk();
        $this->confirmar($this->propor([...$base, 'nome_comercial' => 'Com nao', 'controla_estoque' => 'nao', 'exige_receita' => 'nao'], 'criar_item_catalogo'))->assertOk();

        // O estoque inicial é o único com padrão (0): sem saldo, o produto não sai (falha segura).
        $flags = CatalogoItem::query()->orderBy('id')->get()->map(fn ($i) => [$i->nome_comercial, $i->controla_estoque, $i->exige_receita, $i->estoque])->all();
        $this->assertSame([['Com tudo', true, true, 4], ['Com nao', false, false, 0]], $flags);
    }

    /** @return array<string, array{string, string}> campo obrigatório => trecho da frase */
    public static function camposSemPadrao(): array
    {
        return [
            'exige receita' => ['exige_receita', 'Informe a exigência de receita.'],
            'controla estoque' => ['controla_estoque', 'Informe o controle de estoque.'],
            'cobrança por' => ['cobranca_por', 'Informe a forma de cobrança.'],
        ];
    }

    #[DataProvider('camposSemPadrao')]
    public function test_campo_que_muda_regra_de_negocio_ausente_e_recusado_sem_valor_padrao(string $campo, string $frase): void
    {
        $dados = ['tipo' => 'produto', 'setor' => 'clinica', 'categoria' => 'farmacia', 'nome_comercial' => 'Antibiótico', 'preco_centavos' => 4200, ...self::FLAGS];
        unset($dados[$campo]);

        $mensagem = $this->confirmar($this->propor($dados, 'criar_item_catalogo'))->assertStatus(422)->assertJsonPath('tipo', 'erro')->json('mensagem');

        $this->assertSame($frase, $mensagem);
        $this->assertSame(0, CatalogoItem::count(), 'nada foi gravado: ausente não vira "nao"');
    }

    // ---- lixeira da clínica ----

    public function test_tutor_com_pet_nao_vai_para_a_lixeira_e_o_motivo_aparece_antes_de_segurar(): void
    {
        $tutor = Tutor::create(['nome' => 'Com Pet', 'tenant_id' => self::T]);
        Pet::create(['tutor_id' => $tutor->id, 'nome' => 'Thor', 'especie' => 'cao', 'tenant_id' => self::T]);

        $token = $this->propor(['busca' => 'Com Pet'], 'excluir_tutor');
        $this->confirmar($token)->assertStatus(422)->assertJsonPath('mensagem', fn (string $m) => str_contains($m, 'pets vinculados'));

        $this->assertNull($tutor->fresh()->deleted_at);
    }

    public function test_so_o_dono_exclui_e_o_registro_vai_para_a_lixeira(): void
    {
        $pet = (fn () => Pet::create(['tutor_id' => Tutor::create(['nome' => 'D', 'tenant_id' => self::T])->id, 'nome' => 'Bidu', 'especie' => 'cao', 'tenant_id' => self::T]))();

        $this->logarComo('recepcao', self::T);
        $this->comando('excluir_pet', ['busca' => 'Bidu'])->assertStatus(403);
        $this->assertNull($pet->fresh()->deleted_at);

        $this->logarComo('dono', self::T);
        $this->confirmar($this->propor(['busca' => 'Bidu'], 'excluir_pet'))->assertOk();
        $this->assertNotNull(Pet::withTrashed()->find($pet->id)->deleted_at);
    }

    /** @return array<string, array{string, string}> */
    public static function equipeSemDonoNaLixeira(): array
    {
        $casos = [];
        foreach (['atendente', 'vendedor', 'recepcao', 'veterinario'] as $papel) {
            foreach (['tutor', 'pet', 'item_catalogo'] as $tipo) {
                $casos["{$papel} {$tipo}"] = [$papel, $tipo];
            }
        }

        return $casos;
    }

    /** Item C (12/11): só o dono restaura, pelo chat e pela tela (a recepção já recebia 403 na Lixeira e restaurava pelo chat). */
    #[DataProvider('equipeSemDonoNaLixeira')]
    public function test_so_o_dono_restaura_da_lixeira_inclusive_pelo_chat(string $papel, string $tipo): void
    {
        $registro = match ($tipo) {
            'tutor' => Tutor::create(['nome' => 'Na Lixeira', 'tenant_id' => self::T]),
            'pet' => Pet::create(['tutor_id' => Tutor::create(['nome' => 'Dono do Pet', 'tenant_id' => self::T])->id, 'nome' => 'Na Lixeira', 'especie' => 'cao', 'tenant_id' => self::T]),
            'item_catalogo' => CatalogoItem::create(['tipo' => 'servico', 'setor' => 'clinica', 'categoria' => 'consulta', 'nome_comercial' => 'Na Lixeira',
                'preco_centavos' => 100, 'cobranca_por' => 'unidade', 'controla_estoque' => false, 'exige_receita' => false, 'estoque' => 0, 'tenant_id' => self::T]),
        };
        $registro->delete();

        $this->logarComo($papel, self::T);
        $this->assertFalse(app(AiAgentService::class)->podeExecutar("restaurar_{$tipo}"), 'a tela não mostra o botão');
        $this->comando("restaurar_{$tipo}", ['busca' => 'Na Lixeira'])->assertStatus(403)->assertJsonPath('tipo', 'negado');
        $this->postJson('/agente/formulario', ['tool' => "restaurar_{$tipo}", 'id' => $registro->id])->assertStatus(403);
        $this->get('/lixeira')->assertForbidden();

        $this->assertNotNull($registro::withTrashed()->find($registro->id)->deleted_at, 'continua na lixeira');
        $this->assertSame(['negado'], AuditLog::query()->where('tool', "restaurar_{$tipo}")->pluck('resultado')->unique()->values()->all());
        $this->assertEmpty(session('confirmacoes', []));

        // O dono continua podendo propor a restauração (a senha do dono é pedida no card).
        $this->logarComo('dono', self::T);
        $this->comando("restaurar_{$tipo}", ['busca' => 'Na Lixeira'])->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente');
    }

    /** O passo "Revisar" de uma proposta já existente (sem editar nada). */
    private function revisarEPegarToken(string $proposta): string
    {
        $this->freezeTime();

        return (string) $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->assertOk()->json('token');
    }
}
