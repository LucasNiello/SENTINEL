<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** sentinel:bateria faz chamadas reais (custa dinheiro): fora do SQLite database/bateria.sqlite, não roda. */
class BateriaAgenteTest extends TestCase
{
    use RefreshDatabase;

    public function test_fora_do_banco_da_bateria_recusa_sem_chamar_o_provedor(): void
    {
        Http::fake();

        $this->artisan('sentinel:bateria', ['--limite' => 1])
            ->expectsOutputToContain('database/bateria.sqlite')
            ->assertFailed();

        Http::assertNothingSent();
    }
}
