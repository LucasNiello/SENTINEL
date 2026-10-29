<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IsolamentoDoAmbienteTest extends TestCase
{
    public function test_phpunit_usa_banco_em_memoria_mesmo_com_configuracao_local_no_processo_pai(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', DB::getDefaultConnection());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertNotSame(base_path('bootstrap/cache/config.php'), app()->getCachedConfigPath());
    }
}
