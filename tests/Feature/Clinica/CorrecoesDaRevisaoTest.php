<?php

namespace Tests\Feature\Clinica;

use App\Exceptions\RegraDeNegocioException;
use App\Exceptions\ToolRecusadaException;
use App\Models\AuditLog;
use App\Models\CatalogoItem;
use App\Models\Cliente;
use App\Models\CobrancaItem;
use App\Models\Prontuario;
use App\Models\User;
use App\Services\AgendaService;
use App\Services\AiAgentService;
use App\Services\AuditoriaService;
use App\Services\CatalogoService;
use App\Services\ClienteService;
use App\Services\CobrancaService;
use App\Services\ProntuarioService;
use App\Services\ReautenticacaoAdmin;
use App\Services\SupervisaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use ReflectionMethod;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\ProvaDeTravas;
use Tests\TestCase;

/**
 * Correções da revisão da Fase A (10/11/2026): uma prova para cada defeito corrigido. Os testes de proteção
 * foram provados por sabotagem (tirar a correção faz o teste correspondente falhar).
 */
class CorrecoesDaRevisaoTest extends TestCase
{
    use CenarioClinica, ProvaDeTravas, RefreshDatabase;

    private const PIN = '482915';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
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

    /** Agendamento realizado + prontuário em rascunho com os itens dados, do serviço dado. */
    private function atendimento(CatalogoItem $servico, array $itens = []): array
    {
        $vet = $this->usuarioClinica('veterinario');
        $pet = $this->pet();
        $agendamento = $this->agendamentoDireto($pet, $vet, $servico);
        $prontuarios = app(ProntuarioService::class);
        $prontuario = $prontuarios->abrir(['pet_id' => $pet->id, 'tipo' => 'consulta', 'agendamento_id' => $agendamento->id], $vet->id, self::T);
        foreach ($itens as [$item, $quantidade]) {
            $prontuarios->adicionarItem($prontuario->id, $item->id, $quantidade, $vet->id, self::T);
        }

        return [$agendamento, $prontuario, $vet];
    }

    // ---------------------------------------------------------------- defeito 1: o prontuário assinado não muda

    public function test_prontuario_assinado_nao_muda_quando_o_item_do_catalogo_ou_o_veterinario_mudam(): void
    {
        $vacina = $this->item(['categoria' => 'vacina', 'nome_comercial' => 'Vacina V10']);
        [, $prontuario, $vet] = $this->atendimento($this->item(), [[$vacina, 1]]);
        $nomeDoVet = $vet->name;
        $prontuarios = app(ProntuarioService::class);

        $prontuarios->assinar($prontuario->id, $vet->id, self::T);
        $adendo = $prontuarios->adendar($prontuario->id, 'Tutor informou alergia.', $vet->id, self::T);

        // Depois da assinatura: o item é renomeado e vai para a lixeira, o veterinário muda de nome e de CRMV.
        app(CatalogoService::class)->atualizar($vacina->id, ['nome_comercial' => 'Vacina antirrábica'], self::T);
        $vacina->fresh()->delete();
        $vet->update(['name' => 'Outro Nome Completo', 'crmv' => 'CRMV-SP 99999']);

        $assinado = $prontuario->fresh();
        $this->assertSame([$nomeDoVet, 'CRMV-SP 10001'], [$assinado->assinado_por_nome, $assinado->assinado_por_crmv]);
        $this->assertSame([$nomeDoVet, 'CRMV-SP 10001'], [$adendo->fresh()->autor_nome, $adendo->fresh()->autor_crmv]);

        $item = $assinado->itens()->sole();
        $this->assertSame('Vacina V10', $item->nome_item);
        $this->assertSame('Vacina V10', $item->nomeNoLancamento(), 'o nome que vale é o do lançamento, não o do catálogo de hoje');
        $this->assertNotNull($item->catalogoItem, 'item na lixeira continua ligado ao prontuário');
    }

