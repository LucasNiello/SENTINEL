<?php

namespace Tests\Unit;

use App\Services\GradeDaAgenda;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * As travas do modelo de agenda (adendo final do Lucas, 15/11), em PHP puro, sem banco: clínica em blocos de 1 h; a
 * consulta ocupa 1 de 4 vagas do bloco do veterinário, os demais serviços o bloco inteiro (sozinhos), a cirurgia 2
 * blocos inteiros, a internação não ocupa a agenda; categoria sem regra recusa; bloco em andamento aceita, encerrado
 * recusa. Petshop com um atendimento por vez (encostar não é conflito).
 * A mesma configuração de config/sentinel.php (seção agenda), passada aqui à mão.
 */
class GradeDaAgendaTest extends TestCase
{
    private const FUSO = 'America/Sao_Paulo';

    private GradeDaAgenda $grade;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grade = new GradeDaAgenda([
            'expediente' => ['dias' => [1, 2, 3, 4, 5, 6], 'inicio' => '08:00', 'fim' => '18:00'],
            'clinica' => ['bloco_minutos' => 60, 'vagas' => 4,
                'por_categoria' => ['consulta' => 'vaga', 'vacina' => 1, 'exame' => 1, 'microchipagem' => 1, 'cirurgia' => 2, 'internacao' => 'fora']],
            'petshop' => ['grade_minutos' => 15, 'duracoes' => ['banho' => 60, 'tosa' => 90, '_' => 60]],
        ], self::FUSO);
    }

    /** 2026-11-11 é uma quarta-feira. */
    private function em(string $hora, string $dia = '2026-11-11'): CarbonImmutable
    {
        return CarbonImmutable::parse("{$dia} {$hora}", self::FUSO);
    }

    private function ocupado(string $inicio, string $fim, bool $exclusivo = false): array
    {
        return ['inicio' => $this->em($inicio), 'fim' => $this->em($fim), 'exclusivo' => $exclusivo];
    }

    public function test_o_quinto_no_bloco_lota_e_o_quarto_ainda_entra(): void
    {
        $tres = array_fill(0, 3, $this->ocupado('14:00', '15:00'));
        $quatro = array_fill(0, 4, $this->ocupado('14:00', '15:00'));

        $this->assertSame(1, $this->grade->vagasNoBloco($this->em('14:00'), $tres));
        $this->assertNull($this->grade->motivo('clinica', 'consulta', $this->em('14:00'), $tres), 'o 4º entra');

        $this->assertSame(0, $this->grade->vagasNoBloco($this->em('14:00'), $quatro));
        $this->assertStringContainsString('não tem vaga no bloco das 14:00', (string) $this->grade->motivo('clinica', 'consulta', $this->em('14:00'), $quatro));
        // O bloco vizinho não é afetado: o fim do bloco é o fim do agendamento (encostar não ocupa o seguinte).
        $this->assertNull($this->grade->motivo('clinica', 'consulta', $this->em('15:00'), $quatro));
        $this->assertSame('15:00', $this->grade->fim('clinica', 'consulta', $this->em('14:00'))->format('H:i'), 'fim = fim do bloco, qualquer serviço');
    }

    public function test_cirurgia_de_2_h_fecha_os_dois_blocos_que_cobre_e_so_entra_com_eles_vazios(): void
    {
        $cirurgia = [$this->ocupado('14:00', '16:00', exclusivo: true)];

        $this->assertSame('16:00', $this->grade->fim('clinica', 'cirurgia', $this->em('14:00'))->format('H:i'));
        $this->assertSame(0, $this->grade->vagasNoBloco($this->em('14:00'), $cirurgia), 'bloco das 14h fechado');
        $this->assertSame(0, $this->grade->vagasNoBloco($this->em('15:00'), $cirurgia), 'bloco das 15h fechado');
        $this->assertSame(4, $this->grade->vagasNoBloco($this->em('16:00'), $cirurgia), 'bloco das 16h livre');
        $this->assertNotNull($this->grade->motivo('clinica', 'consulta', $this->em('15:00'), $cirurgia), 'consulta no bloco da cirurgia é recusada');

        // Uma consulta só no 2º bloco já impede a cirurgia (ela ocupa TODAS as vagas dos dois).
        $umaConsulta = [$this->ocupado('15:00', '16:00')];
        $this->assertStringContainsString('ocupa o bloco inteiro', (string) $this->grade->motivo('clinica', 'cirurgia', $this->em('14:00'), $umaConsulta));
        $this->assertNull($this->grade->motivo('clinica', 'cirurgia', $this->em('14:00'), []));
        // Cirurgia às 17:00 não cabe (o 2º bloco passaria do expediente).
        $this->assertNotNull($this->grade->motivo('clinica', 'cirurgia', $this->em('17:00'), []));
    }

    public function test_internacao_nao_ocupa_a_agenda_do_veterinario(): void
    {
        $this->assertTrue($this->grade->foraDaAgenda('internacao'));
        $this->assertSame(0, $this->grade->blocosExclusivos('internacao'));
        // Nem o bloco cheio de consultas impede registrar a internação (ela só tem a data de entrada).
        $this->assertNull($this->grade->motivo('clinica', 'internacao', $this->em('14:00'), array_fill(0, 4, $this->ocupado('14:00', '15:00'))));
    }

    public function test_procedimento_ocupa_o_bloco_inteiro_sozinho(): void
    {
        $umaConsulta = [$this->ocupado('09:00', '10:00')];
        $vacina = [$this->ocupado('09:00', '10:00', exclusivo: true)];

        $this->assertStringContainsString('ocupa o bloco inteiro', (string) $this->grade->motivo('clinica', 'vacina', $this->em('09:00'), $umaConsulta), 'procedimento recusa bloco com 1 consulta');
        $this->assertNull($this->grade->motivo('clinica', 'vacina', $this->em('09:00'), []));
        $this->assertNotNull($this->grade->motivo('clinica', 'consulta', $this->em('09:00'), $vacina), 'consulta recusa bloco com procedimento');
        $this->assertSame('10:00', $this->grade->fim('clinica', 'exame', $this->em('09:00'))->format('H:i'));
        $this->assertTrue($this->grade->mostraVagas('consulta'));
        $this->assertFalse($this->grade->mostraVagas('vacina'), 'o calendário mostra só livre/ocupado fora da consulta');
    }

    public function test_categoria_sem_regra_de_ocupacao_recusa(): void
    {
        $this->assertNull($this->grade->ocupacao('categoria_nova'));
        $this->assertStringContainsString('não tem regra de ocupação', (string) $this->grade->motivo('clinica', 'categoria_nova', $this->em('09:00'), []));
        $this->assertSame(1, $this->grade->blocosExclusivos('categoria_nova'), 'na agenda, quem já está com ela fecha o bloco');
    }

    public function test_bloco_em_andamento_aceita_e_bloco_encerrado_recusa(): void
    {
        $agora = $this->em('14:20');

        $this->assertFalse($this->grade->jaPassou('clinica', $this->em('14:00'), $agora), 'às 14:20 o bloco das 14h ainda vale');
        $this->assertTrue($this->grade->jaPassou('clinica', $this->em('13:00'), $agora), 'o bloco das 13h terminou');
        $this->assertTrue($this->grade->jaPassou('clinica', $this->em('14:00'), $this->em('15:00')), 'às 15:00 o bloco das 14h terminou');

        $horas = array_map(fn (array $h) => $h['inicio']->format('H:i'), $this->grade->horariosDoDia('clinica', 'consulta', $this->em('00:00'), [], $agora));
        $this->assertSame('14:00', $horas[0], 'o calendário mostra o bloco em andamento');
        $this->assertNotContains('13:00', $horas);
    }

    public function test_petshop_recusa_sobreposicao_e_encostar_nao_e_conflito(): void
    {
        $banho = [$this->ocupado('09:00', '10:00')];

        $this->assertSame('O profissional já tem um agendamento nesse horário.', $this->grade->motivo('petshop', 'banho', $this->em('09:30'), $banho));
        $this->assertSame('O profissional já tem um agendamento nesse horário.', $this->grade->motivo('petshop', 'tosa', $this->em('08:00'), $banho), 'tosa de 90 min das 08:00 invade as 09:00');
        $this->assertNull($this->grade->motivo('petshop', 'banho', $this->em('10:00'), $banho), 'começar quando o outro termina não é conflito');
        $this->assertNull($this->grade->motivo('petshop', 'banho', $this->em('08:00'), $banho), 'terminar quando o outro começa não é conflito');

        $livres = array_map(fn (array $h) => $h['inicio']->format('H:i'), $this->grade->horariosDoDia('petshop', 'banho', $this->em('00:00'), $banho, $this->em('07:00')));
        $this->assertNotContains('09:30', $livres);
        $this->assertContains('10:00', $livres);
    }
}
