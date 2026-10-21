<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CategoriaLancamento;
use App\Models\Cliente;
use App\Models\Lancamento;
use App\Models\NotaFiscal;
use App\Models\User;
use App\Services\AiAgentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Bloco 4 — lixeira pelo agente (RF08–RF12): excluir_* vai para a lixeira se nada
 * depender do registro; restaurar_* e excluir_definitivo_* pedem a senha de um admin
 * no card; "pai" na lixeira recusa dizendo qual é; exclusão física só de arquivado,
 * só admin e nunca automática. Os testes da senha em si estão no ReautenticacaoCardTest.
 */
class LixeiraTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    private const SENHA_SUPERVISOR = 'Senha-do-Supervisor#2026';

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararAgente();
        $this->logarComo('admin', 1);
    }

    private function supervisor(int $tenant = 1, string $email = 'supervisor@sentinel.local', string $papel = 'admin'): User
    {
        return User::factory()->papel($papel)->doTenant($tenant)->create(['email' => $email, 'password' => Hash::make(self::SENHA_SUPERVISOR)]);
    }

    private function cliente(string $nome = 'Mercado Central', int $tenant = 1, array $extra = []): Cliente
    {
        return Cliente::create(['nome' => $nome, 'tenant_id' => $tenant, ...$extra]);
    }

    private function lancamento(array $extra = []): Lancamento
    {
        return Lancamento::create(['descricao' => 'Aluguel', 'valor' => 10, 'data' => '2026-09-21', 'status' => 'pendente', 'tenant_id' => 1, ...$extra]);
    }

    private function naLixeira(Model $registro): Model
    {
        $registro->delete();

        return $registro;
    }

    private function arquivado(Model $registro): Model
    {
        $registro->delete();
        $registro->arquivar();

        return $registro;
    }

    /** Segurar e confirmar com o e-mail e a senha do admin no corpo — só o /confirmar recebe a senha. */
    private function confirmarComSenha(string $token, mixed $email = 'supervisor@sentinel.local', mixed $senha = self::SENHA_SUPERVISOR)
    {
        $this->travel(2)->seconds();

        return $this->postJson('/agente/confirmar', ['token' => $token, 'email_admin' => $email, 'senha_admin' => $senha]);
    }

    private function revisar(string $proposta)
    {
        return $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []]);
    }

    // ---- Excluir (RF08/RF09) ----

    public function test_excluir_mostra_o_registro_no_card_e_move_para_a_lixeira(): void
    {
        $cliente = $this->cliente(extra: ['documento' => '123.456.789-01', 'email' => 'contato@mercado.test']);

        $resposta = $this->comando('excluir_cliente', ['id' => $cliente->id])
            ->assertOk()
            ->assertJsonPath('tipo', 'confirmacao_pendente')
            ->assertJsonPath('risco', 'exclusao')
            ->assertJsonPath('formulario.titulo', "Excluir cliente #{$cliente->id}")
            ->assertJsonPath('formulario.campos', [])
            ->assertJsonMissingPath('formulario.reautenticar');
        $resumo = collect($resposta->json('formulario.resumo'))->pluck('valor', 'rotulo');
        $this->assertSame('Mercado Central', $resumo['Nome']);
        $this->assertSame('***.***.789-01', $resumo['Documento'], 'LGPD: o card só mostra a máscara do CPF');
        $this->assertNotNull(Cliente::find($cliente->id), 'propor não exclui nada');

        $token = $this->revisar($resposta->json('proposta'))->assertOk()->json('token');
        $this->confirmar($token)
            ->assertOk()
            ->assertJsonPath('tipo', 'resultado_escrita')
            ->assertJsonPath('mensagem', 'Cliente movido para a lixeira.');

        $this->assertNull(Cliente::find($cliente->id));
        $this->assertTrue(Cliente::naLixeira()->whereKey($cliente->id)->exists(), 'RF09: vai para a lixeira, não some');
        $log = AuditLog::query()->where('resultado', 'sucesso')->sole();
        $this->assertSame(['excluir_cliente', 'excluir', 'Cliente', $cliente->id], [$log->tool, $log->acao, $log->entidade_tipo, $log->entidade_id]);
        $this->assertNull($log->autorizado_por, 'excluir não pede senha de admin');
    }

    public function test_excluir_por_nome_e_no_tenant_e_frase_de_nao_encontrado(): void
    {
        $this->cliente('Padaria Alheia', tenant: 2);
        $cliente = $this->cliente('Padaria Boa');

        $this->comando('excluir_cliente', ['busca' => 'Padaria'])
            ->assertOk()
            ->assertJsonPath('formulario.titulo', "Excluir cliente #{$cliente->id}");

        $this->comando('excluir_cliente', ['busca' => 'Inexistente'])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Não encontrei o cliente com esse nome.');
        $this->comando('excluir_cliente', [])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Informe qual cliente deve ser excluído: o número ou o nome.');
        $this->comando('excluir_nota_fiscal', [])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Informe qual nota fiscal deve ser excluída: o número.');
        $this->comando('restaurar_lancamento', ['busca' => 'Nada'])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Não encontrei o lançamento com essa descrição na lixeira.');
    }

    public function test_dependencia_bloqueia_a_exclusao_ja_no_revisar_com_frase_simples(): void
    {
        $cliente = $this->cliente();
        $this->lancamento(['cliente_id' => $cliente->id]);
        $proposta = $this->comando('excluir_cliente', ['id' => $cliente->id])->json('proposta');

        $this->revisar($proposta)
            ->assertStatus(422)
            ->assertJsonMissingPath('token')
            ->assertJsonPath('mensagem', 'Não é possível excluir: existem lançamentos vinculados a este registro.')
            ->assertJsonPath('formulario.erro', 'Não é possível excluir: existem lançamentos vinculados a este registro.');

        $this->assertNotNull(Cliente::find($cliente->id));
        $this->assertSame(['revisao_com_erro'], AuditLog::query()->pluck('resultado')->all());
    }

    public function test_dependencia_criada_depois_do_revisar_ainda_bloqueia_no_segurar(): void
    {
        $cliente = $this->cliente();
        $token = $this->propor(['id' => $cliente->id], 'excluir_cliente');
        $this->lancamento(['cliente_id' => $cliente->id]);

        $this->confirmar($token)
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Não é possível excluir: existem lançamentos vinculados a este registro.');

        $this->assertNotNull(Cliente::find($cliente->id));
        $this->assertSame('recusado', AuditLog::query()->latest('id')->first()->resultado);
    }

    public function test_excluir_e_so_para_admin(): void
    {
        $this->logarComo('operador');
        $cliente = $this->cliente();

        $this->comando('excluir_cliente', ['id' => $cliente->id])->assertStatus(403)->assertJsonPath('tipo', 'negado');

        $this->assertNotNull(Cliente::find($cliente->id));
        $log = AuditLog::query()->sole();
        $this->assertSame(['negado', false], [$log->resultado, $log->permitido]);
    }

    // ---- Restaurar (RF10) ----

    public function test_restaurar_com_a_senha_do_admin_restaura_e_registra_quem_autorizou(): void
    {
        $supervisor = $this->supervisor();
        $operador = $this->logarComo('operador');
        $cliente = $this->naLixeira($this->cliente());

        $resposta = $this->comando('restaurar_cliente', ['id' => $cliente->id])
            ->assertOk()
            ->assertJsonPath('risco', 'escrita')
            ->assertJsonPath('formulario.titulo', "Restaurar cliente #{$cliente->id}")
            ->assertJsonPath('formulario.reautenticar', true);
        $token = $this->revisar($resposta->json('proposta'))->assertOk()->json('token');

        $this->confirmarComSenha($token)
            ->assertOk()
            ->assertJsonPath('mensagem', 'Cliente restaurado da lixeira.');

        $this->assertNotNull(Cliente::find($cliente->id));
        $log = AuditLog::query()->where('resultado', 'sucesso')->sole();
        $this->assertSame(['restaurar', $operador->id, $supervisor->id], [$log->acao, $log->user_id, $log->autorizado_por],
            'a auditoria registra quem estava logado E qual admin autorizou');
    }

    public function test_restaurar_com_senha_errada_nao_restaura_e_encerra_a_proposta(): void
    {
        $this->supervisor();
        $this->logarComo('operador');
        $cliente = $this->naLixeira($this->cliente());
        $token = $this->propor(['id' => $cliente->id], 'restaurar_cliente');

        $this->confirmarComSenha($token, senha: 'errada')
            ->assertStatus(403)
            ->assertJsonPath('codigo', 'AI_REAUTH_FAILED')
            ->assertJsonPath('mensagem', 'E-mail ou senha de administrador inválidos. Nada foi gravado.');

        $this->assertNull(Cliente::find($cliente->id), 'continua na lixeira');
        $log = AuditLog::query()->sole();
        $this->assertSame(['negado', false, null], [$log->resultado, $log->permitido, $log->autorizado_por]);
        $this->assertSame('Senha de administrador não conferiu.', $log->mensagem);
        // A proposta terminou: o mesmo token não serve para tentar de novo.
        $this->confirmarComSenha($token)->assertStatus(422)->assertJsonPath('mensagem', 'Confirmação inválida ou já utilizada.');
        $this->assertNull(Cliente::find($cliente->id));
    }

    public function test_restaurar_sem_reautenticacao_e_recusado(): void
    {
        $cliente = $this->naLixeira($this->cliente());
        $token = $this->propor(['id' => $cliente->id], 'restaurar_cliente');

        $this->confirmar($token) // sem e-mail e senha no corpo
            ->assertStatus(403)
            ->assertJsonPath('codigo', 'AI_REAUTH_REQUIRED')
            ->assertJsonPath('mensagem', 'Informe o e-mail e a senha de um administrador. Nada foi gravado.');

        $this->assertNull(Cliente::find($cliente->id));
        $this->assertSame('negado', AuditLog::query()->sole()->resultado);
    }

    public function test_so_admin_do_mesmo_tenant_autoriza(): void
    {
        $this->supervisor(2, 'admin.t2@sentinel.local');
        $this->supervisor(1, 'operador.chefe@sentinel.local', 'operador');
        $cliente = $this->naLixeira($this->cliente());

        foreach (['admin.t2@sentinel.local', 'operador.chefe@sentinel.local', 'ninguem@sentinel.local'] as $email) {
            $token = $this->propor(['id' => $cliente->id], 'restaurar_cliente');
            $this->confirmarComSenha($token, $email)->assertStatus(403)->assertJsonPath('codigo', 'AI_REAUTH_FAILED');
        }

        $this->assertNull(Cliente::find($cliente->id));
    }

    public function test_executor_recusa_sem_reautenticacao_mesmo_chamado_direto(): void
    {
        $cliente = $this->naLixeira($this->cliente());

        foreach (['restaurar_cliente', 'excluir_definitivo_cliente'] as $tool) {
            $resultado = app(AiAgentService::class)->executarConfirmada($tool, ['id' => $cliente->id], []);

            $this->assertSame(['erro', 'AI_REAUTH_REQUIRED', 403], [$resultado['tipo'], $resultado['codigo'], $resultado['http_status']], $tool);
        }

        $this->assertNull(Cliente::find($cliente->id));
        $this->assertTrue(Cliente::withTrashed()->whereKey($cliente->id)->exists());
        $this->assertSame([false, false], AuditLog::query()->pluck('permitido')->all());
    }

    public function test_restaurar_com_o_pai_na_lixeira_recusa_dizendo_qual_e_o_pai(): void
    {
        $cliente = $this->cliente();
        $lancamento = $this->naLixeira($this->lancamento(['cliente_id' => $cliente->id]));
        $this->naLixeira($cliente);
        $proposta = $this->comando('restaurar_lancamento', ['id' => $lancamento->id])->json('proposta');

        $this->revisar($proposta)
            ->assertStatus(422)
            ->assertJsonPath('mensagem', "Não é possível restaurar: o cliente #{$cliente->id} (Mercado Central) está na lixeira. Restaure primeiro o cliente.");

        $this->assertNull(Lancamento::find($lancamento->id));
    }

    public function test_pai_que_foi_para_a_lixeira_depois_do_revisar_ainda_recusa(): void
    {
        $this->supervisor();
        $categoria = CategoriaLancamento::create(['nome' => 'Aluguel', 'tipo' => 'despesa', 'tenant_id' => 1]);
        $lancamento = $this->naLixeira($this->lancamento(['categoria_lancamento_id' => $categoria->id]));
        $token = $this->propor(['id' => $lancamento->id], 'restaurar_lancamento');
        $this->arquivado($categoria);

        $this->confirmarComSenha($token)
            ->assertStatus(422)
            ->assertJsonPath('mensagem', "Não é possível restaurar: a categoria #{$categoria->id} (Aluguel) está arquivada.");

        $this->assertNull(Lancamento::find($lancamento->id));
    }

    public function test_restaurar_procura_so_na_lixeira_do_tenant(): void
    {
        $ativo = $this->cliente('Ativo');
        $alheio = $this->naLixeira($this->cliente('Alheio', tenant: 2));
        $arquivado = $this->arquivado($this->cliente('Arquivado'));

        foreach ([$ativo, $alheio, $arquivado] as $cliente) {
            $this->comando('restaurar_cliente', ['id' => $cliente->id])
                ->assertStatus(422)
                ->assertJsonPath('mensagem', 'Não encontrei o cliente com esse número na lixeira.');
        }
    }

    public function test_lista_de_escolha_da_lixeira_traz_a_acao_em_cada_linha(): void
    {
        $um = $this->naLixeira($this->cliente('Mercado Um'));
        $dois = $this->naLixeira($this->cliente('Mercado Dois'));

        $resposta = $this->comando('restaurar_cliente', ['busca' => 'Mercado'])
            ->assertOk()
            ->assertJsonPath('tipo', 'resultado_leitura')
            ->assertJsonMissingPath('editar');

        $this->assertEqualsCanonicalizing([$um->id, $dois->id], array_column($resposta->json('resultado'), 'id'));
        $this->assertSame([['tool' => 'restaurar_cliente', 'rotulo' => 'Restaurar']], array_values(array_unique(array_column($resposta->json('resultado'), 'acao'), SORT_REGULAR)));
    }

    public function test_formulario_sem_ia_abre_o_card_de_restaurar(): void
    {
        $cliente = $this->naLixeira($this->cliente());

        $this->postJson('/agente/formulario', ['tool' => 'restaurar_cliente', 'id' => $cliente->id])
            ->assertOk()
            ->assertJsonPath('tipo', 'confirmacao_pendente')
            ->assertJsonPath('formulario.reautenticar', true)
            ->assertJsonPath('formulario.resumo.0', ['rotulo' => 'Nome', 'valor' => 'Mercado Central']);
    }

    // ---- Excluir de vez (RF12) ----

    public function test_excluir_de_vez_e_negado_a_quem_nao_e_admin(): void
    {
        $cliente = $this->arquivado($this->cliente());

        foreach (['operador', 'leitura'] as $papel) {
            $this->logarComo($papel);
            $this->comando('excluir_definitivo_cliente', ['id' => $cliente->id])->assertStatus(403);
        }

        $this->assertTrue(Cliente::withTrashed()->whereKey($cliente->id)->exists());
    }

    public function test_excluir_de_vez_so_vale_para_arquivado_e_pede_a_senha(): void
    {
        $supervisor = $this->supervisor();
        $naLixeira = $this->naLixeira($this->cliente('Recente'));
        $arquivado = $this->arquivado($this->cliente('Antigo'));

        $this->comando('excluir_definitivo_cliente', ['id' => $naLixeira->id])
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Não encontrei o cliente com esse número entre os arquivados.');

        $resposta = $this->comando('excluir_definitivo_cliente', ['id' => $arquivado->id])
            ->assertOk()
            ->assertJsonPath('risco', 'exclusao')
            ->assertJsonPath('formulario.titulo', "Excluir de vez: cliente #{$arquivado->id}")
            ->assertJsonPath('formulario.reautenticar', true);
        $token = $this->revisar($resposta->json('proposta'))->assertOk()->json('token');
        $this->confirmarComSenha($token)->assertOk()->assertJsonPath('mensagem', 'Cliente excluído de vez.');

        $this->assertFalse(DB::table('clientes')->where('id', $arquivado->id)->exists(), 'RF12: saiu fisicamente');
        $this->assertTrue(Cliente::withTrashed()->whereKey($naLixeira->id)->exists());
        $log = AuditLog::query()->where('resultado', 'sucesso')->sole();
        $this->assertSame(['excluir_definitivo', $arquivado->id, $supervisor->id], [$log->acao, $log->entidade_id, $log->autorizado_por]);
    }

    public function test_dependente_mesmo_arquivado_bloqueia_a_exclusao_de_vez(): void
    {
        $cliente = $this->cliente();
        $this->arquivado($this->lancamento(['cliente_id' => $cliente->id]));
        $this->arquivado($cliente);
        $proposta = $this->comando('excluir_definitivo_cliente', ['id' => $cliente->id])->json('proposta');

        $this->revisar($proposta)
            ->assertStatus(422)
            ->assertJsonPath('mensagem', 'Não é possível excluir de vez: existem lançamentos vinculados a este registro, mesmo na lixeira ou arquivados.');

        $this->assertTrue(DB::table('clientes')->where('id', $cliente->id)->exists());
    }

    public function test_service_nao_exclui_de_vez_sem_senha_nem_o_que_esta_so_na_lixeira(): void
    {
        $nota = NotaFiscal::create(['numero' => 'NF-1', 'tipo' => 'entrada', 'valor' => 1, 'data_emissao' => '2026-01-01', 'tenant_id' => 1]);
        $servico = app(\App\Services\NotaFiscalService::class);
        $this->naLixeira($nota);

        $this->assertSame('Só dá para excluir de vez um registro arquivado.', $servico->excluirDefinitivo($nota->id, 1, true)['motivo']);
        $nota->arquivar();
        $this->assertFalse($servico->excluirDefinitivo($nota->id, 1, false)['excluido']);
        $this->assertTrue(DB::table('notas_fiscais')->where('id', $nota->id)->exists());
    }

    public function test_exclusao_fisica_nunca_e_automatica(): void
    {
        $arquivado = $this->arquivado($this->cliente('Antigo'));
        $vencido = $this->naLixeira($this->cliente('Vencido'));
        DB::table('clientes')->where('id', $vencido->id)->update(['deleted_at' => now()->subDays((int) config('sentinel.retencao_lixeira_dias') + 1)]);

        Artisan::call('sentinel:arquivar-expirados');
        Artisan::call('sentinel:arquivar-expirados');

        $this->assertSame(2, DB::table('clientes')->count(), 'a rotina só arquiva; nada sai fisicamente');
        $this->assertNotNull(Cliente::withTrashed()->find($arquivado->id)->arquivado_em);

        // Em todo o app, o único forceDelete é o da exclusão manual (ExclusaoSegura::excluirDeVez), e nenhum comando a chama.
        $comForceDelete = collect(File::allFiles(app_path()))
            ->filter(fn ($arquivo) => str_contains($arquivo->getContents(), 'forceDelete('))
            ->map(fn ($arquivo) => $arquivo->getRelativePathname())->values()->all();
        $this->assertSame(['Services'.DIRECTORY_SEPARATOR.'Concerns'.DIRECTORY_SEPARATOR.'ExclusaoSegura.php'], $comForceDelete);
        foreach (File::allFiles(app_path('Console')) as $comando) {
            $this->assertDoesNotMatchRegularExpression('/excluirDefinitivo|excluirDeVez|forceDelete/', $comando->getContents(), $comando->getFilename());
        }
    }

    // ---- consultar_lixeira ----

    public function test_consultar_lixeira_lista_so_o_tenant_com_a_acao_que_o_papel_pode(): void
    {
        $naLixeira = $this->naLixeira($this->cliente('Na lixeira'));
        $arquivado = $this->arquivado($this->lancamento(['descricao' => 'Lançamento antigo']));
        $this->naLixeira($this->cliente('De outro tenant', tenant: 2));
        $this->cliente('Ativo');

        $this->logarComo('operador');
        $linhas = collect($this->comando('consultar_lixeira')->assertOk()->assertJsonPath('tipo', 'resultado_leitura')
            ->assertJsonPath('colunas', ['tipo', 'id', 'nome', 'situacao', 'excluido_em', 'restaurar_ate'])
            ->json('resultado'))->keyBy('nome');

        $this->assertEqualsCanonicalizing(['Na lixeira', 'Lançamento antigo'], $linhas->keys()->all());
        $this->assertSame(['Cliente', $naLixeira->id, 'Na lixeira'], [$linhas['Na lixeira']['tipo'], $linhas['Na lixeira']['id'], $linhas['Na lixeira']['situacao']]);
        $this->assertSame(['tool' => 'restaurar_cliente', 'rotulo' => 'Restaurar'], $linhas['Na lixeira']['acao']);
        $this->assertSame('Arquivado', $linhas['Lançamento antigo']['situacao']);
        $this->assertArrayNotHasKey('acao', $linhas['Lançamento antigo'], 'operador não exclui de vez');

        $this->logarComo('admin');
        $arquivadoParaAdmin = collect($this->comando('consultar_lixeira', ['situacao' => 'arquivado'])->json('resultado'))->sole();
        $this->assertSame(['tool' => 'excluir_definitivo_lancamento', 'rotulo' => 'Excluir de vez'], $arquivadoParaAdmin['acao']);
        $this->assertSame($arquivado->id, $arquivadoParaAdmin['id']);

        $this->assertSame([], $this->comando('consultar_lixeira', ['tipo' => 'fornecedor'])->json('resultado'));
        $this->assertSame([], $this->comando('consultar_lixeira', ['tipo' => 'tudo'])->json('resultado'), 'filtro fora da lista não vira consulta ampla');
    }

    public function test_consultar_lixeira_e_negada_ao_papel_leitura(): void
    {
        $this->logarComo('leitura');

        $this->comando('consultar_lixeira')->assertStatus(403);
    }
}
