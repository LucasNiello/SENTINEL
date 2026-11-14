<?php

namespace Tests\Feature\Clinica;

use App\Exceptions\RegraDeNegocioException;
use App\Models\CatalogoItem;
use App\Models\Cobranca;
use App\Models\CobrancaItem;
use App\Models\Estorno;
use App\Models\EstornoItem;
use App\Models\User;
use App\Models\Venda;
use App\Services\CobrancaService;
use App\Services\ProntuarioService;
use App\Services\SupervisaoService;
use App\Services\VendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\ProvaDeTravas;
use Tests\TestCase;

/**
 * T2 (14/11): estorno por itens da cobrança paga e da venda paga (respostas C, D e E). Por linha, de 1 até o que ainda
 * não foi estornado; valor = preço copiado na linha × quantidade; vários parciais até esgotar; estoque volta só na linha
 * avulsa que baixou estoque, nunca na do prontuário; tudo estornado → cancelada; parcial → continua paga.
 */
class EstornoPorItensTest extends TestCase
{
    use CenarioClinica, ProvaDeTravas, RefreshDatabase;

    private CobrancaService $cobrancas;

    private VendaService $vendas;

    private User $dono;

    private User $recepcao;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->cobrancas = app(CobrancaService::class);
        $this->vendas = app(VendaService::class);
        $this->dono = $this->comPin($this->usuarioClinica('dono'));
        $this->recepcao = $this->usuarioClinica('recepcao');
        $this->supervisor = $this->usuarioClinica('veterinario');
        app(SupervisaoService::class)->marcarSupervisor($this->supervisor->id, 'clinica', $this->dono->id, self::T);
        $this->supervisor = $this->comPin($this->supervisor->fresh());
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

    private function vermifugo(): CatalogoItem
    {
        return CatalogoItem::where('nome_comercial', 'Vermífugo')->first()
            ?? $this->produto(['setor' => 'clinica', 'categoria' => 'farmacia', 'nome_comercial' => 'Vermífugo', 'preco_centavos' => 3500, 'estoque' => 5]);
    }

    /** Cobrança PAGA: consulta (15000) + 2 vermífugos avulsos (2 × 3500, baixaram estoque). Com $comProntuario, + 2 vacinas do prontuário. */
    private function paga(bool $comProntuario = false): Cobranca
    {
        $vet = $this->usuarioClinica('veterinario');
        $pet = $this->pet();
        $agendamento = $this->agendamentoDireto($pet, $vet, $this->item());
        if ($comProntuario) {
            $vacina = $this->produto(['setor' => 'clinica', 'categoria' => 'vacina', 'nome_comercial' => 'Vacina V10', 'preco_centavos' => 9000, 'estoque' => 10]);
            $prontuarios = app(ProntuarioService::class);
            $prontuario = $prontuarios->abrir(['pet_id' => $pet->id, 'tipo' => 'consulta', 'agendamento_id' => $agendamento->id, 'diagnostico' => 'Sigiloso.'], $vet->id, self::T);
            $prontuarios->adicionarItem($prontuario->id, $vacina->id, 2, $vet->id, self::T);
            $prontuarios->assinar($prontuario->id, $vet->id, self::T);
        }
        $cobranca = $this->cobrancas->abrir($agendamento->id, self::T);
        if ($comProntuario) {
            $this->cobrancas->importarDoProntuario($cobranca->id, self::T);
        }
        $this->cobrancas->adicionarItem($cobranca->id, $this->vermifugo()->id, 2, self::T);
        $this->cobrancas->fechar($cobranca->id, self::T);

        return $this->cobrancas->registrarPagamento($cobranca->id, 'pix', self::T);
    }

    private function linha(Cobranca $cobranca, string $descricao): CobrancaItem
    {
        return $cobranca->itens()->where('descricao', $descricao)->sole();
    }

    private function estornar(Cobranca $cobranca, string $modo, array $itens = [], ?User $autorizador = null, ?User $solicitante = null): Cobranca
    {
        return $this->cobrancas->estornar($cobranca->id, 'Cobrado a mais.', ($solicitante ?? $this->recepcao)->id, ($autorizador ?? $this->supervisor)->id, self::T, true, $modo, $itens);
    }

