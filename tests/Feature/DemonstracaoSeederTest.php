<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CategoriaLancamento;
use App\Models\Cliente;
use App\Models\Fornecedor;
use App\Models\Funcionario;
use App\Models\Lancamento;
use App\Models\NotaFiscal;
use App\Models\User;
use App\Services\ClienteService;
use App\Services\LancamentoService;
use Database\Seeders\DemonstracaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemonstracaoSeederTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-de-teste-da-demo';

    private const T = DemonstracaoSeeder::TENANT_DEMO;

    protected function tearDown(): void
    {
        $this->definirSenha(null);

        parent::tearDown();
    }

    private function definirSenha(?string $senha): void
    {
        if ($senha === null) {
            unset($_ENV['DEMO_SENHA'], $_SERVER['DEMO_SENHA']);
            putenv('DEMO_SENHA');

            return;
        }

        $_ENV['DEMO_SENHA'] = $_SERVER['DEMO_SENHA'] = $senha;
        putenv("DEMO_SENHA={$senha}");
    }

    private function semear(): string
    {
        $this->assertSame(0, Artisan::call('db:seed', ['--class' => DemonstracaoSeeder::class, '--force' => true]));

        return Artisan::output();
    }

    /** @return array<string, int> */
    private function contagens(): array
    {
        return [
            'users' => User::query()->count(),
            'categorias' => CategoriaLancamento::withTrashed()->count(),
            'clientes' => Cliente::withTrashed()->count(),
            'fornecedores' => Fornecedor::withTrashed()->count(),
            'funcionarios' => Funcionario::withTrashed()->count(),
            'lancamentos' => Lancamento::withTrashed()->count(),
            'notas' => NotaFiscal::withTrashed()->count(),
        ];
    }

    public function test_cria_o_volume_combinado_e_rodar_de_novo_nao_duplica(): void
    {
        $this->definirSenha(self::SENHA);
        $saida = $this->semear();

        $this->assertSame(4, CategoriaLancamento::where('tenant_id', self::T)->count());
        $this->assertSame(6, Cliente::withTrashed()->where('tenant_id', self::T)->count());
        $this->assertSame(4, Fornecedor::where('tenant_id', self::T)->count());
        $this->assertSame(4, Funcionario::where('tenant_id', self::T)->count());
        $this->assertSame(1, Funcionario::where('tenant_id', self::T)->whereNotNull('data_demissao')->count());
        $this->assertSame(30, Lancamento::withTrashed()->where('tenant_id', self::T)->count());
        $this->assertSame(6, NotaFiscal::where('tenant_id', self::T)->whereNotNull('lancamento_id')->count());
        $this->assertSame(['admin', 'leitura', 'operador'], User::where('tenant_id', self::T)->orderBy('papel')->pluck('papel')->all());
        $this->assertSame(1, User::where('tenant_id', DemonstracaoSeeder::TENANT_ISOLAMENTO)->count());
        $this->assertSame(2, Cliente::where('tenant_id', self::T)->where('nome', 'like', 'Padaria Trigo Bom%')->count());
        $this->assertSame('18500.00', NotaFiscal::where('tenant_id', self::T)->where('numero', '1006')->value('valor'));
        $this->assertStringNotContainsString(self::SENHA, $saida);

        $antes = $this->contagens();
        $this->assertStringNotContainsString(self::SENHA, $this->semear());
        $this->assertSame($antes, $this->contagens());
    }

    public function test_pendentes_de_setembro_cabem_num_lote_e_o_tenant_102_fica_isolado(): void
    {
        $this->definirSenha(self::SENHA);
        $this->semear();
        $service = app(LancamentoService::class);

        $ids = $service->idsPendentesNoPeriodo('2026-09-01', '2026-09-30', null, self::T, 26);
        $this->assertGreaterThanOrEqual(8, count($ids));
        $this->assertLessThanOrEqual(12, count($ids));
        $this->assertSame([self::T], Lancamento::whereIn('id', $ids)->distinct()->pluck('tenant_id')->all());

        $doOutro = $service->idsPendentesNoPeriodo('2026-09-01', '2026-09-30', null, DemonstracaoSeeder::TENANT_ISOLAMENTO, 26);
        $this->assertCount(2, $doOutro);
        $this->assertSame([], array_intersect($ids, $doOutro));
    }

    public function test_usuarios_entram_com_a_senha_da_variavel_e_existente_nao_tem_a_senha_trocada(): void
    {
        $existente = User::create(['name' => 'Já existia', 'email' => 'operador@demo.sentinel.local', 'password' => 'outra-senha-qualquer', 'papel' => 'operador', 'tenant_id' => self::T]);
        $hashOriginal = $existente->password;

        $this->definirSenha(self::SENHA);
        $this->semear();

        $this->assertTrue(Hash::check(self::SENHA, User::where('email', 'admin@demo.sentinel.local')->value('password')));
        $this->assertSame($hashOriginal, $existente->fresh()->password);
    }

    public function test_nao_grava_nada_em_audit_logs(): void
    {
        $this->definirSenha(self::SENHA);
        $this->semear();

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_sem_demo_senha_falha_e_nao_grava_nada(): void
    {
        $this->definirSenha(null);

        try {
            (new DemonstracaoSeeder)->run();
            $this->fail('Sem DEMO_SENHA o seeder deveria falhar.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('DEMO_SENHA', $e->getMessage());
        }

        $this->assertSame(0, array_sum($this->contagens()));
    }

    public function test_excluir_a_oficina_vem_bloqueado_com_motivo_em_linguagem_simples(): void
    {
        $this->definirSenha(self::SENHA);
        $this->semear();
        $oficina = Cliente::where('tenant_id', self::T)->where('nome', 'Oficina Mecânica Engrenagem')->sole();

        $resultado = app(ClienteService::class)->excluir($oficina->id, self::T);

        $this->assertTrue($resultado['bloqueado']);
        $this->assertStringStartsWith('Não é possível excluir:', $resultado['motivo']);
        $this->assertNull($oficina->fresh()->deleted_at);
    }

    public function test_os_dois_itens_da_lixeira_seguem_no_prazo_depois_do_arquivamento(): void
    {
        $this->definirSenha(self::SENHA);
        $this->semear();

        $this->assertSame(0, Artisan::call('sentinel:arquivar-expirados'));

        $naLixeira = [
            Cliente::onlyTrashed()->where('tenant_id', self::T)->sole(),
            Lancamento::onlyTrashed()->where('tenant_id', self::T)->sole(),
        ];

        foreach ($naLixeira as $registro) {
            $this->assertNull($registro->fresh()->arquivado_em);
        }
        $this->assertSame('Studio Fit Academia', $naLixeira[0]->nome);
        $this->assertSame('Café e copa — outubro', $naLixeira[1]->descricao);
    }
}
