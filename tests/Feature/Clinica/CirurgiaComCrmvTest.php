<?php

namespace Tests\Feature\Clinica;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Agendamento;
use App\Models\AuditLog;
use App\Models\CatalogoItem;
use App\Models\Pet;
use App\Models\User;
use App\Services\AgendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Cirurgia (adendo final do Lucas, 15/11): agendar (só para si), assumir (o vet faltou), remarcar e cancelar só por
 * usuário com CRMV no perfil, e o Confirmar pede o CRMV + a senha do PRÓPRIO usuário logado. Assumir = remarcar
 * trocando o profissional para si; com conflito nos blocos dele, recusa listando o que está no caminho. Trocar de ou
 * para cirurgia = cancelar e agendar de novo. A internação saiu da trava (e não ocupa a agenda). A trava é do service:
 * sem a reautenticação, a gravação é recusada mesmo sem o card.
 */
class CirurgiaComCrmvTest extends TestCase
{
    use CenarioClinica, RefreshDatabase, SimulaAgente;

    private AgendaService $agenda;

    private CatalogoItem $castracao;

    private Pet $thor;

    protected function setUp(): void
    {
        parent::setUp();
        // 10/11/2026 é uma terça; 13/11 é sexta, dia de expediente.
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->agenda = app(AgendaService::class);
        $this->castracao = $this->item(['categoria' => 'cirurgia', 'nome_comercial' => 'Castração']);
        $this->thor = $this->pet();
        $this->prepararAgente();
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

    /** O vet logado propõe a castração dele e revisa: devolve o token do Confirmar. */
    private function revisarCastracao(User $vet, string $inicio = '2026-11-13 14:00'): string
    {
        $resposta = $this->comando('agendar_atendimento', ['pet_id' => $this->thor->id, 'catalogo_item_id' => $this->castracao->id,
            'profissional_id' => $vet->id, 'inicio' => $inicio])->assertOk()
            ->assertJsonPath('formulario.reautenticar', true)->assertJsonPath('formulario.tipo_autorizacao', 'crmv');

        return (string) $this->postJson('/agente/revisar', ['proposta' => (string) $resposta->json('proposta'), 'valores' => []])
            ->assertOk()->json('token');
    }

    private function semSegredoNaAuditoria(string ...$segredos): void
    {
        $tudo = AuditLog::query()->get()->toJson();
        foreach ($segredos as $segredo) {
            $this->assertStringNotContainsString($segredo, $tudo, 'senha nunca vai para a auditoria');
        }
    }

    public function test_com_crmv_e_senha_certa_a_cirurgia_e_agendada_e_a_auditoria_so_marca_reautenticado(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $this->actingAs($vet);
        $token = $this->revisarCastracao($vet);

        // CRMV digitado em outro formato ("crmv-sp 10001" x "CRMV-SP 10001") é o mesmo.
        $this->postJson('/agente/confirmar', ['token' => $token, 'email_admin' => mb_strtolower((string) $vet->crmv), 'senha_admin' => 'password'])->assertOk();

        $ag = Agendamento::query()->sole();
        $this->assertSame(['2026-11-13 14:00', '2026-11-13 16:00', $vet->id], [$ag->inicio->format('Y-m-d H:i'), $ag->fim->format('Y-m-d H:i'), $ag->profissional_id]);
        $log = AuditLog::query()->where('tool', 'agendar_atendimento')->where('resultado', 'sucesso')->sole();
        $this->assertSame($vet->id, (int) $log->autorizado_por, 'reautenticado: o próprio usuário');
        $this->semSegredoNaAuditoria('password');
    }

    public function test_senha_errada_ou_crmv_de_outro_vet_recusa_e_nada_e_gravado(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $outro = $this->usuarioClinica('veterinario');
        $this->actingAs($vet);

        $this->postJson('/agente/confirmar', ['token' => $this->revisarCastracao($vet), 'email_admin' => $vet->crmv, 'senha_admin' => 'senha-errada-123'])
            ->assertStatus(403)->assertJsonPath('codigo', 'AI_REAUTH_FAILED');
        // O CRMV precisa ser o do usuário logado, mesmo com a senha certa dele.
        $this->postJson('/agente/confirmar', ['token' => $this->revisarCastracao($vet), 'email_admin' => $outro->crmv, 'senha_admin' => 'password'])
            ->assertStatus(403)->assertJsonPath('codigo', 'AI_REAUTH_FAILED');
        $this->postJson('/agente/confirmar', ['token' => $this->revisarCastracao($vet)])->assertStatus(403)->assertJsonPath('codigo', 'AI_REAUTH_REQUIRED');

        $this->assertSame(0, Agendamento::count());
        $this->semSegredoNaAuditoria('senha-errada-123', 'password');
    }

    public function test_sem_crmv_recusa_no_revisar_e_no_service(): void
    {
        $vet = $this->usuarioClinica('veterinario');

        // Recepção e veterinário sem CRMV no perfil: só consultam (o Revisar recusa e não sai token).
        foreach ([$this->logarComo('recepcao', self::T), $this->usuarioClinica('veterinario', self::T, comCrmv: false)] as $semCrmv) {
            $this->actingAs($semCrmv);
            $proposta = (string) $this->comando('agendar_atendimento', ['pet_id' => $this->thor->id, 'catalogo_item_id' => $this->castracao->id,
                'profissional_id' => $vet->id, 'inicio' => '2026-11-13 14:00'])->assertOk()->json('proposta');
            $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->assertStatus(422)->assertJsonMissingPath('token')
                ->assertJsonPath('formulario.campos', fn (array $campos) => str_contains(json_encode($campos, JSON_UNESCAPED_UNICODE), 'CRMV no perfil'));
        }

        // A trava é do service: sem ator, ou com o ator sem ter confirmado CRMV + senha, a gravação é recusada.
        $dados = ['pet_id' => $this->thor->id, 'catalogo_item_id' => $this->castracao->id, 'profissional_id' => $vet->id, 'inicio' => '2026-11-13 14:00'];
        $this->recusa(fn () => $this->agenda->agendar($dados, self::T), AgendaService::RECUSA_SEM_CRMV);
        $this->recusa(fn () => $this->agenda->agendar($dados, self::T, ['ator' => $vet, 'reautenticado' => false]), AgendaService::RECUSA_SEM_REAUTENTICACAO);
        $this->assertSame(0, Agendamento::count());
        // Emergência continua pelo encaixe, sem CRMV.
        $this->agenda->agendar([...$dados, 'inicio' => '2026-11-10 09:40', 'origem' => 'encaixe_emergencia'], self::T);
        $this->assertSame(1, Agendamento::count());
    }

    public function test_substituto_so_escala_a_si_mesmo_e_a_auditoria_guarda_o_vet_anterior(): void
    {
        $faltou = $this->usuarioClinica('veterinario');
        $substituto = $this->usuarioClinica('veterinario');
        $terceiro = $this->usuarioClinica('veterinario');
        $ag = $this->agendamentoDireto($this->thor, $faltou, $this->castracao, ['inicio' => '2026-11-13 14:00', 'fim' => '2026-11-13 16:00', 'status' => 'agendado']);
        $prontuario = $this->prontuarioDireto($this->thor, $faltou, ['agendamento_id' => $ag->id]);

        $this->recusa(fn () => $this->agenda->remarcar($ag->id, '2026-11-13 14:00', self::T, $terceiro->id, null, ['ator' => $substituto, 'reautenticado' => true]),
            AgendaService::RECUSA_SUBSTITUICAO);
        $this->assertSame($faltou->id, $ag->fresh()->profissional_id);

        // Pelo card: o substituto se escala, confirma com o CRMV e a senha dele; o prontuário não é tocado.
        $this->actingAs($substituto);
        $proposta = (string) $this->comando('remarcar_agendamento', ['id' => $ag->id, 'profissional_id' => $substituto->id, 'inicio' => '2026-11-13 14:00'])
            ->assertOk()->assertJsonPath('formulario.tipo_autorizacao', 'crmv')->json('proposta');
        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->assertOk()->json('token');
        $this->postJson('/agente/confirmar', ['token' => $token, 'email_admin' => $substituto->crmv, 'senha_admin' => 'password'])->assertOk();

        $this->assertSame([$substituto->id, '2026-11-13 14:00'], [$ag->fresh()->profissional_id, $ag->fresh()->inicio->format('Y-m-d H:i')]);
        $log = AuditLog::query()->where('tool', 'remarcar_agendamento')->where('resultado', 'sucesso')->sole();
        $this->assertEquals($faltou->id, $log->valores_anteriores['profissional_id'] ?? null, 'vet anterior na auditoria');
        $this->assertSame($substituto->id, (int) $log->autorizado_por);
        $this->assertSame([$faltou->id, $ag->id], [(int) $prontuario->fresh()->veterinario_id, (int) $prontuario->fresh()->agendamento_id], 'o prontuário não muda');
    }

    public function test_substituto_com_bloco_ocupado_recusa_listando_o_que_esta_no_caminho(): void
    {
        $faltou = $this->usuarioClinica('veterinario');
        $substituto = $this->usuarioClinica('veterinario');
        $ag = $this->agendamentoDireto($this->thor, $faltou, $this->castracao, ['inicio' => '2026-11-13 14:00', 'fim' => '2026-11-13 16:00', 'status' => 'agendado']);
        $noCaminho = $this->agendamentoDireto($this->pet(null, self::T, ['nome' => 'Luna']), $substituto, $this->item(),
            ['inicio' => '2026-11-13 15:00', 'fim' => '2026-11-13 16:00', 'status' => 'agendado']);
        // Dado inconsistente de propósito: um agendamento de OUTRO tenant com o mesmo profissional, no mesmo horário.
        $deOutroTenant = $this->agendamentoDireto($this->pet(null, self::OUTRO_TENANT), $substituto, $this->item([], self::OUTRO_TENANT),
            ['inicio' => '2026-11-13 14:00', 'fim' => '2026-11-13 15:00', 'status' => 'agendado', 'tenant_id' => self::OUTRO_TENANT]);

        try {
            $this->agenda->remarcar($ag->id, '2026-11-13 14:00', self::T, $substituto->id, null, ['ator' => $substituto, 'reautenticado' => true]);
            $this->fail('Esperava a recusa do bloco ocupado.');
        } catch (RegraDeNegocioException $e) {
            $this->assertStringContainsString('No caminho: #'.$noCaminho->id.' (15:00–16:00).', $e->getMessage());
            $this->assertStringNotContainsString('#'.$deOutroTenant->id.' ', $e->getMessage(), 'nada de outro tenant na lista');
        }
        $this->assertSame($faltou->id, $ag->fresh()->profissional_id);
    }

    public function test_limite_de_tentativas_do_crmv_e_o_do_login_e_o_acerto_nao_conta(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $this->actingAs($vet);

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/agente/confirmar', ['token' => $this->revisarCastracao($vet), 'email_admin' => $vet->crmv, 'senha_admin' => 'errada'])
                ->assertStatus(403)->assertJsonPath('codigo', 'AI_REAUTH_FAILED');
        }
        // A 6ª no mesmo minuto é barrada sem conferir, mesmo com a senha certa.
        $this->postJson('/agente/confirmar', ['token' => $this->revisarCastracao($vet), 'email_admin' => $vet->crmv, 'senha_admin' => 'password'])
            ->assertStatus(429)->assertJsonPath('codigo', 'AI_REAUTH_RATE_LIMIT');
        $this->assertSame(0, Agendamento::count());

        // Passado o minuto, a senha certa passa.
        $this->travel(61)->seconds();
        $this->postJson('/agente/confirmar', ['token' => $this->revisarCastracao($vet), 'email_admin' => $vet->crmv, 'senha_admin' => 'password'])->assertOk();
        $this->assertSame(1, Agendamento::count());
    }

