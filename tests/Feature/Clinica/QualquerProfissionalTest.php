<?php

namespace Tests\Feature\Clinica;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Agendamento;
use App\Models\AuditLog;
use App\Models\CatalogoItem;
use App\Models\User;
use App\Services\AgendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\ProvaDeTravas;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * 25/11 (decisão do Lucas): "Qualquer profissional" é escolhido pelo SERVIDOR, na hora de gravar, entre os do setor com
 * vaga no horário: o de menos atendimentos no dia (ativos e realizados), empate no menor id. Cirurgia e internação não
 * usam. Também: o select de serviço da ficha só oferece serviço (não produto), a causa do "Não encontrei o serviço".
 */
class QualquerProfissionalTest extends TestCase
{
    use CenarioClinica, ProvaDeTravas, RefreshDatabase, SimulaAgente;

    private AgendaService $agenda;

    private CatalogoItem $banho;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->agenda = app(AgendaService::class);
        $this->banho = $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho']);
        $this->prepararAgente();
    }

    private function atendente(string $nome, int $tenant = self::T): User
    {
        return User::factory()->papel('atendente')->doTenant($tenant)->create(['name' => $nome]);
    }

    private function agendarQualquer(string $inicio = '2026-11-11 14:00', ?CatalogoItem $servico = null): Agendamento
    {
        return $this->agenda->agendar(['pet_id' => $this->pet()->id, 'profissional_id' => null, 'catalogo_item_id' => ($servico ?? $this->banho)->id, 'inicio' => $inicio], self::T);
    }

    private function jaAgendado(User $profissional, string $inicio, string $fim, string $status = 'agendado'): Agendamento
    {
        return $this->agendamentoDireto($this->pet(), $profissional, $this->banho, ['inicio' => $inicio, 'fim' => $fim, 'status' => $status]);
    }

    public function test_qualquer_escolhe_quem_tem_menos_atendimentos_no_dia(): void
    {
        $ana = $this->atendente('Ana');
        $bia = $this->atendente('Bia');
        $this->jaAgendado($ana, '2026-11-11 09:00', '2026-11-11 10:00');
        $this->jaAgendado($ana, '2026-11-11 11:00', '2026-11-11 12:00');
        $this->jaAgendado($bia, '2026-11-11 09:00', '2026-11-11 10:00');

        $this->assertSame($bia->id, $this->agendarQualquer()->profissional_id, 'Bia tem 1 no dia, Ana tem 2');
    }

    public function test_cancelado_e_faltou_nao_contam_e_realizado_conta(): void
    {
        $ana = $this->atendente('Ana');
        $bia = $this->atendente('Bia');
        $this->jaAgendado($ana, '2026-11-11 09:00', '2026-11-11 10:00', 'cancelado');
        $this->jaAgendado($ana, '2026-11-11 10:00', '2026-11-11 11:00', 'faltou');
        $this->jaAgendado($bia, '2026-11-11 09:00', '2026-11-11 10:00', 'realizado');

        $this->assertSame($ana->id, $this->agendarQualquer()->profissional_id, 'Ana: 0 que contam; Bia: 1 realizado');
    }

    public function test_empate_vai_para_o_menor_id(): void
    {
        $ana = $this->atendente('Ana');
        $this->atendente('Bia');

        $this->assertSame($ana->id, $this->agendarQualquer()->profissional_id);
    }

    public function test_so_escolhe_quem_tem_vaga_no_horario_e_a_corrida_em_sequencia_nao_dobra(): void
    {
        $ana = $this->atendente('Ana');
        $bia = $this->atendente('Bia');

        $this->assertSame($ana->id, $this->agendarQualquer()->profissional_id, '1ª confirmação: Ana (menor id)');
        $this->assertSame($bia->id, $this->agendarQualquer()->profissional_id, '2ª, mesmo horário: Ana já está ocupada, vai para Bia');
        $this->recusa(fn () => $this->agendarQualquer(), 'Nenhum profissional livre neste horário');
        $this->assertSame(2, Agendamento::count(), 'a 3ª não gravou nada');
    }

    public function test_atendente_novo_amplia_a_disponibilidade(): void
    {
        $this->atendente('Ana');
        $this->atendente('Bia');
        $this->agendarQualquer();
        $this->agendarQualquer();
        $this->recusa(fn () => $this->agendarQualquer(), 'Nenhum profissional livre');

        $cris = $this->atendente('Cris');

        $this->assertSame($cris->id, $this->agendarQualquer()->profissional_id, 'a nova atendente abre o horário que estava cheio');
        $disp = $this->agenda->disponibilidadeDoDia($this->banho->id, '2026-11-11', null, null, ['petshop'], self::T);
        $this->assertContains('Cris', array_column($disp['profissionais'], 'nome'));
    }

    public function test_nunca_escolhe_profissional_de_outro_tenant(): void
    {
        $ana = $this->atendente('Ana');
        $this->atendente('Fora', self::OUTRO_TENANT);
        $this->agendarQualquer();

        $this->recusa(fn () => $this->agendarQualquer(), 'Nenhum profissional livre');
        $this->assertSame([$ana->id], Agendamento::query()->pluck('profissional_id')->all());
    }

    public function test_clinica_qualquer_veterinario_distribui_e_respeita_o_limite_de_4_por_bloco(): void
    {
        $rafael = $this->usuarioClinica('veterinario');
        $lia = $this->usuarioClinica('veterinario');
        $consulta = $this->item();
        $pet = $this->pet();
        for ($i = 0; $i < 4; $i++) {
            $this->agendamentoDireto($pet, $rafael, $consulta, ['inicio' => '2026-11-11 14:00', 'fim' => '2026-11-11 15:00', 'status' => 'agendado']);
        }

        $novo = $this->agenda->agendar(['pet_id' => $pet->id, 'profissional_id' => '', 'catalogo_item_id' => $consulta->id, 'inicio' => '2026-11-11 14:00'], self::T);

        $this->assertSame($lia->id, $novo->profissional_id, 'Rafael já tem as 4 vagas do bloco');
    }

    public function test_cirurgia_nao_tem_qualquer_o_profissional_e_explicito(): void
    {
        $this->usuarioClinica('veterinario');
        $cirurgia = $this->item(['categoria' => 'cirurgia', 'nome_comercial' => 'Castração']);

        $this->recusa(fn () => $this->agendarQualquer('2026-11-11 14:00', $cirurgia), 'não tem "qualquer profissional"');
        $this->assertSame(0, Agendamento::count());
    }

    public function test_a_escolha_automatica_trava_os_candidatos_e_a_leitura_dos_agendamentos(): void
    {
        $this->atendente('Ana');
        $this->atendente('Bia');
        $this->mostrarTravas();

        $travadas = $this->leiturasTravadas(fn () => $this->agendarQualquer());

        // O SQLite dos testes ignora FOR UPDATE: a trava é provada pela consulta que a pede (como em AgendaLimiteERemarcarTest).
        // Os candidatos do setor são travados num SELECT só, em ordem de id (a mesma ordem de remarcar e trocar: sem impasse).
        $this->assertTravouLeitura($travadas, 'users', 'order by "id"');
        $this->assertTravouLeitura($travadas, 'agendamentos', 'where "tenant_id" = ?');
    }

    public function test_remarcar_em_branco_mantem_o_atual_se_ele_tem_vaga_e_senao_vai_para_o_livre(): void
    {
        $ana = $this->atendente('Ana');
        $bia = $this->atendente('Bia');
        $meu = $this->jaAgendado($ana, '2026-11-11 09:00', '2026-11-11 10:00');

        $this->assertSame($ana->id, $this->agenda->remarcar($meu->id, '2026-11-11 14:00', self::T, null)->profissional_id, 'Ana está livre às 14h: continua com ela');

        $this->jaAgendado($ana, '2026-11-12 14:00', '2026-11-12 15:00');
        $this->assertSame($bia->id, $this->agenda->remarcar($meu->id, '2026-11-12 14:00', self::T, '')->profissional_id, 'Ana ocupada às 14h do dia 12: vai para Bia');
    }

    public function test_remarcar_em_branco_sem_ninguem_livre_recusa_e_nao_muda_nada(): void
    {
        $ana = $this->atendente('Ana');
        $meu = $this->jaAgendado($ana, '2026-11-11 09:00', '2026-11-11 10:00');
        $this->jaAgendado($ana, '2026-11-12 14:00', '2026-11-12 15:00');

        // Ninguém livre: a grade explica o conflito (a mesma frase de antes), em vez de uma frase genérica.
        $this->recusa(fn () => $this->agenda->remarcar($meu->id, '2026-11-12 14:00', self::T, null), 'O profissional já tem um agendamento nesse horário.');
        $this->assertSame('2026-11-11 09:00', $meu->fresh()->inicio->format('Y-m-d H:i'));
    }

    public function test_trocar_o_servico_em_branco_escolhe_o_profissional(): void
    {
        $ana = $this->atendente('Ana');
        $bia = $this->atendente('Bia');
        $tosa = $this->item(['setor' => 'petshop', 'categoria' => 'tosa', 'nome_comercial' => 'Tosa']);
        $meu = $this->jaAgendado($ana, '2026-11-11 14:00', '2026-11-11 15:00');

        $trocado = $this->agenda->trocarServico($meu->id, $tosa->id, null, '2026-11-11 14:00', self::T);

        $this->assertSame([$tosa->id, $ana->id], [(int) $trocado->catalogo_item_id, (int) $trocado->profissional_id], 'Ana continua se tiver vaga (o próprio agendamento não conta)');
        $this->assertNotSame($bia->id, $trocado->profissional_id);
    }

    public function test_profissional_explicito_continua_valendo_e_invalido_continua_recusado(): void
    {
        $ana = $this->atendente('Ana');
        $bia = $this->atendente('Bia');

        $this->assertSame($bia->id, $this->agenda->agendar(['pet_id' => $this->pet()->id, 'profissional_id' => $bia->id, 'catalogo_item_id' => $this->banho->id, 'inicio' => '2026-11-11 14:00'], self::T)->profissional_id);
        $this->recusa(fn () => $this->agenda->agendar(['pet_id' => $this->pet()->id, 'profissional_id' => 999999, 'catalogo_item_id' => $this->banho->id, 'inicio' => '2026-11-11 15:00'], self::T), 'profissional');
        $this->assertNotNull($ana);
    }

    public function test_pelo_agente_sem_profissional_o_servidor_escolhe_e_diz_quem_na_mensagem_e_na_auditoria(): void
    {
        $this->logarComo('recepcao', self::T);
        $ana = $this->atendente('Ana');
        $pet = $this->pet();

        $this->comando('agendar_atendimento', ['pet_id' => $pet->id, 'catalogo_item_id' => $this->banho->id, 'inicio' => '2026-11-11 14:00'])
            ->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente');
        $resposta = $this->confirmar($this->propor(['pet_id' => $pet->id, 'catalogo_item_id' => $this->banho->id, 'inicio' => '2026-11-11 14:00'], 'agendar_atendimento'));

        $resposta->assertOk();
        $this->assertStringContainsString('Profissional escolhido: Ana.', $resposta->json('mensagem'));
        $this->assertStringNotContainsString('Ficou sem', $resposta->json('mensagem'), 'profissional em branco não é dado faltando');
        $this->assertSame($ana->id, Agendamento::query()->sole()->profissional_id);
        $log = AuditLog::query()->where('tool', 'agendar_atendimento')->where('resultado', 'sucesso')->sole();
        $this->assertSame([$ana->id, true], [(int) $log->parametros['profissional_id'], $log->parametros['profissional_escolhido_pelo_servidor']]);
    }

    public function test_o_select_de_servico_da_ficha_nao_oferece_produto(): void
    {
        $this->logarComo('recepcao', self::T);
        $produto = $this->produto(['setor' => 'petshop', 'nome_comercial' => 'Caminha tamanho M']);

        $campos = $this->comando('agendar_atendimento', ['pet_id' => $this->pet()->id])->assertOk()->json('formulario.campos');
        $opcoes = array_column(collect($campos)->firstWhere('nome', 'catalogo_item_id')['opcoes'], 'valor');

        $this->assertContains($this->banho->id, $opcoes);
        $this->assertNotContains($produto->id, $opcoes, 'produto no select de Serviço causava "Não encontrei o serviço."');
    }

    public function test_produto_como_servico_e_recusado_na_validacao_do_agendar(): void
    {
        $this->logarComo('recepcao', self::T);
        $this->atendente('Ana');
        $produto = $this->produto(['setor' => 'petshop', 'nome_comercial' => 'Caminha tamanho M']);

        $this->comando('agendar_atendimento', ['pet_id' => $this->pet()->id, 'catalogo_item_id' => $produto->id, 'inicio' => '2026-11-11 14:00'])->assertOk();
        $resposta = $this->confirmar($this->propor(['pet_id' => $this->pet()->id, 'catalogo_item_id' => $produto->id, 'inicio' => '2026-11-11 14:00'], 'agendar_atendimento'));

        $this->assertNotSame(200, $resposta->status());
        $this->assertSame(0, Agendamento::count());
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
}