    public function test_o_nome_do_item_e_copiado_no_lancamento_e_o_prontuario_assinado_continua_imutavel(): void
    {
        $vacina = $this->item(['categoria' => 'vacina', 'nome_comercial' => 'Vacina V10']);
        [, $prontuario, $vet] = $this->atendimento($this->item(), [[$vacina, 1]]);
        $prontuarios = app(ProntuarioService::class);
        $prontuarios->assinar($prontuario->id, $vet->id, self::T);

        $item = $prontuario->itens()->sole();
        $this->recusa(fn () => $item->update(['nome_item' => 'Outra coisa']), 'Prontuário assinado não pode ser alterado.');
        $this->recusa(fn () => $prontuario->fresh()->update(['assinado_por_nome' => 'Outra pessoa']), 'Prontuário assinado não pode ser alterado.');
        $this->assertSame('Vacina V10', $item->fresh()->nome_item);
    }

    public function test_data_de_entrada_e_alta_com_fuso_ou_formato_solto_e_recusada_no_prontuario(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $pet = $this->pet();
        $prontuarios = app(ProntuarioService::class);

        foreach (['2026-11-07T18:00:00Z', '07/11/2026 18:00', 'ontem', '2026-11-07'] as $solto) {
            $this->recusa(fn () => $prontuarios->abrir(['pet_id' => $pet->id, 'tipo' => 'internacao', 'entrada_em' => $solto], $vet->id, self::T),
                'Informe a entrada e a alta com data e hora');
        }
        $this->assertSame(0, Prontuario::count());

        $prontuario = $prontuarios->abrir(['pet_id' => $pet->id, 'tipo' => 'internacao', 'entrada_em' => '2026-11-07T18:00'], $vet->id, self::T);
        $this->assertSame('2026-11-07 18:00:00', $prontuario->fresh()->entrada_em->format('Y-m-d H:i:s'));
    }

    // ---------------------------------------------------------------- defeito 2: conferência com leitura travada

    public function test_abrir_cobranca_confere_a_duplicidade_com_leitura_travada(): void
    {
        $this->mostrarTravas();
        [$agendamento] = $this->atendimento($this->item());

        $travadas = $this->leiturasTravadas(fn () => app(CobrancaService::class)->abrir($agendamento->id, self::T));

        $this->assertTravouLeitura($travadas, 'agendamentos');
        $this->assertTravouLeitura($travadas, 'cobrancas', '"status" != ?');
    }

    public function test_conflito_de_horario_e_conferido_com_leitura_travada(): void
    {
        $this->mostrarTravas();
        $vet = $this->usuarioClinica('veterinario');
        $dados = ['pet_id' => $this->pet()->id, 'profissional_id' => $vet->id, 'catalogo_item_id' => $this->item()->id,
            'inicio' => '2026-11-11 14:00', 'fim' => '2026-11-11 14:30'];

        $travadas = $this->leiturasTravadas(fn () => app(AgendaService::class)->agendar($dados, self::T));

        $this->assertTravouLeitura($travadas, 'users');
        $this->assertTravouLeitura($travadas, 'agendamentos', '"inicio" < ?');
    }

    public function test_cancelar_ou_marcar_falta_confere_cobranca_e_prontuario_com_leitura_travada(): void
    {
        $this->mostrarTravas();
        $agendamento = $this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item(), ['status' => 'agendado']);

        $travadas = $this->leiturasTravadas(fn () => app(AgendaService::class)->alterarStatus($agendamento->id, 'cancelado', self::T));

