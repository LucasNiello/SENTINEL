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
        AuditLog::create(['user_id' => $user->id, 'tool' => 'cliente_outro_tenant', 'acao' => 'criar', 'entidade_tipo' => 'Cliente',
            'resultado' => 'sucesso', 'papel' => 'admin', 'permitido' => true, 'tenant_id' => 2, 'parametros' => []]);

        $r = $this->get('/auditoria/exportar?tool=criar_cliente&resultado=sucesso&usuario='.$user->id.'&pagina=2')->assertOk();
        $csv = $r->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('QA Usuário Ação', $csv);
        $this->assertCount(28, explode("\n", trim($csv)));
        foreach (['987654.32', '99988877766', 'cliente_outro_tenant'] as $segredo) {
            $this->assertStringNotContainsString($segredo, $csv);
        }
        $this->assertDatabaseHas('audit_logs', ['tool' => 'consultar_auditoria', 'acao' => 'exportar', 'resultado' => 'sucesso']);
    }

    public function test_exportacao_nega_operador_e_valida_filtros(): void
    {
        $this->logarComo('operador');
        $this->get('/auditoria/exportar')->assertForbidden();
        $this->logarComo('admin');
        $this->get('/auditoria/exportar?de[]=invalido')->assertUnprocessable();
    }
}
