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
 * 25/11 (decisão do Lucas): cancelar vários agendamentos NUNCA às cegas. A tool só MOSTRA a lista com checkbox; o usuário marca; o
 * endpoint grava só os ids marcados que estavam na lista, tudo ou nada, uma linha de auditoria por item (mesmo lote_id).
 */
class CancelarEmLoteTest extends TestCase
{
    use CenarioClinica, RefreshDatabase, SimulaAgente;

    private User $ana;

    private CatalogoItem $banho;

    private Pet $thor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->prepararAgente();
        $this->logarComo('recepcao', self::T);
        $this->ana = User::factory()->papel('atendente')->doTenant(self::T)->create(['name' => 'Ana']);
        $this->banho = $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho']);
        $this->thor = $this->pet();
    }

    private function marcar(string $dia, string $hora = '09:00', ?Pet $pet = null, ?CatalogoItem $servico = null, ?User $quem = null, string $status = 'agendado'): Agendamento
    {
        $fim = Carbon::parse("{$dia} {$hora}")->addHour()->format('Y-m-d H:i');

        return $this->agendamentoDireto($pet ?? $this->pet(), $quem ?? $this->ana, $servico ?? $this->banho, ['inicio' => "{$dia} {$hora}", 'fim' => $fim, 'status' => $status]);
    }

    private function listar(array $filtros = ['data_inicio' => '2026-11-11', 'data_fim' => '2026-11-20'])
    {
        return $this->comando('cancelar_agendamentos_em_lote', $filtros)->assertOk()->assertJsonPath('tipo', 'resultado_leitura');
    }

    private function cancelar(array $ids)
    {
        return $this->postJson('/agente/agenda/cancelar-lote', ['ids' => $ids]);
    }

    // ------------------------------------------------------------------ a lista (nada é cancelado aqui)

    public function test_a_tool_so_lista_em_aberto_com_checkbox_e_nao_cancela_nada(): void
    {
        $a = $this->marcar('2026-11-11');
        $b = $this->marcar('2026-11-12', '10:00');
        $this->marcar('2026-11-13', '09:00', null, null, null, 'cancelado');
        $this->marcar('2026-11-14', '09:00', null, null, null, 'realizado');
        $this->marcar('2026-11-11', '12:00', $this->pet(null, self::OUTRO_TENANT), $this->item(['setor' => 'petshop'], self::OUTRO_TENANT), $this->usuarioClinica('atendente', self::OUTRO_TENANT));

        $resposta = $this->listar();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($resposta->json('resultado'), 'id'), 'só em aberto, só deste tenant');
        $resposta->assertJsonPath('selecao.url', '/agente/agenda/cancelar-lote')->assertJsonPath('selecao.segurar', true);
        $this->assertSame(0, Agendamento::query()->where('status', 'cancelado')->count() - 1, 'nada foi cancelado pela tool (o cancelado do cenário já existia)');
    }

    public function test_exceto_dia_mantem_aquele_dia_fora_da_lista(): void
    {
        $dia11 = $this->marcar('2026-11-11');
        $dia12 = $this->marcar('2026-11-12');

        $ids = array_column($this->listar(['data_inicio' => '2026-11-11', 'data_fim' => '2026-11-12', 'exceto_dia' => '2026-11-12'])->json('resultado'), 'id');

        $this->assertSame([$dia11->id], $ids);
        $this->assertNotContains($dia12->id, $ids);
    }

    public function test_cirurgia_vem_bloqueada_com_o_motivo_e_nao_pode_ser_marcada(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $cirurgia = $this->item(['categoria' => 'cirurgia', 'nome_comercial' => 'Castração']);
        $normal = $this->marcar('2026-11-11');
        $cir = $this->marcar('2026-11-12', '09:00', null, $cirurgia, $vet);

        $linhas = collect($this->listar()->json('resultado'))->keyBy('id');

        $this->assertFalse($linhas[$normal->id]['bloqueado']);
        $this->assertTrue($linhas[$cir->id]['bloqueado']);
        $this->assertStringContainsString('CRMV', $linhas[$cir->id]['observacao']);
    }

    public function test_acima_de_25_pede_para_restringir_e_nunca_trunca(): void
    {
        foreach (range(1, 26) as $i) {
            $this->marcar('2026-11-11', sprintf('%02d:%02d', 7 + intdiv($i, 4), ($i % 4) * 15));
        }

        $this->comando('cancelar_agendamentos_em_lote', ['data_inicio' => '2026-11-11', 'data_fim' => '2026-11-11'])->assertStatus(422)
            ->assertJsonPath('mensagem', fn (string $m) => str_contains($m, 'restringir'));
    }

    public function test_sem_nada_em_aberto_diz_que_nao_ha_o_que_cancelar(): void
    {
        $this->comando('cancelar_agendamentos_em_lote', ['data_inicio' => '2026-11-11', 'data_fim' => '2026-11-12'])->assertStatus(422)
            ->assertJsonPath('mensagem', fn (string $m) => str_contains($m, 'nada para cancelar'));
    }

    public function test_o_papel_so_ve_os_agendamentos_do_proprio_setor(): void
    {
        $banhoId = $this->marcar('2026-11-11')->id;
        $clinica = $this->marcar('2026-11-11', '10:00', null, $this->item(), $this->usuarioClinica('veterinario'));
        $this->logarComo('atendente', self::T);

        $ids = array_column($this->listar()->json('resultado'), 'id');

        $this->assertSame([$banhoId], $ids);
        $this->assertNotContains($clinica->id, $ids);
    }

    // ------------------------------------------------------------------ a gravação: só o que foi marcado

    public function test_cancela_so_os_marcados_com_uma_linha_de_auditoria_por_item_e_o_mesmo_lote(): void
    {
        $a = $this->marcar('2026-11-11');
        $b = $this->marcar('2026-11-12');
        $c = $this->marcar('2026-11-13');
        $this->listar();

        $resposta = $this->cancelar([$a->id, $c->id])->assertOk()->assertJsonPath('tipo', 'resultado_escrita');

        $this->assertSame(['cancelado', 'agendado', 'cancelado'], [$a->fresh()->status, $b->fresh()->status, $c->fresh()->status], 'o não marcado (b) continua agendado');
        $logs = AuditLog::query()->where('lote_id', $resposta->json('lote_id'))->where('resultado', 'sucesso')->get();
        $this->assertCount(2, $logs);
        $this->assertEqualsCanonicalizing([$a->id, $c->id], $logs->pluck('entidade_id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame(['alterar_status_agendamento'], $logs->pluck('tool')->unique()->values()->all());
    }

    public function test_id_que_nao_estava_na_lista_e_recusado_e_nada_e_cancelado(): void
    {
        $naLista = $this->marcar('2026-11-11');
        $forasDaLista = $this->marcar('2026-12-01'); // existe e é em aberto, mas fora do período listado
        $deOutroTenant = $this->marcar('2026-11-11', '15:00', $this->pet(null, self::OUTRO_TENANT), $this->item(['setor' => 'petshop'], self::OUTRO_TENANT), $this->usuarioClinica('atendente', self::OUTRO_TENANT));
        $this->listar();

        foreach ([[$naLista->id, $forasDaLista->id], [$naLista->id, $deOutroTenant->id]] as $ids) {
            $this->cancelar($ids)->assertStatus(422)->assertJsonPath('codigo', 'AI_BATCH_IDS_NOT_LISTED');
        }
        $this->assertSame(0, Agendamento::query()->where('status', 'cancelado')->count());
    }

    public function test_sem_lista_de_cancelar_em_lote_a_sessao_nao_aceita_ids_soltos(): void
    {
        $a = $this->marcar('2026-11-11');

        $this->cancelar([$a->id])->assertStatus(422)->assertJsonPath('codigo', 'AI_BATCH_LIST_EXPIRED');

        // Uma tabela comum (consultar_agenda) também não vale como lista de cancelar em lote.
        $this->comando('consultar_agenda', ['data_inicio' => '2026-11-11', 'data_fim' => '2026-11-12'])->assertOk();
        $this->cancelar([$a->id])->assertStatus(422)->assertJsonPath('codigo', 'AI_BATCH_LIST_EXPIRED');
        $this->assertSame('agendado', $a->fresh()->status);
    }

    public function test_a_lista_vale_para_um_envio_so(): void
    {
        $a = $this->marcar('2026-11-11');
        $this->listar();

        $this->cancelar([$a->id])->assertOk();
        $this->cancelar([$a->id])->assertStatus(422)->assertJsonPath('codigo', 'AI_BATCH_LIST_EXPIRED');
        $this->assertSame(1, AuditLog::query()->where('tool', 'alterar_status_agendamento')->where('resultado', 'sucesso')->count(), 'não cancelou nem auditou de novo');
    }

    public function test_tudo_ou_nada_um_item_recusado_desfaz_todos_e_grava_uma_linha_so(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $cirurgia = $this->item(['categoria' => 'cirurgia', 'nome_comercial' => 'Castração']);
        $normal = $this->marcar('2026-11-11');
        $cir = $this->marcar('2026-11-12', '09:00', null, $cirurgia, $vet);
        $this->listar();

        // O usuário (forçando o envio) marca também a cirurgia, que exige CRMV: o lote inteiro é recusado.
        $resposta = $this->cancelar([$normal->id, $cir->id])->assertStatus(422)->assertJsonPath('codigo', 'AI_BATCH_REFUSED');

        $this->assertArrayHasKey((string) $cir->id, $resposta->json('erros'));
        $this->assertArrayNotHasKey((string) $normal->id, $resposta->json('erros'), 'o item bom não é culpado');
        $this->assertSame(['agendado', 'agendado'], [$normal->fresh()->status, $cir->fresh()->status], 'nada foi cancelado, nem o item bom');
        $this->assertSame(0, AuditLog::query()->where('tool', 'alterar_status_agendamento')->where('resultado', 'sucesso')->count());
        $recusa = AuditLog::query()->where('tool', 'cancelar_agendamentos_em_lote')->where('resultado', 'recusado')->sole();
        $this->assertEqualsCanonicalizing([$normal->id, $cir->id], $recusa->parametros['ids']);
        $this->assertArrayHasKey($cir->id, $recusa->parametros['motivos']);
    }

    public function test_sem_ids_ou_com_demais_e_recusado(): void
    {
        $this->marcar('2026-11-11');
        $this->listar();

        $this->postJson('/agente/agenda/cancelar-lote', ['ids' => []])->assertStatus(422);
        $this->postJson('/agente/agenda/cancelar-lote', ['ids' => range(1, 26)])->assertStatus(422);
        $this->postJson('/agente/agenda/cancelar-lote', ['ids' => ['abc']])->assertStatus(422);
    }

    public function test_quem_nao_tem_permissao_na_agenda_e_negado(): void
    {
        $a = $this->marcar('2026-11-11');
        $this->listar();
        $this->logarComo('vendedor', self::T); // o vendedor não mexe na agenda

        $this->cancelar([$a->id])->assertStatus(422); // a lista é de outro usuário: não vale (nem para ele nem como brecha)
        $this->assertSame('agendado', $a->fresh()->status);
    }

    // ------------------------------------------------------------------ "nunca às cegas": o que mudou depois de marcar não é cancelado

    public function test_agendamento_remarcado_por_outra_pessoa_depois_de_a_lista_aparecer_e_recusado(): void
    {
        $visto = $this->marcar('2026-11-11');
        $outro = $this->marcar('2026-11-12');
        $this->listar();

        // Outra pessoa remarca o #visto para outro dia DEPOIS de o usuário ver a lista (muda o updated_at, a "versão" da linha).
        Agendamento::query()->whereKey($visto->id)->update(['inicio' => '2026-11-18 09:00', 'fim' => '2026-11-18 10:00', 'updated_at' => now()->addMinutes(2)]);

        $resposta = $this->cancelar([$visto->id, $outro->id])->assertStatus(422)->assertJsonPath('codigo', 'AI_BATCH_REFUSED');

        $this->assertArrayHasKey((string) $visto->id, $resposta->json('erros'), 'o que mudou é apontado');
        $this->assertArrayNotHasKey((string) $outro->id, $resposta->json('erros'));
        $this->assertSame(['agendado', 'agendado'], [$visto->fresh()->status, $outro->fresh()->status], 'tudo ou nada: nem o item bom foi cancelado');
    }

    public function test_a_versao_das_linhas_nunca_vai_para_o_navegador(): void
    {
        $this->marcar('2026-11-11');

        $linha = $this->listar()->json('resultado.0');

        $this->assertArrayNotHasKey('versao', $linha);
    }

    public function test_cancelar_pelo_nome_com_varios_iguais_mostra_a_lista_de_marcar_e_permite_cancelar_mais_de_um(): void
    {
        $a = $this->marcar('2026-11-11', '09:00', $this->thor);
        $b = $this->marcar('2026-11-12', '10:00', $this->thor);

        $resposta = $this->comando('alterar_status_agendamento', ['busca' => $this->thor->nome, 'novo_status' => 'cancelado'])->assertOk()
            ->assertJsonPath('tipo', 'resultado_leitura')
            ->assertJsonPath('selecao.url', '/agente/agenda/cancelar-lote')->assertJsonPath('selecao.segurar', true);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($resposta->json('resultado'), 'id'));
        $this->assertArrayNotHasKey('versao', $resposta->json('resultado.0'));
        $this->assertArrayNotHasKey('acao', $resposta->json('resultado.0'), 'sem botão "Escolher": a seleção é por caixa de marcar');
        $this->assertSame('agendado', $a->fresh()->status, 'listar não cancela');

        // Marcou os dois: o mesmo endpoint do lote grava, tudo ou nada.
        $this->cancelar([$a->id, $b->id])->assertOk()->assertJsonPath('tipo', 'resultado_escrita');
        $this->assertSame(['cancelado', 'cancelado'], [$a->fresh()->status, $b->fresh()->status]);
    }

    public function test_outros_status_com_varios_iguais_seguem_com_a_escolha_de_um_so(): void
    {
        $this->marcar('2026-11-11', '09:00', $this->thor);
        $this->marcar('2026-11-12', '10:00', $this->thor);

        $this->comando('alterar_status_agendamento', ['busca' => $this->thor->nome, 'novo_status' => 'faltou'])->assertOk()
            ->assertJsonPath('tipo', 'resultado_leitura')->assertJsonMissingPath('selecao')
            ->assertJsonPath('resultado.0.acao.rotulo', 'Escolher');
    }

    public function test_a_lista_com_checkbox_nao_vence_e_continua_valendo_um_envio_so(): void
    {
        // 26/11 (Lucas, opção b): como a proposta, a lista de marcar não vence. Quem volta horas depois ainda cancela o que marcou;
        // o freio é o segurar 2 s na tela, a versão de cada linha e o envio único.
        $a = $this->marcar('2026-11-11');
        $b = $this->marcar('2026-11-12');
        $this->listar();
        $this->travel(2)->hours();

        $this->cancelar([$a->id])->assertOk();
        $this->assertSame(['cancelado', 'agendado'], [$a->fresh()->status, $b->fresh()->status]);
        $this->cancelar([$b->id])->assertStatus(422)->assertJsonPath('codigo', 'AI_BATCH_LIST_EXPIRED');
        $this->assertSame('agendado', $b->fresh()->status, 'a lista vale para um envio só');
    }

    public function test_lista_do_lote_parada_horas_nao_vale_para_referencia_por_posicao(): void
    {
        // A lista sem prazo vale só para o envio do lote: "o segundo" volta a ser resolvido contra tabela de até 30 min.
        $a = $this->marcar('2026-11-11');
        $this->marcar('2026-11-12');
        $this->listar();
        $this->travel(2)->hours();

        $this->comando('remarcar_agendamento', ['posicao' => 2])->assertStatus(422)
            ->assertJsonPath('mensagem', fn ($m) => str_contains($m, 'Não tenho uma tabela recente'));

        // E a mensagem no chat não apaga a lista: ela continua valendo para o envio do lote (decisão de 26/11).
        $this->cancelar([$a->id])->assertOk();
    }

    public function test_lista_sem_prazo_de_outro_usuario_ou_tenant_nao_vale(): void
    {
        $a = $this->marcar('2026-11-11');
        $this->listar();
        $this->travel(2)->hours();
        $this->logarComo('recepcao', self::OUTRO_TENANT);

        $this->cancelar([$a->id])->assertStatus(422)->assertJsonPath('codigo', 'AI_BATCH_LIST_EXPIRED');
        $this->assertSame('agendado', $a->fresh()->status);
    }

    public function test_lista_parada_horas_ainda_recusa_o_que_mudou_depois_de_aparecer(): void
    {
        $visto = $this->marcar('2026-11-11');
        $this->listar();
        $this->travel(3)->hours();
        Agendamento::query()->whereKey($visto->id)->update(['updated_at' => now()]);

        $this->cancelar([$visto->id])->assertStatus(422)->assertJsonPath('codigo', 'AI_BATCH_REFUSED');
        $this->assertSame('agendado', $visto->fresh()->status);
    }

    public function test_papel_rebaixado_com_a_lista_aberta_nao_cancela_agendamento_de_outro_setor(): void
    {
        $banho = $this->marcar('2026-11-11');
        $consulta = $this->marcar('2026-11-12', '09:00', null, $this->item(), $this->usuarioClinica('veterinario'));
        $this->listar(); // a recepção vê os dois setores

        // O papel passa a só mexer no banho e tosa depois de a lista abrir: o setor é conferido de novo, item a item.
        auth()->user()->forceFill(['papel' => 'atendente']);

        $this->cancelar([$banho->id, $consulta->id])->assertStatus(422)->assertJsonPath('codigo', 'AI_BATCH_REFUSED')
            ->assertJsonPath('erros.'.$consulta->id, fn (string $m) => str_contains($m, 'banho e tosa'));
        $this->assertSame(['agendado', 'agendado'], [$banho->fresh()->status, $consulta->fresh()->status]);
    }

    public function test_lote_recusado_nao_avisa_nenhum_tutor_e_lote_gravado_avisa(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $tutor = \App\Models\Tutor::create(['nome' => 'Ana Souza', 'email' => 'ana@exemplo.com', 'tenant_id' => self::T]);
        $pet = $this->pet($tutor);
        $bom = $this->marcar('2026-11-11', '09:00', $pet);
        $vet = $this->usuarioClinica('veterinario');
        $cir = $this->marcar('2026-11-12', '09:00', $pet, $this->item(['categoria' => 'cirurgia', 'nome_comercial' => 'Castração']), $vet);
        $this->listar();

        // Recusado (a cirurgia exige CRMV): nada gravado, e o item bom NÃO pode ter avisado o tutor de um cancelamento que não houve.
        $this->cancelar([$bom->id, $cir->id])->assertStatus(422);
        \Illuminate\Support\Facades\Mail::assertNothingSent();
        \Illuminate\Support\Facades\Mail::assertNothingQueued();

        // Gravado: o tutor é avisado do que foi de fato cancelado.
        $this->listar();
        $this->cancelar([$bom->id])->assertOk();
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\AgendamentoMail::class, 1);
    }

    public function test_falha_tecnica_no_meio_do_lote_desfaz_tudo_para_e_audita_o_erro(): void
    {
        $a = $this->marcar('2026-11-11');
        $b = $this->marcar('2026-11-12');
        $this->listar();
        $this->partialMock(\App\Services\AgendaService::class, fn ($mock) => $mock->shouldReceive('alterarStatus')->andThrow(new \RuntimeException('boom')));

        $this->cancelar([$a->id, $b->id])->assertStatus(500);

        $this->assertSame(['agendado', 'agendado'], [$a->fresh()->status, $b->fresh()->status], 'nada foi cancelado');
        $this->assertSame(1, AuditLog::query()->where('tool', 'cancelar_agendamentos_em_lote')->where('resultado', 'erro')->count(), 'a tentativa fica auditada');
    }

    // ------------------------------------------------------------------ filtros: cliente, animal, profissional, dia (combináveis)

    /** @return array{diegoRex: Agendamento, diegoMel: Agendamento, anaThor: Agendamento, anaThorBia: Agendamento} */
    private function cenarioDeFiltros(): array
    {
        $bia = User::factory()->papel('atendente')->doTenant(self::T)->create(['name' => 'Bia']);
        $diego = $this->tutor(self::T, 'Diego Martins');
        $anaTutora = $this->tutor(self::T, 'Ana Souza');
        $rex = $this->pet($diego, self::T, ['nome' => 'Rex']);
        $mel = $this->pet($diego, self::T, ['nome' => 'Mel']);
        $thorDaAna = $this->pet($anaTutora, self::T, ['nome' => 'Thor']);

        return [
            'diegoRex' => $this->marcar('2026-11-11', '09:00', $rex),
            'diegoMel' => $this->marcar('2026-11-12', '09:00', $mel),
            'anaThor' => $this->marcar('2026-11-11', '10:00', $thorDaAna),
            'anaThorBia' => $this->marcar('2026-11-12', '10:00', $thorDaAna, null, $bia),
        ];
    }

    private function idsDaLista(array $filtros): array
    {
        $base = ['data_inicio' => '2026-11-11', 'data_fim' => '2026-11-20'];
        $ids = array_column($this->listar([...$base, ...$filtros])->json('resultado'), 'id');
        sort($ids);

        return $ids;
    }

    public function test_cancelar_os_agendamentos_do_cliente_traz_so_os_dele(): void
    {
        $c = $this->cenarioDeFiltros();

        $this->assertEqualsCanonicalizing([$c['diegoRex']->id, $c['diegoMel']->id], $this->idsDaLista(['tutor' => 'Diego']), 'só o cliente Diego (dois animais), não a agenda toda');
        $this->assertEqualsCanonicalizing([$c['diegoRex']->id, $c['diegoMel']->id], $this->idsDaLista(['tutor' => 'diego mar']), 'várias palavras, sem acento/caixa');
        $this->assertEqualsCanonicalizing([$c['anaThor']->id, $c['anaThorBia']->id], $this->idsDaLista(['tutor' => 'Ana Souza']));
    }

    public function test_filtra_por_animal(): void
    {
        $c = $this->cenarioDeFiltros();

        $this->assertSame([$c['diegoRex']->id], $this->idsDaLista(['pet' => 'Rex']));
        $this->assertEqualsCanonicalizing([$c['anaThor']->id, $c['anaThorBia']->id], $this->idsDaLista(['pet' => 'Thor']));
    }

    public function test_filtra_por_profissional_pelo_nome(): void
    {
        $c = $this->cenarioDeFiltros();

        $this->assertSame([$c['anaThorBia']->id], $this->idsDaLista(['profissional' => 'Bia']));
    }

    public function test_filtra_por_dia_e_combina_cliente_animal_profissional_e_dia(): void
    {
        $c = $this->cenarioDeFiltros();

        $this->assertEqualsCanonicalizing([$c['diegoRex']->id, $c['anaThor']->id], $this->idsDaLista(['dia' => '2026-11-11']));
        $this->assertSame([$c['diegoMel']->id], $this->idsDaLista(['tutor' => 'Diego', 'dia' => '2026-11-12']), 'cliente + dia');
        $this->assertSame([$c['anaThorBia']->id], $this->idsDaLista(['tutor' => 'Ana', 'pet' => 'Thor', 'profissional' => 'Bia', 'dia' => '2026-11-12']), 'cliente + animal + profissional + dia');
    }

    public function test_cliente_sem_agendamento_em_aberto_diz_que_nao_ha_para_esses_filtros(): void
    {
        $this->cenarioDeFiltros();

        $this->comando('cancelar_agendamentos_em_lote', ['data_inicio' => '2026-11-11', 'data_fim' => '2026-11-20', 'tutor' => 'Fulano Inexistente'])
            ->assertStatus(422)->assertJsonPath('mensagem', fn (string $m) => str_contains($m, 'esses filtros'));
    }

    public function test_o_filtro_de_cliente_nunca_traz_registro_de_outro_tenant(): void
    {
        $this->cenarioDeFiltros();
        $outroTutor = $this->tutor(self::OUTRO_TENANT, 'Diego Martins');
        $this->marcar('2026-11-11', '15:00', $this->pet($outroTutor, self::OUTRO_TENANT), $this->item(['setor' => 'petshop'], self::OUTRO_TENANT), $this->usuarioClinica('atendente', self::OUTRO_TENANT));

        $this->assertCount(2, $this->idsDaLista(['tutor' => 'Diego']), 'o Diego do outro tenant não entra');
    }

    public function test_consultar_agenda_tambem_filtra_por_cliente_profissional_e_dia(): void
    {
        $c = $this->cenarioDeFiltros();

        $ids = fn (array $f) => collect($this->comando('consultar_agenda', ['data_inicio' => '2026-11-11', 'data_fim' => '2026-11-20', ...$f])->assertOk()->json('resultado'))->pluck('id')->sort()->values()->all();

        $this->assertEqualsCanonicalizing([$c['diegoRex']->id, $c['diegoMel']->id], $ids(['tutor' => 'Diego']));
        $this->assertSame([$c['anaThorBia']->id], $ids(['profissional' => 'Bia']));
        $this->assertEqualsCanonicalizing([$c['diegoRex']->id, $c['anaThor']->id], $ids(['dia' => '2026-11-11']));
    }
}
