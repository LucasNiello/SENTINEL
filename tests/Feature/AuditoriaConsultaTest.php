<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\User;
use App\Services\AuditoriaService;
use App\Services\ConfirmacoesPendentes;
use App\Services\ConsultaAuditoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Etapa A1 — consulta da auditoria: só admin, só o tenant do usuário, filtros,
 * paginação, desfechos separados de revisao_com_erro e detalhe mascarado.
 */
class AuditoriaConsultaTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->logarComo('admin', 1);
    }

    /** Registro gravado direto na tabela (sem passar pela máscara do registrar(), como uma linha antiga). */
    private function registro(array $extra = []): AuditLog
    {
        return AuditLog::create([
            'user_id' => $this->admin->id, 'tool' => 'criar_cliente', 'acao' => 'criar', 'entidade_tipo' => 'Cliente',
            'entidade_id' => null, 'parametros' => [], 'resultado' => 'sucesso', 'mensagem' => null,
            'papel' => 'admin', 'permitido' => true, 'tenant_id' => 1, ...$extra,
        ]);
    }

    private function ids($resposta): array
    {
        return array_column($resposta->json('registros'), 'id');
    }

    // ---- RBAC e tenant ----

    public function test_leitura_e_operador_recebem_403_na_lista_e_no_detalhe_e_a_tentativa_e_auditada(): void
    {
        $alvo = $this->registro();

        foreach (['leitura', 'operador'] as $papel) {
            $usuario = $this->logarComo($papel, 1);

            $this->getJson('/auditoria/registros')->assertForbidden()
                ->assertJsonPath('tipo', 'negado')->assertJsonPath('codigo', 'AI_FORBIDDEN')
                ->assertJsonMissingPath('registros');
            $this->getJson("/auditoria/registros/{$alvo->id}")->assertForbidden()->assertJsonMissingPath('proposto');

            $negados = AuditLog::query()->where('user_id', $usuario->id)->get();
            $this->assertCount(2, $negados, $papel);
            foreach ($negados as $negado) {
                $this->assertSame([ConsultaAuditoria::TOOL, 'negado', false, $papel, 1, 'manual'],
                    [$negado->tool, $negado->resultado, $negado->permitido, $negado->papel, $negado->tenant_id, $negado->origem]);
            }
        }
    }

    public function test_exige_login(): void
    {
        auth()->logout();

        $this->getJson('/auditoria/registros')->assertUnauthorized();
        $this->getJson('/auditoria/registros/1')->assertUnauthorized();
    }

    public function test_registro_de_outro_tenant_nao_aparece_na_lista_e_o_detalhe_e_404(): void
    {
        $meu = $this->registro();
        $outro = $this->registro(['tenant_id' => 2, 'parametros' => ['nome' => 'Segredo do tenant 2']]);

        $resposta = $this->getJson('/auditoria/registros')->assertOk();
        $this->assertSame([$meu->id], $this->ids($resposta));
        $this->assertSame(1, $resposta->json('total'));

        $this->getJson("/auditoria/registros/{$outro->id}")->assertNotFound()
            ->assertJsonPath('codigo', 'AI_NOT_FOUND')->assertDontSee('Segredo do tenant 2');
        $this->getJson('/auditoria/registros/999999')->assertNotFound()->assertJsonPath('codigo', 'AI_NOT_FOUND');
    }

    public function test_admin_de_outro_tenant_nao_ve_os_registros_deste(): void
    {
        $meu = $this->registro();
        $this->logarComo('admin', 2);

        $this->getJson('/auditoria/registros')->assertOk()->assertJsonPath('total', 0);
        $this->getJson("/auditoria/registros/{$meu->id}")->assertNotFound();
    }

    // ---- filtros e paginação ----

    public function test_filtros_por_periodo_usuario_tool_e_resultado(): void
    {
        $colega = User::factory()->papel('operador')->doTenant(1)->create();

        $this->travelTo('2026-10-01 10:00:00');
        $antigo = $this->registro();
        $this->travelTo('2026-10-15 23:59:00');
        $doColega = $this->registro(['user_id' => $colega->id, 'tool' => 'atualizar_cliente', 'resultado' => 'cancelado']);
        $this->travelTo('2026-10-20 08:00:00');
        $negado = $this->registro(['resultado' => 'negado', 'permitido' => false]);

        $this->assertSame([$doColega->id], $this->ids($this->getJson('/auditoria/registros?de=2026-10-15&ate=2026-10-15')->assertOk()), 'o dia final entra inteiro');
        $this->assertSame([$negado->id, $doColega->id], $this->ids($this->getJson('/auditoria/registros?de=2026-10-02')));
        $this->assertSame([$antigo->id], $this->ids($this->getJson('/auditoria/registros?ate=2026-10-01')));
        $this->assertSame([$doColega->id], $this->ids($this->getJson("/auditoria/registros?usuario={$colega->id}")));
        $this->assertSame([$doColega->id], $this->ids($this->getJson('/auditoria/registros?tool=atualizar_cliente')));
        $this->assertSame([$negado->id], $this->ids($this->getJson('/auditoria/registros?resultado=negado')));
        $this->assertSame([], $this->ids($this->getJson('/auditoria/registros?tool=atualizar_cliente&resultado=negado')), 'os filtros somam');

        $linha = $this->getJson('/auditoria/registros?tool=atualizar_cliente')->json('registros.0');
        $this->assertSame(['id', 'data', 'usuario', 'papel', 'tool', 'acao', 'entidade_tipo', 'entidade_id', 'resultado', 'final', 'permitido', 'origem'], array_keys($linha));
        $this->assertSame($colega->name, $linha['usuario']);
        $this->assertSame('2026-10-15 23:59:00', $linha['data']);
        $this->assertArrayNotHasKey('parametros', $linha, 'a lista não traz parâmetros');
    }

    public function test_grupo_separa_os_desfechos_da_revisao_com_erro(): void
    {
        $desfechos = [];
        foreach (AuditoriaService::RESULTADOS_FINAIS as $resultado) {
            $desfechos[] = $this->registro(['resultado' => $resultado])->id;
        }
        $revisao = $this->registro(['resultado' => AuditoriaService::REVISAO_COM_ERRO])->id;

        $finais = $this->getJson('/auditoria/registros?grupo=desfechos')->assertOk();
        $this->assertEqualsCanonicalizing($desfechos, $this->ids($finais));
        $this->assertTrue(collect($finais->json('registros'))->every(fn ($linha) => $linha['final'] === true));

        $revisoes = $this->getJson('/auditoria/registros?grupo=revisoes')->assertOk();
        $this->assertSame([$revisao], $this->ids($revisoes));
        $this->assertFalse($revisoes->json('registros.0.final'));

        $this->assertSame(count($desfechos) + 1, $this->getJson('/auditoria/registros')->json('total'), 'sem grupo: todos');
        $this->assertSame([$revisao], $this->ids($this->getJson('/auditoria/registros?resultado=revisao_com_erro')));
    }

    public function test_paginacao_de_25_em_25_dos_mais_recentes_para_os_mais_antigos(): void
    {
        $ids = [];
        for ($i = 0; $i < 30; $i++) {
            $this->travelTo(now()->addMinute());
            $ids[] = $this->registro()->id;
        }
        $recentesPrimeiro = array_reverse($ids);

        $primeira = $this->getJson('/auditoria/registros')->assertOk()
            ->assertJsonPath('pagina', 1)->assertJsonPath('paginas', 2)->assertJsonPath('total', 30)->assertJsonPath('por_pagina', 25);
        $this->assertSame(array_slice($recentesPrimeiro, 0, 25), $this->ids($primeira));

        $segunda = $this->getJson('/auditoria/registros?pagina=2')->assertOk()->assertJsonPath('pagina', 2);
        $this->assertSame(array_slice($recentesPrimeiro, 25), $this->ids($segunda));

        $this->assertSame([], $this->ids($this->getJson('/auditoria/registros?pagina=3')->assertOk()));
    }

    public function test_filtro_invalido_responde_422_no_contrato_sem_500(): void
    {
        foreach ([
            'de=01/10/2026', 'ate=2026-13-40', 'de=2026-10-10&ate=2026-10-01', 'usuario=abc', 'pagina=0',
            'resultado=qualquer', 'grupo=todos', 'tool=a%20b', 'de[]=x', 'tool[]=x', 'usuario[]=1',
            'de[]=x&ate=2026-10-01', 'de[]=2026-10-01&ate=2026-10-02', 'de=2026-10-01&ate[]=x',
        ] as $consulta) {
            $this->getJson("/auditoria/registros?{$consulta}")->assertStatus(422)
                ->assertJsonPath('tipo', 'erro')->assertJsonPath('codigo', 'AI_VALIDATION_ERROR');
        }

        $this->getJson('/auditoria/registros?ate=2026-10-01')->assertOk();
        $this->getJson('/auditoria/registros?de=&ate=&tool=')->assertOk();
    }

    // ---- detalhe ----

    public function test_detalhe_mostra_proposto_e_confirmado_mascarados_mesmo_em_registro_antigo_sem_mascara(): void
    {
        $autorizou = User::factory()->papel('admin')->doTenant(1)->create();
        $log = $this->registro([
            'tool' => 'atualizar_funcionario', 'acao' => 'atualizar', 'entidade_tipo' => 'Funcionario', 'entidade_id' => 7,
            'autorizado_por' => $autorizou->id, 'origem' => 'agente', 'tempo_ia_ms' => 812, 'mensagem' => 'ok',
            'parametros_propostos' => ['busca' => 'ana', 'cpf' => '123.456.789-01', 'salario' => 5000],
            'parametros' => ['id' => 7, 'nome' => 'Ana', 'cpf' => '12345678901', 'salario' => '5200.00', 'documento' => '98765432100', 'tenant_id' => 1],
        ]);

        $resposta = $this->getJson("/auditoria/registros/{$log->id}")->assertOk()
            ->assertJsonPath('proposto', ['busca' => 'ana', 'cpf' => '***.***.789-01', 'salario' => AuditoriaService::REDIGIDO])
            ->assertJsonPath('confirmado', ['id' => 7, 'nome' => 'Ana', 'cpf' => '***.***.789-01', 'salario' => AuditoriaService::REDIGIDO, 'documento' => '***.***.321-00'])
            ->assertJsonPath('autorizado_por', $autorizou->name)
            ->assertJsonPath('tempo_ia_ms', 812)
            ->assertJsonPath('entidade_id', 7);

        foreach (['123.456.789-01', '12345678901', '5200', '5000', '98765432100'] as $bruto) {
            $this->assertStringNotContainsString($bruto, $resposta->getContent(), $bruto);
        }
    }

    public function test_detalhe_mascara_tambem_campos_aninhados_e_em_maiusculas(): void
    {
        $log = $this->registro([
            'parametros_propostos' => ['dados' => ['cpf' => '123.456.789-01', 'lista' => [['Salario' => 9000]],
                'CPF' => ['11122233344'], 'salario' => ['valor' => 7777]]],
            'parametros' => ['CPF' => '12345678901', 'Documento' => '98765432100', 'nome' => 'Ana'],
        ]);

        $resposta = $this->getJson("/auditoria/registros/{$log->id}")->assertOk()
            ->assertJsonPath('proposto.dados.cpf', '***.***.789-01')
            ->assertJsonPath('proposto.dados.lista.0.Salario', AuditoriaService::REDIGIDO)
            ->assertJsonPath('proposto.dados.CPF', AuditoriaService::REDIGIDO, 'chave sensível com valor array não desce')
            ->assertJsonPath('proposto.dados.salario', AuditoriaService::REDIGIDO)
            ->assertJsonPath('confirmado', ['CPF' => '***.***.789-01', 'Documento' => '***.***.321-00', 'nome' => 'Ana']);

        foreach (['123.456.789-01', '12345678901', '9000', '98765432100', '11122233344', '7777'] as $bruto) {
            $this->assertStringNotContainsString($bruto, $resposta->getContent(), $bruto);
        }
    }

    public function test_detalhe_de_tool_fora_do_catalogo_nao_exibe_os_argumentos_da_ia(): void
    {
        $log = $this->registro(['tool' => 'apagar_tudo', 'acao' => 'desconhecida', 'resultado' => 'negado', 'permitido' => false,
            'parametros' => ['qualquer_coisa' => 'cpf 123.456.789-01'], 'parametros_propostos' => ['outra' => 'salário 9876']]);

        $this->getJson("/auditoria/registros/{$log->id}")->assertOk()
            ->assertJsonPath('confirmado', AuditoriaService::REDIGIDO)
            ->assertJsonPath('proposto', AuditoriaService::REDIGIDO)
            ->assertDontSee('123.456.789-01')->assertDontSee('9876');
    }

    public function test_consulta_tem_limite_de_taxa_proprio_no_contrato_de_erro(): void
    {
        $this->prepararAgente();

        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/auditoria/registros')->assertOk();
        }

        $this->getJson('/auditoria/registros')->assertStatus(429)->assertJsonPath('codigo', 'AI_RATE_LIMIT');
        $this->getJson('/auditoria/registros/1')->assertStatus(429);

        // Balde separado: o card do agente continua respondendo.
        $this->postJson('/agente/formulario', ['tool' => 'criar_cliente'])->assertOk();
    }

    public function test_navegar_na_auditoria_tambem_audita_as_propostas_vencidas(): void
    {
        $this->prepararAgente();
        $this->propor(['nome' => 'Esquecido'], 'criar_cliente');
        $this->travel(ConfirmacoesPendentes::VALIDADE_MAXIMA_S + 1)->seconds();

        $this->getJson('/auditoria/registros')->assertOk();
        $this->assertSame('criar_cliente', AuditLog::query()->where('resultado', 'expirado')->sole()->tool);

        // O detalhe também varre.
        $this->propor(['nome' => 'Outro esquecido'], 'criar_cliente');
        $this->travel(ConfirmacoesPendentes::VALIDADE_MAXIMA_S + 1)->seconds();
        $this->getJson('/auditoria/registros/999999')->assertNotFound();
        $this->assertSame(2, AuditLog::query()->where('resultado', 'expirado')->count());
    }

    public function test_detalhe_sem_proposto_vem_nulo(): void
    {
        $log = $this->registro(['parametros' => ['nome' => 'X', 'tenant_id' => 1]]);

        $this->getJson("/auditoria/registros/{$log->id}")->assertOk()
            ->assertJsonPath('proposto', null)
            ->assertJsonPath('confirmado', ['nome' => 'X'])
            ->assertJsonPath('autorizado_por', null);
    }

    // ---- entidade_id nos desfechos sem execução ----

    public function test_cancelar_e_expirar_uma_atualizacao_gravam_qual_registro_era_o_alvo(): void
    {
        $this->prepararAgente();
        $cliente = Cliente::create(['nome' => 'Mercado Central', 'tenant_id' => 1]);

        // Cancelado (com o token de segurar).
        $token = $this->propor(['id' => $cliente->id, 'email' => 'a@exemplo.com'], 'atualizar_cliente');
        $this->postJson('/agente/cancelar', ['token' => $token])->assertOk()->assertJsonPath('tipo', 'cancelado');

        // Expirado pelo /agente/confirmar fora do prazo.
        $token = $this->propor(['id' => $cliente->id, 'email' => 'b@exemplo.com'], 'atualizar_cliente');
        $this->travel(ConfirmacoesPendentes::VALIDADE_MAXIMA_S + 1)->seconds();
        $this->postJson('/agente/confirmar', ['token' => $token])->assertStatus(422);

        // Expirado pela varredura (rascunho esquecido além dos 5 min), aberto sem IA.
        $proposta = $this->postJson('/agente/formulario', ['tool' => 'atualizar_cliente', 'id' => $cliente->id])->assertOk()->json('proposta');
        $this->travel(ConfirmacoesPendentes::PRAZO_RASCUNHO_S + 1)->seconds();
        $this->get('/agente')->assertOk();
        $this->assertNull(session("confirmacoes.{$proposta}"));

        $desfechos = AuditLog::query()->whereIn('resultado', ['cancelado', 'expirado'])->orderBy('id')->get();
        $this->assertSame(['cancelado', 'expirado', 'expirado'], $desfechos->pluck('resultado')->all());
        $this->assertSame([$cliente->id, $cliente->id, $cliente->id], $desfechos->pluck('entidade_id')->all());
    }

    public function test_cancelar_um_cadastro_nao_inventa_entidade_id(): void
    {
        $this->prepararAgente();

        $token = $this->propor(['nome' => 'Novo'], 'criar_cliente');
        $this->postJson('/agente/cancelar', ['token' => $token])->assertOk();

        $this->assertNull(AuditLog::query()->where('resultado', 'cancelado')->sole()->entidade_id);
    }
}
