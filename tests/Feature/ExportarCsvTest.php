<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\Lancamento;
use App\Models\User;
use App\Services\ConsultasExportaveis;
use App\Services\PlanilhaCsv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Etapa A3 (RF02) — exportar em CSV uma tabela de consulta do agente: o servidor
 * reexecuta a consulta guardada na sessão (RBAC, tenant e máscara de agora), com
 * anti-fórmula, UTF-8 com BOM, limite de taxa e auditoria "exportar".
 */
class ExportarCsvTest extends TestCase
{
    use RefreshDatabase, SimulaAgente;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararAgente();
        $this->usuario = $this->logarComo('leitura', 1);
    }

    /** Faz a consulta pelo agente e devolve o id de exportação. */
    private function consultar(string $tool = 'consultar_clientes', array $argumentos = []): string
    {
        $resposta = $this->comando($tool, $argumentos)->assertOk()->assertJsonPath('tipo', 'resultado_leitura');
        $this->assertArrayNotHasKey('consulta', $resposta->json(), 'os argumentos não voltam ao front');

        return (string) $resposta->json('exportar');
    }

    private function exportar(string $consulta, array $extra = [])
    {
        return $this->post('/agente/exportar', ['consulta' => $consulta, ...$extra], ['Accept' => 'application/json']);
    }

    /** @return array<int, array<int, string>> linhas do CSV (sem o BOM) */
    private function linhas(string $csv): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'UTF-8 com BOM');

        return array_map(fn (string $linha) => str_getcsv($linha, ';', '"', ''), array_values(array_filter(explode("\n", substr($csv, 3)))));
    }

    public function test_exporta_a_tabela_com_a_mascara_da_tela_bom_e_auditoria(): void
    {
        Cliente::create(['nome' => 'José da Silva', 'documento' => '123.456.789-01', 'email' => 'jose@exemplo.com', 'tenant_id' => 1]);
        Cliente::create(['nome' => 'Mercado Ação', 'documento' => '12.345.678/0001-90', 'tenant_id' => 1]);

        $resposta = $this->exportar($this->consultar())->assertOk();
        $this->assertStringStartsWith('text/csv', $resposta->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="clientes-', $resposta->headers->get('Content-Disposition'));

        $csv = $resposta->getContent();
        $this->assertStringNotContainsString('123.456.789-01', $csv);
        $this->assertStringNotContainsString('12345678901', $csv);

        $linhas = $this->linhas($csv);
        $this->assertSame(['id', 'nome', 'documento', 'email', 'telefone'], $linhas[0]);
        $this->assertSame('***.***.789-01', $linhas[1][2], 'CPF mascarado como na tabela');
        $this->assertSame('José da Silva', $linhas[1][1], 'acento em UTF-8');
        $this->assertSame('12.345.678/0001-90', $linhas[2][2], 'CNPJ visível como na tabela');
        $this->assertCount(3, $linhas);

        $log = AuditLog::query()->where('acao', 'exportar')->sole();
        $this->assertSame(['consultar_clientes', 'sucesso', true, 'agente', $this->usuario->id, 1],
            [$log->tool, $log->resultado, $log->permitido, $log->origem, $log->user_id, $log->tenant_id]);
        $this->assertSame('2 linha(s) exportada(s) em CSV.', $log->mensagem);
    }

    public function test_celula_que_comeca_com_formula_ganha_apostrofo(): void
    {
        $esperados = [
            '=HYPERLINK("http://x","clique")' => '\'=HYPERLINK("http://x","clique")', '+5511999' => "'+5511999", '-2+3' => "'-2+3",
            '@SOMA(A1)' => "'@SOMA(A1)", "\t=1" => "' =1", "\r=1" => "' =1", ' =1' => "' =1",
            // Planilha com outro separador: o valor se partiria na vírgula ou na quebra de linha.
            'x,=1+1' => "x,'=1+1", 'x; @A1' => "x; '@A1", "x\n=1+1\n" => 'x =1+1 ', 'Normal = ok' => 'Normal = ok',
            // Aspas entre o separador e o = (a aspa fecharia a célula e o resto viraria fórmula).
            'x,"=1+1,' => 'x,"\'=1+1,', '"=1' => '\'"=1', "\u{00A0}=1" => "'\u{00A0}=1",
        ];
        foreach (array_keys($esperados) as $nome) {
            Cliente::create(['nome' => $nome, 'tenant_id' => 1]);
        }

        $csv = $this->exportar($this->consultar())->assertOk()->getContent();
        $nomes = array_column(array_slice($this->linhas($csv), 1), 1);

        foreach ($esperados as $nome => $esperado) {
            $this->assertContains($esperado, $nomes, 'célula de '.json_encode($nome));
        }
        $this->assertCount(count($esperados) + 1, explode("\n", trim(substr($csv, 3))), 'nenhuma quebra de linha dentro de célula');
    }

    public function test_celula_regras_de_numero_lista_e_nulo(): void
    {
        $this->assertSame('-150.00', PlanilhaCsv::celula('-150.00'), 'número puro não é fórmula');
        $this->assertSame('-150', PlanilhaCsv::celula(-150));
        $this->assertSame("'-150.00+1", PlanilhaCsv::celula('-150.00+1'));
        $this->assertSame("'-1e5", PlanilhaCsv::celula('-1e5'));
        $this->assertSame("'-150.00 ", PlanilhaCsv::celula("-150.00\n"), 'não é número puro; a quebra de linha vira espaço');
        $this->assertSame('', PlanilhaCsv::celula(null));
        $this->assertSame('', PlanilhaCsv::celula(['tool' => 'x']), 'lista não vira célula');
        $this->assertSame("'\x0B=1", PlanilhaCsv::celula("\x0B=1"));
        $this->assertSame("'\f=1", PlanilhaCsv::celula("\f=1"));
        $this->assertSame('sim', PlanilhaCsv::celula(true));
        $this->assertSame("?=1,'=2", PlanilhaCsv::celula("\xFF=1,=2"), 'UTF-8 inválido é saneado, não apaga a célula');
    }

    public function test_funcionarios_sai_sem_cpf_nem_salario(): void
    {
        \App\Models\Funcionario::create(['nome' => 'Ana', 'cpf' => '111.222.333-44', 'salario' => 4567.89, 'cargo' => 'Analista', 'data_admissao' => '2026-01-10', 'tenant_id' => 1]);

        $csv = $this->exportar($this->consultar('consultar_funcionarios'))->assertOk()->getContent();

        $this->assertSame(['id', 'nome', 'cargo', 'data_admissao', 'data_demissao'], $this->linhas($csv)[0]);
        foreach (['111.222.333-44', '11122233344', '333-44', '4567'] as $bruto) {
            $this->assertStringNotContainsString($bruto, $csv, $bruto);
        }
    }

    public function test_consulta_negada_nao_recebe_id_de_exportacao(): void
    {
        // Leitura não pode consultar a lixeira (mínimo operador).
        $this->comando('consultar_lixeira')->assertForbidden()->assertJsonMissingPath('exportar');
    }

    public function test_reexecuta_no_servidor_e_ignora_o_que_o_front_manda(): void
    {
        Cliente::create(['nome' => 'Primeiro', 'tenant_id' => 1]);
        $consulta = $this->consultar('consultar_clientes', ['nome' => 'Pri']);
        Cliente::create(['nome' => 'Primo novo', 'tenant_id' => 1]);
        Cliente::create(['nome' => 'Outro nome', 'tenant_id' => 1]);

        $csv = $this->exportar($consulta, [
            'tool' => 'consultar_funcionarios', 'argumentos' => ['nome' => ''], 'linhas' => [['nome' => '=inventado']], 'tenant_id' => 2,
        ])->assertOk()->getContent();

        $nomes = array_column(array_slice($this->linhas($csv), 1), 1);
        $this->assertSame(['Primeiro', 'Primo novo'], $nomes, 'a consulta guardada, rodada de novo (com o filtro dela)');
        $this->assertStringNotContainsString('inventado', $csv);
        $this->assertSame('consultar_clientes', AuditLog::query()->where('acao', 'exportar')->sole()->tool);
    }

    public function test_so_exporta_o_tenant_do_usuario(): void
    {
        Cliente::create(['nome' => 'Meu cliente', 'tenant_id' => 1]);
        Cliente::create(['nome' => 'Cliente do tenant 2', 'documento' => '98765432100', 'tenant_id' => 2]);

        $csv = $this->exportar($this->consultar())->assertOk()->getContent();

        $this->assertStringContainsString('Meu cliente', $csv);
        $this->assertStringNotContainsString('Cliente do tenant 2', $csv);
        $this->assertStringNotContainsString('321-00', $csv);
    }

    public function test_consulta_de_outro_usuario_na_mesma_sessao_nao_exporta(): void
    {
        Cliente::create(['nome' => 'Do tenant 1', 'tenant_id' => 1]);
        $consulta = $this->consultar();

        $this->actingAs(User::factory()->papel('admin')->doTenant(2)->create());

        $this->exportar($consulta)->assertStatus(422)->assertJsonPath('codigo', 'AI_EXPORT_UNAVAILABLE')->assertDontSee('Do tenant 1');
        $this->assertSame(0, AuditLog::query()->where('acao', 'exportar')->count());
    }

    public function test_papel_que_perdeu_a_permissao_e_negado_na_exportacao_e_auditado(): void
    {
        $this->usuario->update(['papel' => 'operador']);
        $cliente = Cliente::create(['nome' => 'Na lixeira', 'tenant_id' => 1]);
        $cliente->delete();
        $consulta = $this->consultar('consultar_lixeira');

        // O papel cai para leitura depois da consulta: vale o papel de AGORA.
        $this->usuario->update(['papel' => 'leitura']);

        $this->exportar($consulta)->assertForbidden()->assertJsonPath('tipo', 'negado')->assertDontSee('Na lixeira');

        $log = AuditLog::query()->where('acao', 'exportar')->sole();
        $this->assertSame(['consultar_lixeira', 'negado', false, 'leitura'], [$log->tool, $log->resultado, $log->permitido, $log->papel]);
    }

    public function test_lixeira_exporta_so_as_colunas_da_tabela(): void
    {
        $this->usuario->update(['papel' => 'admin']);
        Cliente::create(['nome' => 'Apagado', 'tenant_id' => 1])->delete();

        $linhas = $this->linhas($this->exportar($this->consultar('consultar_lixeira'))->assertOk()->getContent());

        $this->assertSame(['tipo', 'id', 'nome', 'situacao', 'excluido_em', 'restaurar_ate'], $linhas[0]);
        $this->assertCount(6, $linhas[1], 'sem a coluna da ação (botão) da linha');
    }

    public function test_limite_de_cinco_exportacoes_por_minuto(): void
    {
        Lancamento::create(['descricao' => 'Aluguel', 'valor' => 10, 'data' => '2026-09-21', 'status' => 'pendente', 'tenant_id' => 1]);
        $consulta = $this->consultar('consultar_lancamentos');

        for ($i = 0; $i < 5; $i++) {
            $this->exportar($consulta)->assertOk();
        }

        $this->exportar($consulta)->assertStatus(429)->assertJsonPath('codigo', 'AI_RATE_LIMIT');
        $this->assertSame(5, AuditLog::query()->where('acao', 'exportar')->count());

        // Balde próprio: o card do agente continua respondendo.
        $this->postJson('/agente/cancelar', ['token' => '00000000-0000-4000-8000-000000000000'])->assertStatus(422);
    }

    public function test_id_invalido_desconhecido_ou_vencido_nao_exporta(): void
    {
        $consulta = $this->consultar();

        foreach ([[], ['consulta' => 'abc'], ['consulta' => ['x']], ['consulta' => '00000000-0000-4000-8000-000000000000']] as $corpo) {
            $this->post('/agente/exportar', $corpo, ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('codigo', 'AI_EXPORT_UNAVAILABLE');
        }

        $this->travel(ConsultasExportaveis::PRAZO_S + 1)->seconds();
        $this->exportar($consulta)->assertStatus(422)->assertJsonPath('codigo', 'AI_EXPORT_UNAVAILABLE');
        $this->assertSame(0, AuditLog::query()->where('acao', 'exportar')->count());
    }

    public function test_resposta_de_escrita_ou_lista_de_escolha_nao_e_exportavel(): void
    {
        $this->usuario->update(['papel' => 'operador']);
        Cliente::create(['nome' => 'Loja A', 'tenant_id' => 1]);
        Cliente::create(['nome' => 'Loja B', 'tenant_id' => 1]);

        // Nome ambíguo no atualizar: volta uma lista para escolher, que não é tabela de consulta.
        $this->comando('atualizar_cliente', ['busca' => 'Loja'])->assertOk()->assertJsonPath('tipo', 'resultado_leitura')->assertJsonMissingPath('exportar');
        $this->comando('criar_cliente', ['nome' => 'X'])->assertOk()->assertJsonMissingPath('exportar');
    }

    public function test_exige_login(): void
    {
        auth()->logout();

        $this->post('/agente/exportar', ['consulta' => '00000000-0000-4000-8000-000000000000'], ['Accept' => 'application/json'])->assertUnauthorized();
    }
}
