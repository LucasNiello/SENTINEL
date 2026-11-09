<?php

namespace Tests\Feature\Clinica;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Cobranca;
use App\Models\CobrancaItem;
use App\Services\CatalogoService;
use App\Services\CobrancaService;
use App\Services\ProntuarioService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

class CobrancaServiceTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    private CobrancaService $cobrancas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->cobrancas = app(CobrancaService::class);
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

    /** Atendimento da clínica com prontuário (rascunho) e os itens dados. */
    private function atendimento(array $itens = [], array $prontuario = []): array
    {
        $vet = $this->usuarioClinica('veterinario');
        $pet = $this->pet();
        $agendamento = $this->agendamentoDireto($pet, $vet, $this->item());
        $servico = app(ProntuarioService::class);
        $registro = $servico->abrir(['pet_id' => $pet->id, 'tipo' => 'consulta', 'agendamento_id' => $agendamento->id,
            'diagnostico' => 'Diagnóstico sigiloso XYZ.', ...$prontuario], $vet->id, self::T);
        foreach ($itens as [$item, $quantidade]) {
            $servico->adicionarItem($registro->id, $item->id, $quantidade, $vet->id, self::T);
        }

        return [$agendamento, $registro, $vet];
    }

    public function test_nasce_aberta_com_o_servico_do_agendamento_copiado_como_primeira_linha(): void
    {
        [$agendamento] = $this->atendimento();

        $cobranca = $this->cobrancas->abrir($agendamento->id, self::T);

        $this->assertSame(['aberta', 'clinica', 15000], [$cobranca->status, $cobranca->setor, $cobranca->total_centavos]);
        $linha = $cobranca->itens()->sole();
        $this->assertSame(['Consulta clínica', 15000, 1, 15000, null], [$linha->descricao, $linha->preco_unitario_centavos, $linha->quantidade, $linha->subtotal_centavos, $linha->prontuario_item_id]);
    }

    public function test_itens_do_prontuario_assinado_viram_linhas_com_copia_e_diarias(): void
    {
        $vacina = $this->item(['categoria' => 'vacina', 'nome_comercial' => 'Vacina V10', 'preco_centavos' => 9000]);
        $internacao = $this->item(['categoria' => 'internacao', 'nome_comercial' => 'Internação (diária)', 'preco_centavos' => 18000, 'cobranca_por' => 'diaria']);
        [$agendamento, $prontuario, $vet] = $this->atendimento([[$vacina, 2], [$internacao, 1]],
            ['tipo' => 'internacao', 'entrada_em' => '2026-11-06 20:00', 'alta_em' => '2026-11-09 09:00']);
        $cobranca = $this->cobrancas->abrir($agendamento->id, self::T);

        $this->recusa(fn () => $this->cobrancas->importarDoProntuario($cobranca->id, self::T), 'depois da assinatura');

        app(ProntuarioService::class)->assinar($prontuario->id, $vet->id, self::T);
        $cobranca = $this->cobrancas->importarDoProntuario($cobranca->id, self::T);

        // 15000 (consulta) + 2 x 9000 (vacina) + 3 diárias x 18000 (06 -> 09/11).
        $this->assertSame(15000 + 18000 + 54000, $cobranca->total_centavos);
        $this->assertSame([['Vacina V10', 9000, 2, 18000], ['Internação (diária)', 18000, 3, 54000]],
            $cobranca->itens()->whereNotNull('prontuario_item_id')->orderBy('id')->get()
                ->map(fn ($l) => [$l->descricao, $l->preco_unitario_centavos, $l->quantidade, $l->subtotal_centavos])->all());

        // Cada item do prontuário entra uma vez só.
        $this->recusa(fn () => $this->cobrancas->importarDoProntuario($cobranca->id, self::T), 'já estão na cobrança');
        $this->assertSame(3, CobrancaItem::count());
    }

    public function test_nenhum_texto_clinico_vai_para_a_cobranca(): void
    {
        $vacina = $this->item(['categoria' => 'vacina', 'nome_comercial' => 'Vacina V10']);
        [$agendamento, $prontuario, $vet] = $this->atendimento([[$vacina, 1]]);
        app(ProntuarioService::class)->assinar($prontuario->id, $vet->id, self::T);

        $cobranca = $this->cobrancas->importarDoProntuario($this->cobrancas->abrir($agendamento->id, self::T)->id, self::T);

        $this->assertStringNotContainsString('sigiloso', json_encode([$cobranca->toArray(), $cobranca->itens->toArray()]));
    }

    public function test_mudar_o_catalogo_nao_altera_cobranca_antiga(): void
    {
        [$agendamento] = $this->atendimento();
        $cobranca = $this->cobrancas->abrir($agendamento->id, self::T);
        $consulta = $agendamento->servico;

        app(CatalogoService::class)->atualizar($consulta->id, ['preco_centavos' => 99900, 'nome_comercial' => 'Consulta premium'], self::T);

        $linha = $cobranca->itens()->sole();
        $this->assertSame(['Consulta clínica', 15000], [$linha->descricao, $linha->preco_unitario_centavos]);
        $this->assertSame(15000, $cobranca->fresh()->total_centavos);
    }

    public function test_total_e_sempre_recalculado_pelo_servidor_em_centavos(): void
    {
        [$agendamento] = $this->atendimento();
        $cobranca = $this->cobrancas->abrir($agendamento->id, self::T);
        $hemograma = $this->item(['categoria' => 'exame', 'nome_comercial' => 'Hemograma', 'preco_centavos' => 6550]);

        $cobranca = $this->cobrancas->adicionarItem($cobranca->id, $hemograma->id, 3, self::T);
        $this->assertSame(15000 + 19650, $cobranca->total_centavos);

        $linha = $cobranca->itens()->where('catalogo_item_id', $hemograma->id)->sole();
        $this->assertSame(15000, $this->cobrancas->removerItem($linha->id, self::T)->total_centavos);
        $this->assertIsInt($cobranca->fresh()->total_centavos);

        $this->recusa(fn () => $this->cobrancas->adicionarItem($cobranca->id, $hemograma->id, 0, self::T), 'inteiro maior que zero');
    }

    public function test_item_que_exige_receita_nao_entra_avulso_e_item_de_outro_setor_e_recusado(): void
    {
        [$agendamento] = $this->atendimento();
        $cobranca = $this->cobrancas->abrir($agendamento->id, self::T);
        $antibiotico = $this->produto(['setor' => 'clinica', 'categoria' => 'farmacia', 'nome_comercial' => 'Antibiótico', 'exige_receita' => true]);

        $this->recusa(fn () => $this->cobrancas->adicionarItem($cobranca->id, $antibiotico->id, 1, self::T), 'exige receita');
        $this->recusa(fn () => $this->cobrancas->adicionarItem($cobranca->id, $this->produto()->id, 1, self::T), 'não é do setor desta cobrança');
        $this->assertSame(1, $cobranca->itens()->count());
    }

    public function test_ciclo_aberta_fechada_paga_e_cancelamento(): void
    {
        [$agendamento] = $this->atendimento();
        $cobranca = $this->cobrancas->abrir($agendamento->id, self::T);

        $this->recusa(fn () => $this->cobrancas->registrarPagamento($cobranca->id, 'pix', self::T), 'Só uma cobrança fechada pode ser paga');

        $this->cobrancas->fechar($cobranca->id, self::T);
        $this->assertNotNull($cobranca->fresh()->fechada_em);
        $this->recusa(fn () => $this->cobrancas->adicionarItem($cobranca->id, $this->item()->id, 1, self::T), 'só a aberta recebe ou perde itens');
        $this->recusa(fn () => $this->cobrancas->registrarPagamento($cobranca->id, 'cheque', self::T), 'dinheiro, pix ou cartao');

        $paga = $this->cobrancas->registrarPagamento($cobranca->id, 'pix', self::T);
        $this->assertSame(['paga', 'pix', '2026-11-10 10:00:00'], [$paga->status, $paga->forma_pagamento, $paga->pago_em->format('Y-m-d H:i:s')]);
        $this->recusa(fn () => $this->cobrancas->cancelar($cobranca->id, self::T), 'Uma cobrança paga não pode ser cancelada.');

        [$outro] = $this->atendimento();
        $this->assertSame('cancelada', $this->cobrancas->cancelar($this->cobrancas->abrir($outro->id, self::T)->id, self::T)->status);
    }

    public function test_uma_cobranca_por_agendamento_e_agendamento_cancelado_nao_cobra(): void
    {
        [$agendamento] = $this->atendimento();
        $this->cobrancas->abrir($agendamento->id, self::T);
        $this->recusa(fn () => $this->cobrancas->abrir($agendamento->id, self::T), 'já tem uma cobrança');

        [$cancelado] = $this->atendimento();
        $cancelado->update(['status' => 'cancelado']);
        $this->recusa(fn () => $this->cobrancas->abrir($cancelado->id, self::T), 'só o realizado é cobrado');
        $this->assertSame(1, Cobranca::count());
    }

    public function test_so_agendamento_realizado_e_cobrado_e_agendamento_com_cobranca_nao_e_desfeito(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $futuro = $this->agendamentoDireto($this->pet(), $vet, $this->item(), ['inicio' => now()->addDay(), 'fim' => now()->addDay()->addHour(), 'status' => 'agendado']);
        $this->recusa(fn () => $this->cobrancas->abrir($futuro->id, self::T), 'O agendamento está agendado: só o realizado é cobrado.');
        $futuro->update(['status' => 'confirmado']);
        $this->recusa(fn () => $this->cobrancas->abrir($futuro->id, self::T), 'só o realizado é cobrado');

        // Defesa: cobrança viva num agendamento ainda não realizado (dado antigo) impede cancelar ou marcar falta.
        Cobranca::create(['agendamento_id' => $futuro->id, 'setor' => 'clinica', 'status' => 'paga', 'total_centavos' => 15000, 'tenant_id' => self::T]);
        foreach (['cancelado', 'faltou'] as $status) {
            $this->recusa(fn () => app(\App\Services\AgendaService::class)->alterarStatus($futuro->id, $status, self::T), 'cancele ou estorne a cobrança');
        }
        $this->assertSame('confirmado', $futuro->fresh()->status);
    }

    public function test_produto_na_cobranca_baixa_o_estoque_e_devolve_ao_sair(): void
    {
        $vermifugo = $this->produto(['setor' => 'clinica', 'categoria' => 'farmacia', 'nome_comercial' => 'Vermífugo', 'preco_centavos' => 3500, 'estoque' => 5]);
        [$agendamento] = $this->atendimento();
        $cobranca = $this->cobrancas->abrir($agendamento->id, self::T);

        $this->cobrancas->adicionarItem($cobranca->id, $vermifugo->id, 2, self::T);
        $this->assertSame(3, $vermifugo->fresh()->estoque);
        $linha = CobrancaItem::where('catalogo_item_id', $vermifugo->id)->sole();
        $this->assertTrue($linha->baixou_estoque);

        $this->recusa(fn () => $this->cobrancas->adicionarItem($cobranca->id, $vermifugo->id, 4, self::T), 'Estoque insuficiente de Vermífugo: há 3');
        $this->assertSame([3, 22000], [$vermifugo->fresh()->estoque, $cobranca->fresh()->total_centavos]);

        $this->cobrancas->removerItem($linha->id, self::T);
        $this->assertSame(5, $vermifugo->fresh()->estoque);

        $this->cobrancas->adicionarItem($cobranca->id, $vermifugo->id, 2, self::T);
        $this->cobrancas->fechar($cobranca->id, self::T);
        $this->cobrancas->cancelar($cobranca->id, self::T);
        $this->assertSame(5, $vermifugo->fresh()->estoque);
    }

    public function test_item_do_prontuario_baixa_o_estoque_e_cobranca_cancelada_libera_nova_cobranca(): void
    {
        $antibiotico = $this->produto(['setor' => 'clinica', 'categoria' => 'farmacia', 'nome_comercial' => 'Antibiótico', 'preco_centavos' => 8000, 'estoque' => 4, 'exige_receita' => true]);
        [$agendamento, $prontuario, $vet] = $this->atendimento([[$antibiotico, 1]]);
        app(ProntuarioService::class)->assinar($prontuario->id, $vet->id, self::T);

        $primeira = $this->cobrancas->importarDoProntuario($this->cobrancas->abrir($agendamento->id, self::T)->id, self::T);
        $this->assertSame(3, $antibiotico->fresh()->estoque);
        $this->recusa(fn () => $this->cobrancas->abrir($agendamento->id, self::T), 'já tem uma cobrança');

        // O item do prontuário foi aplicado no animal: cancelar a cobrança não o devolve ao estoque.
        $this->cobrancas->cancelar($primeira->id, self::T);
        $this->assertSame(3, $antibiotico->fresh()->estoque);

        // Cancelada não conta: o agendamento e o item do prontuário são cobrados de novo, uma vez, e o
        // estoque não baixa outra vez (o consumo já foi registrado na cobrança cancelada).
        $segunda = $this->cobrancas->importarDoProntuario($this->cobrancas->abrir($agendamento->id, self::T)->id, self::T);
        $this->assertSame([23000, 3], [$segunda->total_centavos, $antibiotico->fresh()->estoque]);
        $this->assertFalse(CobrancaItem::where('cobranca_id', $segunda->id)->whereNotNull('prontuario_item_id')->sole()->baixou_estoque);
        $this->recusa(fn () => $this->cobrancas->importarDoProntuario($segunda->id, self::T), 'já estão na cobrança');
        $this->recusa(fn () => $this->cobrancas->abrir($agendamento->id, self::T), 'já tem uma cobrança');
    }

    public function test_outro_tenant_e_inexistente(): void
    {
        [$agendamento] = $this->atendimento();
        $cobranca = $this->cobrancas->abrir($agendamento->id, self::T);

        $this->recusa(fn () => $this->cobrancas->adicionarItem($cobranca->id, $this->item([], self::OUTRO_TENANT)->id, 1, self::T), 'Não encontrei o item do catálogo');

        $this->expectException(ModelNotFoundException::class);
        $this->cobrancas->fechar($cobranca->id, self::OUTRO_TENANT);
    }
}
