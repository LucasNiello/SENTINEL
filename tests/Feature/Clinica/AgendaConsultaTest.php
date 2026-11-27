<?php

namespace Tests\Feature\Clinica;

use App\Models\Agendamento;
use App\Models\Cobranca;
use App\Services\AgendaService;
use App\Services\Agente\FraseDaAgenda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/** 26/11: consulta da agenda com tipo de data (marcado/gravação/alteração), ordenar, limite, serviço, hora, passado/futuro e a frase do servidor. */
class AgendaConsultaTest extends TestCase
{
    public function test_ordenado_pela_alteracao_realca_todas_as_linhas_do_mesmo_lote(): void
    {
        $linha = fn (int $id, string $alterado) => ['id' => $id, 'horario' => '28/11/2026 09:00–10:00', 'pet' => 'Thor', 'tutor' => 'Ana', 'servico' => 'Banho',
            'profissional' => 'Ana', 'status' => 'agendado', 'gravado_em' => '20/11/2026 10:00', 'alterado_em' => $alterado];
        $linhas = [$linha(3, '26/11/2026 23:33'), $linha(2, '26/11/2026 23:33'), $linha(1, '26/11/2026 23:24')];

        $comLimite = \App\Services\Agente\FraseDaAgenda::montar($linhas, ['ordenar' => 'alteracao', 'limite' => 10]);
        $this->assertSame([3, 2], $comLimite['destacar'], 'o lote inteiro (mesmo minuto), não só a primeira');
        $this->assertStringContainsString('Os 2 realçados foram alterados juntos, às 23:33 (o mais recente); os outros 1 são de alterações anteriores.', $comLimite['frase']);

        $porMarcado = \App\Services\Agente\FraseDaAgenda::montar($linhas, ['ordenar' => 'marcado', 'limite' => 10]);
        $this->assertSame([3], $porMarcado['destacar'], 'outras ordenações continuam realçando só a primeira');
    }

    use CenarioClinica, RefreshDatabase, SimulaAgente;

