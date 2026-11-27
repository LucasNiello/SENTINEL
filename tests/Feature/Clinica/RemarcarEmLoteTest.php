<?php

namespace Tests\Feature\Clinica;

use App\Models\Agendamento;
use App\Models\AuditLog;
use App\Models\CatalogoItem;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * 26/11 (decisão do Lucas): remarcar vários agendamentos NUNCA às cegas. A tool só MONTA o plano (o servidor propõe o novo horário de
 * cada um, simulando a grade, sem gravar); o usuário marca; o endpoint grava só os marcados, nos horários congelados no servidor,
 * tudo ou nada, uma linha de auditoria por item (mesmo lote_id), e confere no fim quem ficou para trás.
 */
class RemarcarEmLoteTest extends TestCase
{
    use CenarioClinica, RefreshDatabase, SimulaAgente;

    private User $ana;

    private CatalogoItem $banho;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->prepararAgente();
        $this->logarComo('recepcao', self::T);
        $this->ana = User::factory()->papel('atendente')->doTenant(self::T)->create(['name' => 'Ana']);
        $this->banho = $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho']);
    }

    private function marcar(string $dia, string $hora = '09:00', ?Pet $pet = null, ?User $quem = null, string $status = 'agendado'): Agendamento
    {
        $fim = Carbon::parse("{$dia} {$hora}")->addHour()->format('Y-m-d H:i');

        return $this->agendamentoDireto($pet ?? $this->pet(), $quem ?? $this->ana, $this->banho, ['inicio' => "{$dia} {$hora}", 'fim' => $fim, 'status' => $status]);
    }

    private function plano(array $filtros = [])
    {
        return $this->comando('remarcar_agendamentos_em_lote', ['dia' => '2026-11-11', ...$filtros])->assertOk()->assertJsonPath('tipo', 'resultado_leitura');
    }

    private function remarcar(array $ids)
    {
        return $this->postJson('/agente/agenda/remarcar-lote', ['ids' => $ids]);
    }

    private function inicio(Agendamento $a): string
    {
        return $a->fresh()->inicio->format('Y-m-d H:i');
    }

    public function test_o_plano_so_propoe_no_mesmo_horario_do_proximo_dia_e_nao_grava_nada(): void
    {
        $a = $this->marcar('2026-11-11', '09:00');
        $b = $this->marcar('2026-11-11', '10:00');
        $this->marcar('2026-11-11', '11:00', null, null, 'cancelado');
        $this->marcar('2026-11-11', '12:00', $this->pet(null, self::OUTRO_TENANT), $this->usuarioClinica('atendente', self::OUTRO_TENANT));

        $resposta = $this->plano();

        $linhas = collect($resposta->json('resultado'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $linhas->keys()->all(), 'só em aberto e só deste tenant');
        $this->assertStringStartsWith('12/11/2026 09:00', $linhas[$a->id]['novo_horario']);
        $this->assertStringStartsWith('12/11/2026 10:00', $linhas[$b->id]['novo_horario']);
        $resposta->assertJsonPath('selecao.url', '/agente/agenda/remarcar-lote')->assertJsonPath('selecao.segurar', true);
        $this->assertArrayNotHasKey('novo_inicio', $linhas[$a->id], 'o horário congelado fica no servidor');
        $this->assertArrayNotHasKey('versao', $linhas[$a->id]);
        $this->assertSame('2026-11-11 09:00', $this->inicio($a), 'a tool não grava nada');
        $this->assertSame(0, AuditLog::where('tool', 'remarcar_agendamento')->count());
    }

    public function test_mesmo_horario_acha_o_primeiro_dia_em_que_todos_cabem(): void
    {
        $a = $this->marcar('2026-11-11', '09:00');
        $b = $this->marcar('2026-11-11', '10:00');
        $this->marcar('2026-11-12', '10:00', null, $this->ana); // a Ana já está ocupada às 10h do dia 12: o b não cabe ali

        $linhas = collect($this->plano()->json('resultado'))->keyBy('id');

        $this->assertStringStartsWith('13/11/2026 09:00', $linhas[$a->id]['novo_horario'], 'todos juntos no primeiro dia em que todos cabem');
        $this->assertStringStartsWith('13/11/2026 10:00', $linhas[$b->id]['novo_horario']);
        $this->assertFalse((bool) $linhas[$a->id]['bloqueado']);
    }

    public function test_proximo_livre_vai_por_ordem_de_horario_e_um_nao_ocupa_o_lugar_do_outro(): void
    {
        $a = $this->marcar('2026-11-11', '09:00');
        $b = $this->marcar('2026-11-11', '09:00', null, $this->usuarioClinica('atendente'));
        $c = $this->marcar('2026-11-11', '10:00');
        $this->marcar('2026-11-12', '09:00', null, $this->ana);

        $linhas = collect($this->plano(['modo' => 'proximo_livre'])->json('resultado'));
        $ana = $linhas->filter(fn ($l) => in_array($l['id'], [$a->id, $c->id], true));

        $this->assertCount(3, $linhas);
        $this->assertFalse($linhas->contains(fn ($l) => $l['bloqueado']), 'todos acharam horário');
        $inicios = $ana->pluck('novo_horario')->map(fn ($h) => substr($h, 0, 16));
        $this->assertCount(2, $inicios->unique(), 'a Ana não fica com dois agendamentos no mesmo horário');
        $this->assertTrue($inicios->every(fn ($h) => $h > '12/11/2026 08'), 'depois do dia fechado');
        $this->assertNotContains('12/11/2026 09:00', $inicios->all(), 'o horário já ocupado da Ana não é oferecido');
        $this->assertSame('2026-11-11 09:00', $this->inicio($a), 'nada foi gravado');
    }

    public function test_confirmar_grava_so_os_marcados_nos_horarios_do_plano_e_audita_um_lote(): void
    {
        $a = $this->marcar('2026-11-11', '09:00');
        $b = $this->marcar('2026-11-11', '10:00');
        $c = $this->marcar('2026-11-11', '11:00');
        $this->plano();

        $resposta = $this->remarcar([$a->id, $b->id])->assertOk()->assertJsonPath('tipo', 'resultado_escrita');

        $this->assertSame('2026-11-12 09:00', $this->inicio($a));
        $this->assertSame('2026-11-12 10:00', $this->inicio($b));
        $this->assertSame('2026-11-11 11:00', $this->inicio($c), 'o que não foi marcado fica onde estava');
        $this->assertStringContainsString('ficaram 1 sem remarcar', $resposta->json('mensagem'), 'a conferência aponta quem ficou para trás');
        $this->assertStringContainsString('Remarcados: Thor para 12/11 09:00; Thor para 12/11 10:00.', $resposta->json('mensagem'), 'diz para onde cada um foi');

        $linhas = AuditLog::where('tool', 'remarcar_agendamento')->where('resultado', 'sucesso')->get();
        $this->assertCount(2, $linhas, 'uma linha de auditoria por item');
        $this->assertCount(1, $linhas->pluck('lote_id')->unique(), 'todas com o mesmo lote_id');
        $this->assertNotNull($linhas->first()->lote_id);

        // Uma lista, um envio: reenviar não remarca de novo.
        $this->remarcar([$c->id])->assertStatus(422)->assertJsonPath('codigo', 'AI_BATCH_LIST_EXPIRED');
    }

    public function test_conferencia_diz_que_ninguem_ficou_para_tras_quando_todos_foram(): void
    {
        $a = $this->marcar('2026-11-11', '09:00');
        $b = $this->marcar('2026-11-11', '10:00');
        $this->plano();

        $this->remarcar([$a->id, $b->id])->assertOk()->assertJsonPath('remarcados', [$a->id, $b->id]);
        $mensagem = $this->remarcar([$a->id])->json('mensagem');
        $this->assertNotNull($mensagem);
    }

    public function test_tudo_ou_nada_se_um_marcado_mudou_depois_do_plano(): void
    {
        $a = $this->marcar('2026-11-11', '09:00');
        $b = $this->marcar('2026-11-11', '10:00');
        $this->plano();

        // Outra pessoa mexe no b depois de o usuário ver o plano.
        $this->travelTo(Carbon::parse('2026-11-10 10:05', 'America/Sao_Paulo'));
        Agendamento::query()->whereKey($b->id)->update(['status' => 'confirmado']);

        $resposta = $this->remarcar([$a->id, $b->id])->assertStatus(422)->assertJsonPath('codigo', 'AI_BATCH_REFUSED');

        $this->assertArrayHasKey((string) $b->id, $resposta->json('erros'), 'a linha que mudou é apontada');
        $this->assertSame('2026-11-11 09:00', $this->inicio($a), 'nada foi gravado, nem o que estava certo');
        $this->assertSame('2026-11-11 10:00', $this->inicio($b));
        $this->assertSame(1, AuditLog::where('tool', 'remarcar_agendamentos_em_lote')->where('resultado', 'recusado')->count());
        $this->assertSame(0, AuditLog::where('tool', 'remarcar_agendamento')->where('resultado', 'sucesso')->count());
    }

    public function test_so_aceita_ids_da_lista_e_sem_lista_nao_grava(): void
    {
        $a = $this->marcar('2026-11-11', '09:00');
        $fora = $this->marcar('2026-11-18', '09:00');

        $this->remarcar([$a->id])->assertStatus(422)->assertJsonPath('codigo', 'AI_BATCH_LIST_EXPIRED');

        $this->plano();
        $this->remarcar([$a->id, $fora->id])->assertStatus(422)->assertJsonPath('codigo', 'AI_BATCH_IDS_NOT_LISTED');
        $this->assertSame('2026-11-11 09:00', $this->inicio($a));
        $this->assertSame('2026-11-18 09:00', $this->inicio($fora));
    }

    public function test_cirurgia_fica_bloqueada_no_plano_e_nao_pode_ser_marcada(): void
    {
        $this->logarComo('dono', self::T);
        $vet = $this->usuarioClinica('veterinario');
        $cirurgia = $this->item(['categoria' => 'cirurgia', 'nome_comercial' => 'Castração']);
        $c = $this->agendamentoDireto($this->pet(), $vet, $cirurgia, ['inicio' => '2026-11-11 09:00', 'fim' => '2026-11-11 11:00', 'status' => 'agendado']);

        $linha = $this->plano()->json('resultado.0');

        $this->assertTrue((bool) $linha['bloqueado']);
        $this->assertStringContainsString('um a um', $linha['observacao']);
        $this->remarcar([$c->id])->assertStatus(422)->assertJsonPath('codigo', 'AI_BATCH_IDS_NOT_LISTED');
        $this->assertSame('2026-11-11 09:00', $this->inicio($c));
    }

    public function test_consulta_do_dia_de_destino_realca_so_os_do_ultimo_lote_remarcado(): void
    {
        $a = $this->marcar('2026-11-11', '09:00');
        $b = $this->marcar('2026-11-11', '10:00');
        $antigo = $this->marcar('2026-11-12', '14:00', null, $this->usuarioClinica('atendente'));
        $this->plano();
        $this->remarcar([$a->id, $b->id])->assertOk();

        $consulta = $this->comando('consultar_agenda', ['dia' => '2026-11-12'])->assertOk();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $consulta->json('destacar'), 'só os remarcados brilham, não o que já estava lá');
        $this->assertNotContains($antigo->id, $consulta->json('destacar'));
        $this->assertStringContainsString('Os 2 realçados foram remarcados por você há pouco', $consulta->json('frase'));

        // Uma consulta que não inclui nenhum deles não muda.
        $outra = $this->comando('consultar_agenda', ['dia' => '2026-11-16'])->assertOk();
        $this->assertStringNotContainsString('remarcados por você', (string) $outra->json('frase'));
    }

    public function test_consulta_vazia_num_dia_sem_expediente_diz_o_porque(): void
    {
        $domingo = $this->comando('consultar_agenda', ['dia' => '2026-11-15'])->assertOk()->json('frase');
        $this->assertStringContainsString('a clínica não abre nesse dia', $domingo);
        $this->assertStringContainsString('domingo', $domingo);
        $this->assertStringContainsString('de segunda a sábado', $domingo);

        $util = $this->comando('consultar_agenda', ['dia' => '2026-11-16'])->assertOk()->json('frase');
        $this->assertStringNotContainsString('não abre', $util, 'dia útil vazio mantém a frase de sempre');
    }

    public function test_papel_sem_acesso_a_agenda_e_negado(): void
    {
        $this->marcar('2026-11-11');
        $this->logarComo('vendedor', self::T);

        $this->comando('remarcar_agendamentos_em_lote', ['dia' => '2026-11-11'])->assertJsonPath('tipo', 'negado');
    }
}