    public function test_parcial_de_1_entre_2_continua_paga_devolve_1_ao_estoque_e_mostra_o_liquido(): void
    {
        $cobranca = $this->paga();
        $vermifugo = $this->linha($cobranca, 'Vermífugo');
        $this->assertSame(3, $this->vermifugo()->fresh()->estoque);

        $plano = $this->cobrancas->planoDoEstorno($cobranca->id, 'itens', [['item_id' => $vermifugo->id, 'quantidade' => 1]], self::T);
        $this->assertSame([3500, 0, 22000, 18500, false], [$plano['valor_centavos'], $plano['estornado_antes_centavos'], $plano['total_centavos'], $plano['liquido_centavos'], $plano['esgota']]);

        $depois = $this->estornar($cobranca, 'itens', [['item_id' => $vermifugo->id, 'quantidade' => 1]]);

        $this->assertSame('paga', $depois->fresh()->status);
        $this->assertNull($depois->fresh()->estornado_em, 'parcial não preenche o estado final');
        $estorno = Estorno::sole();
        $this->assertSame([$cobranca->id, null, $this->recepcao->id, $this->supervisor->id, 3500, 'Cobrado a mais.'],
            [$estorno->cobranca_id, $estorno->venda_id, $estorno->solicitado_por, $estorno->autorizado_por, $estorno->valor_centavos, $estorno->motivo]);
        $this->assertSame([[$vermifugo->id, 1, 3500, true]], EstornoItem::all()->map(fn ($i) => [$i->item_id, $i->quantidade, $i->valor_centavos, $i->devolveu_estoque])->all());
        $this->assertSame(4, $this->vermifugo()->fresh()->estoque);
    }

    public function test_dois_parciais_ate_esgotar_deixam_cancelada_com_o_estado_final_de_hoje(): void
    {
        $cobranca = $this->paga();
        $vermifugo = $this->linha($cobranca, 'Vermífugo');

        $this->estornar($cobranca, 'itens', [['item_id' => $vermifugo->id, 'quantidade' => 1]]);
        $this->assertSame(18500, $this->cobrancas->planoDoEstorno($cobranca->id, 'tudo', [], self::T)['valor_centavos'], 'o que resta: consulta + 1 vermífugo');
        $final = $this->estornar($cobranca->fresh(), 'tudo', [], $this->dono)->fresh();

        $this->assertSame(['cancelada', 22000, $this->dono->id, $this->recepcao->id], [$final->status, $final->estorno_valor_centavos, $final->estorno_autorizado_por, $final->estorno_solicitado_por]);
        $this->assertNotNull($final->estornado_em);
        $this->assertSame([3500, 18500], Estorno::orderBy('id')->pluck('valor_centavos')->all());
        $this->assertSame(5, $this->vermifugo()->fresh()->estoque, 'os 2 voltaram, um em cada estorno');
        $this->recusa(fn () => $this->estornar($final, 'tudo'), 'Só uma cobrança paga é estornada');
    }

    public function test_quantidade_acima_do_restante_e_recusada_e_nada_muda(): void
    {
        $cobranca = $this->paga();
        $vermifugo = $this->linha($cobranca, 'Vermífugo');

        $this->recusa(fn () => $this->estornar($cobranca, 'itens', [['item_id' => $vermifugo->id, 'quantidade' => 3]]), 'restam 2');
        $this->recusa(fn () => $this->estornar($cobranca, 'itens', [['item_id' => $vermifugo->id, 'quantidade' => 1], ['item_id' => $vermifugo->id, 'quantidade' => 2]]), 'restam 2');
        $this->estornar($cobranca, 'itens', [['item_id' => $vermifugo->id, 'quantidade' => 2]]);
        $this->recusa(fn () => $this->estornar($cobranca->fresh(), 'itens', [['item_id' => $vermifugo->id, 'quantidade' => 1]]), 'já foi todo estornado');

        foreach ([0, -1, '1.5', 'dois', null] as $quantidade) {
            $this->recusa(fn () => $this->estornar($cobranca->fresh(), 'itens', [['item_id' => $this->linha($cobranca, 'Consulta clínica')->id, 'quantidade' => $quantidade]]), 'de 1 até 1');
        }
        $this->recusa(fn () => $this->estornar($cobranca->fresh(), 'itens', []), 'Escolha pelo menos um item');
        $this->recusa(fn () => $this->estornar($cobranca->fresh(), 'nada'), 'Escolha o que estornar');

        $this->assertSame(1, Estorno::count());
        $this->assertSame('paga', $cobranca->fresh()->status);
    }

