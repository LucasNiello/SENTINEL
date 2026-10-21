<?php

namespace App\Http\Controllers;

use App\Services\AiAgentService;
use App\Services\ConfirmacoesPendentes;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * RF10: login e logout com o que o Laravel já traz (Auth::attempt + sessão),
 * sem pacote de autenticação. Não há cadastro público, recuperação de senha
 * nem "lembrar de mim": os usuários vêm do seeder.
 */
class AutenticacaoController extends Controller
{
    /** Mesma frase para e-mail inexistente, senha errada ou conta sem papel/tenant: não revela qual foi. */
    private const MENSAGEM_FALHA = 'E-mail ou senha inválidos.';

    public function formulario()
    {
        return view('login');
    }

    public function entrar(Request $request)
    {
        $credenciais = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credenciais)) {
            throw ValidationException::withMessages(['email' => self::MENSAGEM_FALHA]);
        }

        // Falha fechada: senha certa não basta. Sem papel da hierarquia ou sem
        // tenant o usuário não teria como agir, então nem entra.
        $usuario = Auth::user();

        if (! in_array($usuario->papel, (array) config('sentinel.papeis', []), true) || $usuario->tenant_id === null) {
            Auth::logout();

            throw ValidationException::withMessages(['email' => self::MENSAGEM_FALHA]);
        }

        // Novo id de sessão após o login (contra fixação de sessão).
        $request->session()->regenerate();

        return redirect()->intended('/agente');
    }

    public function sair(Request $request, ConfirmacoesPendentes $pendentes, AiAgentService $aiAgentService)
    {
        // Card ainda aberto ao sair: a proposta também ganha desfecho na auditoria (antes do logout,
        // porque a auditoria precisa do usuário), pela mesma regra de horário do /agente/cancelar.
        // A sessão é invalidada logo abaixo (R3).
        foreach ($pendentes->retirarTodas($request->session()) as $pendente) {
            [$desfecho, $motivo] = $pendentes->expirou($pendente)
                ? ['expirado', 'A confirmação expirou sem resposta. Nada foi gravado.']
                : ['cancelado', 'Encerrada ao sair do sistema. Nada foi gravado.'];

            try {
                $aiAgentService->auditarDesfecho($pendente['tool'], ConfirmacoesPendentes::propostos($pendente), $desfecho, $motivo);
            } catch (AuthenticationException) {
                // Usuário sem papel ou tenant: não dá para auditar em nome dele, mas sair nunca pode falhar.
            }
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
