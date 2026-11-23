<?php

namespace Tests\Feature\Clinica;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Agendamento;
use App\Models\AuditLog;
use App\Models\ConfiguracaoAgenda;
use App\Services\AgendaService;
use App\Services\ConfiguracaoDaAgenda;
use App\Services\GradeDaAgenda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

/**
 * Painel do dono (23/11): só o dono abre e grava; o expediente, o intervalo e as vagas por bloco ficam no banco por tenant
 * (sem registro vale a config) e a GradeDaAgenda os respeita em todo agendamento. As outras abas só leem.
 */
class PainelDonoTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    private const DIA = '2026-11-13'; // sexta, dia de expediente

    private AgendaService $agenda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->agenda = app(AgendaService::class);
    }

    private function salvar(array $campos = []): \Illuminate\Testing\TestResponse
    {
        return $this->post('/painel/clinica', ['abre' => '08:00', 'fecha' => '18:00', 'intervalo_inicio' => '', 'intervalo_fim' => '', 'vagas' => '4', ...$campos]);
    }

    private function consulta(int $vetId, string $hora, int $tenant = self::T): Agendamento
    {
        return $this->agenda->agendar(['pet_id' => $this->pet(null, $tenant)->id, 'catalogo_item_id' => $this->itemConsulta($tenant)->id,
            'profissional_id' => $vetId, 'inicio' => self::DIA.' '.$hora], $tenant);
    }

    private function itemConsulta(int $tenant = self::T): \App\Models\CatalogoItem
    {
        return \App\Models\CatalogoItem::query()->where('tenant_id', $tenant)->where('categoria', 'consulta')->first() ?? $this->item([], $tenant);
    }

    private function recusa(callable $acao, string $frase): void
    {
        try {
            $acao();
            $this->fail("Esperava a recusa: {$frase}");
        } catch (RegraDeNegocioException $e) {
            $this->assertStringContainsString($frase, $e->getMessage());
        }
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function quemAbre(): array
    {
        return ['dono' => ['dono', 200], 'veterinario' => ['veterinario', 403], 'recepcao' => ['recepcao', 403],
            'atendente' => ['atendente', 403], 'vendedor' => ['vendedor', 403]];
    }

    #[DataProvider('quemAbre')]
    public function test_so_o_dono_abre_o_painel_e_so_o_dono_grava(string $papel, int $status): void
    {
        $this->logarComo($papel, self::T);

        $this->get('/painel')->assertStatus($status);
        $this->salvar(['abre' => '09:00', 'vagas' => '2'])->assertStatus($status === 200 ? 302 : 403);
        $this->assertSame($status === 200 ? 1 : 0, ConfiguracaoAgenda::count());
    }

    public function test_nao_dono_recebe_403_e_a_negacao_fica_na_auditoria(): void
    {
        $this->logarComo('veterinario', self::T);

        $this->post('/painel/clinica', ['abre' => '09:00', 'fecha' => '17:00', 'vagas' => '2'])->assertForbidden();

        $this->assertSame(0, ConfiguracaoAgenda::count());
        $this->assertDatabaseHas('audit_logs', ['tool' => 'salvar_configuracao_agenda', 'resultado' => 'negado', 'permitido' => false]);
    }

    public function test_admin_da_clinica_vai_para_a_supervisao_e_nao_grava_e_fora_da_clinica_nao_existe_e_visitante_vai_ao_login(): void
    {
        $this->logarComo('admin', self::T);
        $this->get('/painel')->assertRedirect('/supervisao');
        $this->salvar(['vagas' => '2'])->assertForbidden();
        $this->assertSame(0, ConfiguracaoAgenda::count());

        $this->logarComo('operador', 5);
        $this->get('/painel')->assertNotFound();
        $this->salvar()->assertNotFound();
    }

    public function test_visitante_sem_login_vai_para_o_login(): void
    {
        $this->get('/painel')->assertRedirect('/login');
        // O POST sem login não chega ao controller (419 do CSRF ou redirect do auth, o que vier primeiro): o que vale é não gravar.
        $this->assertContains($this->post('/painel/clinica', ['vagas' => '2'])->getStatusCode(), [302, 419]);
        $this->assertSame(0, ConfiguracaoAgenda::count());
    }

    public function test_sem_registro_no_banco_vale_a_config(): void
    {
        $valores = app(ConfiguracaoDaAgenda::class)->valores(self::T);

        $this->assertSame(config('sentinel.agenda.expediente.inicio'), $valores['abre']);
        $this->assertSame(config('sentinel.agenda.expediente.fim'), $valores['fecha']);
        $this->assertSame(config('sentinel.agenda.clinica.vagas'), $valores['vagas']);
        $this->assertNull($valores['intervalo_inicio']);
        $this->assertFalse($valores['personalizada']);

        // E a agenda continua com as 4 vagas da config: a 4ª entra e a 5ª é recusada.
        $vet = $this->usuarioClinica('veterinario');
        foreach (range(1, 4) as $i) {
            $this->consulta($vet->id, '09:00');
        }
        $this->recusa(fn () => $this->consulta($vet->id, '09:00'), 'não tem vaga no bloco das 09:00');
    }

    public function test_dono_salva_o_expediente_e_a_grade_respeita(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $this->logarComo('dono', self::T);

        $this->salvar(['abre' => '09:00', 'fecha' => '17:00'])->assertRedirect('/painel?aba=clinica')->assertSessionHas('ok');

        $this->assertDatabaseHas('configuracoes_agenda', ['tenant_id' => self::T, 'expediente_inicio' => '09:00', 'expediente_fim' => '17:00']);
        $this->consulta($vet->id, '09:00');
        $this->consulta($vet->id, '16:00');
        // 08:00 (antes de abrir) e 17:00 (depois de fechar) já não são blocos.
        $this->recusa(fn () => $this->consulta($vet->id, '08:00'), 'escolha um bloco');
        $this->recusa(fn () => $this->consulta($vet->id, '17:00'), 'escolha um bloco');
        $grade = GradeDaAgenda::daConfiguracao(self::T);
        $this->assertSame('09:00', $grade->blocosDoDia(Carbon::parse(self::DIA))[0]->format('H:i'));
        $this->assertCount(8, $grade->blocosDoDia(Carbon::parse(self::DIA)));
    }

    public function test_vagas_por_bloco_alteradas_mudam_o_limite(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $this->logarComo('dono', self::T);

        $this->salvar(['vagas' => '2'])->assertSessionHas('ok');
        $this->consulta($vet->id, '09:00');
        $this->consulta($vet->id, '09:00');
        $this->recusa(fn () => $this->consulta($vet->id, '09:00'), 'não tem vaga no bloco das 09:00');

        $this->salvar(['vagas' => '5'])->assertSessionHas('ok');
        $this->consulta($vet->id, '09:00');
        $this->consulta($vet->id, '09:00');
        $this->consulta($vet->id, '09:00');
        $this->recusa(fn () => $this->consulta($vet->id, '09:00'), 'não tem vaga no bloco das 09:00');
        $this->assertSame(1, ConfiguracaoAgenda::count(), 'salvar de novo troca a linha do tenant');
    }

    public function test_a_configuracao_de_um_tenant_nao_vale_para_o_outro(): void
    {
        $this->logarComo('dono', self::T);
        $this->salvar(['abre' => '10:00', 'fecha' => '12:00', 'vagas' => '1'])->assertSessionHas('ok');

        $outro = app(ConfiguracaoDaAgenda::class)->valores(self::OUTRO_TENANT);
        $this->assertSame(config('sentinel.agenda.expediente.inicio'), $outro['abre']);
        $this->assertSame(config('sentinel.agenda.clinica.vagas'), $outro['vagas']);
        $this->assertFalse($outro['personalizada']);
        $this->assertSame(1, GradeDaAgenda::daConfiguracao(self::T)->vagasPorBloco());
        $this->assertSame(4, GradeDaAgenda::daConfiguracao(self::OUTRO_TENANT)->vagasPorBloco());
    }

    public function test_intervalo_fecha_os_blocos_dele_e_a_cirurgia_e_o_petshop_nao_passam_por_ele(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $banho = $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho']);
        $atendente = $this->usuarioClinica('atendente');
        $this->logarComo('dono', self::T);
        $this->salvar(['intervalo_inicio' => '12:00', 'intervalo_fim' => '13:00'])->assertSessionHas('ok');

        $this->consulta($vet->id, '11:00');
        $this->consulta($vet->id, '13:00');
        $this->recusa(fn () => $this->consulta($vet->id, '12:00'), 'escolha um bloco');
        $this->assertCount(9, GradeDaAgenda::daConfiguracao(self::T)->blocosDoDia(Carbon::parse(self::DIA)));

        // Cirurgia de 2 blocos seguidos não cabe em 11:00 (o bloco seguinte é o intervalo).
        $cirurgia = $this->item(['categoria' => 'cirurgia', 'nome_comercial' => 'Castração']);
        $this->recusa(fn () => $this->agenda->agendar(['pet_id' => $this->pet()->id, 'catalogo_item_id' => $cirurgia->id, 'profissional_id' => $vet->id,
            'inicio' => self::DIA.' 11:00'], self::T, ['ator' => $vet, 'reautenticado' => true]), 'o intervalo do expediente está no meio');

        // Banho e tosa: 11:30 + 60 min invade o intervalo; 11:00 + 60 min encosta e passa.
        $dados = fn (string $hora) => ['pet_id' => $this->pet()->id, 'catalogo_item_id' => $banho->id, 'profissional_id' => $atendente->id, 'inicio' => self::DIA.' '.$hora];
        $this->recusa(fn () => $this->agenda->agendar($dados('11:30'), self::T), 'não pode passar pelo intervalo');
        $this->agenda->agendar($dados('11:00'), self::T);
        $this->agenda->agendar($dados('13:00'), self::T);
    }

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function entradasInvalidas(): array
    {
        return [
            'abre depois de fechar' => [['abre' => '18:00', 'fecha' => '08:00'], 'fechar depois de abrir'],
            'hora fora do formato' => [['abre' => '8h'], 'HH:MM'],
            'só o início do intervalo' => [['intervalo_inicio' => '12:00'], 'os dois em branco'],
            'intervalo fora do expediente' => [['intervalo_inicio' => '07:00', 'intervalo_fim' => '09:00'], 'dentro do expediente'],
            'intervalo invertido' => [['intervalo_inicio' => '13:00', 'intervalo_fim' => '12:00'], 'dentro do expediente'],
            'zero vagas' => [['vagas' => '0'], 'vão de 1 a 20'],
            'vagas demais' => [['vagas' => '21'], 'vão de 1 a 20'],
            'vagas não numéricas' => [['vagas' => 'abc'], 'vão de 1 a 20'],
            'vagas decimais' => [['vagas' => '2.5'], 'vão de 1 a 20'],
        ];
    }

    #[DataProvider('entradasInvalidas')]
    public function test_entrada_invalida_e_recusada_sem_gravar_e_fica_na_auditoria(array $campos, string $frase): void
    {
        $this->logarComo('dono', self::T);

        $this->salvar($campos)->assertRedirect('/painel?aba=clinica')->assertSessionHasErrors('clinica');

        $this->assertStringContainsString($frase, session('errors')->first('clinica'));
        $this->assertSame(0, ConfiguracaoAgenda::count());
        $this->assertDatabaseHas('audit_logs', ['tool' => 'salvar_configuracao_agenda', 'resultado' => 'recusado']);
    }

    public function test_salvar_audita_com_os_valores_anteriores(): void
    {
        $dono = $this->logarComo('dono', self::T);

        $this->salvar(['abre' => '09:00', 'vagas' => '3'])->assertSessionHas('ok');

        $log = AuditLog::query()->where('tool', 'salvar_configuracao_agenda')->where('resultado', 'sucesso')->firstOrFail();
        $this->assertSame($dono->id, (int) $log->user_id);
        $this->assertSame('dono', $log->papel);
        $this->assertSame('08:00', $log->valores_anteriores['abre']);
        $this->assertSame(4, $log->valores_anteriores['vagas']);
    }

    public function test_dashboard_mostra_contagens_reais_so_do_tenant(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $this->travelTo(Carbon::parse('2026-11-13 07:00', 'America/Sao_Paulo'));
        $this->consulta($vet->id, '09:00');
        $this->consulta($vet->id, '10:00');
        $this->consulta($this->usuarioClinica('veterinario', self::OUTRO_TENANT)->id, '09:00', self::OUTRO_TENANT);
        $this->produto(['estoque' => 3]);
        $this->produto(['estoque' => 5, 'nome_comercial' => 'Areia']);
        $this->produto(['estoque' => 9], self::OUTRO_TENANT);
        $this->logarComo('dono', self::T);

        $resposta = $this->get('/painel')->assertOk()->assertSee('Painel do Dono');

        $cards = $resposta->viewData('indicadores');
        $this->assertSame(['Agenda de hoje', 'Funcionários', 'Estoque', 'Financeiro'], array_column($cards, 'titulo'));
        $this->assertSame('2', $cards[0]['numero']);
        $this->assertSame('2', $cards[1]['numero'], 'o veterinário e o dono do tenant');
        $this->assertSame('2 itens', $cards[2]['numero']);
        $this->assertSame('R$ 0,00', $cards[3]['numero']);
        $resposta->assertSee('href="/agenda"', false)->assertSee('href="/resultados"', false)->assertDontSee('AMOSTRA');
    }

    public function test_abas_de_leitura_listam_so_o_tenant_com_link_para_as_telas_atuais(): void
    {
        $tutor = $this->tutor(self::T, 'Marina Alves');
        $pet = $this->pet($tutor, self::T, ['nome' => 'Mel']);
        $this->tutor(self::OUTRO_TENANT, 'Tutor Alheio');
        $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho completo']);
        $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho do outro'], self::OUTRO_TENANT);
        $vet = $this->usuarioClinica('veterinario');
        $this->usuarioClinica('veterinario', self::OUTRO_TENANT);
        $this->logarComo('dono', self::T);

        $this->get('/painel?aba=funcionarios')->assertOk()->assertSee($vet->name)->assertSee('href="/supervisao"', false);
        $this->get('/painel?aba=clientes')->assertOk()->assertSee('Marina Alves')->assertSee('href="/tutores/'.$tutor->id.'"', false)
            ->assertSee('href="/pets/'.$pet->id.'"', false)->assertDontSee('Tutor Alheio');
        $this->get('/painel?aba=petshop')->assertOk()->assertSee('Banho completo')->assertSee('60 minutos')->assertDontSee('Banho do outro')
            ->assertSee('href="/cadastros"', false);
        $this->get('/painel?aba=clinica')->assertOk()->assertSee('Vagas por bloco')->assertSee('name="abre"', false);
        // Aba desconhecida cai no dashboard.
        $this->get('/painel?aba=qualquer')->assertOk()->assertSee('Agenda de hoje');
    }

    public function test_menu_so_mostra_o_painel_para_o_dono(): void
    {
        $this->logarComo('dono', self::T);
        $this->get('/agenda')->assertSee('href="/painel"', false);

        $this->logarComo('recepcao', self::T);
        $this->get('/agenda')->assertDontSee('href="/painel"', false);
    }

    public function test_a_amostra_do_matheus_continua_la_com_o_selo(): void
    {
        $this->logarComo('dono', self::T);

        $this->get('/resultados?amostra=painel-dono')->assertOk()->assertSee('AMOSTRA');
    }
}
