<?php

use App\Http\Controllers\AgenteController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/agente', function () {
    return view('agente');
});

Route::post('/agente/comando', [AgenteController::class, 'processar']);
Route::post('/agente/confirmar', [AgenteController::class, 'confirmar']);
