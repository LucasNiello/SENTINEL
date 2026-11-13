<?php

namespace Tests\Feature\Clinica;

use App\Models\Agendamento;
use App\Models\AuditLog;
use App\Models\Cobranca;
use App\Models\Mensagem;
use App\Models\Venda;
use App\Services\CobrancaService;
use App\Services\FormularioTool;
use App\Services\ProntuarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Item 3 da 1ª leva: as tools de atendimento da clínica pelo fluxo do agente (Anthropic simulada): agenda (consulta e
 * status), cobrança (P-B4: cobrar, receber, cancelar), venda avulsa e caixa de entrada (P-B5). Toda escrita passa por
 * papel, tenant, Revisar → segurar e auditoria; o card mostra o que vai acontecer (resumo) antes do segurar.
 */
class ToolsDeAtendimentoDaClinicaTest extends TestCase
{
    use CenarioClinica, RefreshDatabase, SimulaAgente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->prepararAgente();
        $this->logarComo('recepcao', self::T);
    }

    /** A proposta é recusada já no Revisar: volta 422 com a frase e SEM token de segurar. */
    private function recusadaNoRevisar(string $tool, array $argumentos, string $trecho): void
    {
        $proposta = (string) $this->comando($tool, $argumentos)->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente')->json('proposta');

        $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])
            ->assertStatus(422)
            ->assertJsonMissingPath('token')
            ->assertJsonPath('mensagem', fn (string $m) => str_contains($m, $trecho));
    }

    private function resumo($resposta): array
    {
        return collect($resposta->json('formulario.resumo'))->pluck('valor', 'rotulo')->all();
    }

    /** Atendimento realizado do pet dado, com prontuário assinado contendo os itens dados (ou sem prontuário). */
    private function atendimentoRealizado(?array $itens = [], string $nomePet = 'Thor'): Agendamento
    {
        $vet = $this->usuarioClinica('veterinario');
        $pet = $this->pet(extra: ['nome' => $nomePet]);
        $agendamento = $this->agendamentoDireto($pet, $vet, $this->item(['nome_comercial' => 'Consulta clínica', 'preco_centavos' => 15000]));

        if ($itens !== null) {
            $prontuarios = app(ProntuarioService::class);
            $prontuario = $prontuarios->abrir(['pet_id' => $pet->id, 'tipo' => 'consulta', 'agendamento_id' => $agendamento->id], $vet->id, self::T);
            foreach ($itens as [$item, $quantidade]) {
                $prontuarios->adicionarItem($prontuario->id, $item->id, $quantidade, $vet->id, self::T);
            }
            $prontuarios->assinar($prontuario->id, $vet->id, self::T);
        }

        return $agendamento;
    }

    // ---------------------------------------------------------------- agenda

    public function test_consultar_agenda_mostra_o_periodo_do_tenant_com_nomes_e_horario_local(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $hoje = $this->agendamentoDireto($this->pet(extra: ['nome' => 'Luna']), $vet, $this->item(['nome_comercial' => 'Retorno']),
            ['inicio' => '2026-11-10 17:00', 'fim' => '2026-11-10 17:30', 'status' => 'confirmado']);
        $this->agendamentoDireto($this->pet(), $vet, $this->item(), ['inicio' => '2026-11-01 09:00', 'fim' => '2026-11-01 09:30']); // fora do período
        $this->agendamentoDireto($this->pet(null, self::OUTRO_TENANT), $this->usuarioClinica('veterinario', self::OUTRO_TENANT), $this->item([], self::OUTRO_TENANT),
            ['inicio' => '2026-11-10 17:00', 'fim' => '2026-11-10 17:30']);

        $this->comando('consultar_agenda')
            ->assertOk()
            ->assertJsonCount(1, 'resultado')
            ->assertJsonPath('resultado.0', ['id' => $hoje->id, 'horario' => '10/11/2026 17:00–17:30', 'pet' => 'Luna', 'tutor' => 'Ana Souza',
                'servico' => 'Retorno', 'profissional' => $vet->name, 'setor' => 'clinica', 'status' => 'confirmado']);
    }

    public function test_mudar_status_acha_pelo_pet_mostra_o_agendamento_e_so_o_status_e_editavel(): void
    {
        $agendamento = $this->agendamentoDireto($this->pet(extra: ['nome' => 'Bidu']), $this->usuarioClinica('veterinario'), $this->item(),
            ['inicio' => '2026-11-11 09:00', 'fim' => '2026-11-11 09:30', 'status' => 'agendado']);

        $resposta = $this->comando('alterar_status_agendamento', ['busca' => 'bidu', 'novo_status' => 'confirmado'])
            ->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente');

        $this->assertSame(['novo_status'], array_column($resposta->json('formulario.campos'), 'nome'), 'o id não é campo do formulário');
        $this->assertSame(['Bidu (tutor: Ana Souza)', '11/11/2026 09:00–09:30', 'agendado'],
            array_values(array_intersect_key($this->resumo($resposta), array_flip(['Pet', 'Horário', 'Situação']))));

        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $resposta->json('proposta'), 'valores' => []])->assertOk()->json('token');
        $this->confirmar($token)->assertOk()->assertJsonPath('tipo', 'resultado_escrita');

        $this->assertSame('confirmado', $agendamento->fresh()->status);
        $log = AuditLog::latest('id')->first();
        $this->assertSame(['alterar_status_agendamento', 'Agendamento', $agendamento->id, 'recepcao'], [$log->tool, $log->entidade_tipo, $log->entidade_id, $log->papel]);
    }

    public function test_regra_de_negocio_da_agenda_aparece_no_revisar_antes_de_segurar(): void
    {
        $agendamento = $this->atendimentoRealizado([], 'Rex');
        $agendamento->update(['status' => 'confirmado']); // o prontuário existe: não pode virar falta

        $this->recusadaNoRevisar('alterar_status_agendamento', ['id' => $agendamento->id, 'novo_status' => 'faltou'], 'já tem prontuário');

        $this->assertSame('confirmado', $agendamento->fresh()->status);
    }

    // ---------------------------------------------------------------- cobrança (P-B4)

    public function test_cobrar_mostra_linhas_e_total_antes_de_segurar_sem_gravar_e_cobra_ao_confirmar(): void
    {
        $vacina = $this->produto(['setor' => 'clinica', 'categoria' => 'farmacia', 'nome_comercial' => 'Vermífugo', 'preco_centavos' => 3500, 'estoque' => 5]);
        $agendamento = $this->atendimentoRealizado([[$vacina, 2]]);

        $resposta = $this->comando('cobrar_atendimento', ['busca' => 'Thor'])->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente');

        $resumo = $this->resumo($resposta);
        $this->assertSame(['R$ 150,00', 'R$ 70,00', 'R$ 220,00'], [$resumo['1 × Consulta clínica'], $resumo['2 × Vermífugo'], $resumo['Total']]);
        $this->assertSame([], $resposta->json('formulario.campos'), 'nada a editar: os preços vêm do catálogo');
        $this->assertSame([0, 5], [Cobranca::count(), $vacina->fresh()->estoque], 'a prévia não grava nem baixa o estoque');

        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $resposta->json('proposta'), 'valores' => []])->assertOk()->json('token');
        $this->confirmar($token)->assertOk()->assertJsonPath('mensagem', 'Atendimento cobrado: a cobrança está a receber.');

        $cobranca = Cobranca::sole();
        $this->assertSame([$agendamento->id, 'fechada', 22000, 3], [$cobranca->agendamento_id, $cobranca->status, $cobranca->total_centavos, $vacina->fresh()->estoque]);
        $this->assertSame(['cobrar_atendimento', 'cobrar', 'Cobranca', $cobranca->id], [AuditLog::latest('id')->first()->tool, AuditLog::latest('id')->first()->acao,
            AuditLog::latest('id')->first()->entidade_tipo, AuditLog::latest('id')->first()->entidade_id]);
    }

    public function test_cobrar_com_prontuario_em_rascunho_avisa_no_card_e_recusa_no_revisar(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $pet = $this->pet();
        $agendamento = $this->agendamentoDireto($pet, $vet, $this->item());
        app(ProntuarioService::class)->abrir(['pet_id' => $pet->id, 'tipo' => 'consulta', 'agendamento_id' => $agendamento->id], $vet->id, self::T);

        $resposta = $this->comando('cobrar_atendimento', ['id' => $agendamento->id])->assertOk();
        $this->assertStringContainsString('ainda não foi assinado', (string) $resposta->json('formulario.erro'));

        $this->recusadaNoRevisar('cobrar_atendimento', ['id' => $agendamento->id], 'ainda não foi assinado');
        $this->assertSame(0, Cobranca::count());
    }

    public function test_atendimento_que_ja_tem_cobranca_nao_aparece_para_cobrar_de_novo(): void
    {
        $agendamento = $this->atendimentoRealizado(null);
        app(CobrancaService::class)->cobrarAtendimento($agendamento->id, self::T);

        $this->comando('cobrar_atendimento', ['busca' => 'Thor'])->assertStatus(422)
            ->assertJsonPath('mensagem', fn (string $m) => str_starts_with($m, 'Não encontrei o atendimento'));
        $this->assertSame(1, Cobranca::count());
    }

    public function test_receber_so_acha_cobranca_a_receber_e_grava_a_forma_de_pagamento(): void
    {
        $cobranca = app(CobrancaService::class)->cobrarAtendimento($this->atendimentoRealizado(null)->id, self::T);

        $resposta = $this->comando('receber_cobranca', ['busca' => 'Thor', 'forma_pagamento' => 'pix'])->assertOk();
        $this->assertSame(['forma_pagamento'], array_column($resposta->json('formulario.campos'), 'nome'));
        $this->assertSame('R$ 150,00', $this->resumo($resposta)['Total']);

        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $resposta->json('proposta'), 'valores' => []])->assertOk()->json('token');
        $this->confirmar($token)->assertOk();
        $this->assertSame(['paga', 'pix'], [$cobranca->fresh()->status, $cobranca->fresh()->forma_pagamento]);

        // Já paga: não é mais "a receber".
        $this->comando('receber_cobranca', ['id' => $cobranca->id, 'forma_pagamento' => 'pix'])->assertStatus(422);
    }

    public function test_cancelar_cobranca_e_risco_maximo_e_cobranca_paga_nao_se_cancela(): void
    {
        $cobrancas = app(CobrancaService::class);
        $aberta = $cobrancas->cobrarAtendimento($this->atendimentoRealizado(null)->id, self::T);
        $paga = $cobrancas->registrarPagamento($cobrancas->cobrarAtendimento($this->atendimentoRealizado(null, 'Mel')->id, self::T)->id, 'pix', self::T);

        $this->comando('cancelar_cobranca', ['id' => $aberta->id])->assertOk()->assertJsonPath('risco', 'exclusao');
        $this->confirmar($this->propor(['id' => $aberta->id], 'cancelar_cobranca'))->assertOk();
        $this->assertSame('cancelada', $aberta->fresh()->status);

        $this->comando('cancelar_cobranca', ['id' => $paga->id])->assertStatus(422);
        $this->assertSame('paga', $paga->fresh()->status);
    }

    public function test_consulta_de_cobrancas_oferece_receber_so_na_linha_a_receber_e_so_a_quem_pode(): void
    {
        $cobrancas = app(CobrancaService::class);
        $aReceber = $cobrancas->cobrarAtendimento($this->atendimentoRealizado(null)->id, self::T);
        $cobrancas->registrarPagamento($cobrancas->cobrarAtendimento($this->atendimentoRealizado(null, 'Mel')->id, self::T)->id, 'pix', self::T);

        $linhas = collect($this->comando('consultar_cobrancas')->assertOk()->json('resultado'))->keyBy('id');

        $this->assertSame(['tool' => 'receber_cobranca', 'rotulo' => 'Receber'], $linhas[$aReceber->id]['acao']);
        $this->assertSame(['a receber', 'R$ 150,00'], [$linhas[$aReceber->id]['status'], $linhas[$aReceber->id]['total']]);
        $this->assertCount(1, $linhas->filter(fn ($l) => isset($l['acao'])));

        $this->logarComo('vendedor', self::T);
        $this->comando('consultar_cobrancas')->assertStatus(403);
        $this->comando('cobrar_atendimento', ['busca' => 'Thor'])->assertStatus(403);
    }

    // ---------------------------------------------------------------- venda avulsa

    public function test_venda_proposta_pela_ia_usa_o_preco_do_catalogo_e_o_vendedor_logado(): void
    {
        $this->logarComo('vendedor', self::T);
        $racao = $this->produto(['preco_centavos' => 21000, 'estoque' => 10]);
        $banho = $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho', 'preco_centavos' => 5000]);
        $pet = $this->pet();

        $resposta = $this->comando('registrar_venda', ['itens' => [['catalogo_item_id' => $racao->id, 'quantidade' => 2], ['catalogo_item_id' => $banho->id, 'quantidade' => 1]],
            'pet_id' => $pet->id, 'forma_pagamento' => 'pix'])->assertOk();

        $this->assertSame(['forma_pagamento', 'tutor_id', 'pet_id'], array_column($resposta->json('formulario.campos'), 'nome'), 'os itens não são editáveis no card');
        $resumo = $this->resumo($resposta);
        $this->assertSame(['Petshop', 'R$ 420,00', 'R$ 50,00', 'R$ 470,00'], [$resumo['Setor'], $resumo['2 × Ração 10 kg'], $resumo['1 × Banho'], $resumo['Total']]);
        $this->assertSame([0, 10], [Venda::count(), $racao->fresh()->estoque], 'a prévia não grava');

        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $resposta->json('proposta'), 'valores' => []])->assertOk()->json('token');
        $this->confirmar($token)->assertOk()->assertJsonPath('tipo', 'resultado_escrita');

        $venda = Venda::sole();
        $this->assertSame([47000, 'paga', $pet->id, $pet->tutor_id, auth()->id(), 8], [$venda->total_centavos, $venda->status, $venda->pet_id, $venda->tutor_id, $venda->vendedor_id, $racao->fresh()->estoque]);
        $this->assertSame(['registrar_venda', 'vender', 'Venda'], [AuditLog::latest('id')->first()->tool, AuditLog::latest('id')->first()->acao, AuditLog::latest('id')->first()->entidade_tipo]);
    }

    /** @return array<string, array{array<string, mixed>}> chave proibida posta pela IA DENTRO de um item */
    public static function chavesProibidasNoItem(): array
    {
        return ['preço' => [['preco_centavos' => 1]], 'subtotal' => [['subtotal_centavos' => 1]], 'tenant' => [['tenant_id' => 9]],
            'vendedor' => [['vendedor_id' => 1]], 'autor' => [['usuario_id' => 1]], 'cpf' => [['cpf' => '123.456.789-00']]];
    }

    /** Revisão de 10/11 (achado 3): chave fora do esquema dentro dos itens é recusada como no topo; não entra na sessão nem na auditoria. */
    #[\PHPUnit\Framework\Attributes\DataProvider('chavesProibidasNoItem')]
    public function test_chave_fora_do_esquema_dentro_de_um_item_e_recusada_e_nao_vai_para_sessao_nem_auditoria(array $extra): void
    {
        $racao = $this->produto();

        $this->comando('registrar_venda', ['itens' => [['catalogo_item_id' => $racao->id, 'quantidade' => 1, ...$extra]]])
            ->assertStatus(502)->assertJsonPath('codigo', 'AI_PROVIDER_RESPONSE_INVALID');

        $this->assertSame([0, 10], [Venda::count(), $racao->fresh()->estoque]);
        $valor = (string) reset($extra);
        $this->assertStringNotContainsString(array_key_first($extra), json_encode(session()->all()));
        $this->assertStringNotContainsString($valor === '1' ? '"'.array_key_first($extra).'"' : $valor, AuditLog::all()->toJson());
    }

    public function test_mascara_da_auditoria_desce_nos_campos_aninhados(): void
    {
        $mascarado = \App\Services\AuditoriaService::mascararCamposSensiveis(['itens' => [['catalogo_item_id' => 1, 'cpf' => '123.456.789-00', 'documento' => '987.654.321-00']]]);

        $this->assertSame(['catalogo_item_id' => 1, 'cpf' => '***.***.789-00', 'documento' => '***.***.321-00'], $mascarado['itens'][0]);
    }

    /** Revisão de 10/11 (achados 1, 2 e 7): o que o Confirmar recusaria, o Revisar já recusa (sem token de segurar). */
    public function test_venda_invalida_e_recusada_ja_no_revisar_e_nao_so_depois_de_segurar(): void
    {
        $racao = $this->produto(['estoque' => 100]);
        $item = ['catalogo_item_id' => $racao->id, 'quantidade' => 1];

        $this->recusadaNoRevisar('registrar_venda', ['itens' => array_fill(0, 21, $item)], 'itens');
        foreach (['abc', -3, 0] as $atendimento) {
            $this->recusadaNoRevisar('registrar_venda', ['itens' => [$item], 'agendamento_id' => $atendimento], 'atendimento');
        }
        $this->recusadaNoRevisar('registrar_venda', ['itens' => [['catalogo_item_id' => $racao->id, 'quantidade' => '02']]], 'quantidade');

        $this->assertSame([0, 100], [Venda::count(), $racao->fresh()->estoque]);
    }

    public function test_atendimento_com_numero_quebrado_nao_vira_outro_atendimento_no_card(): void
    {
        $um = $this->atendimentoRealizado(null); // o agendamento #1
        $resposta = $this->comando('registrar_venda', ['itens' => [['catalogo_item_id' => $this->produto()->id, 'quantidade' => 1]], 'agendamento_id' => 1.5])->assertOk();

        $this->assertSame(1, $um->id);
        $this->assertStringContainsString('Informe o atendimento pelo número', (string) $resposta->json('formulario.erro'));
        $this->assertArrayNotHasKey('Atendimento: pet', $this->resumo($resposta), 'o card não mostra o atendimento #1 no lugar do 1.5');
    }

    /** Revisão de 10/11 (achado 5): data fora do formato na agenda é recusa com frase, nunca o período padrão calado. */
    public function test_agenda_com_data_fora_do_formato_e_recusada_com_frase(): void
    {
        $this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item(), ['inicio' => '2026-11-10 17:00', 'fim' => '2026-11-10 17:30']);

        foreach (['data_inicio' => '15/12/2026', 'data_fim' => '2026-02-31'] as $campo => $data) {
            $this->comando('consultar_agenda', [$campo => $data])->assertStatus(422)
                ->assertJsonPath('mensagem', fn (string $m) => str_contains($m, 'formato AAAA-MM-DD'))
                ->assertJsonMissingPath('resultado');
        }
        $this->comando('consultar_agenda', ['data_inicio' => '2026-11-10'])->assertOk()->assertJsonCount(1, 'resultado');
    }

    public function test_ia_nao_escolhe_o_vendedor(): void
    {
        $outro = $this->usuarioClinica('dono');

        $this->comando('registrar_venda', ['itens' => [['catalogo_item_id' => $this->produto()->id, 'quantidade' => 1]], 'vendedor_id' => $outro->id])
            ->assertStatus(502)->assertJsonPath('codigo', 'AI_PROVIDER_RESPONSE_INVALID');
        $this->assertSame(0, Venda::count());
    }

    public function test_venda_recusada_pela_regra_aparece_antes_de_segurar(): void
    {
        $antibiotico = $this->produto(['setor' => 'clinica', 'categoria' => 'farmacia', 'nome_comercial' => 'Antibiótico', 'exige_receita' => true]);
        $racao = $this->produto();

        $resposta = $this->comando('registrar_venda', ['itens' => [['catalogo_item_id' => $antibiotico->id, 'quantidade' => 1]]])->assertOk();
        $this->assertStringContainsString('exige receita', (string) $resposta->json('formulario.erro'));
        $this->recusadaNoRevisar('registrar_venda', ['itens' => [['catalogo_item_id' => $antibiotico->id, 'quantidade' => 1]]], 'exige receita');

        // A recepção vende a clínica, não a loja.
        $this->recusadaNoRevisar('registrar_venda', ['itens' => [['catalogo_item_id' => $racao->id, 'quantidade' => 1]]], 'Itens do petshop são vendidos pela loja');
        $this->assertSame(0, Venda::count());
    }

    public function test_venda_ligada_ao_atendimento_pega_o_pet_dele(): void
    {
        $agendamento = $this->atendimentoRealizado(null);
        $vermifugo = $this->produto(['setor' => 'clinica', 'categoria' => 'farmacia', 'nome_comercial' => 'Vermífugo', 'preco_centavos' => 3500]);

        $resposta = $this->comando('registrar_venda', ['itens' => [['catalogo_item_id' => $vermifugo->id, 'quantidade' => 1]], 'agendamento_id' => $agendamento->id])->assertOk();
        $this->assertSame('Thor (tutor: Ana Souza)', $this->resumo($resposta)['Atendimento: pet']);

        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $resposta->json('proposta'), 'valores' => []])->assertOk()->json('token');
        $this->confirmar($token)->assertOk();
        $this->assertSame([$agendamento->id, $agendamento->pet_id], [Venda::sole()->agendamento_id, Venda::sole()->pet_id]);
    }

    // ---------------------------------------------------------------- caixa de entrada (P-B5)

    public function test_mensagens_novas_trazem_o_pedido_para_o_agente_e_podem_ser_marcadas_como_tratadas(): void
    {
        $tutor = $this->tutor();
        $nova = Mensagem::create(['tutor_id' => $tutor->id, 'texto' => 'Queria remarcar o banho do Thor para sábado.', 'status' => 'nova', 'tenant_id' => self::T]);
        Mensagem::create(['remetente' => 'Luciana', 'texto' => 'Obrigada!', 'status' => 'tratada', 'tenant_id' => self::T]);
        Mensagem::create(['remetente' => 'De fora', 'texto' => 'Outro tenant', 'status' => 'nova', 'tenant_id' => self::OUTRO_TENANT]);

        $linhas = collect($this->comando('consultar_mensagens')->assertOk()->assertJsonCount(2, 'resultado')->json('resultado'))->keyBy('id');
        $this->assertSame(['Ana Souza', 'Queria remarcar o banho do Thor para sábado.', 'Queria remarcar o banho do Thor para sábado.'],
            [$linhas[$nova->id]['de'], $linhas[$nova->id]['mensagem'], $linhas[$nova->id]['pedido']]);
        $this->assertSame('marcar_mensagem_tratada', $linhas[$nova->id]['acao']['tool']);
        $this->assertCount(1, $linhas->filter(fn ($l) => isset($l['pedido'])), 'só a mensagem nova vira pedido');

        $this->confirmar($this->propor(['id' => $nova->id], 'marcar_mensagem_tratada'))->assertOk();
        $this->assertSame('tratada', $nova->fresh()->status);
    }

    public function test_texto_da_mensagem_nao_fica_em_claro_na_auditoria(): void
    {
        $nova = Mensagem::create(['remetente' => 'Marcos', 'texto' => 'Meu CPF é 123.456.789-00', 'status' => 'nova', 'tenant_id' => self::T]);

        $this->comando('consultar_mensagens')->assertOk();
        $this->confirmar($this->propor(['id' => $nova->id], 'marcar_mensagem_tratada'))->assertOk();

        $this->assertStringNotContainsString('123.456.789-00', AuditLog::all()->toJson());
    }

    // ---------------------------------------------------------------- formulário e domínio

    public function test_lista_de_vinculo_de_tabela_sem_lixeira_nao_quebra_o_formulario(): void
    {
        $agendamento = $this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item());

        $formulario = app(FormularioTool::class)->montar('Teste', ['agendamento_id' => 'nullable|integer|exists:agendamentos,id', 'profissional_id' => 'nullable|integer|exists:users,id'],
            ['agendamento_id' => $agendamento->id], [], [], self::T);

        $campos = collect($formulario['campos'])->keyBy('nome');
        $this->assertSame([$agendamento->id], array_column($campos['agendamento_id']['opcoes'], 'valor'));
        $this->assertArrayNotHasKey('erro', $campos['agendamento_id']);
        $this->assertNotEmpty($campos['profissional_id']['opcoes'], 'usuários do tenant (a tabela users não tem lixeira)');
    }

    public function test_tenant_de_contabilidade_nao_tem_as_tools_de_atendimento(): void
    {
        $this->logarComo('admin', 1);

        foreach (['consultar_agenda', 'cobrar_atendimento', 'registrar_venda', 'consultar_mensagens'] as $tool) {
            $this->comando($tool)->assertStatus(422)->assertJsonPath('codigo', 'AI_UNSUPPORTED_OPERATION');
        }
    }
}
