<?php

namespace Tests\Feature\Clinica;

use App\Models\Agendamento;
use App\Models\CatalogoItem;
use App\Models\Pet;
use App\Models\User;
use App\Services\AiAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * 25/11: a IA NÃO vê as linhas das tabelas (só a contagem), então "o Rex", "o último", "esse", "ele" e "pro dia 21, mesmo horário"
 * são resolvidos pelo SERVIDOR, a partir da última tabela mostrada e do agendamento achado. Reproduz as conversas do Lucas.
 */
class AgenteReferenciasTest extends TestCase
{
    use CenarioClinica, RefreshDatabase, SimulaAgente;

    private User $ana;

    private CatalogoItem $banho;

    private Pet $thor;

    private Pet $rex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->prepararAgente();
        $this->logarComo('recepcao', self::T);
        $this->ana = User::factory()->papel('atendente')->doTenant(self::T)->create(['name' => 'Ana']);
        $this->banho = $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho porte grande']);
        $tutor = $this->tutor();
        $this->thor = $this->pet($tutor, self::T, ['nome' => 'Thor']);
        $this->rex = $this->pet($tutor, self::T, ['nome' => 'Rex']);
    }

    private function marcar(Pet $pet, string $inicio, string $fim): Agendamento
    {
        return $this->agendamentoDireto($pet, $this->ana, $this->banho, ['inicio' => $inicio, 'fim' => $fim, 'status' => 'agendado']);
    }

    private function mostrarAgenda(string $de, string $ate)
    {
        return $this->comando('consultar_agenda', ['data_inicio' => $de, 'data_fim' => $ate])->assertOk()->assertJsonPath('tipo', 'resultado_leitura');
    }

    // ------------------------------------------------------------------ item 1: referência pela última tabela

    public function test_nome_ambiguo_e_resolvido_pelo_rex_da_tabela_que_o_usuario_acabou_de_ver(): void
    {
        $this->marcar($this->rex, '2026-11-11 09:00', '2026-11-11 10:00');
        $this->marcar($this->rex, '2026-11-13 09:00', '2026-11-13 10:00');
        $hoje = $this->marcar($this->rex, '2026-11-12 14:00', '2026-11-12 15:00');
        $this->marcar($this->thor, '2026-11-12 09:00', '2026-11-12 10:00');

        // Sem tabela: "Rex" bate com 3 agendamentos e o card pergunta qual (como antes).
        $this->comando('remarcar_agendamento', ['busca' => 'Rex'])->assertOk()->assertJsonPath('tipo', 'resultado_leitura');

        // Com a tabela do dia 12 na tela (um Rex só nela), "o Rex" é aquele.
        $this->mostrarAgenda('2026-11-12', '2026-11-12');
        $this->comando('remarcar_agendamento', ['busca' => 'Rex'])->assertOk()
            ->assertJsonPath('tipo', 'confirmacao_pendente')
            ->assertJsonPath('formulario.ficha.agendamento_id', $hoje->id);
    }

    public function test_dois_rex_na_tabela_mostram_so_os_dois_e_nao_todos_os_do_banco(): void
    {
        $a = $this->marcar($this->rex, '2026-11-11 09:00', '2026-11-11 10:00');
        $b = $this->marcar($this->rex, '2026-11-11 11:00', '2026-11-11 12:00');
        $this->marcar($this->rex, '2026-11-20 09:00', '2026-11-20 10:00');

        $this->mostrarAgenda('2026-11-11', '2026-11-11');
        $resposta = $this->comando('remarcar_agendamento', ['busca' => 'Rex'])->assertOk()->assertJsonPath('tipo', 'resultado_leitura');

        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($resposta->json('resultado'), 'id'));
    }

    public function test_o_ultimo_com_tabela_e_a_ultima_linha_dela_e_sem_tabela_e_o_ultimo_criado(): void
    {
        $primeiro = $this->marcar($this->thor, '2026-11-11 09:00', '2026-11-11 10:00');
        $ultimoDaTabela = $this->marcar($this->thor, '2026-11-11 11:00', '2026-11-11 12:00');
        $criadoPorUltimo = $this->marcar($this->rex, '2026-11-20 09:00', '2026-11-20 10:00');
        // Todos nascem às 10:00 (relógio congelado); este é, de propósito, o mais novo de todos.
        Agendamento::query()->whereKey($criadoPorUltimo->id)->update(['created_at' => '2026-11-10 11:00:00']);

        // Sem tabela: o último CRIADO.
        $this->comando('remarcar_agendamento', ['busca' => 'último'])->assertOk()->assertJsonPath('formulario.ficha.agendamento_id', $criadoPorUltimo->id);

        // Com a tabela do dia 11 na tela: a última linha dela (não o último criado do banco).
        $this->mostrarAgenda('2026-11-11', '2026-11-11');
        $this->comando('remarcar_agendamento', ['busca' => 'o último agendamento'])->assertOk()->assertJsonPath('formulario.ficha.agendamento_id', $ultimoDaTabela->id);
        $this->assertNotSame($primeiro->id, $ultimoDaTabela->id);
    }

    public function test_o_ultimo_que_criei_e_o_ultimo_criado_e_a_tabela_nao_decide(): void
    {
        $naTabela = $this->marcar($this->thor, '2026-11-11 09:00', '2026-11-11 10:00');
        $criadoPorUltimo = $this->marcar($this->rex, '2026-11-20 09:00', '2026-11-20 10:00');
        Agendamento::query()->whereKey($criadoPorUltimo->id)->update(['created_at' => '2026-11-10 11:00:00']);
        $this->mostrarAgenda('2026-11-11', '2026-11-11'); // a tabela só tem o Thor

        $this->comando('remarcar_agendamento', ['busca' => 'o último que eu criei'])->assertOk()
            ->assertJsonPath('formulario.ficha.agendamento_id', $criadoPorUltimo->id);
        $this->assertNotSame($naTabela->id, $criadoPorUltimo->id);
    }

    public function test_tabela_de_uma_linha_so_vale_para_ele_esse_sem_dizer_qual(): void
    {
        $unico = $this->marcar($this->thor, '2026-11-11 09:00', '2026-11-11 10:00');
        $this->marcar($this->rex, '2026-11-20 09:00', '2026-11-20 10:00');

        $this->mostrarAgenda('2026-11-11', '2026-11-11');

        $this->comando('remarcar_agendamento', [])->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente')
            ->assertJsonPath('formulario.ficha.agendamento_id', $unico->id);
    }

    public function test_tabela_de_outra_entidade_nao_vale_para_agendamento(): void
    {
        $this->marcar($this->thor, '2026-11-11 09:00', '2026-11-11 10:00');
        $this->marcar($this->thor, '2026-11-12 09:00', '2026-11-12 10:00');
        $this->comando('consultar_pets', ['nome' => 'Thor'])->assertOk(); // tabela de PETS (1 linha)

        // Sem id e sem busca: a tabela de pets não é a de agendamentos, então segue pedindo qual (nunca chuta).
        $this->comando('remarcar_agendamento', [])->assertStatus(422);
    }

    // ------------------------------------------------------------------ item 2: data relativa, o servidor mantém o horário

    private function inicioProposto(array $argumentos): ?string
    {
        return $this->comando('remarcar_agendamento', $argumentos)->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente')->json('argumentos.inicio');
    }

    public function test_dia_do_mes_mantem_o_horario_e_o_mes_do_proprio_agendamento(): void
    {
        $meu = $this->marcar($this->thor, '2026-12-22 08:00', '2026-12-22 09:00');

        // "altere pro dia 21, mesmo horário": a IA não sabe o horário nem o mês, e mesmo se chutar "inicio", o servidor corrige.
        $this->assertSame('2026-12-21 08:00', $this->inicioProposto(['id' => $meu->id, 'dia_do_mes' => 21, 'inicio' => '2026-11-21 14:00']));
    }

    public function test_dia_completo_mantem_o_horario_e_hora_muda_so_a_hora(): void
    {
        $meu = $this->marcar($this->thor, '2026-12-22 08:00', '2026-12-22 09:00');

        $this->assertSame('2026-12-24 08:00', $this->inicioProposto(['id' => $meu->id, 'dia' => '2026-12-24']));
        $this->assertSame('2026-12-24 10:30', $this->inicioProposto(['id' => $meu->id, 'dia' => '2026-12-24', 'hora' => '10:30']));
        $this->assertSame('2026-12-22 10:30', $this->inicioProposto(['id' => $meu->id, 'hora' => '10:30']), 'só a hora: o dia não muda');
    }

    public function test_deslocamento_relativo_soma_ao_horario_atual_e_mantem_a_hora(): void
    {
        $meu = $this->marcar($this->thor, '2026-12-22 08:00', '2026-12-22 09:00');

        // "no dia seguinte", "semana que vem", "mês que vem", "daqui a 1 mês e 2 dias": a IA não sabe a data atual; o servidor calcula.
        $this->assertSame('2026-12-23 08:00', $this->inicioProposto(['id' => $meu->id, 'somar_dias' => 1, 'inicio' => '2026-01-01 14:00']));
        $this->assertSame('2026-12-29 08:00', $this->inicioProposto(['id' => $meu->id, 'somar_semanas' => 1]));
        $this->assertSame('2027-01-22 08:00', $this->inicioProposto(['id' => $meu->id, 'somar_meses' => 1]));
        $this->assertSame('2027-01-24 08:00', $this->inicioProposto(['id' => $meu->id, 'somar_meses' => 1, 'somar_dias' => 2]));
        $this->assertSame('2026-12-23 10:30', $this->inicioProposto(['id' => $meu->id, 'somar_dias' => 1, 'hora' => '10:30']), 'hora nova junto do deslocamento');
        // Dia explícito vence o deslocamento; valor fora da faixa é ignorado (o início da IA segue como veio).
        $this->assertSame('2026-12-24 08:00', $this->inicioProposto(['id' => $meu->id, 'dia' => '2026-12-24', 'somar_dias' => 1]));
        $this->assertSame('2026-12-23 15:00', $this->inicioProposto(['id' => $meu->id, 'somar_dias' => 9999, 'inicio' => '2026-12-23 15:00']));
    }

    public function test_inicio_completo_sem_dia_relativo_continua_valendo_como_antes(): void
    {
        $meu = $this->marcar($this->thor, '2026-12-22 08:00', '2026-12-22 09:00');

        $this->assertSame('2026-12-23 15:00', $this->inicioProposto(['id' => $meu->id, 'inicio' => '2026-12-23 15:00']));
    }

    public function test_dia_que_nao_existe_no_mes_fica_em_branco_para_a_ficha_pedir(): void
    {
        $meu = $this->marcar($this->thor, '2026-11-22 08:00', '2026-11-22 09:00'); // novembro tem 30 dias

        $this->assertNull($this->inicioProposto(['id' => $meu->id, 'dia_do_mes' => 31]));
    }

    public function test_trocar_o_servico_pelo_nome_so_quando_um_servico_bate(): void
    {
        $tosa = $this->item(['setor' => 'petshop', 'categoria' => 'tosa', 'nome_comercial' => 'Tosa completa']);
        $this->item(['setor' => 'petshop', 'categoria' => 'tosa', 'nome_comercial' => 'Tosa higiênica']);
        $meu = $this->marcar($this->thor, '2026-11-11 09:00', '2026-11-11 10:00');

        $unico = $this->comando('alterar_servico_agendamento', ['id' => $meu->id, 'servico' => 'tosa completa'])->assertOk()->json('argumentos.catalogo_item_id');
        $ambiguo = $this->comando('alterar_servico_agendamento', ['id' => $meu->id, 'servico' => 'tosa'])->assertOk()->json('argumentos.catalogo_item_id');

        $this->assertSame($tosa->id, $unico);
        $this->assertNull($ambiguo, 'dois serviços batem com "tosa": a ficha pergunta, o servidor não chuta');
    }

    // ------------------------------------------------------------------ "agenda do Thor": o pet pelo nome

    public function test_consultar_agenda_filtra_pelo_nome_do_pet(): void
    {
        $doThor = $this->marcar($this->thor, '2026-11-11 09:00', '2026-11-11 10:00');
        $this->marcar($this->rex, '2026-11-12 09:00', '2026-11-12 10:00');

        $resposta = $this->comando('consultar_agenda', ['data_inicio' => '2026-11-10', 'data_fim' => '2026-11-30', 'pet' => 'thor'])->assertOk();

        $this->assertSame([$doThor->id], array_column($resposta->json('resultado'), 'id'));
    }

    // ------------------------------------------------------------------ item 3: o texto não pode afirmar gravação

    private function respostaSoDeTexto(string $texto)
    {
        $this->respostaIa = ['choices' => [['message' => ['role' => 'assistant', 'content' => $texto]]]];

        return $this->postJson('/agente/comando', ['mensagem' => 'então é ele'])->assertOk();
    }

    public function test_texto_que_afirma_gravacao_sem_tool_e_trocado_e_nada_e_gravado(): void
    {
        $meu = $this->marcar($this->thor, '2026-11-11 09:00', '2026-11-11 10:00');

        $resposta = $this->respostaSoDeTexto('Agendamento remarcado.');

        $this->assertSame('texto', $resposta->json('tipo'));
        $this->assertStringContainsString('ainda não alterei nada', $resposta->json('mensagem'));
        $this->assertSame('2026-11-11 09:00', $meu->fresh()->inicio->format('Y-m-d H:i'));
    }

    // ------------------------------------------------------------------ a voz: frase natural do modelo junto da tool

    private function respostaComTextoETool(string $texto, string $tool, array $argumentos)
    {
        $this->respostaIa = ['choices' => [['message' => ['role' => 'assistant', 'content' => $texto, 'tool_calls' => [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => $tool, 'arguments' => json_encode((object) $argumentos)]],
        ]]]]];

        return $this->postJson('/agente/comando', ['mensagem' => 'remarque o do Thor'])->assertOk();
    }

    public function test_a_frase_natural_que_o_modelo_escreve_junto_da_tool_vai_como_introducao(): void
    {
        $meu = $this->marcar($this->thor, '2026-11-11 09:00', '2026-11-11 10:00');

        $resposta = $this->respostaComTextoETool('Beleza! Achei o agendamento do Thor, dá uma conferida no card antes de confirmar.', 'remarcar_agendamento', ['id' => $meu->id]);

        $resposta->assertJsonPath('tipo', 'confirmacao_pendente')->assertJsonPath('introducao', 'Beleza! Achei o agendamento do Thor, dá uma conferida no card antes de confirmar.');
    }

    public function test_a_introducao_que_afirma_gravacao_ou_e_longa_ou_tem_link_e_descartada(): void
    {
        $meu = $this->marcar($this->thor, '2026-11-11 09:00', '2026-11-11 10:00');

        foreach (['Pronto, remarquei o agendamento do Thor!', 'O agendamento foi remarcado.', 'Já cancelei tudo.', str_repeat('Texto longo demais. ', 30), 'Veja em https://exemplo.com/x'] as $texto) {
            $resposta = $this->respostaComTextoETool($texto, 'remarcar_agendamento', ['id' => $meu->id]);

            $resposta->assertJsonPath('tipo', 'confirmacao_pendente');
            $this->assertArrayNotHasKey('introducao', $resposta->json(), $texto);
        }
        $this->assertSame('2026-11-11 09:00', $meu->fresh()->inicio->format('Y-m-d H:i'), 'nada foi gravado');
    }

    public function test_a_pergunta_de_qual_deles_e_uma_frase_de_conversa_com_a_quantidade(): void
    {
        $this->marcar($this->rex, '2026-11-11 09:00', '2026-11-11 10:00');
        $this->marcar($this->rex, '2026-11-12 09:00', '2026-11-12 10:00');

        $aviso = $this->comando('remarcar_agendamento', ['busca' => 'Rex'])->assertOk()->json('aviso');

        $this->assertStringContainsString('Achei 2 agendamentos que batem com “Rex”', $aviso);
        $this->assertStringContainsString('Qual deles você quer?', $aviso);
    }

    public function test_respostas_legitimas_em_texto_passam_sem_mudar(): void
    {
        foreach (['Para remarcar, diga o novo dia.', 'Quer que eu remarque para o dia 21?', 'O agendamento está cancelado.'] as $texto) {
            $this->assertSame($texto, $this->respostaSoDeTexto($texto)->json('mensagem'), $texto);
        }
    }

    public function test_o_filtro_de_afirmacao_falsa_pega_so_frases_curtas_de_conclusao(): void
    {
        foreach (['Agendamento remarcado.', 'Feito, remarquei o agendamento.', 'Pronto! Cancelei o agendamento do Thor.', 'O cadastro foi atualizado.', 'Já cancelei.'] as $falso) {
            $this->assertTrue(AiAgentService::afirmaGravacaoSemTool($falso), $falso);
        }
        foreach (['Não consegui remarcar: preciso do dia.', 'Posso remarcar se você disser o dia.', 'Há 3 agendamentos cancelados hoje no banho e tosa, quer ver a lista completa na tela agora mesmo?', 'Proposta pendente de confirmação humana.', 'Para excluir, use a lixeira.', 'O agendamento está cancelado.'] as $legitimo) {
            $this->assertFalse(AiAgentService::afirmaGravacaoSemTool($legitimo), $legitimo);
        }
    }
}