    private AgendaService $agenda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 12:00', 'America/Sao_Paulo'));
        $this->agenda = app(AgendaService::class);
    }

    private function marcar(array $extra, $pet = null, $item = null): Agendamento
    {
        return $this->agendamentoDireto($pet ?? $this->pet(), $this->usuarioClinica('veterinario'), $item ?? $this->item(), $extra);
    }

    public function test_o_ultimo_realizado_e_o_mais_recente_pelo_dia_marcado_sem_limite_de_periodo(): void
    {
        $velho = $this->marcar(['inicio' => '2026-10-01 09:00', 'fim' => '2026-10-01 09:30', 'status' => 'realizado']);
        $novo = $this->marcar(['inicio' => '2026-10-20 10:00', 'fim' => '2026-10-20 10:30', 'status' => 'realizado']);
        $this->marcar(['inicio' => '2026-10-25 10:00', 'fim' => '2026-10-25 10:30', 'status' => 'cancelado']);

        $r = $this->agenda->buscarAvancado(['status' => 'realizado', 'ordenar' => 'marcado', 'sentido' => 'desc', 'limite' => 1], self::T);

        $this->assertSame([$novo->id], $r->pluck('id')->all());
        $this->assertNotContains($velho->id, $r->pluck('id')->all());
    }

    public function test_tipo_de_data_gravacao_filtra_pela_criacao_e_nao_pelo_dia_marcado(): void
    {
        $ag = $this->marcar(['inicio' => '2026-12-20 10:00', 'fim' => '2026-12-20 10:30', 'status' => 'agendado']);
        $ag->forceFill(['created_at' => '2026-11-09 08:00:00'])->save();
        $outro = $this->marcar(['inicio' => '2026-12-21 10:00', 'fim' => '2026-12-21 10:30', 'status' => 'agendado']);
        $outro->forceFill(['created_at' => '2026-11-01 08:00:00'])->save();

        $r = $this->agenda->buscarAvancado(['tipo_data' => 'gravacao', 'dia' => '2026-11-09'], self::T);

        $this->assertSame([$ag->id], $r->pluck('id')->all());
    }

    public function test_ordenar_por_gravacao_difere_de_ordenar_pelo_dia_marcado(): void
    {
        $a = $this->marcar(['inicio' => '2026-10-01 09:00', 'fim' => '2026-10-01 09:30', 'status' => 'realizado']);
        $b = $this->marcar(['inicio' => '2026-10-02 09:00', 'fim' => '2026-10-02 09:30', 'status' => 'realizado']);
        $a->forceFill(['created_at' => '2026-11-05 10:00:00'])->save(); // marcado antes, mas gravado por último
        $b->forceFill(['created_at' => '2026-11-01 10:00:00'])->save();

        $this->assertSame($b->id, $this->agenda->buscarAvancado(['ordenar' => 'marcado', 'sentido' => 'desc', 'limite' => 1], self::T)->first()->id);
        $this->assertSame($a->id, $this->agenda->buscarAvancado(['ordenar' => 'gravacao', 'sentido' => 'desc', 'limite' => 1], self::T)->first()->id);
    }

    public function test_servico_filtra_por_nome_ou_categoria_e_hora_e_passado_futuro(): void
    {
        $banho = $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho porte pequeno']);
        $consulta = $this->item();
        $b = $this->marcar(['inicio' => '2026-11-12 15:00', 'fim' => '2026-11-12 16:00', 'status' => 'agendado', 'setor' => 'petshop'], null, $banho);
        $c = $this->marcar(['inicio' => '2026-11-12 09:00', 'fim' => '2026-11-12 09:30', 'status' => 'agendado'], null, $consulta);
        $p = $this->marcar(['inicio' => '2026-11-03 15:00', 'fim' => '2026-11-03 16:00', 'status' => 'realizado', 'setor' => 'petshop'], null, $banho);

        $this->assertEqualsCanonicalizing([$b->id, $p->id], $this->agenda->buscarAvancado(['servico' => 'banho', 'data_inicio' => '2026-11-01', 'data_fim' => '2026-11-30'], self::T)->pluck('id')->all());
        $this->assertSame([$c->id], $this->agenda->buscarAvancado(['servico' => 'consulta', 'quando' => 'futuro'], self::T)->pluck('id')->all());
        $this->assertSame([$b->id], $this->agenda->buscarAvancado(['hora_de' => '12:00', 'quando' => 'futuro'], self::T)->pluck('id')->all());
        $this->assertSame([$p->id], $this->agenda->buscarAvancado(['quando' => 'passado'], self::T)->pluck('id')->all());
    }

    public function test_cobrado_origem_e_dia_da_semana(): void
    {
        $com = $this->marcar(['inicio' => '2026-11-11 10:00', 'fim' => '2026-11-11 10:30', 'status' => 'realizado']); // quarta
        $sem = $this->marcar(['inicio' => '2026-11-12 10:00', 'fim' => '2026-11-12 10:30', 'status' => 'realizado']); // quinta
        Cobranca::create(['agendamento_id' => $com->id, 'status' => 'paga', 'setor' => 'clinica', 'total_centavos' => 15000, 'tenant_id' => self::T]);

        $ids = fn (array $f) => $this->agenda->buscarAvancado([...$f, 'data_inicio' => '2026-11-09', 'data_fim' => '2026-11-15'], self::T)->pluck('id')->all();
        $this->assertSame([$sem->id], $ids(['cobrado' => 'nao', 'status' => 'realizado']));
        $this->assertSame([$com->id], $ids(['cobrado' => 'sim']));
        $this->assertSame([$sem->id], $ids(['dia_semana' => 'quinta']));
        $this->assertSame([], $ids(['origem' => 'encaixe_emergencia']));
    }

    public function test_sem_filtro_nenhum_continua_hoje_mais_seis_dias(): void
    {
        $dentro = $this->marcar(['inicio' => '2026-11-12 10:00', 'fim' => '2026-11-12 10:30', 'status' => 'agendado']);
        $this->marcar(['inicio' => '2026-10-01 10:00', 'fim' => '2026-10-01 10:30', 'status' => 'agendado']);
        $this->marcar(['inicio' => '2026-11-30 10:00', 'fim' => '2026-11-30 10:30', 'status' => 'agendado']);

        $this->assertSame([$dentro->id], $this->agenda->buscarAvancado([], self::T)->pluck('id')->all());
    }

    public function test_a_frase_diz_o_registro_e_a_gravacao(): void
    {
        $linhas = [['id' => 13, 'horario' => '19/10/2026 09:00–10:00', 'pet' => 'Bidu', 'tutor' => 'Bruno Carvalho', 'servico' => 'Banho porte pequeno',
            'profissional' => 'Carlos Mendes', 'setor' => 'petshop', 'status' => 'realizado', 'gravado_em' => '12/10/2026 14:32', 'alterado_em' => '19/10/2026 10:05']];

        $r = FraseDaAgenda::montar($linhas, ['status' => 'realizado', 'ordenar' => 'marcado', 'sentido' => 'desc', 'limite' => 1]);

        $this->assertSame('O último agendamento realizado foi o #13: Bidu (Bruno Carvalho), Banho porte pequeno, 19/10/2026 às 09:00, com Carlos Mendes — realizado. Criado no sistema em 12/10/2026 às 14:32.', $r['frase']);
        $this->assertSame([13], $r['destacar']);
        $this->assertStringContainsString('Não encontrei', FraseDaAgenda::montar([], [])['frase']);
        $this->assertSame([], FraseDaAgenda::montar(array_fill(0, 5, $linhas[0]), [])['destacar']);
    }

    public function test_pedido_do_ultimo_pelo_chat_devolve_frase_e_linha_destacada(): void
    {
        $this->prepararAgente();
        $this->logarComo('recepcao', self::T);
        $this->marcar(['inicio' => '2026-10-01 09:00', 'fim' => '2026-10-01 09:30', 'status' => 'realizado']);
        $novo = $this->marcar(['inicio' => '2026-10-20 10:00', 'fim' => '2026-10-20 10:30', 'status' => 'realizado']);

        $this->comando('consultar_agenda', ['status' => 'realizado', 'ordenar' => 'marcado', 'sentido' => 'desc', 'limite' => 1])
            ->assertOk()->assertJsonPath('tipo', 'resultado_leitura')->assertJsonCount(1, 'resultado')
            ->assertJsonPath('resultado.0.id', $novo->id)->assertJsonPath('destacar', [$novo->id])
            ->assertJsonPath('frase', fn ($f) => str_starts_with($f, 'O último agendamento realizado foi o #'.$novo->id));
    }

    public function test_so_limite_pega_os_proximos_e_so_status_fechado_pega_os_mais_recentes(): void
    {
        $passado = $this->marcar(['inicio' => '2026-10-01 09:00', 'fim' => '2026-10-01 09:30', 'status' => 'realizado']);
        $recente = $this->marcar(['inicio' => '2026-11-05 09:00', 'fim' => '2026-11-05 09:30', 'status' => 'realizado']);
        $proximo = $this->marcar(['inicio' => '2026-11-20 09:00', 'fim' => '2026-11-20 09:30', 'status' => 'agendado']);
        $depois = $this->marcar(['inicio' => '2026-12-20 09:00', 'fim' => '2026-12-20 09:30', 'status' => 'agendado']);

        $this->assertSame([$proximo->id, $depois->id], $this->agenda->buscarAvancado(['limite' => 3], self::T)->pluck('id')->all());
        $this->assertSame([$recente->id, $passado->id], $this->agenda->buscarAvancado(['status' => 'realizado'], self::T)->pluck('id')->all());
    }

    public function test_so_status_e_limite_diz_ultimo_e_ultimos_do_pet_nao_viram_futuro(): void
    {
        $velho = $this->marcar(['inicio' => '2026-10-01 09:00', 'fim' => '2026-10-01 09:30', 'status' => 'realizado']);
        $novo = $this->marcar(['inicio' => '2026-11-05 09:00', 'fim' => '2026-11-05 09:30', 'status' => 'realizado']);
        $filtros = ['status' => 'realizado', 'limite' => 1];

        $linhas = $this->agenda->buscarAvancado($filtros, self::T);
        $this->assertSame([$novo->id], $linhas->pluck('id')->all());
        $frase = FraseDaAgenda::montar([['id' => $novo->id, 'horario' => '05/11/2026 09:00–09:30', 'pet' => 'Thor', 'tutor' => 'Ana', 'servico' => 'Consulta', 'profissional' => 'vet1', 'status' => 'realizado']], $filtros)['frase'];
        $this->assertStringStartsWith('O último agendamento realizado', $frase);

        // "os 3 últimos" sem ordenar: sentido desc, sem virar "só futuros".
        $this->assertSame([$novo->id, $velho->id], $this->agenda->buscarAvancado(['limite' => 3, 'sentido' => 'desc'], self::T)->pluck('id')->all());
    }

    public function test_dia_da_semana_filtra_antes_do_teto_de_linhas(): void
    {
        $quinta = $this->marcar(['inicio' => '2026-11-12 10:00', 'fim' => '2026-11-12 10:30', 'status' => 'realizado']); // quinta
        $this->marcar(['inicio' => '2026-11-13 10:00', 'fim' => '2026-11-13 10:30', 'status' => 'realizado']); // sexta

        $this->assertSame([$quinta->id], $this->agenda->buscarAvancado(['status' => 'realizado', 'dia_semana' => 'quinta'], self::T)->pluck('id')->all());
    }

    public function test_argumentos_com_tipo_errado_sao_descartados_sem_erro(): void
    {
        $this->prepararAgente();
        $this->logarComo('recepcao', self::T);
        $ag = $this->marcar(['inicio' => '2026-11-12 10:00', 'fim' => '2026-11-12 10:30', 'status' => 'agendado']);

        $this->comando('consultar_agenda', ['tipo_data' => ['x'], 'ordenar' => ['y'], 'id' => 'abc', 'limite' => [5], 'dia' => '2026-11-12'])
            ->assertOk()->assertJsonPath('tipo', 'resultado_leitura')->assertJsonPath('resultado.0.id', $ag->id);
    }

    public function test_atendente_nao_ve_consulta_da_clinica_mesmo_pedindo(): void
    {
        $this->prepararAgente();
        $this->logarComo('atendente', self::T);
        $this->marcar(['inicio' => '2026-10-20 10:00', 'fim' => '2026-10-20 10:30', 'status' => 'realizado']); // consulta da clínica

        $this->comando('consultar_agenda', ['servico' => 'consulta', 'setor' => 'clinica', 'ordenar' => 'marcado', 'limite' => 1])
            ->assertOk()->assertJsonCount(0, 'resultado');
    }
}