    public function test_o_acerto_devolve_a_tentativa(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $this->actingAs($vet);
        // Depois do acerto, os blocos das 14h e 15h ficam com a cirurgia: a última tentativa usa outro horário.
        $errada = fn (string $inicio = '2026-11-13 14:00') => $this->postJson('/agente/confirmar', ['token' => $this->revisarCastracao($vet, $inicio), 'email_admin' => $vet->crmv, 'senha_admin' => 'errada']);

        foreach (range(1, 4) as $i) {
            $errada()->assertStatus(403);
        }
        $this->postJson('/agente/confirmar', ['token' => $this->revisarCastracao($vet), 'email_admin' => $vet->crmv, 'senha_admin' => 'password'])->assertOk();
        // 4 erradas + 1 certa: a 5ª errada ainda é conferida (403), não barrada (429).
        $errada('2026-11-13 09:00')->assertStatus(403)->assertJsonPath('codigo', 'AI_REAUTH_FAILED');
    }

    /**
     * A trava é do service mesmo se o controller não pedir o CRMV: o executor chamado direto, sem autorizado_por (ou com o
     * de OUTRO usuário), recusa a cirurgia. Sabotar autorizacaoDaAgenda (reautenticado sempre true) derruba este teste.
     */
    public function test_executor_sem_a_reautenticacao_do_proprio_usuario_recusa_a_cirurgia(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $outro = $this->usuarioClinica('dono');
        $this->actingAs($vet);
        $args = ['pet_id' => $this->thor->id, 'catalogo_item_id' => $this->castracao->id, 'profissional_id' => $vet->id, 'inicio' => '2026-11-13 14:00', 'tenant_id' => self::T];
        $agente = app(\App\Services\AiAgentService::class);

        foreach ([null, $outro->id] as $autorizadoPor) {
            $resultado = $agente->executarConfirmada('agendar_atendimento', $args, [], 'agente', $autorizadoPor);
            $this->assertNotSame('resultado_escrita', $resultado['tipo'] ?? null);
            $this->assertStringContainsString(AgendaService::RECUSA_SEM_REAUTENTICACAO, (string) ($resultado['mensagem'] ?? ''));
        }
        $this->assertSame(0, Agendamento::count());

        $this->assertSame('resultado_escrita', $agente->executarConfirmada('agendar_atendimento', $args, [], 'agente', $vet->id)['tipo'] ?? null);
        $this->assertSame(1, Agendamento::count());
    }

