<?php

namespace Tests\Feature;

use App\Models\Agendamento;
use App\Models\AuditLog;
use App\Models\CatalogoItem;
use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\Estorno;
use App\Models\Lancamento;
use App\Models\User;
use App\Services\AuditoriaService;
use App\Services\CobrancaService;
use App\Services\SupervisaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Confirmar em um passo (23/11, decisão do Lucas, opção B): POST /agente/confirmar aceita { proposta, valores,
 * email_admin, senha_admin } e faz o Revisar + o Confirmar na mesma requisição, sem o token. A prévia
 * (POST /agente/revisar?previa=1) mostra avisos e plano sem emitir token, sem auditar e sem gravar. Estes testes usam
 * só o caminho novo; o contrato {token} antigo continua coberto pelos testes legados, que não mudaram.
 */
class ConfirmarEmUmPassoTest extends TestCase
{
    use CenarioClinica, RefreshDatabase, SimulaAgente;

    private const PIN = '482915';

    private const SENHA_ADMIN = 'Senha-do-Supervisor#2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->prepararAgente();
    }

    private function proporLancamento(array $argumentos = []): string
    {
        return (string) $this->comando('criar_lancamento', ['descricao' => 'Café', 'valor' => 12.5, 'data' => '2026-09-21', ...$argumentos])
            ->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente')->json('proposta');
    }

    private function tokensNaSessao(): array
    {
        return array_filter((array) session('confirmacoes', []), fn ($p) => ($p['estado'] ?? null) === 'revisada');
    }

    // ---- Confirmar direto ----

    public function test_confirmar_direto_grava_sem_passar_pelo_revisar(): void
    {
        $this->logarComo('admin', 1);
        $proposta = $this->proporLancamento();
        $this->assertSame(0, Lancamento::count(), 'propor não grava');

        $this->postJson('/agente/confirmar', ['proposta' => $proposta])
            ->assertOk()
            ->assertJsonPath('tipo', 'resultado_escrita');

        $this->assertDatabaseHas('lancamentos', ['descricao' => 'Café', 'status' => 'pendente', 'tenant_id' => 1]);
        $this->assertSame(1, AuditLog::query()->where('tool', 'criar_lancamento')->where('resultado', 'sucesso')->count());
        $this->assertSame([], (array) session('confirmacoes', []), 'a proposta saiu da sessão e não sobrou token');
    }

    public function test_os_valores_editados_no_card_prevalecem_e_o_corpo_nao_escolhe_a_tool(): void
    {
        $this->logarComo('admin', 1);
        $proposta = $this->proporLancamento();

        $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'tool' => 'excluir_cliente', 'tenant_id' => 99,
            'valores' => ['descricao' => 'Editado no card', 'valor' => '20', 'tenant_id' => 99]])->assertOk();

        $this->assertDatabaseHas('lancamentos', ['descricao' => 'Editado no card', 'tenant_id' => 1]);
        $this->assertSame(1, Lancamento::count());
        $this->assertSame(0, Lancamento::where('tenant_id', 99)->count());
    }

    public function test_nada_e_gravado_sem_o_confirmar(): void
    {
        $this->logarComo('admin', 1);
        $proposta = $this->proporLancamento();
        $this->postJson('/agente/revisar', ['proposta' => $proposta, 'previa' => 1, 'valores' => []])->assertOk();
        $this->postJson('/agente/revisar?previa=1', ['proposta' => $proposta, 'valores' => []])->assertOk();

        $this->assertSame(0, Lancamento::count());
        $this->assertSame(0, AuditLog::query()->where('resultado', 'sucesso')->count());
    }

    // ---- Erro de validação: 422, valores preservados, proposta em rascunho ----

    public function test_erro_de_validacao_devolve_422_com_valores_preservados_audita_e_a_proposta_continua(): void
    {
        $this->logarComo('admin', 1);
        $proposta = $this->proporLancamento();

        $erro = $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'valores' => ['descricao' => '', 'valor' => '33']])
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'AI_TOOL_REFUSED')
            ->assertJsonPath('proposta', $proposta)
            ->assertJsonMissingPath('token');

        $campos = collect($erro->json('formulario.campos'))->keyBy('nome');
        $this->assertNotEmpty($campos['descricao']['erro'] ?? null, 'o erro aponta o campo');
        $this->assertEquals(33, $campos['valor']['valor'], 'o que o humano digitou volta preenchido');
        $this->assertSame(0, Lancamento::count());
        $this->assertSame(1, AuditLog::query()->where('tool', 'criar_lancamento')->where('resultado', AuditoriaService::REVISAO_COM_ERRO)->count());

        // A proposta segue em rascunho: o humano corrige e confirma na mesma proposta.
        $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'valores' => ['descricao' => 'Corrigido', 'valor' => '33']])->assertOk();
        $this->assertDatabaseHas('lancamentos', ['descricao' => 'Corrigido']);
        $this->assertSame(1, Lancamento::count());
    }

    public function test_valores_em_formato_invalido_ou_proposta_inexistente_da_422_e_nao_500(): void
    {
        $this->logarComo('admin', 1);
        $proposta = $this->proporLancamento();

        $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'valores' => 'texto'])->assertStatus(422)->assertJsonPath('tipo', 'erro');
        $this->postJson('/agente/confirmar', ['proposta' => 'nao-e-uuid'])->assertStatus(422);
        $this->postJson('/agente/confirmar', ['proposta' => '6f1c1f0e-3d1f-4c7e-9d0a-1d2b3c4d5e6f'])->assertStatus(422)->assertJsonPath('codigo', 'AI_PROPOSAL_UNAVAILABLE');
        $this->assertSame(0, Lancamento::count());
    }

    public function test_recusas_em_laco_batem_no_teto_do_confirmar(): void
    {
        $this->logarComo('admin', 1);
        $proposta = $this->proporLancamento();

        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'valores' => ['descricao' => '']])->assertStatus(422);
        }

        $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'valores' => ['descricao' => '']])->assertStatus(429);
        $this->assertSame(30, AuditLog::query()->where('resultado', AuditoriaService::REVISAO_COM_ERRO)->count());
    }

    // ---- Duplo clique, expiração e desfechos ----

    public function test_duplo_clique_rende_uma_gravacao_e_a_segunda_confirmacao_e_409(): void
    {
        $this->logarComo('admin', 1);
        $proposta = $this->proporLancamento();
        $entrada = session("confirmacoes.{$proposta}");

        $this->postJson('/agente/confirmar', ['proposta' => $proposta])->assertOk();

        // Intercalação: a segunda requisição leu a sessão antes de a primeira encerrar a proposta (mesmo padrão do M1 legado).
        $this->withSession(['confirmacoes' => [$proposta => $entrada]])
            ->postJson('/agente/confirmar', ['proposta' => $proposta])
            ->assertStatus(409)
            ->assertJsonPath('mensagem', 'Esta confirmação já foi usada.');

        $this->assertSame(1, Lancamento::count());
        $this->assertSame(1, AuditLog::query()->where('tool', 'criar_lancamento')->where('resultado', 'sucesso')->count());
    }

    public function test_proposta_expirada_nao_grava_e_o_expirado_e_auditado_uma_vez_so(): void
    {
        $this->logarComo('admin', 1);
        $proposta = $this->proporLancamento();
        $this->travel(301)->seconds();

        $this->postJson('/agente/confirmar', ['proposta' => $proposta])->assertStatus(422)->assertJsonPath('codigo', 'AI_PROPOSAL_EXPIRED');

        $this->assertSame(0, Lancamento::count());
        $this->assertSame(1, AuditLog::query()->where('tool', 'criar_lancamento')->where('resultado', 'expirado')->count(), 'a varredura audita uma vez');
        $this->assertSame([], (array) session('confirmacoes', []));
    }

    public function test_cancelar_e_substituida_por_novo_pedido_continuam_auditados(): void
    {
        $this->logarComo('admin', 1);
        $cancelada = $this->proporLancamento();
        $this->postJson('/agente/cancelar', ['proposta' => $cancelada])->assertOk()->assertJsonPath('tipo', 'cancelado');

        $substituida = $this->proporLancamento(['descricao' => 'Outra']);
        $this->proporLancamento(['descricao' => 'Nova']); // um novo pedido descarta a pendência anterior

        $this->postJson('/agente/confirmar', ['proposta' => $substituida])->assertStatus(422)->assertJsonPath('codigo', 'AI_PROPOSAL_UNAVAILABLE');
        $this->assertSame(0, Lancamento::count());
        $this->assertSame(2, AuditLog::query()->where('tool', 'criar_lancamento')->where('resultado', 'cancelado')->count(), 'cancelada + substituída');
    }

    public function test_sem_permissao_o_confirmar_direto_nega_e_encerra_a_proposta(): void
    {
        $this->logarComo('admin', 1);
        $proposta = $this->proporLancamento();
        \Illuminate\Support\Facades\Auth::user()->forceFill(['papel' => 'leitor'])->save(); // o papel mudou depois da proposta; RBAC é conferido de novo, no servidor

        $this->postJson('/agente/confirmar', ['proposta' => $proposta])->assertStatus(403);

        $this->assertSame(0, Lancamento::count());
        $this->assertSame([], (array) session('confirmacoes', []));
    }

    // ---- Segurar desde a abertura ----

    public function test_a_proposta_ja_abre_com_segurar_e_a_condicao_quando_depende_de_campo(): void
    {
        $this->logarComo('admin', 1);
        $cliente = Cliente::create(['nome' => 'Mercado Central', 'tenant_id' => 1]);

        $this->comando('criar_lancamento', ['descricao' => 'X', 'valor' => 1, 'data' => '2026-09-21'])
            ->assertJsonPath('segurar', false)->assertJsonPath('segurar_quando', null);
        $this->comando('excluir_cliente', ['id' => $cliente->id])->assertJsonPath('segurar', true)->assertJsonPath('segurar_quando', null);

        // Cancelar agendamento: depende do campo, que o humano pode trocar no card.
        $this->logarComo('recepcao', self::T);
        $agendamento = $this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item(),
            ['inicio' => '2026-11-11 09:00', 'fim' => '2026-11-11 09:30', 'status' => 'agendado']);
        $this->comando('alterar_status_agendamento', ['id' => $agendamento->id, 'novo_status' => 'cancelado'])
            ->assertJsonPath('segurar', true)->assertJsonPath('segurar_quando', ['campo' => 'novo_status', 'valor' => 'cancelado']);
        $this->comando('alterar_status_agendamento', ['id' => $agendamento->id, 'novo_status' => 'confirmado'])
            ->assertJsonPath('segurar', false)->assertJsonPath('segurar_quando', ['campo' => 'novo_status', 'valor' => 'cancelado']);
    }

    public function test_o_formulario_aberto_sem_ia_tambem_traz_segurar(): void
    {
        $this->logarComo('admin', 1);
        $cliente = Cliente::create(['nome' => 'Mercado Central', 'tenant_id' => 1]);

        $this->postJson('/agente/formulario', ['tool' => 'excluir_cliente', 'id' => $cliente->id])->assertOk()->assertJsonPath('segurar', true);
        $this->postJson('/agente/formulario', ['tool' => 'atualizar_cliente', 'id' => $cliente->id])->assertOk()->assertJsonPath('segurar', false);
    }

    // ---- Prévia ----

    public function test_a_previa_nao_emite_token_nao_audita_nao_grava_e_ignora_segredos(): void
    {
        $this->logarComo('admin', 1);
        $proposta = $this->proporLancamento();
        $antes = [AuditLog::count(), DB::transactionLevel(), json_encode(session('confirmacoes'))];

        $resposta = $this->postJson('/agente/revisar?previa=1', ['proposta' => $proposta, 'email_admin' => 'x@y.z', 'senha_admin' => 'SEGREDO-123',
            'pin' => '999111', 'crmv' => 'CRMV-SP 555', 'valores' => ['descricao' => 'Visto na prévia', 'senha_admin' => 'SEGREDO-123', 'pin' => '999111']])
            ->assertOk()
            ->assertJsonPath('tipo', 'previa')
            ->assertJsonPath('proposta', $proposta)
            ->assertJsonMissingPath('token');

        $this->assertSame('Visto na prévia', collect($resposta->json('formulario.campos'))->firstWhere('nome', 'descricao')['valor']);
        foreach (['SEGREDO-123', '999111', 'CRMV-SP 555'] as $segredo) {
            $this->assertStringNotContainsString($segredo, $resposta->getContent());
        }
        $this->assertSame($antes, [AuditLog::count(), DB::transactionLevel(), json_encode(session('confirmacoes'))], 'sem auditoria, sem trava presa, sessão intacta');
        $this->assertSame(0, Lancamento::count());
        $this->assertSame([], $this->tokensNaSessao());
        $this->assertStringNotContainsString('SEGREDO-123', AuditLog::all()->toJson());

        // Prévia com valor inválido não é recusa: só mostra (quem recusa é o Confirmar), e a proposta continua.
        $this->postJson('/agente/revisar?previa=1', ['proposta' => $proposta, 'valores' => ['descricao' => '']])->assertOk()->assertJsonPath('tipo', 'previa');
        $this->assertSame(0, AuditLog::query()->where('resultado', AuditoriaService::REVISAO_COM_ERRO)->count());
        $this->postJson('/agente/confirmar', ['proposta' => $proposta])->assertOk();
    }

    public function test_a_previa_nao_vaza_para_quem_nao_pode_executar_a_tool(): void
    {
        $this->logarComo('admin', 1);
        $proposta = $this->proporLancamento();
        \Illuminate\Support\Facades\Auth::user()->forceFill(['papel' => 'leitor'])->save();

        $this->postJson('/agente/revisar?previa=1', ['proposta' => $proposta, 'valores' => []])->assertStatus(403)->assertJsonPath('codigo', 'AI_FORBIDDEN');
        $this->assertSame(0, AuditLog::query()->where('resultado', 'negado')->count(), 'a prévia não audita');
    }

    // ---- Senha de admin (lixeira), dentro do passo único ----

    public function test_excluir_de_vez_pede_a_senha_de_admin_no_mesmo_passo(): void
    {
        $this->logarComo('admin', 1);
        User::factory()->papel('admin')->doTenant(1)->create(['email' => 'supervisor@sentinel.local', 'password' => Hash::make(self::SENHA_ADMIN)]);
        $cliente = Cliente::create(['nome' => 'Mercado Central', 'tenant_id' => 1]);
        $cliente->delete();
        $cliente->arquivar(); // excluir de vez só vale para o que já foi arquivado (RF11)

        $proposta = (string) $this->comando('excluir_definitivo_cliente', ['id' => $cliente->id])
            ->assertOk()->assertJsonPath('formulario.reautenticar', true)->json('proposta');

        $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'email_admin' => 'supervisor@sentinel.local', 'senha_admin' => 'errada-123'])
            ->assertStatus(403)->assertJsonPath('codigo', 'AI_REAUTH_FAILED');
        $this->assertTrue(Cliente::withTrashed()->whereKey($cliente->id)->exists(), 'senha errada não exclui');

        $outra = (string) $this->comando('excluir_definitivo_cliente', ['id' => $cliente->id])->assertOk()->json('proposta');
        $this->postJson('/agente/confirmar', ['proposta' => $outra, 'email_admin' => 'supervisor@sentinel.local', 'senha_admin' => self::SENHA_ADMIN])
            ->assertOk()->assertJsonPath('tipo', 'resultado_escrita');
        $this->assertFalse(Cliente::withTrashed()->whereKey($cliente->id)->exists());
        $this->assertStringNotContainsString(self::SENHA_ADMIN, AuditLog::all()->toJson());
        $this->assertStringNotContainsString('errada-123', AuditLog::all()->toJson());
    }

    // ---- Estorno com PIN, plano congelado com os valores do próprio Confirmar ----

    private function paga(): Cobranca
    {
        $cobrancas = app(CobrancaService::class);
        $cobranca = $cobrancas->abrir($this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item())->id, self::T);
        $vermifugo = $this->produto(['setor' => 'clinica', 'categoria' => 'farmacia', 'nome_comercial' => 'Vermífugo', 'preco_centavos' => 3500, 'estoque' => 5]);
        $cobrancas->adicionarItem($cobranca->id, $vermifugo->id, 2, self::T);
        $cobrancas->fechar($cobranca->id, self::T);

        return $cobrancas->registrarPagamento($cobranca->id, 'pix', self::T);
    }

    private function comSupervisor(): User
    {
        $dono = $this->comPin($this->usuarioClinica('dono'), self::PIN);
        $supervisor = $this->usuarioClinica('veterinario');
        app(SupervisaoService::class)->marcarSupervisor($supervisor->id, 'clinica', $dono->id, self::T);

        return $this->comPin($supervisor->fresh(), self::PIN);
    }

    public function test_estorno_congela_o_plano_com_os_valores_recebidos_e_o_pin_vale_no_mesmo_passo(): void
    {
        $supervisor = $this->comSupervisor();
        $this->logarComo('recepcao', self::T);
        $cobranca = $this->paga();
        $vermifugo = (int) $cobranca->itens()->where('descricao', 'Vermífugo')->value('id');

        // A IA propôs "tudo"; o humano muda para 1 vermífugo no card e confirma de uma vez, com o PIN.
        $proposta = (string) $this->comando('estornar_cobranca', ['id' => $cobranca->id, 'motivo' => 'Cobrado a mais.', 'modo' => 'tudo'])
            ->assertOk()->assertJsonPath('segurar', true)->json('proposta');

        $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'email_admin' => $supervisor->email, 'senha_admin' => self::PIN,
            'valores' => ['modo' => 'itens', 'motivo' => 'Cobrado a mais.', 'linhas' => [['item_id' => $vermifugo, 'quantidade' => '1', 'valor_centavos' => 1]]]])
            ->assertOk()->assertJsonPath('tipo', 'resultado_escrita');

        $this->assertSame(['paga', 3500], [$cobranca->fresh()->status, Estorno::sole()->valor_centavos], 'o plano é o dos valores recebidos, e o valor é do servidor');
        $this->assertSame(4, CatalogoItem::where('nome_comercial', 'Vermífugo')->value('estoque'));
        $log = AuditLog::query()->where('tool', 'estornar_cobranca')->where('resultado', 'sucesso')->sole();
        $this->assertSame($supervisor->id, $log->autorizado_por);
        $this->assertStringNotContainsString(self::PIN, AuditLog::all()->toJson());
    }

    public function test_estorno_sem_pin_ou_com_pin_errado_nao_grava(): void
    {
        $supervisor = $this->comSupervisor();
        $this->logarComo('recepcao', self::T);
        $cobranca = $this->paga();

        $proposta = (string) $this->comando('estornar_cobranca', ['id' => $cobranca->id, 'motivo' => 'Erro.', 'modo' => 'tudo'])->assertOk()->json('proposta');
        $this->postJson('/agente/confirmar', ['proposta' => $proposta])->assertStatus(403)->assertJsonPath('codigo', 'AI_REAUTH_REQUIRED');

        $outra = (string) $this->comando('estornar_cobranca', ['id' => $cobranca->id, 'motivo' => 'Erro.', 'modo' => 'tudo'])->assertOk()->json('proposta');
        $this->postJson('/agente/confirmar', ['proposta' => $outra, 'email_admin' => $supervisor->email, 'senha_admin' => '000000'])
            ->assertStatus(403)->assertJsonPath('codigo', 'AI_REAUTH_FAILED');

        $this->assertSame(0, Estorno::count());
        $this->assertSame('paga', $cobranca->fresh()->status);
    }

    public function test_a_previa_do_estorno_mostra_o_plano_sem_gravar_e_sem_ler_o_pin(): void
    {
        $this->comSupervisor();
        $this->logarComo('recepcao', self::T);
        $cobranca = $this->paga();
        $vermifugo = (int) $cobranca->itens()->where('descricao', 'Vermífugo')->value('id');
        $proposta = (string) $this->comando('estornar_cobranca', ['id' => $cobranca->id, 'motivo' => 'Erro.', 'modo' => 'tudo'])->assertOk()->json('proposta');
        $antes = AuditLog::count();

        $previa = $this->postJson('/agente/revisar?previa=1', ['proposta' => $proposta, 'senha_admin' => self::PIN,
            'valores' => ['modo' => 'itens', 'linhas' => [['item_id' => $vermifugo, 'quantidade' => '1']]]])->assertOk()->assertJsonPath('tipo', 'previa');

        $resumo = array_column($previa->json('formulario.resumo') ?? [], 'valor', 'rotulo');
        $this->assertSame('R$ 35,00', $resumo['Valor do estorno']);
        $this->assertStringNotContainsString(self::PIN, $previa->getContent());
        $this->assertSame([$antes, 0, 0], [AuditLog::count(), Estorno::count(), count($this->tokensNaSessao())]);
    }

    // ---- R1 (24/11): o plano visto é o plano estornado (plano_ref opcional) ----

    /** Abre a proposta "tudo" e pede a prévia: devolve [proposta, plano_ref]. */
    private function previaDoEstornoTudo(Cobranca $cobranca): array
    {
        $proposta = (string) $this->comando('estornar_cobranca', ['id' => $cobranca->id, 'motivo' => 'Cobrado a mais.', 'modo' => 'tudo'])->assertOk()->json('proposta');
        $previa = $this->postJson('/agente/revisar?previa=1', ['proposta' => $proposta, 'senha_admin' => self::PIN, 'valores' => []])
            ->assertOk()->assertJsonPath('tipo', 'previa')->assertJsonMissingPath('token');
        $this->assertStringNotContainsString(self::PIN, $previa->getContent());
        $this->assertNotEmpty($previa->json('plano_ref'));

        return [$proposta, (string) $previa->json('plano_ref')];
    }

    /** Outro usuário estorna 1 vermífugo (entre a prévia e o Confirmar). */
    private function outroEstornaUmVermifugo(Cobranca $cobranca, User $supervisor): void
    {
        $linha = (int) $cobranca->itens()->where('descricao', 'Vermífugo')->value('id');
        app(CobrancaService::class)->estornar($cobranca->id, 'Outro usuário.', (int) auth()->id(), $supervisor->id, self::T, true, 'itens', [['item_id' => $linha, 'quantidade' => 1]]);
    }

    public function test_plano_ref_igual_estorna(): void
    {
        $supervisor = $this->comSupervisor();
        $this->logarComo('recepcao', self::T);
        $cobranca = $this->paga();
        [$proposta, $ref] = $this->previaDoEstornoTudo($cobranca);

        $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'plano_ref' => $ref, 'email_admin' => $supervisor->email, 'senha_admin' => self::PIN])
            ->assertOk()->assertJsonPath('tipo', 'resultado_escrita');
        $this->assertSame(22000, (int) Estorno::sole()->valor_centavos);
    }

    public function test_plano_que_mudou_entre_a_previa_e_o_confirmar_da_422_e_nao_grava(): void
    {
        $supervisor = $this->comSupervisor();
        $this->logarComo('recepcao', self::T);
        $cobranca = $this->paga();
        [$proposta, $ref] = $this->previaDoEstornoTudo($cobranca);
        $this->outroEstornaUmVermifugo($cobranca, $supervisor);
        $antes = [Estorno::count(), AuditLog::query()->where('resultado', AuditoriaService::REVISAO_COM_ERRO)->count()];

        $resposta = $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'plano_ref' => $ref, 'email_admin' => $supervisor->email, 'senha_admin' => self::PIN])
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'AI_PLAN_CHANGED')
            ->assertJsonPath('mensagem', 'O plano mudou desde a prévia. Revise de novo.')
            ->assertJsonPath('proposta', $proposta)
            ->assertJsonMissingPath('token');

        $this->assertNotSame($ref, $resposta->json('plano_ref'), 'devolve o plano_ref novo');
        $this->assertNotEmpty($resposta->json('formulario.itens_estorno'), 'devolve o formulário com o plano novo');
        $this->assertSame($antes[0], Estorno::count(), 'nada gravado');
        $this->assertSame($antes[1] + 1, AuditLog::query()->where('resultado', AuditoriaService::REVISAO_COM_ERRO)->count());
        $this->assertNotNull(session("confirmacoes.{$proposta}"), 'a proposta continua em rascunho');
        $this->assertStringNotContainsString(self::PIN, AuditLog::all()->toJson());

        // Com o plano_ref novo, o humano vê o plano atual e confirma.
        $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'plano_ref' => $resposta->json('plano_ref'), 'email_admin' => $supervisor->email, 'senha_admin' => self::PIN])
            ->assertOk();
        $this->assertSame(2, Estorno::count());
    }

    public function test_sem_plano_ref_o_confirmar_e_como_hoje_mesmo_com_o_plano_mudado(): void
    {
        $supervisor = $this->comSupervisor();
        $this->logarComo('recepcao', self::T);
        $cobranca = $this->paga();
        [$proposta] = $this->previaDoEstornoTudo($cobranca);
        $this->outroEstornaUmVermifugo($cobranca, $supervisor);

        $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'email_admin' => $supervisor->email, 'senha_admin' => self::PIN])->assertOk();
        $this->assertSame(2, Estorno::count());
    }

    public function test_modo_itens_nao_e_afetado_quando_o_plano_das_linhas_e_o_mesmo(): void
    {
        $supervisor = $this->comSupervisor();
        $this->logarComo('recepcao', self::T);
        $cobranca = $this->paga();
        $consulta = (int) $cobranca->itens()->where('descricao', 'Consulta clínica')->value('id');
        $proposta = (string) $this->comando('estornar_cobranca', ['id' => $cobranca->id, 'motivo' => 'Erro.', 'modo' => 'tudo'])->assertOk()->json('proposta');
        $valores = ['modo' => 'itens', 'linhas' => [['item_id' => $consulta, 'quantidade' => 1]]];
        $ref = (string) $this->postJson('/agente/revisar?previa=1', ['proposta' => $proposta, 'valores' => $valores])->assertOk()->json('plano_ref');

        $this->outroEstornaUmVermifugo($cobranca, $supervisor); // outra linha: o plano das linhas escolhidas não muda

        $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'valores' => $valores, 'plano_ref' => $ref, 'email_admin' => $supervisor->email, 'senha_admin' => self::PIN])->assertOk();
        $this->assertSame(2, Estorno::count());
    }

    public function test_plano_ref_de_tipo_errado_e_tratado_como_plano_diferente(): void
    {
        $supervisor = $this->comSupervisor();
        $this->logarComo('recepcao', self::T);
        $cobranca = $this->paga();
        [$proposta] = $this->previaDoEstornoTudo($cobranca);

        $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'plano_ref' => ['x'], 'email_admin' => $supervisor->email, 'senha_admin' => self::PIN])
            ->assertStatus(422)->assertJsonPath('codigo', 'AI_PLAN_CHANGED');
        $this->assertSame(0, Estorno::count());
    }

    // ---- R2 (24/11): segredos em "valores" não vazam ----

    public function test_segredos_dentro_de_valores_nao_vazam_no_422_na_sessao_nem_na_auditoria(): void
    {
        $this->logarComo('admin', 1);
        $proposta = $this->proporLancamento();

        $resposta = $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'valores' => [
            'descricao' => '', 'email_admin' => 'x@y.z', 'senha_admin' => 'SEGREDO-789', 'senha' => 'SEGREDO-789', 'password' => 'SEGREDO-789', 'pin' => '777222', 'crmv' => 'CRMV-SP 777',
        ]])->assertStatus(422);

        foreach (['SEGREDO-789', '777222', 'CRMV-SP 777', 'x@y.z'] as $segredo) {
            $this->assertStringNotContainsString($segredo, $resposta->getContent());
            $this->assertStringNotContainsString($segredo, json_encode(session()->all()));
            $this->assertStringNotContainsString($segredo, AuditLog::all()->toJson());
        }
        $this->assertSame(0, Lancamento::count());
    }

    public function test_chave_a_mais_dentro_dos_itens_da_venda_nao_vai_para_a_sessao_nem_para_a_auditoria(): void
    {
        $this->logarComo('dono', self::T);
        $racao = $this->produto();
        $proposta = (string) $this->comando('registrar_venda', ['itens' => [['catalogo_item_id' => $racao->id, 'quantidade' => 1]], 'forma_pagamento' => 'pix'])
            ->assertOk()->json('proposta');

        // "itens" no corpo de valores: o que não é catalogo_item_id/quantidade não chega à sessão nem à auditoria.
        $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'valores' => ['itens' => [['catalogo_item_id' => $racao->id, 'quantidade' => 1, 'pin' => 'PIN-NO-ITEM', 'preco_centavos' => 1]]]])
            ->assertOk();
        $this->assertStringNotContainsString('PIN-NO-ITEM', AuditLog::all()->toJson());
        $this->assertStringNotContainsString('PIN-NO-ITEM', json_encode(session()->all()));
    }

    // ---- T5 (24/11): testes adversariais do endpoint ----

    public function test_proposta_de_outra_sessao_e_recusada_e_nada_e_gravado(): void
    {
        $this->logarComo('admin', 1);
        $proposta = $this->proporLancamento();

        // Outro navegador/usuário: sessão nova, a proposta do primeiro não existe lá (a proposta mora na sessão de quem a pediu).
        $this->flushSession();
        $this->logarComo('admin', 1);

        $this->postJson('/agente/confirmar', ['proposta' => $proposta])->assertStatus(422)->assertJsonPath('codigo', 'AI_PROPOSAL_UNAVAILABLE');
        $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'valores' => ['descricao' => 'Sequestrada']])->assertStatus(422);

        $this->assertSame(0, Lancamento::count());
        $this->assertSame(0, AuditLog::query()->where('resultado', 'sucesso')->count());
    }

    public function test_id_de_outro_tenant_dentro_de_valores_e_recusado_e_nada_e_gravado(): void
    {
        $this->logarComo('dono', self::T); // as tools da clínica só existem num tenant da clínica
        $tutorProprio = $this->tutor(self::T, 'Ana Souza');
        $tutorAlheio = $this->tutor(self::T + 1, 'Tutor de outra clínica');
        $proposta = (string) $this->comando('criar_pet', ['tutor_id' => $tutorProprio->id, 'nome' => 'Thor', 'especie' => 'cao'])
            ->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente')->json('proposta');

        $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'valores' => ['tutor_id' => $tutorAlheio->id]])
            ->assertStatus(422)->assertJsonPath('codigo', 'AI_TOOL_REFUSED');

        $this->assertSame(0, \App\Models\Pet::count(), 'o tutor de outro tenant não vira dono do pet');
        $this->assertSame(1, AuditLog::query()->where('tool', 'criar_pet')->where('resultado', AuditoriaService::REVISAO_COM_ERRO)->count());
    }

    public function test_sem_permissao_a_senha_de_admin_nao_e_conferida_nem_gasta_tentativa(): void
    {
        $this->logarComo('admin', 1);
        User::factory()->papel('admin')->doTenant(1)->create(['email' => 'supervisor@sentinel.local', 'password' => Hash::make(self::SENHA_ADMIN)]);
        $cliente = Cliente::create(['nome' => 'Mercado Central', 'tenant_id' => 1]);
        $cliente->delete();
        $cliente->arquivar();
        $proposta = (string) $this->comando('excluir_definitivo_cliente', ['id' => $cliente->id])->assertOk()->json('proposta');
        \Illuminate\Support\Facades\Auth::user()->forceFill(['papel' => 'leitor'])->save(); // o papel mudou depois da proposta
        $this->semConferirCredenciais();

        $resposta = $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'email_admin' => 'supervisor@sentinel.local', 'senha_admin' => 'errada-123'])
            ->assertStatus(403);

        $this->assertStringNotContainsString('AI_REAUTH', $resposta->getContent(), 'sem permissão a senha nem é conferida: a resposta é a mesma para senha certa ou errada');
        $this->assertTrue(Cliente::withTrashed()->whereKey($cliente->id)->exists());
        $this->assertSame(1, AuditLog::query()->where('tool', 'excluir_definitivo_cliente')->where('resultado', 'negado')->count());
        $this->assertStringNotContainsString('errada-123', AuditLog::all()->toJson());
    }

    /** Falha se qualquer conferência de senha, PIN ou CRMV for chamada (prova "nem confere, nem gasta tentativa"). */
    private function semConferirCredenciais(): void
    {
        $this->mock(\App\Services\ReautenticacaoAdmin::class, function ($mock) {
            foreach (['autorizar', 'autorizarEstorno', 'autorizarComCrmv'] as $metodo) {
                $mock->shouldNotReceive($metodo);
            }
        });
    }

    public function test_sem_permissao_o_pin_do_estorno_nao_e_conferido(): void
    {
        $supervisor = $this->comSupervisor();
        $this->logarComo('recepcao', self::T);
        $cobranca = $this->paga();
        $proposta = (string) $this->comando('estornar_cobranca', ['id' => $cobranca->id, 'motivo' => 'Erro.', 'modo' => 'tudo'])->assertOk()->json('proposta');
        \Illuminate\Support\Facades\Auth::user()->forceFill(['papel' => 'leitor'])->save(); // o papel mudou depois da proposta
        $this->semConferirCredenciais();

        $resposta = $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'email_admin' => $supervisor->email, 'senha_admin' => self::PIN])->assertStatus(403);

        $this->assertStringNotContainsString('AI_REAUTH', $resposta->getContent());
        $this->assertSame(0, Estorno::count());
        $this->assertSame('paga', $cobranca->fresh()->status);
        $this->assertStringNotContainsString(self::PIN, AuditLog::all()->toJson());
    }

    public function test_proposta_ja_concluida_nao_executa_de_novo(): void
    {
        $this->logarComo('admin', 1);
        $proposta = $this->proporLancamento();
        $this->postJson('/agente/confirmar', ['proposta' => $proposta])->assertOk();

        // Sem reinserir a entrada na sessão (como num segundo clique tardio): proposta indisponível, nada novo gravado.
        $this->postJson('/agente/confirmar', ['proposta' => $proposta])->assertStatus(422)->assertJsonPath('codigo', 'AI_PROPOSAL_UNAVAILABLE');
        $this->assertSame(1, Lancamento::count());
        $this->assertSame(1, AuditLog::query()->where('tool', 'criar_lancamento')->where('resultado', 'sucesso')->count());
    }

    public function test_valores_com_tipo_invalido_ou_gigante_da_422_e_nao_500(): void
    {
        $this->logarComo('admin', 1);
        $proposta = $this->proporLancamento();

        $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'valores' => ['descricao' => ['a', 'b'], 'valor' => ['x' => 1]]])->assertStatus(422);
        $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'valores' => ['descricao' => str_repeat('A', 200000), 'valor' => '10']])->assertStatus(422);
        $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'valores' => ['valor' => str_repeat('9', 5000)]])->assertStatus(422);
        $this->postJson('/agente/confirmar', ['proposta' => ['x'], 'valores' => []])->assertStatus(422);
        $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'valores' => ['descricao' => 'Café', 'valor' => '10'], 'plano_ref' => ['x']])->assertStatus(200); // tool sem plano: plano_ref de tipo estranho é ignorado

        $this->assertSame(1, Lancamento::count(), 'só a última requisição (válida) gravou');
    }

    public function test_o_31o_pedido_no_minuto_devolve_429_sem_gravar_nem_auditar(): void
    {
        $this->logarComo('admin', 1);
        $proposta = $this->proporLancamento();

        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'valores' => ['descricao' => '']])->assertStatus(422);
        }
        $antes = AuditLog::count();

        // Mesmo um pedido válido, no 31º, é barrado antes de qualquer trabalho.
        $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'valores' => ['descricao' => 'Válido']])->assertStatus(429);

        $this->assertSame($antes, AuditLog::count(), 'o 429 não audita');
        $this->assertSame(0, Lancamento::count(), 'o 429 não grava');
    }

    // ---- CRMV + senha dentro do passo único ----

    public function test_cirurgia_pede_crmv_e_senha_no_mesmo_passo(): void
    {
        $castracao = $this->item(['categoria' => 'cirurgia', 'nome_comercial' => 'Castração']);
        $thor = $this->pet();
        $vet = $this->usuarioClinica('veterinario');
        $this->actingAs($vet);
        $argumentos = ['pet_id' => $thor->id, 'catalogo_item_id' => $castracao->id, 'profissional_id' => $vet->id, 'inicio' => '2026-11-13 14:00'];

        $proposta = (string) $this->comando('agendar_atendimento', $argumentos)->assertOk()->assertJsonPath('formulario.tipo_autorizacao', 'crmv')->json('proposta');
        $this->postJson('/agente/confirmar', ['proposta' => $proposta, 'email_admin' => $vet->crmv, 'senha_admin' => 'senha-errada-123'])
            ->assertStatus(403)->assertJsonPath('codigo', 'AI_REAUTH_FAILED');
        $this->assertSame(0, Agendamento::count());

        $outra = (string) $this->comando('agendar_atendimento', $argumentos)->assertOk()->json('proposta');
        $this->postJson('/agente/confirmar', ['proposta' => $outra, 'email_admin' => $vet->crmv, 'senha_admin' => 'password'])->assertOk();

        $this->assertSame(1, Agendamento::count());
        $this->assertStringNotContainsString('password', AuditLog::all()->toJson());
        $this->assertStringNotContainsString('senha-errada-123', AuditLog::all()->toJson());
    }
}
