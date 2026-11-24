<?php

namespace Tests\Feature\Clinica;

use App\Models\AuditLog;
use App\Models\Cobranca;
use App\Models\Mensagem;
use App\Services\AiAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

/**
 * Painel lateral do /agente (24/11): GET /agente/painel. Só leitura, com escopo de tenant, papel e usuário. Pendentes do
 * negócio (cobranças, mensagens novas, agenda de hoje) e as ações recentes do PRÓPRIO usuário, sem payload nem dado de tutor.
 */
class AgentePainelTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
    }

    private function cobranca(int $tenant, string $status, \App\Models\Agendamento $agendamento): void
    {
        Cobranca::create(['agendamento_id' => $agendamento->id, 'setor' => 'clinica', 'status' => $status, 'total_centavos' => 1000, 'tenant_id' => $tenant]);
    }

    private function agendamentoDoDia(int $tenant, string $inicio, string $setor = 'clinica', string $status = 'agendado'): \App\Models\Agendamento
    {
        $item = $this->item(['setor' => $setor, 'categoria' => $setor === 'petshop' ? 'banho' : 'consulta'], $tenant);

        return $this->agendamentoDireto($this->pet(null, $tenant), $this->usuarioClinica('veterinario', $tenant), $item,
            ['inicio' => $inicio, 'fim' => Carbon::parse($inicio)->addMinutes(30)->format('Y-m-d H:i'), 'status' => $status]);
    }

    private function pendentes(): array
    {
        $resposta = $this->getJson('/agente/painel')->assertOk()->json('pendentes');

        return array_column($resposta, 'total', 'chave');
    }

    private function log(int $tenant, int $user, string $tool, string $acao, string $resultado = 'sucesso', string $mensagem = 'segredo do tutor'): void
    {
        AuditLog::create(['user_id' => $user, 'tool' => $tool, 'acao' => $acao, 'resultado' => $resultado, 'mensagem' => $mensagem,
            'parametros' => ['nome' => 'Ana Souza', 'cpf' => '123.456.789-00'], 'papel' => 'recepcao', 'permitido' => true, 'tenant_id' => $tenant]);
    }

    public function test_exige_login(): void
    {
        $this->getJson('/agente/painel')->assertUnauthorized();
    }

    public function test_conta_so_o_pendente_do_proprio_tenant(): void
    {
        $this->logarComo('dono', self::T);
        $a = $this->agendamentoDoDia(self::T, '2026-11-10 14:00');
        $this->agendamentoDoDia(self::T, '2026-11-11 09:00');                 // amanhã: não conta
        $this->agendamentoDoDia(self::T, '2026-11-10 15:00', 'clinica', 'cancelado'); // cancelado: não conta
        $this->cobranca(self::T, 'aberta', $a);
        $this->cobranca(self::T, 'fechada', $a);
        $this->cobranca(self::T, 'paga', $a);                                  // paga: não conta
        Mensagem::create(['tutor_id' => $this->tutor()->id, 'remetente' => 'tutor', 'texto' => 'oi', 'status' => 'nova', 'tenant_id' => self::T]);
        Mensagem::create(['tutor_id' => $this->tutor()->id, 'remetente' => 'tutor', 'texto' => 'oi', 'status' => 'tratada', 'tenant_id' => self::T]);

        // Outro tenant: nada disso pode entrar na conta.
        $b = $this->agendamentoDoDia(self::OUTRO_TENANT, '2026-11-10 14:00');
        $this->cobranca(self::OUTRO_TENANT, 'aberta', $b);
        Mensagem::create(['tutor_id' => $this->tutor(self::OUTRO_TENANT)->id, 'remetente' => 'tutor', 'texto' => 'oi', 'status' => 'nova', 'tenant_id' => self::OUTRO_TENANT]);

        $this->assertSame(['cobrancas' => 2, 'mensagens' => 1, 'agenda' => 1], $this->pendentes());
    }

    public function test_usuario_de_outro_tenant_nao_ve_nada_do_primeiro(): void
    {
        $a = $this->agendamentoDoDia(self::T, '2026-11-10 14:00');
        $this->cobranca(self::T, 'aberta', $a);
        Mensagem::create(['tutor_id' => $this->tutor()->id, 'remetente' => 'tutor', 'texto' => 'oi', 'status' => 'nova', 'tenant_id' => self::T]);
        $dono = $this->usuarioClinica('dono');
        $this->log(self::T, $dono->id, 'criar_tutor', 'criar');

        config(['sentinel.tenants_clinica' => [self::T, self::OUTRO_TENANT]]); // o outro tenant também é uma clínica
        $this->logarComo('dono', self::OUTRO_TENANT);
        $resposta = $this->getJson('/agente/painel')->assertOk();

        $this->assertSame(['cobrancas' => 0, 'mensagens' => 0, 'agenda' => 0], array_column($resposta->json('pendentes'), 'total', 'chave'));
        $this->assertSame(['com_efeito' => [], 'sem_efeito' => ['total' => 0, 'itens' => []]], $resposta->json('atividade'));
    }

    public function test_atividade_e_so_do_proprio_usuario_sem_consultas_e_sem_payload(): void
    {
        $eu = $this->logarComo('recepcao', self::T);
        $outro = $this->usuarioClinica('recepcao');
        $this->log(self::T, $eu->id, 'criar_tutor', 'criar');
        $this->log(self::T, $eu->id, 'consultar_agenda', 'consultar');        // leitura: fica de fora
        $this->log(self::T, $eu->id, 'consultar_tutores', 'exportar');        // exportação (CSV): também não é ação
        $this->log(self::T, $outro->id, 'criar_pet', 'criar');                // de outro usuário do mesmo tenant
        $this->log(self::OUTRO_TENANT, $eu->id, 'criar_pet', 'criar');        // mesmo user_id, outro tenant
        $this->log(self::T, $eu->id, 'agendar_atendimento', 'criar', 'negado');

        $resposta = $this->getJson('/agente/painel')->assertOk();
        $titulos = app(AiAgentService::class);

        $this->assertSame([$titulos->tituloDaTool('criar_tutor')], array_column($resposta->json('atividade.com_efeito'), 'acao'));
        $this->assertSame(1, $resposta->json('atividade.sem_efeito.total'));
        $this->assertSame([$titulos->tituloDaTool('agendar_atendimento')], array_column($resposta->json('atividade.sem_efeito.itens'), 'acao'));
        $this->assertSame(['Negada'], array_column($resposta->json('atividade.sem_efeito.itens'), 'resultado'));
        $this->assertStringNotContainsString('_', implode('', array_column($resposta->json('atividade.com_efeito'), 'acao')), 'nunca o identificador interno da tool');
        foreach ([...$resposta->json('atividade.com_efeito'), ...$resposta->json('atividade.sem_efeito.itens')] as $linha) {
            $this->assertEqualsCanonicalizing(['acao', 'quando', 'resultado', 'quantidade'], array_keys($linha));
        }
        $texto = $resposta->getContent();
        $this->assertStringNotContainsString('segredo do tutor', $texto);
        $this->assertStringNotContainsString('Ana Souza', $texto);
        $this->assertStringNotContainsString('123.456', $texto);
    }

    public function test_atividade_agrupa_repeticoes_e_so_mostra_o_que_teve_efeito_com_teto_de_cinco(): void
    {
        $eu = $this->logarComo('dono', self::T);
        for ($i = 0; $i < 3; $i++) {
            $this->log(self::T, $eu->id, 'agendar_atendimento', 'criar', 'expirado');
        }
        $this->log(self::T, $eu->id, 'agendar_atendimento', 'criar', 'cancelado');
        foreach (['criar_tutor', 'criar_pet', 'agendar_atendimento', 'registrar_venda', 'cobrar_atendimento', 'receber_cobranca', 'registrar_encaixe_emergencia'] as $tool) {
            $this->log(self::T, $eu->id, $tool, 'criar');
        }
        $this->log(self::T, $eu->id, 'registrar_encaixe_emergencia', 'criar'); // repetição do sucesso: ×2, a mais recente

        $atividade = $this->getJson('/agente/painel')->assertOk()->json('atividade');

        $this->assertCount(5, $atividade['com_efeito']);
        $this->assertSame(2, $atividade['com_efeito'][0]['quantidade']);
        $this->assertSame(['Concluída'], array_values(array_unique(array_column($atividade['com_efeito'], 'resultado'))));
        $this->assertSame(4, $atividade['sem_efeito']['total']);
        // Mais recente primeiro: o cancelado foi gravado depois dos três expirados.
        $this->assertSame([['Cancelado', 1], ['Expirado', 3]],array_map(fn ($i) => [$i['resultado'], $i['quantidade']], $atividade['sem_efeito']['itens']));
    }

    private function logSucesso(int $user, string $tool, string $acao, string $tipo, int $id, string $papel = 'recepcao'): void
    {
        AuditLog::create(['user_id' => $user, 'tool' => $tool, 'acao' => $acao, 'entidade_tipo' => $tipo, 'entidade_id' => $id,
            'parametros' => [], 'resultado' => 'sucesso', 'papel' => $papel, 'permitido' => true, 'tenant_id' => self::T]);
    }

    public function test_atividade_so_abre_ficha_de_tutor_e_pet_que_ainda_existem_no_tenant(): void
    {
        $eu = $this->logarComo('recepcao', self::T);
        $tutor = $this->tutor();
        $pet = $this->pet($tutor);
        $excluido = $this->pet($tutor);
        $excluido->delete();
        $alheio = $this->pet($this->tutor(self::OUTRO_TENANT), self::OUTRO_TENANT);
        $this->logSucesso($eu->id, 'criar_tutor', 'criar', 'Tutor', $tutor->id);
        $this->logSucesso($eu->id, 'atualizar_pet', 'atualizar', 'Pet', $pet->id);
        $this->logSucesso($eu->id, 'criar_pet', 'criar', 'Pet', $excluido->id);
        $this->logSucesso($eu->id, 'agendar_atendimento', 'criar', 'Pet', $alheio->id); // id de outro tenant gravado no log

        $itens = $this->getJson('/agente/painel')->assertOk()->json('atividade.com_efeito');

        $this->assertEqualsCanonicalizing(['/tutores/'.$tutor->id, '/pets/'.$pet->id], array_column($itens, 'href'), 'excluído e de outro tenant não ganham link');
        $this->assertCount(4, $itens, 'o item sem link continua na lista, só não abre');
        $this->get('/pets/'.$pet->id)->assertOk();
    }

    public function test_atividade_nao_abre_ficha_onde_a_tool_de_consulta_nao_existe(): void
    {
        // Fora da clínica (tenant 1) não há consultar_tutores: mesmo com o registro existindo, o item não ganha link.
        $eu = $this->logarComo('admin', 1);
        $tutor = $this->tutor(1);
        AuditLog::create(['user_id' => $eu->id, 'tool' => 'criar_tutor', 'acao' => 'criar', 'entidade_tipo' => 'Tutor', 'entidade_id' => $tutor->id,
            'parametros' => [], 'resultado' => 'sucesso', 'papel' => 'admin', 'permitido' => true, 'tenant_id' => 1]);

        $item = $this->getJson('/agente/painel')->assertOk()->json('atividade.com_efeito.0');

        $this->assertNotNull($item);
        $this->assertArrayNotHasKey('href', $item);
    }

    public function test_atividade_nao_abre_registro_de_acao_de_exclusao(): void
    {
        $eu = $this->logarComo('dono', self::T);
        $this->logSucesso($eu->id, 'excluir_tutor', 'excluir', 'Tutor', $this->tutor()->id, 'dono');

        $this->assertArrayNotHasKey('href', $this->getJson('/agente/painel')->assertOk()->json('atividade.com_efeito.0'));
    }

    public function test_sem_atividade_a_lista_fica_vazia_e_sem_tentativas(): void
    {
        $this->logarComo('dono', self::T);

        $this->assertSame(['com_efeito' => [], 'sem_efeito' => ['total' => 0, 'itens' => []]], $this->getJson('/agente/painel')->json('atividade'));
    }

    /**
     * Condição do Lucas para o clique virar pedido ao chat: o que a tool devolve tem que bater com o contador. Mensagens
     * bate (a tool filtra status 'nova', o mesmo do contador). Cobranças e agenda NÃO batem (o contador soma aberta+fechada e
     * a tool filtra um status só; a agenda do contador é só o dia, só agendado/confirmado): ficam com o link e sem `pedido`.
     */
    public function test_contador_de_mensagens_bate_com_a_tool_e_so_ela_envia_pedido(): void
    {
        $this->logarComo('dono', self::T);
        $tutor = $this->tutor();
        foreach (['nova', 'nova', 'tratada'] as $status) {
            Mensagem::create(['tutor_id' => $tutor->id, 'remetente' => 'tutor', 'texto' => 'oi', 'status' => $status, 'tenant_id' => self::T]);
        }
        $ag = $this->agendamentoDoDia(self::T, '2026-11-10 14:00');
        $this->cobranca(self::T, 'aberta', $ag);
        $this->cobranca(self::T, 'fechada', $ag);

        $pendentes = collect($this->getJson('/agente/painel')->assertOk()->json('pendentes'))->keyBy('chave');

        $this->assertSame(app(\App\Services\MensagemService::class)->buscarPorFiltro('nova', self::T)->count(), $pendentes['mensagens']['total']);
        $this->assertStringContainsString('novas', $pendentes['mensagens']['pedido']);

        $this->assertNotSame($pendentes['cobrancas']['total'], app(\App\Services\CobrancaService::class)->buscarPorFiltro('fechada', null, self::T)->count(), 'a tool não soma aberta+fechada');
        $this->assertSame('/cobrancas', $pendentes['cobrancas']['href']);
        $this->assertArrayNotHasKey('pedido', $pendentes['cobrancas']);
        $this->assertSame('/agenda', $pendentes['agenda']['href']);
        $this->assertArrayNotHasKey('pedido', $pendentes['agenda']);
    }

    public function test_agenda_conta_agendado_e_confirmado_e_nao_conta_realizado_nem_faltou(): void
    {
        foreach (['agendado', 'confirmado', 'realizado', 'faltou', 'cancelado'] as $i => $status) {
            $this->agendamentoDoDia(self::T, sprintf('2026-11-10 %02d:00', 11 + $i), 'clinica', $status);
        }

        $this->logarComo('dono', self::T);
        $this->assertSame(2, $this->pendentes()['agenda']);
    }

    public function test_veterinario_ve_cobrancas_pendentes(): void
    {
        $this->cobranca(self::T, 'fechada', $this->agendamentoDoDia(self::T, '2026-11-10 14:00'));

        $this->logarComo('veterinario', self::T);
        $this->assertSame(1, $this->pendentes()['cobrancas']);
    }

    public function test_papel_sem_permissao_de_leitura_nao_ganha_o_item(): void
    {
        $a = $this->agendamentoDoDia(self::T, '2026-11-10 14:00');
        $this->cobranca(self::T, 'aberta', $a);

        $this->logarComo('vendedor', self::T);
        $this->assertArrayNotHasKey('agenda', $this->pendentes());
    }

    public function test_atendente_so_conta_a_agenda_do_banho_e_tosa(): void
    {
        $this->agendamentoDoDia(self::T, '2026-11-10 14:00', 'clinica');
        $this->agendamentoDoDia(self::T, '2026-11-10 15:00', 'petshop');

        $this->logarComo('atendente', self::T);
        $this->assertSame(1, $this->pendentes()['agenda']);
    }

    public function test_administrador_da_clinica_leva_403(): void
    {
        $this->logarComo('admin', self::T);

        $this->getJson('/agente/painel')->assertForbidden();
    }

    public function test_fora_da_clinica_nao_ha_pendentes_so_recentes_do_proprio_usuario(): void
    {
        $eu = $this->logarComo('admin', 1);
        $this->log(1, $eu->id, 'criar_cliente', 'criar');

        $resposta = $this->getJson('/agente/painel')->assertOk();
        $this->assertSame([], $resposta->json('pendentes'));
        $this->assertSame([app(AiAgentService::class)->tituloDaTool('criar_cliente')], array_column($resposta->json('atividade.com_efeito'), 'acao'));
    }

    public function test_so_responde_a_get(): void
    {
        $this->logarComo('dono', self::T);

        $this->postJson('/agente/painel')->assertStatus(405);
    }
}