    public function test_cirurgia_que_ja_existe_nao_e_mexida_sem_crmv_nem_sem_a_senha(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $ag = $this->agendamentoDireto($this->thor, $vet, $this->castracao, ['inicio' => '2026-11-13 14:00', 'fim' => '2026-11-13 16:00', 'status' => 'agendado']);

        // Ninguém troca a cirurgia por consulta: é cancelar e agendar de novo (limitação aceita).
        $this->logarComo('recepcao', self::T);
        $proposta = (string) $this->comando('alterar_servico_agendamento', ['id' => $ag->id, 'catalogo_item_id' => $this->item()->id])->assertOk()->json('proposta');
        $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->assertStatus(422)->assertJsonMissingPath('token')
            ->assertJsonPath('formulario.campos', fn (array $campos) => str_contains(json_encode($campos, JSON_UNESCAPED_UNICODE), 'cancele e agende de novo'));

        // O vet com CRMV remarca, mas sem digitar o CRMV e a senha o Confirmar recusa.
        $this->actingAs($vet);
        $proposta = (string) $this->comando('remarcar_agendamento', ['id' => $ag->id, 'inicio' => '2026-11-13 09:00'])->assertOk()
            ->assertJsonPath('formulario.tipo_autorizacao', 'crmv')->json('proposta');
        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->assertOk()->json('token');
        $this->postJson('/agente/confirmar', ['token' => $token])->assertStatus(403)->assertJsonPath('codigo', 'AI_REAUTH_REQUIRED');

        $ag->refresh();
        $this->assertSame([$this->castracao->id, '2026-11-13 14:00'], [$ag->catalogo_item_id, $ag->inicio->format('Y-m-d H:i')]);
    }

