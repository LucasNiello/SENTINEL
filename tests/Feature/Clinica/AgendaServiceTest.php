<?php

namespace Tests\Feature\Clinica;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Agendamento;
use App\Services\AgendaService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

class AgendaServiceTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    private AgendaService $agenda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->agenda = app(AgendaService::class);
    }

    private function dados(array $extra = []): array
    {
        $this->vet ??= $this->usuarioClinica('veterinario');
        $this->consulta ??= $this->item();
        $this->thor ??= $this->pet();

        return ['pet_id' => $this->thor->id, 'profissional_id' => $this->vet->id, 'catalogo_item_id' => $this->consulta->id,
            'inicio' => '2026-11-11 14:00', 'fim' => '2026-11-11 14:30', ...$extra];
    }

    private $vet = null;

    private $consulta = null;

    private $thor = null;

    private function recusa(callable $acao, string $frase): void
    {
        try {
            $acao();
            $this->fail("Esperava a recusa: {$frase}");
        } catch (RegraDeNegocioException $e) {
            $this->assertStringContainsString($frase, $e->getMessage());
        }
    }

    public function test_agenda_copiando_o_setor_do_servico_no_fuso_de_sao_paulo(): void
    {
        $agendamento = $this->agenda->agendar($this->dados(), self::T);

        $this->assertSame(['clinica', 'agendado', 'normal', self::T], [$agendamento->setor, $agendamento->status, $agendamento->origem, $agendamento->tenant_id]);
        $this->assertSame('2026-11-11 14:00:00', $agendamento->fresh()->getRawOriginal('inicio'));
    }

    public function test_recusa_conflito_de_horario_do_mesmo_profissional(): void
    {
        $this->agenda->agendar($this->dados(), self::T);

        $this->recusa(fn () => $this->agenda->agendar($this->dados(['inicio' => '2026-11-11 14:15', 'fim' => '2026-11-11 14:45']), self::T),
            'O profissional já tem um agendamento nesse horário.');
        $this->recusa(fn () => $this->agenda->agendar($this->dados(['inicio' => '2026-11-11 13:00', 'fim' => '2026-11-11 15:00']), self::T),
            'já tem um agendamento');
        $this->assertSame(1, Agendamento::count());
        $this->assertSame('O profissional já tem um agendamento nesse horário.',
            $this->agenda->motivoParaNaoAgendar($this->dados(['inicio' => '2026-11-11 14:10']), self::T));
    }

    public function test_horarios_que_encostam_e_outro_profissional_nao_conflitam(): void
    {
        $this->agenda->agendar($this->dados(), self::T);

        $this->agenda->agendar($this->dados(['inicio' => '2026-11-11 14:30', 'fim' => '2026-11-11 15:00']), self::T);
        $this->agenda->agendar($this->dados(['profissional_id' => $this->usuarioClinica('dono')->id]), self::T);

        $this->assertSame(3, Agendamento::count());
    }

    public function test_cancelado_e_faltou_liberam_o_horario(): void
    {
        $primeiro = $this->agenda->agendar($this->dados(), self::T);
        $this->agenda->alterarStatus($primeiro->id, 'cancelado', self::T);
        $segundo = $this->agenda->agendar($this->dados(), self::T);
        $this->agenda->alterarStatus($segundo->id, 'faltou', self::T);

        $this->agenda->agendar($this->dados(), self::T);

        $this->assertSame(3, Agendamento::count());
    }

    public function test_encaixe_de_emergencia_nao_confere_conflito_mas_so_aceita_inicio_ate_agora(): void
    {
        $this->agenda->agendar($this->dados(['inicio' => '2026-11-10 10:00', 'fim' => '2026-11-10 11:00']), self::T);

        $encaixe = $this->agenda->agendar($this->dados(['inicio' => '2026-11-10 09:45', 'fim' => '2026-11-10 10:15', 'origem' => 'encaixe_emergencia']), self::T);
        $this->assertSame('encaixe_emergencia', $encaixe->origem);

        // "Agora" vale; um minuto depois, não.
        $this->agenda->agendar($this->dados(['inicio' => '2026-11-10 10:00', 'fim' => '2026-11-10 10:20', 'origem' => 'encaixe_emergencia']), self::T);
        $this->recusa(fn () => $this->agenda->agendar($this->dados(['inicio' => '2026-11-10 10:01', 'fim' => '2026-11-10 10:30', 'origem' => 'encaixe_emergencia']), self::T),
            'o início não pode estar no futuro');
        $this->assertSame(3, Agendamento::count());
    }

    public function test_agendamento_normal_nao_cai_em_cima_de_um_encaixe(): void
    {
        $this->agenda->agendar($this->dados(['inicio' => '2026-11-10 09:00', 'fim' => '2026-11-10 11:00', 'origem' => 'encaixe_emergencia']), self::T);

        $this->recusa(fn () => $this->agenda->agendar($this->dados(['inicio' => '2026-11-10 10:30', 'fim' => '2026-11-10 11:30']), self::T),
            'já tem um agendamento');
    }

    public function test_encaixe_de_emergencia_e_so_da_clinica(): void
    {
        $banho = $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho']);

        $this->recusa(fn () => $this->agenda->agendar($this->dados(['catalogo_item_id' => $banho->id, 'profissional_id' => $this->usuarioClinica('atendente')->id,
            'inicio' => '2026-11-10 08:00', 'fim' => '2026-11-10 09:00', 'origem' => 'encaixe_emergencia']), self::T), 'só da clínica');
    }

    public function test_profissional_segue_o_setor_clinica_veterinario_ou_dono_petshop_atendente(): void
    {
        $banho = $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho']);
        $atendente = $this->usuarioClinica('atendente');

        $this->recusa(fn () => $this->agenda->agendar($this->dados(['profissional_id' => $atendente->id]), self::T), 'precisa ser um veterinário');
        $this->recusa(fn () => $this->agenda->agendar($this->dados(['profissional_id' => $this->usuarioClinica('recepcao')->id]), self::T), 'precisa ser um veterinário');
        $this->recusa(fn () => $this->agenda->agendar($this->dados(['catalogo_item_id' => $banho->id]), self::T), 'precisa ser um atendente');

        $this->agenda->agendar($this->dados(['catalogo_item_id' => $banho->id, 'profissional_id' => $atendente->id]), self::T);
        $this->agenda->agendar($this->dados(['profissional_id' => $this->usuarioClinica('dono')->id]), self::T);
        $this->assertSame(2, Agendamento::count());
    }

    public function test_produto_nao_e_agendado_e_fim_precisa_ser_depois_do_inicio(): void
    {
        $this->recusa(fn () => $this->agenda->agendar($this->dados(['catalogo_item_id' => $this->produto()->id]), self::T), 'é um produto');
        $this->recusa(fn () => $this->agenda->agendar($this->dados(['fim' => '2026-11-11 14:00']), self::T), 'O fim precisa ser depois do início.');
        $this->recusa(fn () => $this->agenda->agendar($this->dados(['inicio' => 'amanhã cedo']), self::T), 'Informe o início e o fim');
    }

    public function test_referencias_de_outro_tenant_sao_tratadas_como_inexistentes(): void
    {
        $dados = $this->dados();
        $petDeFora = $this->pet(null, self::OUTRO_TENANT);
        $vetDeFora = $this->usuarioClinica('veterinario', self::OUTRO_TENANT);
        $servicoDeFora = $this->item([], self::OUTRO_TENANT);

        $this->recusa(fn () => $this->agenda->agendar([...$dados, 'pet_id' => $petDeFora->id], self::T), "Não encontrei o pet #{$petDeFora->id}.");
        $this->recusa(fn () => $this->agenda->agendar([...$dados, 'profissional_id' => $vetDeFora->id], self::T), 'Não encontrei o profissional');
        $this->recusa(fn () => $this->agenda->agendar([...$dados, 'catalogo_item_id' => $servicoDeFora->id], self::T), 'Não encontrei o serviço');
        $this->assertSame(0, Agendamento::count());

        $agendamento = $this->agenda->agendar($dados, self::T);
        $this->expectException(ModelNotFoundException::class);
        $this->agenda->alterarStatus($agendamento->id, 'confirmado', self::OUTRO_TENANT);
    }

    public function test_agendamento_normal_no_passado_e_recusado_mas_agora_vale(): void
    {
        $this->recusa(fn () => $this->agenda->agendar($this->dados(['inicio' => '2026-11-10 09:59', 'fim' => '2026-11-10 10:30']), self::T),
            'Não dá para agendar no passado');
        $this->assertSame(0, Agendamento::count());

        $this->agenda->agendar($this->dados(['inicio' => '2026-11-10 10:00', 'fim' => '2026-11-10 10:30']), self::T);
        $this->assertSame(1, Agendamento::count());
    }

    public function test_duracao_maxima_vem_da_configuracao_e_vale_tambem_no_encaixe(): void
    {
        $this->recusa(fn () => $this->agenda->agendar($this->dados(['inicio' => '2026-11-11 08:00', 'fim' => '2026-11-11 16:01']), self::T),
            'dura no máximo 8 h');
        $this->agenda->agendar($this->dados(['inicio' => '2026-11-11 08:00', 'fim' => '2026-11-11 16:00']), self::T);

        // O limite não é número fixo no código: mudou a configuração, mudou a regra.
        config(['sentinel.agenda_duracao_maxima_minutos' => 45]);
        $this->recusa(fn () => $this->agenda->agendar($this->dados(['inicio' => '2026-11-12 08:00', 'fim' => '2026-11-12 08:46']), self::T),
            'dura no máximo 45 min');
        $this->agenda->agendar($this->dados(['inicio' => '2026-11-12 08:00', 'fim' => '2026-11-12 08:45']), self::T);

        // Encaixe de emergência não confere conflito, mas também não pode virar o dia inteiro do veterinário.
        $this->recusa(fn () => $this->agenda->agendar($this->dados(['inicio' => '2026-11-10 09:00', 'fim' => '2026-11-10 10:00', 'origem' => 'encaixe_emergencia']), self::T),
            'dura no máximo 45 min');
        $this->assertSame(2, Agendamento::count());
    }

    public function test_sem_duracao_configurada_o_servidor_recusa(): void
    {
        config(['sentinel.agenda_duracao_maxima_minutos' => null]);

        $this->recusa(fn () => $this->agenda->agendar($this->dados(), self::T), 'dura no máximo');
        $this->assertSame(0, Agendamento::count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('formatosSoltos')]
    public function test_data_em_formato_solto_ou_com_fuso_e_recusada(string $inicio): void
    {
        $this->recusa(fn () => $this->agenda->agendar($this->dados(['inicio' => $inicio, 'fim' => '2026-11-12 18:00']), self::T),
            'Informe o início e o fim com data e hora');
        $this->assertSame(0, Agendamento::count());
    }

    public static function formatosSoltos(): array
    {
        return [
            'dia e mês invertidos' => ['11/12/2026 15:00'],
            'sem hora' => ['2026-11-11'],
            'palavra do inglês' => ['tomorrow 15:00'],
            'relativa' => ['+1 day'],
            'amanhã' => ['amanhã às 15h'],
            'sufixo Z' => ['2026-11-11T16:00:00Z'],
            'fuso explícito' => ['2026-11-11 16:00:00-03:00'],
            'dia que não existe' => ['2026-02-31 10:00'],
            'hora que não existe' => ['2026-11-11 25:00'],
        ];
    }

    public function test_encaixe_com_hora_de_outro_fuso_nao_fura_o_inicio_ate_agora(): void
    {
        // 13:00Z são 10:00 em São Paulo, mas a frase com fuso é recusada: nunca vira "13:00 local" no futuro.
        $this->recusa(fn () => $this->agenda->agendar($this->dados(['inicio' => '2026-11-10T13:00:00Z', 'fim' => '2026-11-10T13:30:00Z', 'origem' => 'encaixe_emergencia']), self::T),
            'Informe o início e o fim com data e hora');
        $this->assertSame(0, Agendamento::count());

        $this->agenda->agendar($this->dados(['inicio' => '2026-11-10T09:00', 'fim' => '2026-11-10 09:30:00', 'origem' => 'encaixe_emergencia']), self::T);
        $this->assertSame(1, Agendamento::count());
    }

    public function test_agendamento_com_prontuario_nao_vira_cancelado_nem_faltou(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $agendamento = $this->agendamentoDireto($this->pet(), $vet, $this->item(), ['status' => 'agendado']);
        $this->prontuarioDireto($agendamento->pet, $vet, ['agendamento_id' => $agendamento->id]);

        foreach (['cancelado', 'faltou'] as $status) {
            $this->recusa(fn () => $this->agenda->alterarStatus($agendamento->id, $status, self::T), 'já tem prontuário');
        }
        $this->assertSame('agendado', $agendamento->fresh()->status);

        // Realizado continua valendo: é o desfecho normal de quem tem prontuário.
        $this->agenda->alterarStatus($agendamento->id, 'realizado', self::T);
        $this->assertSame('realizado', $agendamento->fresh()->status);
    }

    public function test_cobranca_viva_do_agendamento_e_a_nao_cancelada(): void
    {
        $agendamento = $this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item());
        $cancelada = \App\Models\Cobranca::create(['agendamento_id' => $agendamento->id, 'setor' => 'clinica', 'status' => 'cancelada', 'total_centavos' => 0, 'tenant_id' => self::T]);
        $this->assertNull($agendamento->fresh()->cobranca);

        $viva = \App\Models\Cobranca::create(['agendamento_id' => $agendamento->id, 'setor' => 'clinica', 'status' => 'aberta', 'total_centavos' => 0, 'tenant_id' => self::T]);

        $this->assertSame($viva->id, $agendamento->fresh()->cobranca->id);
        $this->assertEqualsCanonicalizing([$cancelada->id, $viva->id], $agendamento->fresh()->cobrancas->pluck('id')->all());
    }

    public function test_transicoes_de_status(): void
    {
        $agendamento = $this->agenda->agendar($this->dados(), self::T);

        $this->agenda->alterarStatus($agendamento->id, 'confirmado', self::T);
        $this->agenda->alterarStatus($agendamento->id, 'realizado', self::T);

        $this->recusa(fn () => $this->agenda->alterarStatus($agendamento->id, 'cancelado', self::T), 'Um agendamento realizado não pode passar para cancelado.');
        $this->assertSame('realizado', $agendamento->fresh()->status);
    }
}
