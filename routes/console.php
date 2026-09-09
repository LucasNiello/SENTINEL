<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// RF11 — requer que o cron do SO chame "php artisan schedule:run" a cada
// minuto; não configurado neste ambiente de dev (fora do escopo do código).
Schedule::command('sentinel:arquivar-expirados')->daily();
