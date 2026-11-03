<?php

namespace Tests\Feature;

use Tests\TestCase;

class AmostraRemovidaTest extends TestCase
{
    // P0: as telas de amostra abriam sem login fora de produção. Foram removidas.
    public function test_amostra_nao_existe_mais(): void
    {
        $this->get('/amostra')->assertNotFound();
        $this->get('/amostra/fila')->assertNotFound();
        $this->get('/amostra-recursos/amostra.css')->assertNotFound();
        $this->assertFileDoesNotExist(base_path('routes/amostra.php'));
        $this->assertFileDoesNotExist(public_path('amostra-recursos'));
    }
}
