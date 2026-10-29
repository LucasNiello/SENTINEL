<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Quem já está logado e abre /login vai direto para o agente.
        $middleware->redirectUsersTo('/agente');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Formulário HTML aberto antes do logout/expiração: indicar o motivo,
        // sem executar a ação. APIs continuam recebendo 401 e GET vai ao login.
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            if (! $request->expectsJson() && ! $request->isMethodSafe()) {
                return response()->view('errors.419', [], 419);
            }
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
