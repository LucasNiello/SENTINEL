<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportarAuditoriaTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_exporta_todas_as_paginas_do_tenant_sem_parametros_sensiveis(): void
    {
        $user = $this->logarComo('admin', 1);
        $user->update(['name' => 'QA Usuário Ação']);
        foreach (range(1, 27) as $i) {
            AuditLog::create(['user_id' => $user->id, 'tool' => 'criar_cliente', 'acao' => 'criar', 'entidade_tipo' => 'Cliente',
                'entidade_id' => $i, 'resultado' => 'sucesso', 'papel' => 'admin', 'permitido' => true, 'tenant_id' => 1,
                'parametros' => ['salario' => '987654.32', 'cpf' => '99988877766']]);
        }
        // Mesma tool, resultado e usuário: só o tenant a separa do filtro (o marcador não é filtro).
        AuditLog::create(['user_id' => $user->id, 'tool' => 'criar_cliente', 'acao' => 'criar', 'entidade_tipo' => 'MarcadorTenant2',
            'resultado' => 'sucesso', 'papel' => 'admin', 'permitido' => true, 'tenant_id' => 2, 'parametros' => []]);

        $r = $this->get('/auditoria/exportar?tool=criar_cliente&resultado=sucesso&usuario='.$user->id.'&pagina=2')->assertOk();
        $csv = $r->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('QA Usuário Ação', $csv);
        $this->assertCount(28, explode("\n", trim($csv)));
        foreach (['987654.32', '99988877766', 'MarcadorTenant2'] as $segredo) {
            $this->assertStringNotContainsString($segredo, $csv);
        }
        $this->assertDatabaseHas('audit_logs', ['tool' => 'consultar_auditoria', 'acao' => 'exportar', 'resultado' => 'sucesso']);

        // Sem filtro nenhum, o tenant continua sendo o único corte.
        $semFiltro = $this->get('/auditoria/exportar')->assertOk()->getContent();
        $this->assertStringContainsString('criar_cliente', $semFiltro);
        $this->assertStringNotContainsString('MarcadorTenant2', $semFiltro);
    }

    public function test_exportacao_nega_operador_e_valida_filtros(): void
    {
        $this->logarComo('operador');
        $this->get('/auditoria/exportar')->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['tool' => 'consultar_auditoria', 'acao' => 'exportar', 'resultado' => 'negado',
            'papel' => 'operador', 'permitido' => false]);
        $this->logarComo('admin');
        $this->get('/auditoria/exportar?de[]=invalido')->assertUnprocessable();
    }

    public function test_celula_que_comeca_com_formula_sai_com_apostrofo(): void
    {
        $user = $this->logarComo('admin', 1);
        $user->update(['name' => '=HYPERLINK("http://x.test","clique")']);
        AuditLog::create(['user_id' => $user->id, 'tool' => 'criar_cliente', 'acao' => 'criar', 'entidade_tipo' => 'Cliente',
            'resultado' => 'sucesso', 'papel' => 'admin', 'permitido' => true, 'tenant_id' => 1, 'parametros' => []]);

        $csv = $this->get('/auditoria/exportar?tool=criar_cliente')->assertOk()->getContent();
        $linhas = array_map(fn ($l) => str_getcsv($l, ';', '"', ''), explode("\n", trim(substr($csv, 3))));
        $usuario = $linhas[1][array_search('Usuário', $linhas[0], true)];

        // Conteúdo da célula já sem as aspas do CSV: o apóstrofo vem antes do =.
        $this->assertSame('\'=HYPERLINK("http://x.test","clique")', $usuario);
    }

    public function test_sexta_exportacao_no_minuto_recebe_429(): void
    {
        $this->logarComo('admin');
        foreach (range(1, 5) as $i) {
            $this->get('/auditoria/exportar')->assertOk();
        }
        $this->get('/auditoria/exportar')->assertStatus(429)->assertJsonPath('codigo', 'AI_RATE_LIMIT');
    }
}