    public function test_item_de_outra_cobranca_nao_e_encontrado(): void
    {
        $cobranca = $this->paga();
        $outra = $this->paga();

        $this->recusa(fn () => $this->estornar($cobranca, 'itens', [['item_id' => $this->linha($outra, 'Vermífugo')->id, 'quantidade' => 1]]), 'Não encontrei o item #');
        $this->assertSame(0, Estorno::count());
    }

    public function test_tudo_menos_prontuario_e_o_prontuario_nunca_devolve_estoque(): void
    {
        $cobranca = $this->paga(comProntuario: true);
        $vacina = CatalogoItem::where('nome_comercial', 'Vacina V10')->sole();
        $estoqueDaVacina = $vacina->estoque;

        $plano = $this->cobrancas->planoDoEstorno($cobranca->id, 'tudo_menos_prontuario', [], self::T);
        $this->assertSame(15000 + 7000, $plano['valor_centavos'], 'consulta + 2 vermífugos, sem as vacinas');
        $this->estornar($cobranca, 'tudo_menos_prontuario');
        $this->assertSame('paga', $cobranca->fresh()->status, 'as vacinas continuam cobradas');

        // De propósito, só o valor das vacinas (resposta D): o estoque nunca volta.
        $vacinas = $cobranca->itens()->whereNotNull('prontuario_item_id')->sole();
        $this->estornar($cobranca->fresh(), 'itens', [['item_id' => $vacinas->id, 'quantidade' => 2]], $this->dono);

        $this->assertSame($estoqueDaVacina, $vacina->fresh()->estoque);
        $this->assertFalse(EstornoItem::where('item_id', $vacinas->id)->sole()->devolveu_estoque);
        $this->assertSame('cancelada', $cobranca->fresh()->status);
        $this->recusa(fn () => $this->cobrancas->planoDoEstorno($cobranca->id, 'tudo_menos_prontuario', [], self::T), 'Só uma cobrança paga');
    }

    public function test_tudo_menos_prontuario_sem_avulso_restante_e_recusado(): void
    {
        $cobranca = $this->paga(comProntuario: true);
        $this->estornar($cobranca, 'tudo_menos_prontuario');

        $this->recusa(fn () => $this->estornar($cobranca->fresh(), 'tudo_menos_prontuario'), 'o que resta veio do prontuário');
    }

    public function test_valor_usa_o_preco_da_epoca_depois_de_mudar_o_catalogo(): void
    {
        $cobranca = $this->paga();
        $this->vermifugo()->update(['preco_centavos' => 99900]);

        $this->estornar($cobranca, 'itens', [['item_id' => $this->linha($cobranca, 'Vermífugo')->id, 'quantidade' => 2]]);

        $this->assertSame(7000, Estorno::sole()->valor_centavos);
    }

    public function test_sem_autoaprovacao_admin_e_dono_sem_pin_nao_autorizam(): void
    {
        $cobranca = $this->paga();
        $recepcaoSupervisora = $this->usuarioClinica('recepcao');
        app(SupervisaoService::class)->marcarSupervisor($recepcaoSupervisora->id, 'clinica', $this->dono->id, self::T);
        $recepcaoSupervisora = $this->comPin($recepcaoSupervisora->fresh());

        $this->recusa(fn () => $this->estornar($cobranca, 'tudo', [], $recepcaoSupervisora, $recepcaoSupervisora), 'não pode autorizá-lo');
        $this->recusa(fn () => $this->estornar($cobranca, 'tudo', [], $this->comPin($this->usuarioClinica('admin'))), 'O Administrador não autoriza');
        $this->recusa(fn () => $this->estornar($cobranca, 'tudo', [], $this->usuarioClinica('dono')), 'O dono ainda não tem PIN');
        $this->recusa(fn () => $this->cobrancas->estornar($cobranca->id, 'Motivo.', $this->recepcao->id, $this->supervisor->id, self::T, false, 'tudo'), 'precisa da confirmação');
        $this->assertSame(0, Estorno::count());
    }