    public function test_cirurgia_nova_para_outro_vet_recusa_e_para_si_passa(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $colega = $this->usuarioClinica('veterinario');
        $dados = ['pet_id' => $this->thor->id, 'catalogo_item_id' => $this->castracao->id, 'inicio' => '2026-11-13 14:00'];

        $this->recusa(fn () => $this->agenda->agendar([...$dados, 'profissional_id' => $colega->id], self::T, ['ator' => $vet, 'reautenticado' => true]),
            AgendaService::RECUSA_CIRURGIA_PARA_OUTRO);
        $this->assertSame(0, Agendamento::count());

        $this->agenda->agendar([...$dados, 'profissional_id' => $vet->id], self::T, ['ator' => $vet, 'reautenticado' => true]);
        $this->assertSame($vet->id, Agendamento::query()->sole()->profissional_id);
    }

    public function test_cancelar_cirurgia_pede_crmv_e_senha_e_a_recepcao_nao_cancela(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $colega = $this->usuarioClinica('veterinario');
        $ag = $this->agendamentoDireto($this->thor, $colega, $this->castracao, ['inicio' => '2026-11-13 14:00', 'fim' => '2026-11-13 16:00', 'status' => 'agendado']);

        // A recepção (sem CRMV) não cancela: o Revisar recusa.
        $this->logarComo('recepcao', self::T);
        $proposta = (string) $this->comando('alterar_status_agendamento', ['id' => $ag->id, 'novo_status' => 'cancelado'])->assertOk()->json('proposta');
        $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->assertStatus(422)->assertJsonMissingPath('token');
        $this->assertSame('agendado', $ag->fresh()->status);
        // 16/11: "faltou" numa cirurgia que ainda não começou é recusado (seria cancelar sem CRMV).
        $this->assertStringContainsString('"Faltou" só vale a partir do início', (string) $this->agenda->motivoParaNaoAlterarStatus($ag->id, 'faltou', self::T));

        // Qualquer usuário com CRMV cancela, com o CRMV e a senha dele; a auditoria diz o tipo de conferência.
        $this->actingAs($vet);
        $proposta = (string) $this->comando('alterar_status_agendamento', ['id' => $ag->id, 'novo_status' => 'cancelado'])->assertOk()
            ->assertJsonPath('formulario.tipo_autorizacao', 'crmv')->json('proposta');
        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->assertOk()->json('token');
        $this->postJson('/agente/confirmar', ['token' => $token, 'email_admin' => $vet->crmv, 'senha_admin' => 'password'])->assertOk();

        $this->assertSame('cancelado', $ag->fresh()->status);
        $log = AuditLog::query()->where('tool', 'alterar_status_agendamento')->where('resultado', 'sucesso')->sole();
        $this->assertSame($vet->id, (int) $log->autorizado_por);
        $this->assertStringContainsString('conferência: crmv+senha', (string) $log->mensagem);
        $this->semSegredoNaAuditoria('password');
    }

