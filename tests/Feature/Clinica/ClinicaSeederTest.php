<?php

namespace Tests\Feature\Clinica;

use App\Models\Agendamento;
use App\Models\CatalogoItem;
use App\Models\Cobranca;
use App\Models\Lancamento;
use App\Models\Mensagem;
use App\Models\Pet;
use App\Models\Prontuario;
use App\Models\Tutor;
use App\Models\User;
use App\Models\Venda;
use Database\Seeders\ClinicaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClinicaSeederTest extends TestCase
{
    use RefreshDatabase;

    private const T = ClinicaSeeder::TENANT;

    private const SENHA = 'Clinica-Demo-2026!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 08:00', 'America/Sao_Paulo'));
        $this->definirSenha(self::SENHA);
        if ($this->name() !== 'test_sem_demo_senha_ou_com_senha_fraca_nada_e_gravado') {
            $this->seed(ClinicaSeeder::class);
        }
    }

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

    public function test_todas_as_contas_usam_a_demo_senha_guardada_so_como_hash(): void
    {
        $usuarios = User::where('tenant_id', self::T)->get();

        $this->assertCount(5, $usuarios);
        foreach ($usuarios as $usuario) {
            $this->assertNotSame(self::SENHA, $usuario->getRawOriginal('password'));
            $this->assertTrue(Hash::check(self::SENHA, $usuario->password), $usuario->email);
        }
    }

    public function test_sem_demo_senha_ou_com_senha_fraca_nada_e_gravado(): void
    {
        foreach ([[null, 'DEMO_SENHA'], ['curta-9x!', 'curta demais'], ['sentinel123', 'senhas conhecidas']] as [$senha, $trecho]) {
            $this->definirSenha($senha);
            try {
                (new ClinicaSeeder)->run();
                $this->fail('Deveria recusar: '.$trecho);
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($trecho, $e->getMessage());
            }
        }

        $this->assertSame(0, User::count() + Tutor::count() + CatalogoItem::count());
    }

    public function test_rodar_de_novo_com_outra_demo_senha_nao_troca_a_senha_de_quem_ja_existe(): void
    {
        $hash = User::where('email', 'dono@clinica.sentinel.local')->value('password');

        $this->definirSenha('Outra-Senha-Forte-99');
        $this->seed(ClinicaSeeder::class);

        $this->assertSame($hash, User::where('email', 'dono@clinica.sentinel.local')->value('password'));
    }

    public function test_fica_fora_do_database_seeder(): void
    {
        $this->assertStringNotContainsString('ClinicaSeeder', file_get_contents(database_path('seeders/DatabaseSeeder.php')));
    }

    public function test_um_usuario_por_papel_e_dono_e_veterinario_com_crmv(): void
    {
        $usuarios = User::where('tenant_id', self::T)->orderBy('papel')->get();

        $this->assertSame(['atendente', 'dono', 'recepcao', 'vendedor', 'veterinario'], $usuarios->pluck('papel')->all());
        $this->assertSame(['dono', 'veterinario'], $usuarios->whereNotNull('crmv')->pluck('papel')->values()->all());
    }

    public function test_cadastros_e_catalogo_dos_dois_setores_com_farmacia(): void
    {
        $this->assertSame(8, Tutor::where('tenant_id', self::T)->count());
        $this->assertSame(12, Pet::where('tenant_id', self::T)->count());
        $this->assertTrue(CatalogoItem::where('tenant_id', self::T)->where('setor', 'petshop')->exists());
        $this->assertTrue(CatalogoItem::where('tenant_id', self::T)->where('categoria', 'farmacia')->where('exige_receita', true)->exists());
        $this->assertTrue(CatalogoItem::where('tenant_id', self::T)->where('cobranca_por', 'diaria')->exists());
    }

    public function test_agenda_da_semana_e_emergencia_sem_agendamento(): void
    {
        $semana = Agendamento::where('tenant_id', self::T)->whereBetween('inicio', [now()->startOfDay(), now()->addDays(7)])->get();
        $this->assertGreaterThanOrEqual(12, $semana->count());
        $this->assertEqualsCanonicalizing(['clinica', 'petshop'], $semana->pluck('setor')->unique()->values()->all());

        $emergencia = Prontuario::where('tenant_id', self::T)->whereNull('agendamento_id')->sole();
        $this->assertSame('emergencia', $emergencia->tipo);
        $this->assertNotNull($emergencia->assinado_em);
    }

    public function test_cobrancas_e_vendas_pagas_e_em_aberto_espalhadas_no_ultimo_mes(): void
    {
        $pagas = Cobranca::where('tenant_id', self::T)->where('status', 'paga')->get();
        $this->assertGreaterThanOrEqual(10, $pagas->count());
        $this->assertTrue($pagas->every(fn ($c) => $c->pago_em->between(now()->subDays(31), now())));
        $this->assertGreaterThan(5, $pagas->map(fn ($c) => $c->pago_em->toDateString())->unique()->count(), 'pagamentos em dias diferentes');
        $this->assertEqualsCanonicalizing(['clinica', 'petshop'], $pagas->pluck('setor')->unique()->values()->all());

        $this->assertGreaterThanOrEqual(2, Cobranca::where('tenant_id', self::T)->where('status', 'fechada')->count(), 'a receber');
        $this->assertSame(1, Cobranca::where('tenant_id', self::T)->where('status', 'aberta')->count());
        $this->assertSame(2, Venda::where('tenant_id', self::T)->where('status', 'aberta')->count());
        $this->assertGreaterThanOrEqual(8, Venda::where('tenant_id', self::T)->where('status', 'paga')->count());

        // Internação: 3 diárias de R$ 180,00 copiadas para a cobrança.
        $this->assertDatabaseHas('cobranca_itens', ['tenant_id' => self::T, 'descricao' => 'Internação (diária)', 'quantidade' => 3, 'subtotal_centavos' => 54000]);
    }

    public function test_despesas_gerais_e_mensagens(): void
    {
        $this->assertGreaterThanOrEqual(5, Lancamento::where('tenant_id', self::T)->count());
        $this->assertTrue(Lancamento::where('tenant_id', self::T)->with('categoriaLancamento')->get()->every(fn ($l) => $l->categoriaLancamento->tipo === 'despesa'));
        $this->assertSame(5, Mensagem::where('tenant_id', self::T)->count());
        $this->assertSame(4, Mensagem::where('tenant_id', self::T)->where('status', 'nova')->count());
    }

    public function test_rodar_de_novo_nao_duplica_nada(): void
    {
        $antes = [User::count(), Tutor::count(), Venda::count(), Cobranca::count()];

        $this->seed(ClinicaSeeder::class);

        $this->assertSame($antes, [User::count(), Tutor::count(), Venda::count(), Cobranca::count()]);
    }
}
