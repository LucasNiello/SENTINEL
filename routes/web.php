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

    Route::post('/agente/comando', [AgenteController::class, 'processar']);
    Route::post('/agente/confirmar', [AgenteController::class, 'confirmar']);
});