    public function test_internacao_nao_exige_crmv_e_nao_ocupa_vaga(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $internacao = $this->item(['categoria' => 'internacao', 'nome_comercial' => 'Internação (diária)', 'cobranca_por' => 'diaria']);
        $consulta = $this->item();
        foreach (range(1, 4) as $i) {
            $this->agendamentoDireto($this->pet(null, self::T, ['nome' => 'Pet '.$i]), $vet, $consulta, ['inicio' => '2026-11-13 14:00', 'fim' => '2026-11-13 15:00', 'status' => 'agendado']);
        }

        // A recepção registra a internação com o bloco do vet cheio, sem CRMV.
        $this->logarComo('recepcao', self::T);
        $this->confirmar($this->propor(['pet_id' => $this->thor->id, 'catalogo_item_id' => $internacao->id, 'profissional_id' => $vet->id,
            'inicio' => '2026-11-13 14:00'], 'agendar_atendimento'))->assertOk();
        $this->assertSame(5, Agendamento::count());

        // E ela não tira vaga de ninguém: com 3 consultas + a internação, a 4ª consulta ainda entra.
        Agendamento::query()->where('catalogo_item_id', $consulta->id)->latest('id')->first()->update(['status' => 'cancelado']);
        $this->assertNull($this->agenda->motivoParaNaoAgendar(['pet_id' => $this->thor->id, 'catalogo_item_id' => $consulta->id,
            'profissional_id' => $vet->id, 'inicio' => '2026-11-13 14:00'], self::T));
    }

    public function test_consulta_entra_com_3_no_bloco_e_a_quinta_recusa(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $dados = ['pet_id' => $this->thor->id, 'catalogo_item_id' => $this->item()->id, 'profissional_id' => $vet->id, 'inicio' => '2026-11-13 09:00'];
        foreach (range(1, 4) as $i) {
            $this->agenda->agendar($dados, self::T);
        }
        $this->recusa(fn () => $this->agenda->agendar($dados, self::T), 'não tem vaga no bloco das 09:00');
        $this->assertSame(4, Agendamento::count());
    }

