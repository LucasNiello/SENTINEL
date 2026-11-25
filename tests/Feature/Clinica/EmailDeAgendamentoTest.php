<?php

namespace Tests\Feature\Clinica;

use App\Mail\AgendamentoMail;
use App\Models\Agendamento;
use App\Models\Tutor;
use App\Services\AgendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

/**
 * 24/11: o tutor recebe e-mail ao CRIAR, CONFIRMAR e CANCELAR o agendamento (Mailpit na demo). Disparado depois do commit;
 * sem e-mail do tutor = não envia; falha de envio nunca afeta o agendamento e só deixa log com metadados.
 */
class EmailDeAgendamentoTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    private AgendaService $agenda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->agenda = app(AgendaService::class);
    }

    private function dados(?string $emailDoTutor = 'ana@exemplo.com', string $nomeDoPet = 'Thor', int $tenant = self::T): array
    {
        $tutor = Tutor::create(['nome' => 'Ana Souza', 'email' => $emailDoTutor, 'tenant_id' => $tenant]);
        $pet = $this->pet($tutor, $tenant, ['nome' => $nomeDoPet]);

        return ['pet_id' => $pet->id, 'profissional_id' => $this->usuarioClinica('veterinario', $tenant)->id,
            'catalogo_item_id' => $this->item([], $tenant)->id, 'inicio' => '2026-11-11 14:00'];
    }

    public function test_criar_envia_ao_tutor_com_pet_servico_e_horario_e_sem_preco(): void
    {
        Mail::fake();

        $this->agenda->agendar($this->dados(), self::T);

        Mail::assertSent(AgendamentoMail::class, 1);
        Mail::assertSent(AgendamentoMail::class, function (AgendamentoMail $m) {
            $m->assertTo('ana@exemplo.com');
            $m->assertSeeInText('Thor');
            $m->assertSeeInText('Consulta clínica');
            $m->assertSeeInText('11/11/2026 às 14:00');
            $m->assertDontSeeInText('150');   // sem preço
            $m->assertHasSubject('Agendamento recebido — Thor');

            return $m->evento === 'criado';
        });
    }

    public function test_confirmar_e_cancelar_enviam_e_os_outros_status_nao(): void
    {
        Mail::fake();
        $porStatus = [];
        foreach (['confirmado', 'cancelado', 'realizado', 'faltou'] as $status) {
            $agendamento = $this->agenda->agendar([...$this->dados(), 'inicio' => '2026-11-11 14:00'], self::T);
            Mail::fake(); // zera: só o que o status gerou
            $this->agenda->alterarStatus($agendamento->id, $status, self::T);
            $porStatus[$status] = Mail::sent(AgendamentoMail::class)->map(fn ($m) => $m->evento)->all();
        }

        $this->assertSame(['confirmado'], $porStatus['confirmado']);
        $this->assertSame(['cancelado'], $porStatus['cancelado']);
        $this->assertSame([], $porStatus['realizado']);
        $this->assertSame([], $porStatus['faltou']);
    }

    public function test_tutor_sem_email_ou_com_email_invalido_nao_recebe_e_o_agendamento_segue(): void
    {
        Mail::fake();

        foreach ([null, '', 'isto-nao-e-email', 'a b@exemplo.com'] as $email) {
            $agendamento = $this->agenda->agendar($this->dados($email), self::T);
            $this->assertDatabaseHas('agendamentos', ['id' => $agendamento->id, 'status' => 'agendado']);
        }

        Mail::assertNothingSent();
    }

    public function test_tutor_de_outro_tenant_nunca_recebe(): void
    {
        Mail::fake();
        $dados = $this->dados();
        // O pet é do tenant 3, mas o tutor ao qual ele aponta é do tenant 4: nada sai.
        $tutorAlheio = Tutor::create(['nome' => 'Alheio', 'email' => 'alheio@exemplo.com', 'tenant_id' => self::OUTRO_TENANT]);
        \App\Models\Pet::whereKey($dados['pet_id'])->update(['tutor_id' => $tutorAlheio->id]);

        $this->agenda->agendar($dados, self::T);

        Mail::assertNothingSent();
    }

    public function test_encaixe_de_emergencia_nao_envia(): void
    {
        Mail::fake();

        $this->agenda->agendar([...$this->dados(), 'inicio' => '2026-11-10 09:00', 'origem' => 'encaixe_emergencia'], self::T);

        Mail::assertNothingSent();
    }

    public function test_o_seeder_e_a_chave_de_config_desligam_o_envio(): void
    {
        Mail::fake();
        config(['sentinel.notificar_agendamento' => false]);

        $this->agenda->agendar($this->dados(), self::T);

        Mail::assertNothingSent();
    }

    public function test_so_sai_depois_do_commit_transacao_desfeita_nao_envia_e_a_feita_envia_uma_vez(): void
    {
        Mail::fake();
        $dados = $this->dados();

        try {
            DB::transaction(function () use ($dados) {
                $this->agenda->agendar($dados, self::T);
                throw new RuntimeException('desfaz');
            });
        } catch (RuntimeException) {
        }
        Mail::assertNothingSent();
        $this->assertSame(0, Agendamento::count());

        DB::transaction(fn () => $this->agenda->agendar($dados, self::T));
        Mail::assertSent(AgendamentoMail::class, 1);
    }

    public function test_falha_do_envio_nao_afeta_o_agendamento_e_o_log_so_tem_metadados(): void
    {
        Log::spy();
        Mail::shouldReceive('to')->andThrow(new \Symfony\Component\Mailer\Exception\TransportException('Connection refused para ana@exemplo.com'));

        $agendamento = $this->agenda->agendar($this->dados(), self::T);

        $this->assertDatabaseHas('agendamentos', ['id' => $agendamento->id, 'status' => 'agendado']);
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $mensagem, array $contexto) {
            $this->assertEqualsCanonicalizing(['categoria', 'evento', 'excecao', 'local'], array_keys($contexto));
            $this->assertStringNotContainsString('ana@exemplo.com', $mensagem.json_encode($contexto));
            $this->assertStringNotContainsString('Connection refused', json_encode($contexto));

            return $contexto['evento'] === 'criado';
        });
    }

    public function test_nome_com_quebra_de_linha_nao_forja_linha_nem_cabecalho(): void
    {
        Mail::fake();

        $this->agenda->agendar($this->dados('ana@exemplo.com', "Thor\r\nBcc: espiao@exemplo.com"), self::T);

        Mail::assertSent(AgendamentoMail::class, function (AgendamentoMail $m) {
            $this->assertSame('Thor Bcc: espiao@exemplo.com', $m->pet);
            $m->assertHasSubject('Agendamento recebido — Thor Bcc: espiao@exemplo.com');
            $this->assertStringNotContainsString("\nBcc:", $m->render());

            return true;
        });
    }
}
