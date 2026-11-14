<?php

namespace Tests\Feature\Clinica;

use App\Models\AuditLog;
use App\Models\CatalogoItem;
use App\Models\Cobranca;
use App\Models\Estorno;
use App\Models\User;
use App\Models\Venda;
use App\Services\CobrancaService;
use App\Services\SupervisaoService;
use App\Services\VendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * T2 (14/11): o estorno por itens pelo agente e pelo card. A IA escolhe o modo e as linhas; o servidor resolve itens e
 * valores (a IA nunca informa valor); o card mostra as linhas com a quantidade, o valor do estorno e o líquido, e o
 * Revisar refaz esse resumo com o que o humano mudou. Autoriza o PIN do supervisor do setor ou do dono (T1).
 */
class EstornoPorItensNoCardTest extends TestCase
{
    use CenarioClinica, RefreshDatabase, SimulaAgente;

    private const PIN = '482915';

    private User $supervisor;

    private User $dono;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->prepararAgente();
        $this->dono = $this->comPin($this->usuarioClinica('dono'), self::PIN);
        $this->supervisor = $this->usuarioClinica('veterinario');
        app(SupervisaoService::class)->marcarSupervisor($this->supervisor->id, 'clinica', $this->dono->id, self::T);
        $this->supervisor = $this->comPin($this->supervisor->fresh(), self::PIN);
        $this->logarComo('recepcao', self::T);
    }

    /** Cobrança PAGA: consulta (15000) + 2 vermífugos (2 × 3500). */
    private function paga(): Cobranca
    {
        $cobrancas = app(CobrancaService::class);
        $cobranca = $cobrancas->abrir($this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item())->id, self::T);
        $vermifugo = $this->produto(['setor' => 'clinica', 'categoria' => 'farmacia', 'nome_comercial' => 'Vermífugo', 'preco_centavos' => 3500, 'estoque' => 5]);
        $cobrancas->adicionarItem($cobranca->id, $vermifugo->id, 2, self::T);
        $cobrancas->fechar($cobranca->id, self::T);

        return $cobrancas->registrarPagamento($cobranca->id, 'pix', self::T);
    }

    private function linhaDoVermifugo(Cobranca $cobranca): int
    {
        return (int) $cobranca->itens()->where('descricao', 'Vermífugo')->value('id');
    }

    private function resumo($resposta): array
    {
        return array_column($resposta->json('formulario.resumo') ?? [], 'valor', 'rotulo');
    }

    public function test_card_mostra_as_linhas_e_o_humano_escolhe_a_quantidade_e_o_revisar_refaz_o_resumo(): void
    {
        $cobranca = $this->paga();
        $vermifugo = $this->linhaDoVermifugo($cobranca);

        // A IA propôs "tudo"; o card traz o editor com o que resta de cada linha e o resumo de "tudo".
        $proposta = $this->comando('estornar_cobranca', ['id' => $cobranca->id, 'motivo' => 'Cobrado a mais.', 'modo' => 'tudo'])->assertOk();
        $this->assertSame([['Consulta clínica', 1, 1], ['Vermífugo', 2, 2]],
            array_map(fn ($l) => [$l['descricao'], $l['restante'], $l['quantidade']], $proposta->json('formulario.itens_estorno')));
        $this->assertSame(['R$ 220,00', 'R$ 0,00'], [$this->resumo($proposta)['Valor do estorno'], $this->resumo($proposta)['Líquido depois do estorno']]);

        // O humano troca para 1 vermífugo no card: o Revisar devolve o token E o resumo refeito (antes do segurar).
        $revisao = $this->postJson('/agente/revisar', ['proposta' => $proposta->json('proposta'),
            'valores' => ['modo' => 'itens', 'motivo' => 'Cobrado a mais.', 'linhas' => [['item_id' => $vermifugo, 'quantidade' => '1']]]])
            ->assertOk()->assertJsonPath('tipo', 'revisada')->assertJsonPath('segurar', true);
        $this->assertSame(['R$ 35,00', 'R$ 0,00', 'R$ 185,00'],
            [$this->resumo($revisao)['Valor do estorno'], $this->resumo($revisao)['Já estornado antes'], $this->resumo($revisao)['Líquido depois do estorno']]);
        $this->assertSame('R$ 35,00', $this->resumo($revisao)['Estornar 1 × Vermífugo']);

        $this->travel(2)->seconds();
        $this->postJson('/agente/confirmar', ['token' => $revisao->json('token'), 'email_admin' => $this->supervisor->email, 'senha_admin' => self::PIN])->assertOk();

        $this->assertSame(['paga', 3500], [$cobranca->fresh()->status, Estorno::sole()->valor_centavos]);
        $this->assertSame(4, CatalogoItem::where('nome_comercial', 'Vermífugo')->value('estoque'));

        // Auditoria do estorno, com os itens (e sem o PIN).
        $log = AuditLog::query()->where('tool', 'estornar_cobranca')->where('resultado', 'sucesso')->sole();
        $this->assertSame([['item_id' => $vermifugo, 'quantidade' => 1, 'valor_centavos' => 3500, 'devolveu_estoque' => true]], $log->valores_anteriores['itens_estornados']);
        $this->assertSame([3500, 0, 'paga'], [$log->valores_anteriores['estorno_valor_centavos'], $log->valores_anteriores['estornado_antes_centavos'], $log->valores_anteriores['status']]);
        $this->assertSame($this->supervisor->id, $log->autorizado_por);
        $this->assertStringNotContainsString(self::PIN, (string) json_encode(AuditLog::all()->toArray()));
    }

    public function test_a_ia_nunca_informa_valor_chave_a_mais_recusa_a_proposta(): void
    {
        $cobranca = $this->paga();
        $vermifugo = $this->linhaDoVermifugo($cobranca);

        // Um "valor" no nível da tool está fora do esquema: a proposta é recusada inteira, nada vira card.
        $this->comando('estornar_cobranca', ['id' => $cobranca->id, 'motivo' => 'Erro.', 'modo' => 'tudo', 'valor_centavos' => 1])->assertStatus(502);

        // Dentro das linhas também: um "valor" ou "preço" na linha recusa a proposta.
        $this->comando('estornar_cobranca', ['id' => $cobranca->id, 'motivo' => 'Erro.', 'modo' => 'itens',
            'linhas' => [['item_id' => $vermifugo, 'quantidade' => 2, 'valor_centavos' => 1, 'preco_unitario_centavos' => 1]]])->assertStatus(502);
        $this->assertSame(0, AuditLog::query()->where('tool', 'estornar_cobranca')->where('resultado', 'sucesso')->count());

        // Só item e quantidade: o valor é o preço copiado na linha × quantidade, mesmo depois de mudar o catálogo.
        CatalogoItem::where('nome_comercial', 'Vermífugo')->update(['preco_centavos' => 1]);
        $token = $this->propor(['id' => $cobranca->id, 'motivo' => 'Erro.', 'modo' => 'itens',
            'linhas' => [['item_id' => $vermifugo, 'quantidade' => 2]]], 'estornar_cobranca');
        $this->travel(2)->seconds();
        $this->postJson('/agente/confirmar', ['token' => $token, 'email_admin' => $this->dono->email, 'senha_admin' => self::PIN])->assertOk();

        $this->assertSame([7000], Estorno::pluck('valor_centavos')->all());
        $this->assertSame([7000], \App\Models\EstornoItem::pluck('valor_centavos')->all());

        // O esquema da tool não tem campo de valor (nem nas linhas).
        $definicao = collect(app(\App\Services\AiAgentService::class)->tools())->firstWhere('function.name', 'estornar_cobranca') ?? null;
        $this->assertNotNull($definicao);
        $propriedades = $definicao['function']['parameters']['properties'];
        $this->assertSame(['item_id', 'quantidade'], array_keys($propriedades['linhas']['items']['properties']));
        $this->assertFalse($propriedades['linhas']['items']['additionalProperties']);
        $this->assertSame(['tudo', 'tudo_menos_prontuario', 'itens'], $propriedades['modo']['enum']);
        $this->assertEmpty(array_filter(array_keys($propriedades), fn ($k) => str_contains($k, 'valor') || str_contains($k, 'preco')));
    }

    public function test_quantidade_acima_do_restante_no_card_volta_com_a_frase_e_nada_grava(): void
    {
        $cobranca = $this->paga();
        $proposta = $this->comando('estornar_cobranca', ['id' => $cobranca->id, 'motivo' => 'Erro.', 'modo' => 'tudo'])->json('proposta');

        $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => ['modo' => 'itens', 'motivo' => 'Erro.',
            'linhas' => [['item_id' => $this->linhaDoVermifugo($cobranca), 'quantidade' => '3']]]])
            ->assertStatus(422)->assertJsonPath('formulario.erro', fn (string $m) => str_contains($m, 'restam 2'));
        $this->assertSame(0, Estorno::count());
    }

    /** 1ª passada do reviewer (imp. 1): o Confirmar não recalcula "tudo"; vale o plano que o card mostrou no Revisar. */
    public function test_tudo_revisado_nao_vira_outro_valor_se_outro_estorno_entrar_antes_do_confirmar(): void
    {
        $cobranca = $this->paga();
        $proposta = $this->comando('estornar_cobranca', ['id' => $cobranca->id, 'motivo' => 'Erro.', 'modo' => 'tudo'])->json('proposta');
        $revisao = $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->assertOk();
        $this->assertSame('R$ 220,00', $this->resumo($revisao)['Valor do estorno']);
        $this->assertSame('itens', collect($revisao->json('formulario.campos'))->firstWhere('nome', 'modo')['valor'], 'o plano ficou congelado em linhas');

        // Em outra aba, um parcial de 1 vermífugo entra antes do Confirmar.
        app(CobrancaService::class)->estornar($cobranca->id, 'Outro.', auth()->id(), $this->supervisor->id, self::T, true, 'itens',
            [['item_id' => $this->linhaDoVermifugo($cobranca), 'quantidade' => 1]]);

        $this->travel(2)->seconds();
        $this->postJson('/agente/confirmar', ['token' => $revisao->json('token'), 'email_admin' => $this->dono->email, 'senha_admin' => self::PIN])
            ->assertStatus(422)->assertJsonPath('mensagem', fn (string $m) => str_contains($m, 'restam 1'));
        $this->assertSame([3500], Estorno::pluck('valor_centavos')->all(), 'nada além do parcial da outra aba');
        $this->assertSame('paga', $cobranca->fresh()->status);
    }

    /** 1ª passada do reviewer (imp. 2): chave a mais nas linhas vindas do navegador não vai para a sessão nem para a auditoria. */
    public function test_linhas_do_navegador_vao_so_com_item_e_quantidade(): void
    {
        $cobranca = $this->paga();
        $vermifugo = $this->linhaDoVermifugo($cobranca);
        $proposta = $this->comando('estornar_cobranca', ['id' => $cobranca->id, 'motivo' => 'Erro.', 'modo' => 'tudo'])->json('proposta');

        // Revisar recusado (3 > 2): o rascunho guardado e a auditoria da revisão também não levam a chave a mais.
        $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => ['modo' => 'itens', 'motivo' => 'Erro.',
            'linhas' => [['item_id' => $vermifugo, 'quantidade' => '3', 'preco_unitario_centavos' => 1]]]])->assertStatus(422);
        $this->assertStringNotContainsString('preco_unitario_centavos', (string) json_encode(session()->all()));
        $this->assertStringNotContainsString('preco_unitario_centavos', (string) json_encode(AuditLog::all()->toArray()));

        $token = $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => ['modo' => 'itens', 'motivo' => 'Erro.',
            'linhas' => [['item_id' => $vermifugo, 'quantidade' => '1', 'valor_centavos' => 1, 'preco_unitario_centavos' => 1]]]])->assertOk()->json('token');
        $this->assertStringNotContainsString('preco_unitario_centavos', (string) json_encode(session()->all()));
        $this->travel(2)->seconds();
        $this->postJson('/agente/confirmar', ['token' => $token, 'email_admin' => $this->supervisor->email, 'senha_admin' => self::PIN])->assertOk();

        $log = AuditLog::query()->where('tool', 'estornar_cobranca')->where('resultado', 'sucesso')->sole();
        $this->assertSame([['item_id' => $vermifugo, 'quantidade' => 1]], $log->parametros['linhas']);
        $this->assertSame(3500, Estorno::sole()->valor_centavos);
    }

    /** 2ª passada do reviewer (cosm. 1): "tudo" numa cobrança de mais de 50 linhas congela e grava. */
    public function test_tudo_numa_cobranca_com_mais_de_50_linhas_passa_no_confirmar(): void
    {
        $cobrancas = app(CobrancaService::class);
        $cobranca = $cobrancas->abrir($this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item())->id, self::T);
        for ($i = 1; $i <= 51; $i++) {
            $cobrancas->adicionarItem($cobranca->id, $this->item(['categoria' => 'exame', 'nome_comercial' => "Exame {$i}", 'preco_centavos' => 100])->id, 1, self::T);
        }
        $cobrancas->fechar($cobranca->id, self::T);
        $cobrancas->registrarPagamento($cobranca->id, 'pix', self::T);

        $token = $this->propor(['id' => $cobranca->id, 'motivo' => 'Erro.', 'modo' => 'tudo'], 'estornar_cobranca');
        $this->travel(2)->seconds();
        $this->postJson('/agente/confirmar', ['token' => $token, 'email_admin' => $this->dono->email, 'senha_admin' => self::PIN])->assertOk();

        $this->assertSame(['cancelada', 15000 + 5100], [$cobranca->fresh()->status, Estorno::sole()->valor_centavos]);
    }

    public function test_consulta_de_cobrancas_mostra_total_estornado_e_liquido(): void
    {
        $cobranca = $this->paga();
        app(CobrancaService::class)->estornar($cobranca->id, 'M.', auth()->id(), $this->supervisor->id, self::T, true, 'itens',
            [['item_id' => $this->linhaDoVermifugo($cobranca), 'quantidade' => 1]]);

        $resposta = $this->postJson('/agente/consultar', ['tool' => 'consultar_cobrancas'])->assertOk();
        $this->assertSame(['estornado', 'liquido'], array_values(array_intersect(['estornado', 'liquido'], array_keys($resposta->json('resultado.0')))));
        $linha = collect($resposta->json('resultado'))->firstWhere('id', $cobranca->id);
        $this->assertSame(['R$ 220,00', 'R$ 35,00', 'R$ 185,00', 'paga'], [$linha['total'], $linha['estornado'], $linha['liquido'], $linha['status']]);

        // Sem pagamento, não há líquido (cosm. 4 da 1ª passada).
        $aberta = app(CobrancaService::class)->abrir($this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item())->id, self::T);
        $linhaAberta = collect($this->postJson('/agente/consultar', ['tool' => 'consultar_cobrancas'])->json('resultado'))->firstWhere('id', $aberta->id);
        $this->assertSame(['R$ 0,00', '—'], [$linhaAberta['estornado'], $linhaAberta['liquido']]);
    }

    public function test_venda_paga_se_estorna_pelo_card_e_aparece_na_consulta_de_vendas(): void
    {
        $racao = $this->produto();
        $venda = app(VendaService::class)->registrar([['catalogo_item_id' => $racao->id, 'quantidade' => 2]], $this->usuarioClinica('vendedor')->id, null, 'pix', self::T);
        $this->logarComo('atendente', self::T);

        $proposta = $this->comando('estornar_venda', ['id' => $venda->id, 'motivo' => 'Devolução.', 'modo' => 'itens',
            'linhas' => [['item_id' => $venda->itens()->value('id'), 'quantidade' => 1]]])->assertOk()
            ->assertJsonPath('formulario.tipo_autorizacao', 'estorno')->assertJsonPath('risco', 'exclusao');
        $this->assertSame('R$ 210,00', $this->resumo($proposta)['Valor do estorno']);
        $this->assertSame(['tudo', 'itens'], array_column(collect($proposta->json('formulario.campos'))->firstWhere('nome', 'modo')['opcoes'], 'valor'));

        $revisao = $this->postJson('/agente/revisar', ['proposta' => $proposta->json('proposta'), 'valores' => []])->assertOk()->assertJsonPath('segurar', true);
        $this->travel(2)->seconds();
        $this->postJson('/agente/confirmar', ['token' => $revisao->json('token'), 'email_admin' => $this->dono->email, 'senha_admin' => self::PIN])->assertOk();

        $this->assertSame(['paga', 9], [$venda->fresh()->status, $racao->fresh()->estoque]);
        $linha = collect($this->postJson('/agente/consultar', ['tool' => 'consultar_vendas'])->assertOk()->json('resultado'))->firstWhere('id', $venda->id);
        $this->assertSame(['R$ 420,00', 'R$ 210,00', 'R$ 210,00'], [$linha['total'], $linha['estornado'], $linha['liquido']]);
    }

    public function test_quem_nao_pode_pedir_nao_propoe_o_estorno_da_venda_e_outro_tenant_nao_acha(): void
    {
        $venda = app(VendaService::class)->registrar([['catalogo_item_id' => $this->produto()->id, 'quantidade' => 1]], $this->usuarioClinica('vendedor')->id, null, 'pix', self::T);

        foreach (['vendedor', 'veterinario'] as $papel) {
            $this->logarComo($papel, self::T);
            $this->comando('estornar_venda', ['id' => $venda->id, 'motivo' => 'X.', 'modo' => 'tudo'])->assertStatus(403);
        }

        config(['sentinel.tenants_clinica' => [self::T, self::OUTRO_TENANT]]);
        $this->logarComo('recepcao', self::OUTRO_TENANT);
        $this->comando('estornar_venda', ['id' => $venda->id, 'motivo' => 'X.', 'modo' => 'tudo'])->assertStatus(422);
        $this->assertSame([], $this->postJson('/agente/consultar', ['tool' => 'consultar_vendas'])->assertOk()->json('resultado'));
        $this->assertSame('paga', $venda->fresh()->status);
    }
}
