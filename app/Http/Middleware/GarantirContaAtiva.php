<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 26/11: conta desativada pelo Administrador/RH perde o acesso na PRÓXIMA requisição, sem esperar a sessão vencer.
 * A sessão é encerrada e invalidada; o navegador vai ao login (ou recebe 401 em JSON) com o motivo.
 */
class GarantirContaAtiva
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = Auth::user();

        if ($usuario !== null && $usuario->ativo === false) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['tipo' => 'erro', 'categoria' => 'authentication_error', 'codigo' => 'AI_ACCOUNT_DISABLED',
                    'http_status' => 401, 'mensagem' => 'Esta conta foi desativada. Fale com o administrador.'], 401);
            }

            return redirect('/login')->withErrors(['email' => 'Esta conta foi desativada. Fale com o administrador.']);
        }

        return $next($request);
    }
}
