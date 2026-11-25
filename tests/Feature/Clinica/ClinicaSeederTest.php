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
        if (! in_array($this->name(), ['test_sem_demo_senha_ou_com_senha_fraca_nada_e_gravado', 'test_saida_do_seed_nao_mostra_a_demo_senha', 'test_tenant_fora_da_config_da_clinica_nada_e_gravado', 'test_nenhum_agendamento_cai_no_intervalo_do_expediente'], true)) {
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

        $this->assertCount(11, $usuarios);
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

    public function test_tenant_fora_da_config_da_clinica_nada_e_gravado(): void
    {
        config(['sentinel.tenants_clinica' => [4]]);

        try {
            (new ClinicaSeeder)->run();
            $this->fail('Deveria recusar o tenant fora de SENTINEL_TENANTS_CLINICA.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SENTINEL_TENANTS_CLINICA', $e->getMessage());
        }

        $this->assertSame(0, User::count() + Tutor::count() + CatalogoItem::count());
    }

    public function test_saida_do_seed_nao_mostra_a_demo_senha(): void
    {
        $this->artisan('db:seed', ['--class' => ClinicaSeeder::class])
            ->doesntExpectOutputToContain(self::SENHA)
            ->expectsOutputToContain('Senha: a de DEMO_SENHA')
            ->assertSuccessful();
    }

    public function test_rodar_de_novo_com_outra_demo_senha_nao_troca_a_senha_de_quem_ja_existe(): void
    {
        $hash = User::where('email', 'vet1@clinica.sentinel.br')->value('password');

        $this->definirSenha('Outra-Senha-Forte-99');
        $this->seed(ClinicaSeeder::class);

        $this->assertSame($hash, User::where('email', 'vet1@clinica.sentinel.br')->value('password'));
    }

    public function test_database_seeder_planta_so_a_clinica_num_tenant(): void
    {
        // 17/11: um único tenant. O DatabaseSeeder chama só o ClinicaSeeder e é o único outro seeder da pasta.
        $this->assertStringContainsString('ClinicaSeeder::class', file_get_contents(database_path('seeders/DatabaseSeeder.php')));
        $this->assertEqualsCanonicalizing(['ClinicaSeeder.php', 'DatabaseSeeder.php'], array_map('basename', glob(database_path('seeders/*.php'))));
        $this->assertSame([self::T], User::query()->distinct()->pluck('tenant_id')->map(fn ($t) => (int) $t)->all());
    }

    public function test_contas_por_setor_e_veterinarios_com_crmv(): void
    {
        $usuarios = User::where('tenant_id', self::T)->orderBy('papel')->get();
        $login = fn (User $u) => \Illuminate\Support\Str::before($u->email, '@');

        $this->assertSame(['admin' => 1, 'atendente' => 2, 'dono' => 1, 'recepcao' => 3, 'vendedor' => 2, 'veterinario' => 2], $usuarios->countBy('papel')->all());
        $this->assertEqualsCanonicalizing(['vet1', 'vet2', 'vet3', 'recepcao1', 'recepcao2', 'recepcao3', 'estetica1', 'estetica2', 'vendas1', 'vendas2', 'administracao'],
            $usuarios->map($login)->all());
        $this->assertTrue($usuarios->every(fn ($u) => str_ends_with($u->email, '@clinica.sentinel.br')));
        $this->assertSame('dono', $usuarios->first(fn ($u) => $login($u) === 'vet1')->papel);
        $this->assertEqualsCanonicalizing(['vet1', 'vet2', 'vet3'], $usuarios->whereNotNull('crmv')->map($login)->values()->all());

        // A Administração nasce sem PIN e sem setor (por enquanto só gera e revoga os PINs dos outros).
        $administracao = $usuarios->firstWhere('papel', 'admin');
        $this->assertSame(['administracao@clinica.sentinel.br', null, null], [$administracao->email, $administracao->supervisor_setor, $administracao->pin_autorizacao]);
    }

    public function test_cadastros_e_catalogo_dos_dois_setores_com_farmacia(): void
    {
        $this->assertSame(8, Tutor::where('tenant_id', self::T)->count());
        $this->assertSame(5, Pet::where('tenant_id', self::T)->count());
        $this->assertSame(3, Tutor::where('tenant_id', self::T)->doesntHave('pets')->whereHas('vendas')->count(), 'clientes só da loja');
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

    /**
     * Decisão do Lucas (15/11): a demo não mostra prejuízo nos Resultados. Prova em todo dia, semana e mês dos 35 dias do
     * seed, e que outubro e novembro têm lucro com margem plausível (nem prejuízo, nem despesa quase zero).
     */
    public function test_resultados_do_seed_nao_mostram_prejuizo_em_nenhum_periodo(): void
    {
        $resultados = app(\App\Services\ResultadosClinica::class);
        $hoje = \Carbon\CarbonImmutable::now();

        foreach (['dia', 'semana', 'mes'] as $periodo) {
            for ($d = 0; $d <= 35; $d++) {
                [$inicio, $fim] = $resultados->intervalo($periodo, $hoje->subDays($d));
                $r = $resultados->resumo(self::T, $inicio, $fim);
                $this->assertGreaterThanOrEqual(0, $r['lucro'], "{$periodo} de {$inicio->toDateString()}: lucro {$r['lucro']}");
            }
        }

        foreach ([$hoje, $hoje->subMonthNoOverflow()] as $mes) {
            $r = $resultados->resumo(self::T, ...$resultados->intervalo('mes', $mes));
            $this->assertGreaterThan(0, $r['despesas'], $mes->format('m/Y'));
            $margem = $r['lucro'] / max(1, $r['faturamento']);
            $this->assertTrue($margem > 0.1 && $margem < 0.7, $mes->format('m/Y').': margem '.round($margem * 100).'%');
        }
    }

    /** 23/11, pedidos 20 e 26 da bateria: um bloco de hoje com as 4 vagas do vet2 ocupadas e a atendente ocupada no mesmo horário. */
    public function test_bloco_lotado_para_a_bateria_do_agente(): void
    {
        $vet2 = User::where('email', 'vet2@clinica.sentinel.br')->firstOrFail();
        $lotado = Agendamento::where('tenant_id', self::T)->where('profissional_id', $vet2->id)->where('setor', 'clinica')
            ->where('inicio', '>=', now())->get()->groupBy(fn ($a) => $a->inicio->toDateTimeString())->filter(fn ($g) => $g->count() === 4);
        $this->assertCount(1, $lotado, 'um bloco com as 4 vagas do vet2 ocupadas');
        $bloco = $lotado->keys()->first();
        $this->assertSame(now()->toDateString(), substr($bloco, 0, 10), 'o bloco é de hoje');

        $quinta = app(\App\Services\AgendaService::class)->motivoParaNaoAgendar(['pet_id' => Pet::where('tenant_id', self::T)->where('nome', 'Amora')->value('id'),
            'profissional_id' => $vet2->id, 'catalogo_item_id' => CatalogoItem::where('tenant_id', self::T)->where('nome_comercial', 'Consulta clínica')->value('id'),
            'inicio' => Carbon::parse($bloco), 'fim' => Carbon::parse($bloco)->addMinutes(30)], self::T);
        $this->assertNotNull($quinta, 'a quinta consulta no bloco é recusada');

        $estetica = User::where('email', 'estetica1@clinica.sentinel.br')->firstOrFail();
        $this->assertTrue(Agendamento::where('tenant_id', self::T)->where('profissional_id', $estetica->id)->where('setor', 'petshop')
            ->where('inicio', $bloco)->exists(), 'a atendente está ocupada no mesmo horário (pedido 26)');
    }

    public function test_nenhum_agendamento_cai_no_intervalo_do_expediente(): void
    {
        // O setUp não semeou este teste: o seed roda aqui, com almoço das 12:00 às 13:00 no expediente.
        config(['sentinel.agenda.expediente.intervalo' => ['inicio' => '12:00', 'fim' => '13:00']]);
        $this->seed(ClinicaSeeder::class);

        $agendamentos = Agendamento::where('tenant_id', self::T)->where('inicio', '>=', now()->startOfDay())->get();
        $this->assertGreaterThan(10, $agendamentos->count());
        foreach ($agendamentos as $a) {
            $almocoIni = $a->inicio->copy()->setTime(12, 0);
            $almocoFim = $a->inicio->copy()->setTime(13, 0);
            $this->assertFalse($a->inicio->lt($almocoFim) && $a->fim->gt($almocoIni), "agendamento {$a->id} às {$a->inicio} toca o intervalo");
        }
    }

    public function test_lancamentos_em_varios_meses_e_o_ano_sem_prejuizo(): void
    {
        $meses = Lancamento::where('tenant_id', self::T)->get()->map(fn ($l) => substr((string) $l->data, 0, 7))->unique();
        $this->assertGreaterThanOrEqual(6, $meses->count(), 'despesas em pelo menos 6 meses diferentes');

        $resultados = app(\App\Services\ResultadosClinica::class);
        $hoje = \Carbon\CarbonImmutable::now();
        $ano = $resultados->resumo(self::T, ...$resultados->intervalo('ano', $hoje));
        $this->assertGreaterThan(0, $ano['despesas']);
        $this->assertGreaterThan(0, $ano['lucro'], 'o ano não mostra prejuízo');

        for ($m = $hoje->month; $m >= 1; $m--) {
            $r = $resultados->resumo(self::T, ...$resultados->intervalo('mes', $hoje->setDay(1)->setMonth($m)));
            $this->assertGreaterThanOrEqual(0, $r['lucro'], "mês {$m}: lucro {$r['lucro']}");
        }
    }

    public function test_rodar_de_novo_nao_duplica_nada(): void
    {
        $antes = [User::count(), Tutor::count(), Venda::count(), Cobranca::count()];

        $this->seed(ClinicaSeeder::class);

        $this->assertSame($antes, [User::count(), Tutor::count(), Venda::count(), Cobranca::count()]);
    }
}