        $this->assertTravouLeitura($travadas, 'cobrancas');
        $this->assertTravouLeitura($travadas, 'prontuarios');
    }

    public function test_devolucao_de_estoque_do_cancelamento_le_as_linhas_travadas(): void
    {
        $this->mostrarTravas();
        $vermifugo = $this->produto(['setor' => 'clinica', 'categoria' => 'farmacia', 'nome_comercial' => 'Vermífugo', 'estoque' => 5]);
        [$agendamento] = $this->atendimento($this->item());
        $cobrancas = app(CobrancaService::class);
        $cobranca = $cobrancas->abrir($agendamento->id, self::T);
        $cobrancas->adicionarItem($cobranca->id, $vermifugo->id, 2, self::T);

        $travadas = $this->leiturasTravadas(fn () => $cobrancas->cancelar($cobranca->id, self::T));

        $this->assertTravouLeitura($travadas, 'cobranca_itens', '"baixou_estoque" = ?');
        $this->assertSame(5, $vermifugo->fresh()->estoque);
    }

    // ---------------------------------------------------------------- defeito 3: item do prontuário não sai da cobrança

    public function test_linha_vinda_do_prontuario_nao_sai_da_cobranca_nem_devolve_estoque(): void
    {
        $antibiotico = $this->produto(['setor' => 'clinica', 'categoria' => 'farmacia', 'nome_comercial' => 'Antibiótico', 'preco_centavos' => 8000, 'estoque' => 4]);
        [$agendamento, $prontuario, $vet] = $this->atendimento($this->item(), [[$antibiotico, 1]]);
        app(ProntuarioService::class)->assinar($prontuario->id, $vet->id, self::T);
        $cobrancas = app(CobrancaService::class);
        $cobranca = $cobrancas->importarDoProntuario($cobrancas->abrir($agendamento->id, self::T)->id, self::T);
        $linha = CobrancaItem::where('cobranca_id', $cobranca->id)->whereNotNull('prontuario_item_id')->sole();
        $this->assertSame(3, $antibiotico->fresh()->estoque);

        $this->recusa(fn () => $cobrancas->removerItem($linha->id, self::T), 'veio do prontuário');

        $this->assertSame([3, 23000, true], [$antibiotico->fresh()->estoque, $cobranca->fresh()->total_centavos, CobrancaItem::whereKey($linha->id)->exists()]);
    }

    // ---------------------------------------------------------------- defeito 4: serviço do agendamento não é cobrado em dobro

    public function test_importar_nao_cobra_de_novo_so_uma_unidade_do_servico_do_agendamento(): void
    {
        $servico = $this->item(['categoria' => 'consulta', 'nome_comercial' => 'Consulta de emergência', 'preco_centavos' => 25000]);
        $vacina = $this->item(['categoria' => 'vacina', 'nome_comercial' => 'Vacina V10', 'preco_centavos' => 9000]);
        [$agendamento, $prontuario, $vet] = $this->atendimento($servico, [[$servico, 1], [$vacina, 1]]);
        app(ProntuarioService::class)->assinar($prontuario->id, $vet->id, self::T);
        $cobrancas = app(CobrancaService::class);

        $cobranca = $cobrancas->importarDoProntuario($cobrancas->abrir($agendamento->id, self::T)->id, self::T);

        // 25000 do serviço (linha automática) + 9000 da vacina. A consulta do prontuário é a mesma já cobrada.
        $this->assertSame(34000, $cobranca->total_centavos);
        $this->assertSame(['Consulta de emergência' => 1, 'Vacina V10' => 1], $cobranca->itens()->pluck('quantidade', 'descricao')->all());
        $this->recusa(fn () => $cobrancas->importarDoProntuario($cobranca->id, self::T), 'já estão na cobrança');
        $this->assertSame(34000, $cobranca->fresh()->total_centavos);
    }

    public function test_quantidade_acima_de_uma_do_mesmo_servico_continua_cobrada(): void
    {
        $servico = $this->item(['categoria' => 'consulta', 'nome_comercial' => 'Consulta clínica', 'preco_centavos' => 15000]);
        [$agendamento, $prontuario, $vet] = $this->atendimento($servico, [[$servico, 3]]);
        app(ProntuarioService::class)->assinar($prontuario->id, $vet->id, self::T);
        $cobrancas = app(CobrancaService::class);

        $cobranca = $cobrancas->importarDoProntuario($cobrancas->abrir($agendamento->id, self::T)->id, self::T);

        // 1 (linha automática) + 2 (o que passa de uma unidade) = as 3 do prontuário.
        $this->assertSame(45000, $cobranca->total_centavos);
        $this->assertSame([1, 2], $cobranca->itens()->orderBy('id')->pluck('quantidade')->all());
    }

    public function test_duas_linhas_do_mesmo_servico_no_prontuario_abatem_uma_unidade_so(): void
    {
        $servico = $this->item(['categoria' => 'consulta', 'nome_comercial' => 'Consulta clínica', 'preco_centavos' => 15000]);
        [$agendamento, $prontuario, $vet] = $this->atendimento($servico, [[$servico, 1], [$servico, 1]]);
        app(ProntuarioService::class)->assinar($prontuario->id, $vet->id, self::T);
        $cobrancas = app(CobrancaService::class);

        $cobranca = $cobrancas->importarDoProntuario($cobrancas->abrir($agendamento->id, self::T)->id, self::T);

        // Duas consultas no prontuário: a primeira é a que já está cobrada (linha automática); a segunda é cobrada.
        $this->assertSame(30000, $cobranca->total_centavos);
        $this->assertSame(2, $cobranca->itens()->count());
    }

    public function test_prontuario_so_com_o_servico_do_agendamento_nao_acrescenta_nada(): void
    {
        $servico = $this->item(['categoria' => 'consulta', 'nome_comercial' => 'Consulta clínica', 'preco_centavos' => 15000]);
        [$agendamento, $prontuario, $vet] = $this->atendimento($servico, [[$servico, 1]]);
        app(ProntuarioService::class)->assinar($prontuario->id, $vet->id, self::T);
        $cobrancas = app(CobrancaService::class);
        $cobranca = $cobrancas->abrir($agendamento->id, self::T);

        $this->recusa(fn () => $cobrancas->importarDoProntuario($cobranca->id, self::T), 'já estão na cobrança');

        $this->assertSame([15000, 1], [$cobranca->fresh()->total_centavos, $cobranca->itens()->count()]);
    }

    // ---------------------------------------------------------------- preço: a linha guarda o preço do momento da cobrança

    public function test_mudar_o_preco_depois_de_cobrar_nao_muda_a_cobranca_antiga_em_nenhuma_etapa(): void
    {
        $catalogo = app(CatalogoService::class);
        $cobrancas = app(CobrancaService::class);
        $hemograma = $this->item(['categoria' => 'exame', 'nome_comercial' => 'Hemograma', 'preco_centavos' => 6500]);
        [$agendamento] = $this->atendimento($this->item(['preco_centavos' => 15000]));

        $cobranca = $cobrancas->abrir($agendamento->id, self::T);
        $cobrancas->adicionarItem($cobranca->id, $hemograma->id, 2, self::T);
        $catalogo->atualizar($hemograma->id, ['preco_centavos' => 9900], self::T);
        $catalogo->atualizar($agendamento->catalogo_item_id, ['preco_centavos' => 99900], self::T);

        // A linha nova entra com o preço novo; as antigas continuam com o da época, em toda etapa até o estorno.
        $cobrancas->adicionarItem($cobranca->id, $hemograma->id, 1, self::T);
        $esperado = 15000 + 2 * 6500 + 9900;
        $this->assertSame($esperado, $cobrancas->fechar($cobranca->id, self::T)->fresh()->total_centavos);
        $this->assertSame($esperado, $cobrancas->registrarPagamento($cobranca->id, 'pix', self::T)->fresh()->total_centavos);
        $this->assertSame([[15000, 1, 15000], [6500, 2, 13000], [9900, 1, 9900]],
            $cobranca->itens()->orderBy('id')->get()->map(fn ($l) => [$l->preco_unitario_centavos, $l->quantidade, $l->subtotal_centavos])->all());

        $dono = $this->usuarioClinica('dono');
        $estornada = $cobrancas->estornar($cobranca->id, 'Duplicidade.', $dono->id, $dono->id, self::T, true);
        $this->assertSame([$esperado, $esperado], [$estornada->fresh()->total_centavos, $estornada->fresh()->estorno_valor_centavos]);
    }

    public function test_mudar_o_preco_depois_da_venda_nao_muda_a_venda_nem_ao_receber(): void
    {
        $racao = $this->produto(['preco_centavos' => 21000, 'estoque' => 10]);
        $vendas = app(\App\Services\VendaService::class);
        $venda = $vendas->registrar([['catalogo_item_id' => $racao->id, 'quantidade' => 2]], $this->usuarioClinica('vendedor')->id, null, null, self::T);

        app(CatalogoService::class)->atualizar($racao->id, ['preco_centavos' => 25000], self::T);
        $paga = $vendas->registrarPagamento($venda->id, 'pix', self::T);

        $this->assertSame(42000, $paga->fresh()->total_centavos);
        $this->assertSame([21000, 42000], [$venda->itens()->sole()->preco_unitario_centavos, $venda->itens()->sole()->subtotal_centavos]);
    }

    // ---------------------------------------------------------------- defeito 7: texto clínico não vai em claro para a auditoria

    public function test_auditoria_nao_grava_texto_clinico_em_claro(): void
    {
        $this->actingAs($this->usuarioClinica('dono'));

        app(AuditoriaService::class)->registrar('qualquer_tool', 'criar', 'Prontuario', 1, [
            'anamnese' => 'Tutor relata vômitos.', 'exame_fisico' => 'Mucosas pálidas.', 'diagnostico' => 'Gastroenterite.',
            'conduta' => 'Jejum de 12 h.', 'texto' => 'Alergia a dipirona.', 'tipo' => 'consulta', 'estorno_motivo' => 'Cobrado em duplicidade.',
        ], 'sucesso', null, 'dono', true);

        $gravado = json_encode(AuditLog::latest('id')->first()->parametros);
        foreach (['vômitos', 'pálidas', 'Gastroenterite', 'Jejum', 'dipirona'] as $conteudo) {
            $this->assertStringNotContainsString($conteudo, $gravado);
        }
        $this->assertStringContainsString('[redigido]', $gravado);
        $this->assertStringContainsString('consulta', $gravado, 'o que não é clínico continua legível');
        $this->assertStringContainsString('Cobrado em duplicidade.', $gravado, 'o motivo do estorno é para o auditor ler');

        foreach (['anamnese', 'exame_fisico', 'diagnostico', 'conduta', 'texto'] as $campo) {
            $this->assertContains($campo, AuditoriaService::camposSensiveis());
        }
    }

    // ---------------------------------------------------------------- defeito 8: exists: só filtra deleted_at onde a coluna existe

    private function validar(array $argumentos, array $regras): array
    {
        $metodo = new ReflectionMethod(AiAgentService::class, 'validar');

        return $metodo->invoke(app(AiAgentService::class), $argumentos, $regras);
    }

    public function test_exists_de_tabela_sem_lixeira_nao_quebra_e_continua_escopado_por_tenant(): void
    {
        $meu = $this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item());
        $deFora = $this->agendamentoDireto($this->pet(null, self::OUTRO_TENANT), $this->usuarioClinica('veterinario', self::OUTRO_TENANT), $this->item([], self::OUTRO_TENANT));
        $regras = ['agendamento_id' => 'required|integer|exists:agendamentos,id'];

        $this->assertSame(['agendamento_id' => $meu->id], $this->validar(['agendamento_id' => $meu->id, 'tenant_id' => self::T], $regras));

        try {
            $this->validar(['agendamento_id' => $deFora->id, 'tenant_id' => self::T], $regras);
            $this->fail('Agendamento de outro tenant não pode passar.');
        } catch (ToolRecusadaException $e) {
            $this->assertStringContainsString('Não encontrei', $e->getMessage());
        }
    }

    public function test_exists_de_tabela_com_lixeira_continua_recusando_registro_excluido(): void
    {
        $cliente = Cliente::create(['nome' => 'Na lixeira', 'tenant_id' => self::T]);
        $cliente->delete();

        $this->expectException(ToolRecusadaException::class);
        $this->validar(['cliente_id' => $cliente->id, 'tenant_id' => self::T], ['cliente_id' => 'required|integer|exists:clientes,id']);
    }

    // ---------------------------------------------------------------- defeito 10: autorização só com papel válido

    private function pagaDaClinica(): \App\Models\Cobranca
    {
        $cobrancas = app(CobrancaService::class);
        $cobranca = $cobrancas->abrir($this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item())->id, self::T);
        $cobrancas->fechar($cobranca->id, self::T);

        return $cobrancas->registrarPagamento($cobranca->id, 'pix', self::T);
    }

    private function supervisor(string $papel, string $setor = 'clinica'): User
    {
        $usuario = $this->usuarioClinica($papel);
        $supervisao = app(SupervisaoService::class);
        $supervisao->marcarSupervisor($usuario->id, $setor, $this->usuarioClinica('dono')->id, self::T);
        $supervisao->definirProprioPin($usuario->id, self::PIN, self::T);

        return $usuario->fresh();
    }

    public function test_supervisor_com_papel_fora_da_lista_de_validos_nao_autoriza_estorno(): void
    {
        $cobranca = $this->pagaDaClinica();
        $supervisor = $this->supervisor('veterinario');
        $recepcao = $this->usuarioClinica('recepcao');

        config(['sentinel.papeis' => array_values(array_diff((array) config('sentinel.papeis'), ['veterinario']))]);

        $this->recusa(fn () => app(CobrancaService::class)->estornar($cobranca->id, 'Duplicidade.', $recepcao->id, $supervisor->id, self::T, true), 'perfil válido');
        $this->assertSame('paga', $cobranca->fresh()->status);
    }

    public function test_trocar_o_papel_do_usuario_zera_a_marcacao_de_supervisor_e_o_pin(): void
    {
        $cobranca = $this->pagaDaClinica();
        $supervisor = $this->supervisor('veterinario');
        $recepcao = $this->usuarioClinica('recepcao');
        $this->assertNotNull($supervisor->pin_autorizacao);

        $supervisor->update(['papel' => 'vendedor']);

        $depois = $supervisor->fresh();
        $this->assertSame([null, null], [$depois->supervisor_setor, $depois->pin_autorizacao]);
        $this->recusa(fn () => app(CobrancaService::class)->estornar($cobranca->id, 'Duplicidade.', $recepcao->id, $depois->id, self::T, true), 'Só o dono ou um supervisor');
    }

    /** Item B (12/11): trocar o tenant zera a supervisão e o PIN, como trocar o papel. */
    public function test_trocar_o_tenant_do_usuario_zera_a_marcacao_de_supervisor_e_o_pin(): void
    {
        $cobranca = $this->pagaDaClinica();
        $supervisor = $this->supervisor('veterinario');
        $recepcao = $this->usuarioClinica('recepcao');
        $this->assertNotNull($supervisor->pin_autorizacao);

        $supervisor->update(['tenant_id' => self::T + 100]);
        $this->assertSame([null, null], [$supervisor->fresh()->supervisor_setor, $supervisor->fresh()->pin_autorizacao]);

        // De volta ao tenant de antes, nada volta sozinho (o banco já guardou null): precisa ser marcado e ter PIN de novo.
        $supervisor->fresh()->update(['tenant_id' => self::T]);
        $depois = $supervisor->fresh();
        $this->assertSame([null, null], [$depois->supervisor_setor, $depois->pin_autorizacao]);
        $this->recusa(fn () => app(CobrancaService::class)->estornar($cobranca->id, 'Duplicidade.', $recepcao->id, $depois->id, self::T, true), 'Só o dono ou um supervisor');
    }

    public function test_na_troca_de_tenant_nem_a_marcacao_gravada_junto_fica(): void
    {
        // Marcar supervisor é ato do dono do tenant novo: a marcação e o PIN gravados junto com a troca também zeram.
        $supervisor = $this->supervisor('veterinario');
        $supervisor->tenant_id = self::T + 100;
        $supervisor->supervisor_setor = 'petshop';
        $supervisor->pin_autorizacao = 'hash-qualquer';
        $supervisor->save();

        $this->assertSame([null, null], [$supervisor->fresh()->supervisor_setor, $supervisor->fresh()->pin_autorizacao]);
    }

    public function test_na_troca_de_papel_so_fica_o_que_foi_gravado_junto(): void
    {
        // Marcação gravada junto fica; o PIN antigo não sobrevive por causa dela.
        $remarcado = $this->supervisor('veterinario');
        $remarcado->papel = 'dono';
        $remarcado->supervisor_setor = 'petshop';
        $remarcado->save();
        $this->assertSame(['petshop', null], [$remarcado->fresh()->supervisor_setor, $remarcado->fresh()->pin_autorizacao]);

        // Regravar o mesmo setor não conta como gravado (o Eloquent não vê mudança): zera também.
        $mesmoSetor = $this->supervisor('veterinario');
        $mesmoSetor->papel = 'dono';
        $mesmoSetor->supervisor_setor = 'clinica';
        $mesmoSetor->save();
        $this->assertSame([null, null], [$mesmoSetor->fresh()->supervisor_setor, $mesmoSetor->fresh()->pin_autorizacao]);

        // PIN novo gravado junto com a troca de papel fica (a marcação, não gravada, zera).
        $comPinNovo = $this->supervisor('veterinario');
        $comPinNovo->papel = 'dono';
        $comPinNovo->pin_autorizacao = 'hash-novo';
        $comPinNovo->save();
        $this->assertSame([null, 'hash-novo'], [$comPinNovo->fresh()->supervisor_setor, $comPinNovo->fresh()->pin_autorizacao]);
    }

    public function test_atualizar_outro_campo_do_usuario_nao_zera_a_supervisao(): void
    {
        $supervisor = $this->supervisor('veterinario');

        $supervisor->update(['name' => 'Novo Nome']);

        $this->assertSame('clinica', $supervisor->fresh()->supervisor_setor);
        $this->assertNotNull($supervisor->fresh()->pin_autorizacao);
    }

    public function test_senha_de_admin_fora_da_lista_de_papeis_validos_nao_autoriza_nada(): void
    {
        $logado = User::factory()->papel('dono')->doTenant(1)->create();
        User::factory()->papel('admin')->doTenant(1)->create(['email' => 'admin@teste.local', 'password' => Hash::make('senha-do-admin-123')]);
        $this->actingAs($logado);
        $reautenticacao = app(ReautenticacaoAdmin::class);

        $this->assertTrue($reautenticacao->confirmar('admin@teste.local', 'senha-do-admin-123'));

        // O papel "admin" continua em papeis_administrativos, mas saiu da lista de papéis válidos.
        config(['sentinel.papeis' => ['leitura', 'operador', 'dono']]);

        $this->assertFalse($reautenticacao->confirmar('admin@teste.local', 'senha-do-admin-123'));
    }

    public function test_exclusao_definitiva_nao_aceita_autorizacao_de_papel_fora_da_lista_de_validos(): void
    {
        $admin = User::factory()->papel('admin')->doTenant(1)->create();
        $cliente = Cliente::create(['nome' => 'Arquivado', 'tenant_id' => 1]);
        $cliente->delete();
        $cliente->arquivar();

        config(['sentinel.papeis' => ['leitura', 'operador', 'dono']]);
        $this->assertFalse(app(ClienteService::class)->excluirDefinitivo($cliente->id, 1, $admin->id)['excluido']);

        config(['sentinel.papeis' => ['leitura', 'operador', 'admin', 'dono']]);
        $this->assertTrue(app(ClienteService::class)->excluirDefinitivo($cliente->id, 1, $admin->id)['excluido']);
    }

    // ---------------------------------------------------------------- defeito 12: o PIN é medido e guardado sem espaços nas pontas

    public function test_pin_e_medido_comparado_e_guardado_sem_espacos_nas_pontas(): void
    {
        $usuario = $this->usuarioClinica('veterinario');
        $usuario->forceFill(['password' => Hash::make('abc123xyz')])->save();
        $supervisao = app(SupervisaoService::class);
        $supervisao->marcarSupervisor($usuario->id, 'clinica', $this->usuarioClinica('dono')->id, self::T);

        // Mesmo texto da senha com espaço no fim: depois do trim é igual a ela.
        $this->recusa(fn () => $supervisao->definirProprioPin($usuario->id, 'abc123xyz ', self::T), 'não pode ser igual à sua senha de login');
        $this->assertNull($usuario->fresh()->pin_autorizacao);

        $supervisao->definirProprioPin($usuario->id, '  '.self::PIN.'  ', self::T);
        $this->assertTrue(Hash::check(self::PIN, $usuario->fresh()->getRawOriginal('pin_autorizacao')), 'guardado sem os espaços');
        $this->assertFalse(Hash::check('  '.self::PIN.'  ', $usuario->fresh()->getRawOriginal('pin_autorizacao')));
    }
}
