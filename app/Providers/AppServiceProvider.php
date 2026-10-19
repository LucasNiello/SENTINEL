<?php

namespace App\Providers;

use App\Services\DetalhesAuditoria;
use Illuminate\Cache\RateLimiting\Limit;
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
    }
}