    public function test_procedimento_e_cirurgia_ocupam_o_bloco_inteiro_no_service(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $vacina = $this->item(['categoria' => 'vacina', 'nome_comercial' => 'Vacina V10']);
        $consulta = $this->item();
        $base = ['pet_id' => $this->thor->id, 'profissional_id' => $vet->id];

        $this->agenda->agendar([...$base, 'catalogo_item_id' => $consulta->id, 'inicio' => '2026-11-13 09:00'], self::T);
        $this->recusa(fn () => $this->agenda->agendar([...$base, 'catalogo_item_id' => $vacina->id, 'inicio' => '2026-11-13 09:00'], self::T), 'ocupa o bloco inteiro');

        $this->agenda->agendar([...$base, 'catalogo_item_id' => $vacina->id, 'inicio' => '2026-11-13 10:00'], self::T);
        $this->recusa(fn () => $this->agenda->agendar([...$base, 'catalogo_item_id' => $consulta->id, 'inicio' => '2026-11-13 10:00'], self::T), 'não tem vaga');

        // Cirurgia (com CRMV + senha) recusa bloco com consulta e, livre, fecha 2 blocos.
        $aut = ['ator' => $vet, 'reautenticado' => true];
        $this->recusa(fn () => $this->agenda->agendar([...$base, 'catalogo_item_id' => $this->castracao->id, 'inicio' => '2026-11-13 09:00'], self::T, $aut), 'ocupa o bloco inteiro');
        $this->agenda->agendar([...$base, 'catalogo_item_id' => $this->castracao->id, 'inicio' => '2026-11-13 14:00'], self::T, $aut);
        $this->recusa(fn () => $this->agenda->agendar([...$base, 'catalogo_item_id' => $consulta->id, 'inicio' => '2026-11-13 15:00'], self::T), 'não tem vaga');
    }

    /** Revisão de 16/11: mover a cirurgia de um colega mantendo o colega é recusado; e consulta não vira cirurgia pela troca. */
    public function test_nao_remarca_a_cirurgia_do_colega_sem_assumir_e_consulta_nao_vira_cirurgia(): void
    {
        $dono = $this->usuarioClinica('veterinario');
        $colega = $this->usuarioClinica('veterinario');
        $ag = $this->agendamentoDireto($this->thor, $dono, $this->castracao, ['inicio' => '2026-11-13 14:00', 'fim' => '2026-11-13 16:00', 'status' => 'agendado']);

        $this->recusa(fn () => $this->agenda->remarcar($ag->id, '2026-11-13 09:00', self::T, null, null, ['ator' => $colega, 'reautenticado' => true]),
            AgendaService::RECUSA_SUBSTITUICAO);
        $this->assertSame('2026-11-13 14:00', $ag->fresh()->inicio->format('Y-m-d H:i'));
        // O próprio vet remarca a dele.
        $this->agenda->remarcar($ag->id, '2026-11-13 09:00', self::T, null, null, ['ator' => $dono, 'reautenticado' => true]);
        $this->assertSame('2026-11-13 09:00', $ag->fresh()->inicio->format('Y-m-d H:i'));

        $consulta = $this->agendamentoDireto($this->pet(null, self::T, ['nome' => 'Luna']), $dono, $this->item(), ['inicio' => '2026-11-14 10:00', 'fim' => '2026-11-14 11:00', 'status' => 'agendado']);
        $this->recusa(fn () => $this->agenda->trocarServico($consulta->id, $this->castracao->id, $dono->id, '2026-11-14 10:00', self::T, null, ['ator' => $dono, 'reautenticado' => true]),
            AgendaService::RECUSA_TROCA_CIRURGIA);
    }

    public function test_realizado_de_cirurgia_segue_a_regra_atual_sem_crmv(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $ag = $this->agendamentoDireto($this->thor, $vet, $this->castracao, ['inicio' => '2026-11-10 08:00', 'fim' => '2026-11-10 10:00', 'status' => 'agendado']);

        $this->agenda->alterarStatus($ag->id, 'realizado', self::T);
        $this->assertSame('realizado', $ag->fresh()->status);
    }

