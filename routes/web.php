<?php

use App\Http\Controllers\AgenteController;
use App\Http\Controllers\AuditoriaController;
use App\Http\Controllers\AutenticacaoController;
use App\Http\Middleware\VarrerConfirmacoesVencidas;
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

    // R3: em toda requisição ao agente, as propostas vencidas são auditadas como "expirado" e saem da sessão.
    Route::middleware(VarrerConfirmacoesVencidas::class)->group(function () {
        // Etapa A1: consulta da auditoria em JSON (só admin e só o tenant do usuário — conferido no service).
        // Com teto por usuário: cada tentativa negada também vira uma linha na auditoria.
        Route::get('/auditoria/registros', [AuditoriaController::class, 'registros'])->middleware('throttle:auditoria');
        Route::get('/auditoria/registros/{id}', [AuditoriaController::class, 'registro'])->whereNumber('id')->middleware('throttle:auditoria');

        Route::get('/agente', function () {
            return view('agente');
        });

        Route::post('/agente/comando', [AgenteController::class, 'processar'])->middleware('throttle:agente');
        Route::post('/agente/confirmar', [AgenteController::class, 'confirmar']);
        Route::post('/agente/revisar', [AgenteController::class, 'revisar'])->middleware('throttle:agente-sem-ia');
        Route::post('/agente/cancelar', [AgenteController::class, 'cancelar'])->middleware('throttle:agente-sem-ia');
        Route::post('/agente/editar', [AgenteController::class, 'editar'])->middleware('throttle:agente-sem-ia');
        Route::post('/agente/formulario', [AgenteController::class, 'formulario'])->middleware('throttle:agente-sem-ia');
        // B2 (RF05/RNF05): modo manual, lista e busca sem IA.
        Route::post('/agente/consultar', [AgenteController::class, 'consultar'])->middleware('throttle:agente-sem-ia');
        // A3 (RF02): CSV de uma tabela de consulta, reexecutada no servidor.
        Route::post('/agente/exportar', [AgenteController::class, 'exportar'])->middleware('throttle:exportar');
    });
});

// AMOSTRA: telas de demonstração com dados fictícios (sem login e sem banco). Fora do ar em produção.
if (! app()->environment('production')) {
    require __DIR__.'/amostra.php';
}
