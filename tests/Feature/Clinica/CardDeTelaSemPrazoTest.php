<?php

namespace Tests\Feature\Clinica;

use App\Models\Agendamento;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * 25/11 (decisão do Lucas): o card aberto por uma TELA (/agente/formulario, origem "formulario") não vence: quem abriu é
 * o humano, sem IA propondo nada, e o Confirmar revalida tudo no servidor (vaga, versão, setor do papel). A proposta da IA
 * (chat) mantém os 5 min (CLAUDE.md). O contrato vai no campo prazo_s (null = sem prazo), para o front e o React só criarem
 * contador se vier número. Há um teto de 20 cards de tela por usuário; o excedente sai auditado como "cancelado".
 */
class CardDeTelaSemPrazoTest extends TestCase
{
    use CenarioClinica, RefreshDatabase, SimulaAgente;

    private int $banhoId;

    private int $petId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->prepararAgente();
        $this->logarComo('recepcao', self::T);
        $this->banhoId = $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho'])->id;
        $this->petId = $this->pet()->id;
    }

    private function valores(string $inicio = '2026-11-11 14:00', string $profissional = ''): array
    {
        return ['pet_id' => $this->petId, 'catalogo_item_id' => $this->banhoId, 'profissional_id' => $profissional, 'inicio' => $inicio];
    }

    private function atendente(string $nome = 'Ana'): User
    {
        return User::factory()->papel('atendente')->doTenant(self::T)->create(['name' => $nome]);
    }

    private function abrirCardDeTela(): string
    {
        return (string) $this->postJson('/agente/formulario', ['tool' => 'agendar_atendimento'])->assertOk()->json('proposta');
    }

    public function test_card_de_tela_nao_traz_prazo_e_continua_valendo_horas_depois_ate_gravar(): void
    {
        $this->atendente();
        $aberto = $this->postJson('/agente/formulario', ['tool' => 'agendar_atendimento'])->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente');
        $this->assertArrayHasKey('prazo_s', $aberto->json());
        $this->assertNull($aberto->json('prazo_s'));
        $this->assertTrue($aberto->json('passo_unico'), 'a agenda confirma em um passo');

        $this->travel(3)->hours(); // muito além dos 5 min do chat
        $this->postJson('/agente/revisar', ['proposta' => $aberto->json('proposta'), 'valores' => $this->valores(), 'previa' => true])->assertOk();
        $this->postJson('/agente/confirmar', ['proposta' => $aberto->json('proposta'), 'valores' => $this->valores()])->assertOk()
            ->assertJsonPath('tipo', 'resultado_escrita');

        $this->assertSame(1, Agendamento::count());
        $this->assertSame(0, AuditLog::query()->where('resultado', 'expirado')->count(), 'nada expirou por tempo');
    }

    public function test_a_varredura_nao_audita_expirado_para_card_de_tela(): void
    {
        $this->abrirCardDeTela();
        $this->travel(2)->hours();

        $this->getJson('/agente/painel')->assertOk(); // qualquer requisição atrás do middleware que varre as vencidas

        $this->assertSame(0, AuditLog::query()->where('resultado', 'expirado')->count());
    }

    public function test_proposta_do_chat_traz_prazo_de_5_minutos_e_vence_com_desfecho_expirado(): void
    {
        $this->atendente();
        $proposta = $this->comando('agendar_atendimento', ['pet_id' => $this->petId, 'catalogo_item_id' => $this->banhoId, 'inicio' => '2026-11-11 14:00']);
        $this->assertSame(300, $proposta->json('prazo_s'));
        $id = $proposta->json('proposta');

        // Dentro do prazo, com valores válidos, o Revisar vale (prova que o 422 abaixo é do PRAZO e não da validação).
        $this->postJson('/agente/revisar', ['proposta' => $id, 'valores' => $this->valores(), 'previa' => true])->assertOk();

        $this->travel(6)->minutes();
        $this->postJson('/agente/revisar', ['proposta' => $id, 'valores' => $this->valores()])->assertStatus(422);
        $this->assertSame(0, Agendamento::count());
        $this->assertSame(1, AuditLog::query()->where('resultado', 'expirado')->where('tool', 'agendar_atendimento')->count(), 'o desfecho do chat é "expirado"');
    }

    public function test_a_sessao_guarda_no_maximo_20_cards_de_tela_e_o_excedente_sai_auditado_como_cancelado(): void
    {
        $this->atendente();
        $primeiro = $this->abrirCardDeTela();
        // Enquanto vive, o card de tela revisa normalmente (prova que o 422 do fim é do despejo).
        $this->postJson('/agente/revisar', ['proposta' => $primeiro, 'valores' => $this->valores(), 'previa' => true])->assertOk();

        for ($i = 0; $i < 20; $i++) {
            $this->travel(1)->seconds();
            $this->abrirCardDeTela();
        }

        $this->postJson('/agente/revisar', ['proposta' => $primeiro, 'valores' => $this->valores(), 'previa' => true])->assertStatus(422);
        $despejados = AuditLog::query()->where('resultado', 'cancelado')->get();
        $this->assertCount(1, $despejados, 'só o mais antigo saiu, e com desfecho');
        $this->assertSame(['agendar_atendimento', 'formulario'], [$despejados->first()->tool, $despejados->first()->origem]);
    }

    public function test_card_de_tela_velho_e_recusado_quando_a_vaga_foi_tomada_depois_de_abrir(): void
    {
        $ana = $this->atendente();
        $aberto = $this->abrirCardDeTela();
        $this->travel(3)->hours();
        // Outra pessoa pega o horário da única atendente enquanto o card ficava aberto.
        $this->agendamentoDireto($this->pet(), $ana, \App\Models\CatalogoItem::find($this->banhoId), ['inicio' => '2026-11-11 14:00', 'fim' => '2026-11-11 15:00', 'status' => 'agendado']);

        $resposta = $this->postJson('/agente/confirmar', ['proposta' => $aberto, 'valores' => $this->valores()])->assertStatus(422);

        // O front mostra o motivo (mensagem do servidor ou o erro do campo) e mantém o card aberto e editável.
        $json = $resposta->json();
        $motivo = ($json['mensagem'] ?? '') ?: collect($json['formulario']['campos'] ?? [])->pluck('erro')->filter()->first();
        $this->assertNotEmpty($motivo, 'a recusa traz o motivo');
        $this->assertArrayHasKey('formulario', $json, 'o card volta com o formulário para o usuário trocar o horário');
        $this->assertSame(1, Agendamento::count(), 'só o que já existia: o card velho não gravou');
    }

    public function test_card_de_remarcar_velho_e_recusado_quando_o_agendamento_mudou_depois_de_abrir(): void
    {
        $ana = $this->atendente();
        $banho = \App\Models\CatalogoItem::find($this->banhoId);
        $meu = $this->agendamentoDireto($this->pet(), $ana, $banho, ['inicio' => '2026-11-11 09:00', 'fim' => '2026-11-11 10:00', 'status' => 'agendado']);

        $aberto = $this->postJson('/agente/formulario', ['tool' => 'remarcar_agendamento', 'id' => $meu->id])->assertOk();
        $this->assertNull($aberto->json('prazo_s'));

        $this->travel(2)->hours();
        // Alguém mexe no agendamento (muda o updated_at, a "versão" da ficha) enquanto o card ficava aberto.
        Agendamento::query()->whereKey($meu->id)->update(['updated_at' => now()->addMinute()]);

        $this->postJson('/agente/confirmar', ['proposta' => $aberto->json('proposta'), 'valores' => ['inicio' => '2026-11-12 14:00', 'profissional_id' => '']])
            ->assertStatus(422);
        $this->assertSame('2026-11-11 09:00', $meu->fresh()->inicio->format('Y-m-d H:i'), 'a versão antiga não desfaz a mudança dos outros');
    }

    public function test_token_antigo_de_card_de_tela_nao_grava_fora_do_setor_do_papel_atual(): void
    {
        $this->atendente();
        $clinica = $this->item();
        $token = $this->propor(['pet_id' => $this->petId, 'catalogo_item_id' => $clinica->id, 'profissional_id' => $this->usuarioClinica('veterinario')->id, 'inicio' => '2026-11-11 09:00'], 'agendar_atendimento');

        // O papel passa a só mexer no banho e tosa depois do Revisar: a execução confere o setor de novo.
        auth()->user()->forceFill(['papel' => 'atendente']);
        $resposta = $this->confirmar($token);

        // Recusa de regra (422 com a frase do setor), não erro técnico (500): o motivo chega ao usuário e a auditoria diz "recusado".
        $this->assertSame(422, $resposta->status());
        $this->assertSame('AI_TOOL_REFUSED', $resposta->json('codigo'));
        $this->assertStringContainsString('banho e tosa', (string) $resposta->json('mensagem'));
        $this->assertSame(0, Agendamento::count());
        $this->assertSame(1, AuditLog::query()->where('tool', 'agendar_atendimento')->where('resultado', 'recusado')->count());
    }
}
