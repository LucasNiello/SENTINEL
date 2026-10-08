<?php

use App\Http\Controllers\AgenteController;
use App\Http\Controllers\AutenticacaoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// RF10: login/logout com o Auth nativo do Laravel (sem cadastro público).
Route::middleware('guest')->group(function () {
    Route::get('/login', [AutenticacaoController::class, 'formulario'])->name('login');
    Route::post('/login', [AutenticacaoController::class, 'entrar'])->middleware('throttle:login');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AutenticacaoController::class, 'sair']);

    Route::get('/agente', function () {
        return view('agente');
    });

    Route::post('/agente/comando', [AgenteController::class, 'processar'])->middleware('throttle:agente');
    Route::post('/agente/confirmar', [AgenteController::class, 'confirmar']);
});

// AMOSTRA: telas de demonstração com dados fictícios (sem login e sem banco). Fora do ar em produção.
if (! app()->environment('production')) {
    require __DIR__.'/amostra.php';
}