    /** 2ª passada de 16/11: a senha é exigida pelo SERVICE também na cirurgia que já existe (remarcar, assumir, cancelar). */
    public function test_cirurgia_existente_sem_a_senha_no_service_recusa_remarcar_assumir_e_cancelar(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $colega = $this->usuarioClinica('veterinario');
        $ag = $this->agendamentoDireto($this->thor, $vet, $this->castracao, ['inicio' => '2026-11-13 14:00', 'fim' => '2026-11-13 16:00', 'status' => 'agendado']);
        $semSenha = ['ator' => $vet, 'reautenticado' => false];

        $this->recusa(fn () => $this->agenda->remarcar($ag->id, '2026-11-13 09:00', self::T, null, null, $semSenha), AgendaService::RECUSA_SEM_REAUTENTICACAO);
        $this->recusa(fn () => $this->agenda->remarcar($ag->id, '2026-11-13 14:00', self::T, $colega->id, null, ['ator' => $colega, 'reautenticado' => false]),
            AgendaService::RECUSA_SEM_REAUTENTICACAO);
        $this->recusa(fn () => $this->agenda->alterarStatus($ag->id, 'cancelado', self::T, null, $semSenha), AgendaService::RECUSA_SEM_REAUTENTICACAO);

        $ag->refresh();
        $this->assertSame([$vet->id, '2026-11-13 14:00', 'agendado'], [$ag->profissional_id, $ag->inicio->format('Y-m-d H:i'), $ag->status]);
    }

    /**
     * Decisão do Lucas (16/11): em cirurgia e internação, "faltou" só a partir do início do agendamento. Antes, é recusado
     * com o motivo (para desfazer, cancelar). Consulta segue a regra de antes.
     */
    public function test_faltou_em_cirurgia_e_internacao_so_a_partir_do_inicio(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $internacao = $this->item(['categoria' => 'internacao', 'nome_comercial' => 'Internação (diária)', 'cobranca_por' => 'diaria']);
        $cirurgia = $this->agendamentoDireto($this->thor, $vet, $this->castracao, ['inicio' => '2026-11-13 14:00', 'fim' => '2026-11-13 16:00', 'status' => 'agendado']);
        $entrada = $this->agendamentoDireto($this->pet(null, self::T, ['nome' => 'Zeca']), $vet, $internacao, ['inicio' => '2026-11-12 09:00', 'fim' => '2026-11-12 10:00', 'status' => 'confirmado']);
        $consulta = $this->agendamentoDireto($this->pet(null, self::T, ['nome' => 'Luna']), $vet, $this->item(), ['inicio' => '2026-11-13 09:00', 'fim' => '2026-11-13 10:00', 'status' => 'agendado']);

        $this->recusa(fn () => $this->agenda->alterarStatus($cirurgia->id, 'faltou', self::T), 'use cancelar (com CRMV e senha)');
        $this->recusa(fn () => $this->agenda->alterarStatus($entrada->id, 'faltou', self::T), '"Faltou" só vale a partir do início');
        $this->assertSame(['agendado', 'confirmado'], [$cirurgia->fresh()->status, $entrada->fresh()->status]);

        // Pelo card, a recepção recebe a recusa no Revisar.
        $this->logarComo('recepcao', self::T);
        $proposta = (string) $this->comando('alterar_status_agendamento', ['id' => $cirurgia->id, 'novo_status' => 'faltou'])->assertOk()->json('proposta');
        $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->assertStatus(422)->assertJsonMissingPath('token')
            ->assertJsonPath('formulario.campos.0.erro', fn (string $m) => str_contains($m, '"Faltou" só vale a partir do início'));

        // Consulta: regra de antes ("faltou" mesmo antes do início).
        $this->agenda->alterarStatus($consulta->id, 'faltou', self::T);
        $this->assertSame('faltou', $consulta->fresh()->status);

        // A partir do início, vale (no minuto do início, inclusive).
        $this->travelTo(Carbon::parse('2026-11-12 09:00', 'America/Sao_Paulo'));
        $this->agenda->alterarStatus($entrada->id, 'faltou', self::T);
        $this->travelTo(Carbon::parse('2026-11-13 14:20', 'America/Sao_Paulo'));
        $this->agenda->alterarStatus($cirurgia->id, 'faltou', self::T);
        $this->assertSame(['faltou', 'faltou'], [$entrada->fresh()->status, $cirurgia->fresh()->status]);
    }
}
