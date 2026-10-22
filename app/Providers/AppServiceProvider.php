<?php

namespace App\Providers;

use App\Services\DetalhesAuditoria;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Uma por requisição: o escopo aberto pelo AiAgentService vale para o registro gravado pelo AuditoriaService.
        $this->app->scoped(DetalhesAuditoria::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // RF10: 5 tentativas de login por minuto, por e-mail + IP. Só por IP, um
        // aluno travaria a turma inteira (no laboratório todos saem pelo mesmo IP);
        // com e-mail + IP, o ataque a uma conta é limitado e as outras continuam entrando.
        RateLimiter::for('login', function (Request $request) {
            // email[] (array) não pode derrubar a chave do throttle (B4): vira string vazia + IP.
            $email = $request->input('email');

            return Limit::perMinute(5)->by(Str::lower(is_string($email) ? $email : '').'|'.$request->ip());
        });

        // RNF10: cada pedido ao agente gasta crédito do Foundry. 10 por minuto por usuário logado,
        // com o 429 no mesmo formato {tipo, mensagem} que o front já exibe.
        RateLimiter::for('agente', function (Request $request) {
            return Limit::perMinute(10)->by((string) $request->user()->id)->response(
                fn (Request $request, array $headers) => response()->json(['tipo' => 'erro', 'mensagem' => 'Muitos pedidos em pouco tempo. Aguarde um minuto.'], 429, $headers),
            );
        });

        // Ações do card que não chamam a IA (cancelar): teto folgado por usuário, só contra abuso.
        RateLimiter::for('agente-sem-ia', function (Request $request) {
            return Limit::perMinute(30)->by((string) $request->user()->id)->response(
                fn (Request $request, array $headers) => response()->json(['tipo' => 'erro', 'categoria' => 'rate_limit', 'codigo' => 'AI_RATE_LIMIT',
                    'http_status' => 429, 'mensagem' => 'Muitos pedidos em pouco tempo. Aguarde um minuto.'], 429, $headers),
            );
        });

        // Etapa A1: consulta da auditoria, balde próprio (navegar nela não trava o card do agente, e vice-versa).
        RateLimiter::for('auditoria', function (Request $request) {
            return Limit::perMinute(60)->by('auditoria:'.$request->user()->id)->response(
                fn (Request $request, array $headers) => response()->json(['tipo' => 'erro', 'categoria' => 'rate_limit', 'codigo' => 'AI_RATE_LIMIT',
                    'http_status' => 429, 'mensagem' => 'Muitos pedidos em pouco tempo. Aguarde um minuto.'], 429, $headers),
            );
        });

        // Etapa A3: exportar tira dados do sistema — teto curto e balde próprio, por usuário.
        RateLimiter::for('exportar', function (Request $request) {
            return Limit::perMinute(5)->by('exportar:'.$request->user()->id)->response(
                fn (Request $request, array $headers) => response()->json(['tipo' => 'erro', 'categoria' => 'rate_limit', 'codigo' => 'AI_RATE_LIMIT',
                    'http_status' => 429, 'mensagem' => 'Muitas exportações em pouco tempo. Aguarde um minuto.'], 429, $headers),
            );
        });

        // RF10 (Bloco 4): a senha de admin digitada no card não é aparada (espaço faz parte dela) e nunca
        // volta à sessão como "valor antigo" num redirecionamento de erro de validação.
        TrimStrings::except(['senha_admin']);
        $tratador = $this->app->make(ExceptionHandler::class);
        if ($tratador instanceof Handler) {
            $tratador->dontFlash('senha_admin');
        }
    }
}