    public function test_outro_tenant_nao_encontra_a_cobranca_nem_a_venda(): void
    {
        $cobranca = $this->paga();
        $venda = $this->vendaPaga();
        $donoDeFora = $this->comPin($this->usuarioClinica('dono', self::OUTRO_TENANT));

        foreach ([fn () => $this->cobrancas->estornar($cobranca->id, 'M.', $donoDeFora->id, $donoDeFora->id, self::OUTRO_TENANT, true, 'tudo'),
            fn () => $this->cobrancas->planoDoEstorno($cobranca->id, 'tudo', [], self::OUTRO_TENANT),
            fn () => $this->vendas->estornar($venda->id, 'M.', $donoDeFora->id, $donoDeFora->id, self::OUTRO_TENANT, true, 'tudo'),
            fn () => $this->vendas->planoDoEstorno($venda->id, 'tudo', [], self::OUTRO_TENANT)] as $acao) {
            try {
                $acao();
                $this->fail('outro tenant alcançou o registro');
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, Estorno::count());
    }

    // ---------------------------------------------------------------- venda avulsa (resposta E)

    /** Venda PAGA do petshop: 3 rações (3 × 21000, baixaram estoque) + 1 banho (6000, serviço). */
    private function vendaPaga(): Venda
    {
        $racao = CatalogoItem::where('nome_comercial', 'Ração 10 kg')->first() ?? $this->produto();
        $banho = $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho', 'preco_centavos' => 6000]);

        return $this->vendas->registrar([['catalogo_item_id' => $racao->id, 'quantidade' => 3], ['catalogo_item_id' => $banho->id, 'quantidade' => 1]],
            $this->usuarioClinica('vendedor')->id, null, 'pix', self::T);
    }

    public function test_venda_parcial_e_depois_o_resto_com_a_mesma_regra(): void
    {
        $venda = $this->vendaPaga();
        $racao = $venda->itens()->where('descricao', 'Ração 10 kg')->sole();
        $atendente = $this->usuarioClinica('atendente');
        $doPetshop = $this->usuarioClinica('vendedor');
        app(SupervisaoService::class)->marcarSupervisor($doPetshop->id, 'petshop', $this->dono->id, self::T);
        $doPetshop = $this->comPin($doPetshop->fresh());
        $this->assertSame(7, CatalogoItem::where('nome_comercial', 'Ração 10 kg')->value('estoque'));

        // Quem pede segue o setor: no petshop, o atendente (a recepção não); quem autoriza, o supervisor do petshop.
        $this->recusa(fn () => $this->vendas->estornar($venda->id, 'Troca.', $this->recepcao->id, $doPetshop->id, self::T, true, 'tudo'), 'equipe de atendimento do petshop');
        $this->recusa(fn () => $this->vendas->estornar($venda->id, 'Troca.', $atendente->id, $this->supervisor->id, self::T, true, 'tudo'), 'supervisor do setor petshop');

        $this->vendas->estornar($venda->id, 'Troca.', $atendente->id, $doPetshop->id, self::T, true, 'itens', [['item_id' => $racao->id, 'quantidade' => 1]]);
        $this->assertSame(['paga', 8], [$venda->fresh()->status, CatalogoItem::where('nome_comercial', 'Ração 10 kg')->value('estoque')]);
        $this->recusa(fn () => $this->vendas->estornar($venda->id, 'Troca.', $atendente->id, $doPetshop->id, self::T, true, 'itens', [['item_id' => $racao->id, 'quantidade' => 3]]), 'restam 2');

        $plano = $this->vendas->planoDoEstorno($venda->id, 'tudo', [], self::T);
        $this->assertSame([48000, 21000, 69000, 0, true], [$plano['valor_centavos'], $plano['estornado_antes_centavos'], $plano['total_centavos'], $plano['liquido_centavos'], $plano['esgota']]);
        $this->vendas->estornar($venda->id, 'Troca.', $atendente->id, $doPetshop->id, self::T, true, 'tudo');

        $this->assertSame(['cancelada', 10], [$venda->fresh()->status, CatalogoItem::where('nome_comercial', 'Ração 10 kg')->value('estoque')]);
        $this->assertSame([21000, 48000], Estorno::where('venda_id', $venda->id)->orderBy('id')->pluck('valor_centavos')->all());
        $this->assertFalse(EstornoItem::where('item_id', $venda->itens()->where('descricao', 'Banho')->value('id'))->sole()->devolveu_estoque, 'serviço não baixou estoque');
    }

    public function test_venda_aberta_nao_se_estorna_e_o_preco_e_o_da_epoca(): void
    {
        $aberta = $this->vendas->registrar([['catalogo_item_id' => $this->produto()->id, 'quantidade' => 1]], $this->usuarioClinica('vendedor')->id, null, null, self::T);
        $this->recusa(fn () => $this->vendas->estornar($aberta->id, 'M.', $this->dono->id, $this->dono->id, self::T, true, 'tudo'), 'Só uma venda paga é estornada');

        $venda = $this->vendaPaga();
        CatalogoItem::where('nome_comercial', 'Ração 10 kg')->update(['preco_centavos' => 1]);
        $this->vendas->estornar($venda->id, 'M.', $this->dono->id, $this->dono->id, self::T, true, 'itens', [['item_id' => $venda->itens()->where('descricao', 'Ração 10 kg')->value('id'), 'quantidade' => 2]]);
        $this->assertSame(42000, Estorno::where('venda_id', $venda->id)->sole()->valor_centavos);
    }

    public function test_o_ja_estornado_e_do_proprio_registro_mesmo_com_o_mesmo_numero_de_linha(): void
    {
        // cobranca_itens e venda_itens numeram as linhas cada uma por si: a linha #1 da venda não é a #1 da cobrança.
        $cobranca = $this->paga();
        $venda = $this->vendaPaga();
        $linhaDaVenda = (int) $venda->itens()->orderBy('id')->value('id');
        $this->assertNotNull(CobrancaItem::find($linhaDaVenda), 'o cenário precisa do mesmo número nas duas tabelas');

        $this->vendas->estornar($venda->id, 'M.', $this->dono->id, $this->dono->id, self::T, true, 'tudo');
        $outraCobranca = $this->paga();
        $this->estornar($outraCobranca, 'tudo', [], $this->dono);

        $this->assertSame(22000, $this->cobrancas->planoDoEstorno($cobranca->id, 'tudo', [], self::T)['valor_centavos'], 'nada dos outros estornos conta aqui');
    }

    public function test_estorno_da_venda_sem_a_conferencia_do_pin_nao_muda_nada(): void
    {
        $venda = $this->vendaPaga();

        $this->recusa(fn () => $this->vendas->estornar($venda->id, 'M.', $this->dono->id, $this->dono->id, self::T, false, 'tudo'), 'precisa da confirmação');
        $this->assertSame(['paga', 0], [$venda->fresh()->status, Estorno::count()]);
    }

    public function test_estorno_trava_o_registro_e_as_linhas_antes_de_decidir(): void
    {
        $cobranca = $this->paga();
        $venda = $this->vendaPaga();
        $this->mostrarTravas();

        $sqls = $this->leiturasTravadas(fn () => $this->estornar($cobranca, 'itens', [['item_id' => $this->linha($cobranca, 'Vermífugo')->id, 'quantidade' => 1]]));
        $this->assertTravouLeitura($sqls, 'cobrancas');
        $this->assertTravouLeitura($sqls, 'cobranca_itens');
        $this->assertTravouLeitura($sqls, 'estorno_itens');

        $sqls = $this->leiturasTravadas(fn () => $this->vendas->estornar($venda->id, 'M.', $this->dono->id, $this->dono->id, self::T, true, 'tudo'));
        $this->assertTravouLeitura($sqls, 'vendas');
        $this->assertTravouLeitura($sqls, 'venda_itens');
    }

    public function test_consulta_das_vendas_traz_o_estornado(): void
    {
        $venda = $this->vendaPaga();
        $this->vendas->estornar($venda->id, 'M.', $this->dono->id, $this->dono->id, self::T, true, 'itens', [['item_id' => $venda->itens()->where('descricao', 'Banho')->value('id'), 'quantidade' => 1]]);

        $linha = $this->vendas->buscarPorFiltro(null, null, self::T)->sole();
        $this->assertSame([69000, 6000], [(int) $linha->total_centavos, (int) $linha->estornado_centavos]);
    }
}
