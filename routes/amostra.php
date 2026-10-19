<?php

use App\Http\Controllers\AmostraController;
use Illuminate\Support\Facades\Route;

/*
 * AMOSTRA: telas de demonstração com dados fictícios. Sem login, sem banco, sem IA.
 * Carregado por routes/web.php apenas fora de produção.
 */
Route::prefix('amostra')->group(function () {
    Route::get('/', [AmostraController::class, 'indice']);
    Route::get('/login', [AmostraController::class, 'login']);
    Route::get('/casca', [AmostraController::class, 'casca']);
    Route::get('/responsivo', [AmostraController::class, 'responsivo']);
    Route::get('/agente/{estado?}', [AmostraController::class, 'agente']);
    Route::get('/formulario/{entidade}', [AmostraController::class, 'formulario']);
    Route::get('/cadastros', [AmostraController::class, 'cadastros']);
    Route::get('/cadastros/{slug}', [AmostraController::class, 'cadastroLista']);
    Route::get('/lixeira', [AmostraController::class, 'lixeira']);
    Route::get('/lixeira/retencao', [AmostraController::class, 'retencao']);
    Route::get('/arquivados', [AmostraController::class, 'arquivados']);
    Route::get('/auditoria', [AmostraController::class, 'auditoria']);
    Route::get('/erro/{codigo}', [AmostraController::class, 'erro']);
    Route::get('/fila', [AmostraController::class, 'fila']);
});
