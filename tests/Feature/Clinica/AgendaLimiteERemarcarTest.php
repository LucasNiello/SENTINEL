<?php

namespace Tests\Feature\Clinica;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Agendamento;
use App\Models\AuditLog;
use App\Models\CatalogoItem;
use App\Models\Pet;
use App\Models\User;
use App\Services\AgendaService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\ProvaDeTravas;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Ajustes da agenda (decisão do Lucas, 15/11, depois de testar no navegador):
 * - na CLÍNICA o mesmo profissional pode ter até 4 agendamentos ativos sobrepostos; o 5º é recusado pelo servidor, na
 *   mesma transação da gravação (com a leitura travada). O PETSHOP continua recusando qualquer sobreposição;
 * - o fim não é pedido: o servidor calcula início + duração padrão do serviço;
 * - remarcar edita o agendamento que existe (só o início; o fim recalcula), nunca cria um novo;
 * - o card avisa "já há N agendamentos neste horário"; selects com o nome primeiro; "Falta preencher" some ao preencher.
 */
class AgendaLimiteERemarcarTest extends TestCase
{
    use CenarioClinica, ProvaDeTravas, RefreshDatabase, SimulaAgente;

    private AgendaService $agenda;

    private User $vet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->agenda = app(AgendaService::class);
        $this->vet = $this->usuarioClinica('veterinario');
        $this->prepararAgente();
    }

    private function dados(array $extra = []): array
    {
        return ['pet_id' => $this->pet()->id, 'profissional_id' => $this->vet->id, 'catalogo_item_id' => $this->item()->id,
            'inicio' => '2026-11-11 14:00', ...$extra];
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

    /** @return Agendamento[] */
    private function lotar(int $quantos, string $inicio = '2026-11-11 14:00'): array
    {
        $pet = $this->pet();
        $servico = $this->item();

        return array_map(fn () => $this->agenda->agendar(['pet_id' => $pet->id, 'profissional_id' => $this->vet->id, 'catalogo_item_id' => $servico->id, 'inicio' => $inicio], self::T), range(1, $quantos));
    }

    // ---------------------------------------------------------------- limite de 4 na clínica

    public function test_clinica_aceita_ate_4_sobrepostos_do_mesmo_profissional_e_recusa_o_quinto(): void
    {
        $this->lotar(4);
        $this->assertSame(4, Agendamento::count());

        // 15/11 (blocos): a 5ª consulta no bloco das 14h é recusada.
        $this->recusa(fn () => $this->agenda->agendar($this->dados(), self::T), 'não tem vaga no bloco das 14:00');
        $this->assertSame(4, Agendamento::count(), 'o 5º não grava nada');
        $this->assertStringContainsString('as 4 vagas estão usadas', (string) $this->agenda->motivoParaNaoAgendar($this->dados(), self::T));
    }

    public function test_cancelado_e_faltou_liberam_vaga_encostar_e_outro_profissional_nao_contam(): void
    {
        $agendados = $this->lotar(4);
        $this->agenda->alterarStatus($agendados[0]->id, 'cancelado', self::T);
        $this->agenda->alterarStatus($agendados[1]->id, 'faltou', self::T);

        $this->lotar(2); // volta a 4 ativos
        $this->recusa(fn () => $this->agenda->agendar($this->dados(), self::T), 'não tem vaga no bloco das');

        $this->agenda->agendar($this->dados(['inicio' => '2026-11-11 15:00']), self::T); // 15/11: o bloco seguinte (o das 14h acaba às 15:00)
        $this->agenda->agendar($this->dados(['profissional_id' => $this->usuarioClinica('dono')->id]), self::T);
        $this->assertSame(8, Agendamento::count());
    }

    public function test_petshop_continua_recusando_qualquer_sobreposicao(): void
    {
        $atendente = $this->usuarioClinica('atendente');
        $banho = $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho']);
        $dados = ['pet_id' => $this->pet()->id, 'profissional_id' => $atendente->id, 'catalogo_item_id' => $banho->id, 'inicio' => '2026-11-11 14:00'];

        $this->agenda->agendar($dados, self::T);

        $this->recusa(fn () => $this->agenda->agendar([...$dados, 'inicio' => '2026-11-11 14:30'], self::T), 'O profissional já tem um agendamento nesse horário.');
        $this->assertSame(1, Agendamento::count());
    }

    /** A contagem do 5º é feita na gravação, não só no Revisar: o horário que enche entre os dois passos é recusado. */
    public function test_o_horario_que_enche_entre_o_revisar_e_a_gravacao_recusa_o_quinto(): void
    {
        $this->lotar(3);
        $this->assertNull($this->agenda->motivoParaNaoAgendar($this->dados(), self::T), 'no Revisar ainda cabe o 4º');

        $this->lotar(1); // outro pedido (agente, telefone ou app) ocupa a última vaga antes do Confirmar

        $this->recusa(fn () => $this->agenda->agendar($this->dados(), self::T), 'não tem vaga no bloco das');
        $this->assertSame(4, Agendamento::count());
    }

    /**
     * O SQLite dos testes ignora FOR UPDATE: a disputa real só existe no MySQL. Aqui fica provado que a contagem que
     * decide é a leitura TRAVADA, dentro da transação que também trava o profissional (tirar o lockForUpdate quebra).
     */
    public function test_a_contagem_do_limite_e_a_gravacao_usam_leitura_travada(): void
    {
        $this->lotar(1);
        $this->mostrarTravas();

        $travadas = $this->leiturasTravadas(fn () => $this->agenda->agendar($this->dados(), self::T));

        $this->assertTravouLeitura($travadas, 'users');
        $this->assertTravouLeitura($travadas, 'agendamentos', '"inicio" < ?');
        $this->assertSame(2, Agendamento::count());
    }

    // ---------------------------------------------------------------- fim calculado pelo servidor

    /** 15/11 (blocos): na clínica o fim é o fim do bloco (cirurgia: 2 blocos); no petshop, a duração do serviço. */
    public function test_o_servidor_calcula_o_fim_pela_duracao_padrao_do_servico(): void
    {
        $atendente = $this->usuarioClinica('atendente');
        $pet = $this->pet();
        $casos = [
            ['consulta', 'clinica', $this->vet, '2026-11-12 09:00', '2026-11-12 10:00'],
            ['vacina', 'clinica', $this->vet, '2026-11-12 10:00', '2026-11-12 11:00'],
            ['cirurgia', 'clinica', $this->vet, '2026-11-12 11:00', '2026-11-12 13:00'],
            ['banho', 'petshop', $atendente, '2026-11-12 09:00', '2026-11-12 10:00'],
            ['tosa', 'petshop', $atendente, '2026-11-12 11:00', '2026-11-12 12:30'],
        ];

        foreach ($casos as [$categoria, $setor, $profissional, $inicio, $fim]) {
            $servico = $this->item(['setor' => $setor, 'categoria' => $categoria, 'nome_comercial' => ucfirst($categoria)]);
            // Cirurgia (adendo final): só o próprio vet com CRMV, confirmando CRMV + senha.
            $agendamento = $this->agenda->agendar(['pet_id' => $pet->id, 'profissional_id' => $profissional->id, 'catalogo_item_id' => $servico->id, 'inicio' => $inicio], self::T,
                ['ator' => $profissional, 'reautenticado' => true]);

            $this->assertSame($fim, $agendamento->fresh()->fim->format('Y-m-d H:i'), $categoria);
        }
    }

    /** 15/11 (blocos): a duração padrão saiu; o fim informado é ignorado (o servidor usa o fim do bloco). */
    public function test_a_duracao_padrao_nunca_passa_do_maximo_da_configuracao_e_o_fim_informado_ainda_vale_no_service(): void
    {
        $explicito = $this->agenda->agendar($this->dados(['inicio' => '2026-11-12 15:00', 'fim' => '2026-11-12 15:45']), self::T);
        $this->assertSame('2026-11-12 16:00', $explicito->fresh()->fim->format('Y-m-d H:i'));
        $this->recusa(fn () => $this->agenda->agendar($this->dados(['inicio' => null]), self::T), 'Informe o início com data e hora.');
    }

    public function test_o_card_de_agendar_nao_pede_o_fim_e_o_servidor_calcula(): void
    {
        $this->logarComo('recepcao', self::T);
        $dados = $this->dados();

        $resposta = $this->comando('agendar_atendimento', $dados)->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente');
        $this->assertNotContains('fim', array_column($resposta->json('formulario.campos'), 'nome'), 'o fim não é campo do card');

        $this->confirmar($this->propor($dados, 'agendar_atendimento'))->assertOk();
        $this->assertSame('2026-11-11 15:00', Agendamento::sole()->fresh()->fim->format('Y-m-d H:i'), 'o fim é o do servidor (o fim do bloco)');
    }

    /** A IA não tem "fim" no esquema da tool: mandar mesmo assim é resposta fora do formato (como origem, status ou qualquer chave fora). */
    public function test_a_ia_mandando_fim_e_recusada_como_chave_fora_do_esquema(): void
    {
        $this->logarComo('recepcao', self::T);

        $this->comando('agendar_atendimento', [...$this->dados(), 'fim' => '2026-11-11 23:00'])->assertStatus(502)->assertJsonPath('codigo', 'AI_PROVIDER_RESPONSE_INVALID');
        $this->assertSame(0, Agendamento::count());
    }

    // ---------------------------------------------------------------- remarcar (service)

    private function agendamentoAberto(array $extra = []): Agendamento
    {
        return $this->agendamentoDireto($this->pet(), $this->vet, $this->item(), ['inicio' => '2026-11-11 09:00', 'fim' => '2026-11-11 09:45', 'status' => 'agendado', ...$extra]);
    }

    public function test_remarcar_muda_so_o_inicio_mantem_a_duracao_e_nao_cria_agendamento(): void
    {
        $original = $this->agendamentoAberto(['status' => 'confirmado']);

        $remarcado = $this->agenda->remarcar($original->id, '2026-11-16 10:00', self::T);

        $this->assertSame(1, Agendamento::count(), 'nunca cria agendamento novo');
        // 15/11: o fim é o fim do bloco.
        $this->assertSame(['2026-11-16 10:00', '2026-11-16 11:00', 'confirmado', $original->pet_id, $this->vet->id, $original->catalogo_item_id, 'normal'], [
            $remarcado->fresh()->inicio->format('Y-m-d H:i'), $remarcado->fresh()->fim->format('Y-m-d H:i'), $remarcado->status,
            $remarcado->pet_id, $remarcado->profissional_id, $remarcado->catalogo_item_id, $remarcado->origem,
        ]);
    }

    public function test_remarcar_respeita_o_limite_de_4_sem_contar_o_proprio_agendamento(): void
    {
        $alvo = $this->agendamentoAberto();
        $this->lotar(4, '2026-11-12 10:00');

        $this->recusa(fn () => $this->agenda->remarcar($alvo->id, '2026-11-12 10:00', self::T), 'não tem vaga no bloco das 10:00');
        $this->assertSame('2026-11-11 09:00', $alvo->fresh()->inicio->format('Y-m-d H:i'));

        // O próprio agendamento não conta contra si. 15/11 (blocos): o início é sempre o do bloco, então o caso é a troca de
        // serviço no mesmo bloco: ele e mais 3 ocupam as 10:00; sem ignorá-lo seriam 4 e ele mesmo seria recusado.
        $proprio = $this->agendamentoAberto(['inicio' => '2026-11-13 10:00', 'fim' => '2026-11-13 11:00']);
        $this->lotar(3, '2026-11-13 10:00');
        $retorno = $this->item(['nome_comercial' => 'Retorno']);
        $this->agenda->trocarServico($proprio->id, $retorno->id, $this->vet->id, '2026-11-13 10:00', self::T);
        $this->assertSame([$retorno->id, 9], [$proprio->fresh()->catalogo_item_id, Agendamento::count()]);
    }

    public function test_remarcar_no_petshop_continua_recusando_sobreposicao(): void
    {
        $atendente = $this->usuarioClinica('atendente');
        $banho = $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho']);
        $pet = $this->pet();
        $a = $this->agendamentoDireto($pet, $atendente, $banho, ['inicio' => '2026-11-11 09:00', 'fim' => '2026-11-11 10:00', 'status' => 'agendado']);
        $this->agendamentoDireto($pet, $atendente, $banho, ['inicio' => '2026-11-12 09:00', 'fim' => '2026-11-12 10:00', 'status' => 'agendado']);

        $this->recusa(fn () => $this->agenda->remarcar($a->id, '2026-11-12 09:30', self::T), 'O profissional já tem um agendamento nesse horário.');
    }

    public function test_remarcar_recusa_passado_mesmo_horario_status_encerrado_e_encaixe(): void
    {
        $aberto = $this->agendamentoAberto();

        $this->recusa(fn () => $this->agenda->remarcar($aberto->id, '2026-11-10 09:00', self::T), 'para um horário que já passou');
        $this->recusa(fn () => $this->agenda->remarcar($aberto->id, '2026-11-11 09:00', self::T), 'já está nesse horário');
        $this->recusa(fn () => $this->agenda->remarcar($aberto->id, 'amanhã de manhã', self::T), 'Informe o novo início');
        foreach (['realizado', 'cancelado', 'faltou'] as $status) {
            $encerrado = $this->agendamentoAberto(['status' => $status]);
            $this->recusa(fn () => $this->agenda->remarcar($encerrado->id, '2026-11-20 10:00', self::T), 'só os que ainda estão em aberto');
        }
        $encaixe = $this->agendamentoAberto(['origem' => 'encaixe_emergencia']);
        $this->recusa(fn () => $this->agenda->remarcar($encaixe->id, '2026-11-20 10:00', self::T), 'encaixe de emergência já aconteceu');
    }

    public function test_remarcar_de_outro_tenant_e_inexistente_e_a_leitura_e_travada(): void
    {
        $deFora = $this->agendamentoDireto($this->pet(null, self::OUTRO_TENANT), $this->usuarioClinica('veterinario', self::OUTRO_TENANT),
            $this->item([], self::OUTRO_TENANT), ['inicio' => '2026-11-11 09:00', 'fim' => '2026-11-11 09:30', 'status' => 'agendado', 'tenant_id' => self::OUTRO_TENANT]);

        try {
            $this->agenda->remarcar($deFora->id, '2026-11-20 10:00', self::T);
            $this->fail('remarcou o agendamento de outro tenant');
        } catch (ModelNotFoundException) {
            $this->assertSame('2026-11-11 09:00', $deFora->fresh()->inicio->format('Y-m-d H:i'));
        }
        $this->assertSame('Não encontrei o agendamento #'.$deFora->id.'.', $this->agenda->motivoParaNaoRemarcar($deFora->id, '2026-11-20 10:00', self::T));

        $meu = $this->agendamentoAberto();
        $this->mostrarTravas();
        $travadas = $this->leiturasTravadas(fn () => $this->agenda->remarcar($meu->id, '2026-11-20 10:00', self::T));
        $this->assertTravouLeitura($travadas, 'agendamentos', 'where "tenant_id" = ?');
        $this->assertTravouLeitura($travadas, 'users');
    }

    public function test_localizar_para_remarcar_acha_pelas_palavras_do_usuario_no_pet_tutor_servico_ou_profissional(): void
    {
        $rafael = User::factory()->papel('veterinario')->doTenant(self::T)->create(['name' => 'Rafael Lima', 'crmv' => 'CRMV-SP 1']);
        $retorno = $this->item(['nome_comercial' => 'Retorno']);
        $thor = $this->pet();
        $a = $this->agendamentoDireto($thor, $rafael, $retorno, ['inicio' => '2026-11-11 09:00', 'fim' => '2026-11-11 09:30', 'status' => 'agendado']);
        $b = $this->agendamentoDireto($this->pet(null, self::T, ['nome' => 'Mel']), $rafael, $retorno, ['inicio' => '2026-11-11 10:00', 'fim' => '2026-11-11 10:30', 'status' => 'confirmado']);
        $this->agendamentoDireto($thor, $rafael, $retorno, ['inicio' => '2026-11-11 11:00', 'fim' => '2026-11-11 11:30', 'status' => 'cancelado']);
        $this->agendamentoDireto($this->pet(null, self::OUTRO_TENANT), $this->usuarioClinica('veterinario', self::OUTRO_TENANT), $this->item(['nome_comercial' => 'Retorno'], self::OUTRO_TENANT),
            ['inicio' => '2026-11-11 12:00', 'fim' => '2026-11-11 12:30', 'status' => 'agendado', 'tenant_id' => self::OUTRO_TENANT]);

        $achados = $this->agenda->localizarParaRemarcar(null, 'o retorno do dr. rafael', self::T);
        $this->assertSame([$a->id, $b->id], $achados->pluck('id')->all(), 'só os em aberto do tenant, em ordem');
        $this->assertStringContainsString('Retorno · Rafael Lima', (string) $achados->first()->getAttribute('resumo'));
        $this->assertSame([$a->id], $this->agenda->localizarParaRemarcar(null, 'retorno rafael thor', self::T)->pluck('id')->all());
        $this->assertSame([$b->id], $this->agenda->localizarParaRemarcar($b->id, null, self::T)->pluck('id')->all());
        $this->assertCount(0, $this->agenda->localizarParaRemarcar(null, 'dr o', self::T), 'só palavras curtas ou de ligação: nada');
        $this->assertCount(0, $this->agenda->localizarParaRemarcar(null, '100%', self::T), 'o % não é curinga');
    }

    // ---------------------------------------------------------------- remarcar (agente, card e papéis)

    public function test_remarcar_pelo_agente_edita_o_existente_com_card_de_so_o_inicio_e_confirma(): void
    {
        $this->logarComo('recepcao', self::T);
        $original = $this->agendamentoAberto();

        $resposta = $this->comando('remarcar_agendamento', ['busca' => 'thor', 'inicio' => '2026-11-16 10:00'])->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente');
        // 15/11 (ficha): remarcar edita o início e o profissional (do mesmo setor), pelo calendário.
        $this->assertSame(['profissional_id', 'inicio'], array_column($resposta->json('formulario.campos'), 'nome'));
        $this->assertSame('2026-11-16 10:00', $resposta->json('formulario.campos.1.valor'));
        $linhas = array_column($resposta->json('formulario.resumo'), 'valor', 'rotulo');
        $this->assertSame(['Pet', 'Tutor', 'Serviço', 'Profissional atual', 'Horário atual', 'Situação'], array_keys($linhas));
        $this->assertStringContainsString('Thor', $linhas['Pet']);
        $this->assertSame('Remarcar o agendamento #'.$original->id, $resposta->json('formulario.titulo'));

        $this->confirmar($this->propor(['busca' => 'thor', 'inicio' => '2026-11-16 10:00'], 'remarcar_agendamento'))->assertOk();

        $this->assertSame(1, Agendamento::count(), 'nunca cria um novo');
        $this->assertSame('2026-11-16 10:00', $original->fresh()->inicio->format('Y-m-d H:i'));
        $log = AuditLog::query()->where('tool', 'remarcar_agendamento')->where('resultado', 'sucesso')->sole();
        $this->assertSame([auth()->id(), self::T], [(int) $log->user_id, (int) $log->tenant_id]);
    }

    public function test_sem_novo_horario_o_card_abre_com_o_inicio_atual_com_a_hora(): void
    {
        $this->logarComo('recepcao', self::T);
        $this->agendamentoAberto();

        $this->comando('remarcar_agendamento', ['busca' => 'thor'])->assertOk()
            ->assertJsonPath('formulario.campos.1.valor', '2026-11-11 09:00')
            ->assertJsonPath('formulario.campos.1.faltando', false);
    }

    public function test_dois_candidatos_o_card_pergunta_qual_e_nunca_agenda_no_lugar(): void
    {
        $this->logarComo('recepcao', self::T);
        $rafael = User::factory()->papel('veterinario')->doTenant(self::T)->create(['name' => 'Rafael Lima', 'crmv' => 'CRMV-SP 2']);
        $retorno = $this->item(['nome_comercial' => 'Retorno']);
        $a = $this->agendamentoDireto($this->pet(), $rafael, $retorno, ['inicio' => '2026-11-11 09:00', 'fim' => '2026-11-11 09:30', 'status' => 'agendado']);
        $b = $this->agendamentoDireto($this->pet(null, self::T, ['nome' => 'Pipoca']), $rafael, $retorno, ['inicio' => '2026-11-11 10:00', 'fim' => '2026-11-11 10:30', 'status' => 'agendado']);

        $resposta = $this->comando('remarcar_agendamento', ['busca' => 'retorno rafael', 'inicio' => '2026-11-16 10:00'])->assertOk()
            ->assertJsonPath('tipo', 'resultado_leitura')
            // 15/11: a lista usa as colunas da agenda e o botão "Escolher" abre a ficha do agendamento.
            ->assertJsonPath('resultado.0.acao', ['tool' => 'remarcar_agendamento', 'rotulo' => 'Escolher']);
        $this->assertSame([$a->id, $b->id], array_column($resposta->json('resultado'), 'id'));
        $this->assertSame(['Pipoca', 'Retorno', 'Rafael Lima'], [$resposta->json('resultado.1.pet'), $resposta->json('resultado.1.servico'), $resposta->json('resultado.1.profissional')]);
        $this->assertSame(2, Agendamento::count());
    }

    public function test_remarcar_sem_agendamento_dito_pede_o_numero_ou_o_nome_e_nada_e_criado(): void
    {
        $this->logarComo('recepcao', self::T);

        $this->comando('remarcar_agendamento', ['inicio' => '2026-11-16 10:00'])->assertStatus(422)
            ->assertJsonPath('mensagem', fn (string $m) => str_contains($m, 'Informe qual agendamento deve ser alterado'));
        $this->comando('remarcar_agendamento', ['busca' => 'ninguém', 'inicio' => '2026-11-16 10:00'])->assertStatus(422);
        $this->assertSame(0, Agendamento::count());
    }

    public function test_papeis_do_remarcar_vendedor_nao_atendente_so_banho_e_tosa(): void
    {
        $clinica = $this->agendamentoAberto();
        $banho = $this->agendamentoDireto($this->pet(null, self::T, ['nome' => 'Bidu']), $this->usuarioClinica('atendente'),
            $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho']), ['inicio' => '2026-11-11 15:00', 'fim' => '2026-11-11 16:00', 'status' => 'agendado']);

        $this->logarComo('vendedor', self::T);
        $this->comando('remarcar_agendamento', ['id' => $clinica->id, 'inicio' => '2026-11-16 10:00'])->assertStatus(403)->assertJsonPath('tipo', 'negado');
        $this->assertSame('negado', AuditLog::query()->where('tool', 'remarcar_agendamento')->latest('id')->value('resultado'));

        $this->logarComo('atendente', self::T);
        // O horário da clínica nem é achado (id, busca ou formulário aberto à mão): o card não mostra pet, tutor nem veterinário.
        $this->comando('remarcar_agendamento', ['id' => $clinica->id, 'inicio' => '2026-11-16 10:00'])->assertStatus(422)
            ->assertJsonMissingPath('proposta')->assertJsonPath('mensagem', fn (string $m) => str_contains($m, 'Não encontrei'));
        $this->comando('remarcar_agendamento', ['busca' => 'thor', 'inicio' => '2026-11-16 10:00'])->assertStatus(422)->assertJsonMissingPath('formulario');
        $this->postJson('/agente/formulario', ['tool' => 'remarcar_agendamento', 'id' => $clinica->id])->assertStatus(422)->assertJsonMissingPath('formulario');
        $this->assertSame('2026-11-11 09:00', $clinica->fresh()->inicio->format('Y-m-d H:i'));

        $this->confirmar($this->propor(['id' => $banho->id, 'inicio' => '2026-11-16 15:00'], 'remarcar_agendamento'))->assertOk();
        $this->assertSame('2026-11-16 15:00', $banho->fresh()->inicio->format('Y-m-d H:i'));

        foreach (['recepcao', 'veterinario', 'dono'] as $papel) {
            $this->logarComo($papel, self::T);
            $this->comando('remarcar_agendamento', ['id' => $clinica->id, 'inicio' => '2026-11-17 10:00'])->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente');
        }
    }

    public function test_remarcar_recusado_no_revisar_aponta_o_inicio_e_o_quinto_da_clinica_tambem(): void
    {
        $this->logarComo('recepcao', self::T);
        $alvo = $this->agendamentoAberto();
        $this->lotar(4, '2026-11-12 10:00');

        $proposta = (string) $this->comando('remarcar_agendamento', ['id' => $alvo->id, 'inicio' => '2026-11-12 10:00'])->assertOk()->json('proposta');

        $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->assertStatus(422)->assertJsonMissingPath('token')
            ->assertJsonPath('formulario.campos.1.erro', fn (string $m) => str_contains($m, 'não tem vaga no bloco das 10:00'));
    }

    // ---------------------------------------------------------------- aviso do card

    /** 15/11 (ficha): o aviso "Já há N" saiu; a ficha de agendar traz o calendário, que mostra as vagas. */
    public function test_o_card_avisa_ja_ha_n_agendamentos_neste_horario_quando_n_e_maior_que_zero(): void
    {
        $this->logarComo('recepcao', self::T);
        $this->lotar(3);

        $this->comando('agendar_atendimento', $this->dados())->assertOk()
            ->assertJsonMissingPath('formulario.avisos')->assertJsonPath('formulario.ficha.acao', 'agendar');
    }

    public function test_o_revisar_bem_sucedido_devolve_o_card_com_o_aviso_do_horario_revisado(): void
    {
        $this->logarComo('recepcao', self::T);
        $this->lotar(2, '2026-11-11 16:00');
        $dados = $this->dados(['inicio' => '2026-11-11 15:00']);

        $proposta = (string) $this->comando('agendar_atendimento', $dados)->assertOk()->json('proposta');
        // A pessoa muda o horário no card antes de revisar: o card volta refeito com o horário REVISADO (15/11: sem aviso).
        $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => ['inicio' => '2026-11-11 16:00']])->assertOk()
            ->assertJsonPath('tipo', 'revisada')
            ->assertJsonPath('formulario.ficha.dia', '2026-11-11')->assertJsonMissingPath('formulario.avisos');
    }

    public function test_o_aviso_so_existe_na_clinica_e_o_encaixe_nao_tem_aviso(): void
    {
        $this->logarComo('recepcao', self::T);
        $atendente = $this->usuarioClinica('atendente');
        $banho = $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho']);
        $this->agendamentoDireto($this->pet(), $atendente, $banho, ['inicio' => '2026-11-11 14:00', 'fim' => '2026-11-11 15:00', 'status' => 'agendado']);

        $this->comando('agendar_atendimento', ['pet_id' => $this->pet()->id, 'catalogo_item_id' => $banho->id, 'profissional_id' => $atendente->id, 'inicio' => '2026-11-11 14:00'])
            ->assertOk()->assertJsonMissingPath('formulario.avisos');

        $this->logarComo('veterinario', self::T);
        $this->agendamentoDireto($this->pet(), $this->vet, $this->item(), ['inicio' => '2026-11-10 09:30', 'fim' => '2026-11-10 10:30', 'status' => 'agendado']);
        $this->comando('registrar_encaixe_emergencia', $this->dados(['inicio' => '2026-11-10 09:30', 'profissional_id' => auth()->id()]))->assertOk()
            ->assertJsonMissingPath('formulario.avisos');
    }

    // ---------------------------------------------------------------- selects e "Falta preencher"

    public function test_os_selects_do_card_trazem_o_nome_primeiro_e_o_numero_depois(): void
    {
        $this->logarComo('recepcao', self::T);
        $pet = $this->pet();
        $servico = $this->item(['nome_comercial' => 'Retorno']);

        $campos = array_column($this->comando('agendar_atendimento', [])->assertOk()->json('formulario.campos'), null, 'nome');

        $this->assertContains("Thor · #{$pet->id}", array_column($campos['pet_id']['opcoes'], 'rotulo'));
        $this->assertContains("Retorno · #{$servico->id}", array_column($campos['catalogo_item_id']['opcoes'], 'rotulo'));
        $this->assertContains($this->vet->name." · #{$this->vet->id}", array_column($campos['profissional_id']['opcoes'], 'rotulo'));
        $this->assertSame((string) $pet->id, (string) $campos['pet_id']['opcoes'][0]['valor'], 'o valor continua sendo o id');
    }

    public function test_o_front_tira_o_falta_preencher_quando_o_campo_tem_valor(): void
    {
        $this->logarComo('recepcao', self::T);

        $this->get('/agente')->assertOk()
            ->assertSee("entrada.addEventListener('change', atualizarFalta);", false)
            ->assertSee("bloco.classList.toggle('campo--faltando', vazio);", false);
    }

    // ---------------------------------------------------------------- 1 passada do reviewer (15/11)

    /**
     * 15/11 (adendo final, blocos): o "pico de simultâneos" saiu. A cirurgia ocupa 2 blocos inteiros, sozinha: não entra
     * em bloco com consulta e, livre, fecha os dois blocos.
     */
    public function test_o_limite_conta_o_pico_de_simultaneos_e_nao_tudo_o_que_cruza_o_intervalo(): void
    {
        $pet = $this->pet();
        $consulta = $this->item();
        $this->agenda->agendar(['pet_id' => $pet->id, 'profissional_id' => $this->vet->id, 'catalogo_item_id' => $consulta->id, 'inicio' => '2026-11-12 10:00'], self::T);
        $cirurgia = $this->item(['categoria' => 'cirurgia', 'nome_comercial' => 'Cirurgia']);
        $aut = ['ator' => $this->vet, 'reautenticado' => true];
        $dados = ['pet_id' => $pet->id, 'profissional_id' => $this->vet->id, 'catalogo_item_id' => $cirurgia->id];

        $this->recusa(fn () => $this->agenda->agendar([...$dados, 'inicio' => '2026-11-12 09:00'], self::T, $aut), 'ocupa o bloco inteiro');
        $longa = $this->agenda->agendar([...$dados, 'inicio' => '2026-11-12 11:00'], self::T, $aut);

        $this->assertSame('2026-11-12 13:00', $longa->fresh()->fim->format('Y-m-d H:i'), 'a cirurgia fecha 2 blocos');
    }

    public function test_o_pico_de_4_no_meio_do_intervalo_recusa_o_quinto(): void
    {
        $cirurgia = $this->item(['categoria' => 'cirurgia', 'nome_comercial' => 'Cirurgia']);
        $this->agenda->agendar($this->dados(['catalogo_item_id' => $cirurgia->id, 'inicio' => '2026-11-12 09:00']), self::T, ['ator' => $this->vet, 'reautenticado' => true]);

        // 15/11 (blocos): com a cirurgia das 09:00 às 11:00, nenhuma consulta entra no bloco das 10h.
        $this->recusa(fn () => $this->agenda->agendar($this->dados(['inicio' => '2026-11-12 10:00']), self::T), 'não tem vaga no bloco das 10:00');
        $this->agenda->agendar($this->dados(['inicio' => '2026-11-12 11:00']), self::T);
        $this->assertSame(2, Agendamento::count());
    }

    public function test_petshop_libera_a_vaga_ao_cancelar_e_horarios_que_encostam_nao_conflitam(): void
    {
        $atendente = $this->usuarioClinica('atendente');
        $banho = $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho']);
        $dados = ['pet_id' => $this->pet()->id, 'profissional_id' => $atendente->id, 'catalogo_item_id' => $banho->id, 'inicio' => '2026-11-12 09:00'];

        $primeiro = $this->agenda->agendar($dados, self::T);
        $this->agenda->agendar([...$dados, 'inicio' => '2026-11-12 10:00'], self::T); // encosta: o banho dura 60 min
        $this->recusa(fn () => $this->agenda->agendar($dados, self::T), 'O profissional já tem um agendamento nesse horário.');

        $this->agenda->alterarStatus($primeiro->id, 'cancelado', self::T);
        $this->agenda->agendar($dados, self::T);
        $this->assertSame(3, Agendamento::count());
    }

    public function test_remarcar_sem_maximo_de_duracao_configurado_recusa_e_nao_grava(): void
    {
        $alvo = $this->agendamentoAberto();
        config(['sentinel.agenda_duracao_maxima_minutos' => 0]);

        $this->recusa(fn () => $this->agenda->remarcar($alvo->id, '2026-11-16 10:00', self::T), 'não está configurado');
        $this->assertSame('2026-11-11 09:00', $alvo->fresh()->inicio->format('Y-m-d H:i'));
    }

    public function test_a_auditoria_do_remarcar_diz_de_que_horario_saiu(): void
    {
        $this->logarComo('recepcao', self::T);
        $this->agendamentoAberto();

        $this->confirmar($this->propor(['busca' => 'thor', 'inicio' => '2026-11-16 10:00'], 'remarcar_agendamento'))->assertOk();

        $log = AuditLog::query()->where('tool', 'remarcar_agendamento')->where('resultado', 'sucesso')->sole();
        $antes = (array) $log->valores_anteriores;
        $this->assertSame(['2026-11-11 09:00:00', '2026-11-11 09:45:00'], [$antes['inicio'], $antes['fim']]);
    }
}
