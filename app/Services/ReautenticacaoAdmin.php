<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * RF10: confere a senha de um administrador antes de uma ação sensível
 * (restaurar da lixeira e excluir de vez). Um operador pode chamar um admin
 * para autorizar, e um admin logado digita a própria senha.
 *
 * Pelo agente (Bloco 4), a senha vem do card direto para o /agente/confirmar,
 * que chama autorizar(): ela só existe em memória nesta requisição — nunca vai
 * para a IA, a sessão, o log, a auditoria, o cache ou o histórico do agente.
 */
class ReautenticacaoAdmin
{
    /**
     * Hash bcrypt fixo (custo 12, o mesmo de BCRYPT_ROUNDS) usado quando o admin
     * não é encontrado: a conferência custa o mesmo tempo que a de um admin real.
     */
    private const HASH_FICTICIO = '$2y$12$ezHBDHIf9Iqph0jkQPb3x.xurvG.vVzAgs.I82I7jHRKAqxIFXJgi';

    /** Tentativas erradas por usuário logado + e-mail do admin, a cada JANELA_S. */
    public const LIMITE_POR_ADMIN = 5;

    /** Tentativas erradas por usuário logado, com qualquer e-mail, a cada JANELA_S. */
    public const LIMITE_POR_USUARIO = 10;

    /** Janela dos dois limites: 10 minutos. */
    public const JANELA_S = 600;

    public function __construct(private ContextoUsuario $contexto)
    {
    }

    /**
     * true só se o e-mail for de um admin do MESMO tenant de quem está logado
     * e a senha conferir. Sem usuário logado, lança AuthenticationException
     * (via ContextoUsuario) — falha fechada.
     */
    public function confirmar(string $email, string $senha): bool
    {
        return $this->adminQueConfere($email, $senha) !== null;
    }

    /**
     * Bloco 4: a reautenticação pelo card. Mesma conferência de confirmar(), com dois
     * limites de tentativas ERRADAS por janela de 10 min: usuário logado + e-mail do
     * admin (LIMITE_POR_ADMIN) e só o usuário logado, com qualquer e-mail
     * (LIMITE_POR_USUARIO). Nunca lança, exceto a AuthenticationException de quem não
     * está logado: qualquer outra falha vira "invalido", e o log leva só a classe da
     * exceção e onde foi (nunca o e-mail nem a senha).
     *
     * @return array{admin: int}|array{falha: 'faltando'|'limite'|'invalido'}
     */
    public function autorizar(mixed $email, mixed $senha): array
    {
        $usuarioId = $this->contexto->usuario()->id;

        if (! is_string($email) || ! is_string($senha) || trim($email) === '' || $senha === '') {
            return ['falha' => 'faltando'];
        }

        try {
            // O e-mail digitado entra na chave só como HMAC: se alguém digitar a senha no campo
            // do e-mail, ela não fica legível no cache.
            $porUsuario = "reautenticacao:usuario:{$usuarioId}";
            $porAdmin = "reautenticacao:admin:{$usuarioId}:".hash_hmac('sha256', Str::lower(trim($email)), (string) config('app.key'));

            // A tentativa conta ANTES de conferir a senha (incremento atômico no cache): pedidos em
            // paralelo não passam todos pela conferência antes de o primeiro erro ser contado.
            $tentativasDoUsuario = RateLimiter::hit($porUsuario, self::JANELA_S);
            $tentativasNoAdmin = RateLimiter::hit($porAdmin, self::JANELA_S);

            if ($tentativasDoUsuario > self::LIMITE_POR_USUARIO || $tentativasNoAdmin > self::LIMITE_POR_ADMIN) {
                // Barrada sem conferir senha: não é tentativa errada, então não fica nos contadores.
                $this->devolverTentativa($porUsuario, $porAdmin);

                return ['falha' => 'limite'];
            }

            $admin = strlen($email) <= 255 && strlen($senha) <= 255 ? $this->adminQueConfere(trim($email), $senha) : null;

            if ($admin === null) {
                return ['falha' => 'invalido'];
            }

            // Acerto não conta: a tentativa volta.
            $this->devolverTentativa($porUsuario, $porAdmin);

            return ['admin' => (int) $admin->id];
        } catch (AuthenticationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('ReautenticacaoAdmin: falha ao conferir', [
                'correlation_id' => (string) Str::uuid(),
                'excecao' => $e::class,
                'local' => basename($e->getFile()).':'.$e->getLine(),
            ]);

            return ['falha' => 'invalido'];
        }
    }

    /** Desfaz a tentativa contada; se a janela venceu no meio do pedido, o contador recomeçou e não vai abaixo de zero. */
    private function devolverTentativa(string ...$chaves): void
    {
        foreach ($chaves as $chave) {
            if (RateLimiter::attempts($chave) > 0) {
                RateLimiter::decrement($chave, self::JANELA_S);
            }
        }
    }

    private function adminQueConfere(string $email, string $senha): ?User
    {
        $tenantId = $this->contexto->tenantId();

        $admin = User::query()
            ->where('email', $email)
            ->where('papel', 'admin')
            ->where('tenant_id', $tenantId)
            ->first();

        if ($admin === null) {
            // Confere mesmo assim, para o tempo de resposta não revelar se o
            // e-mail existe ou se é de um admin.
            Hash::check($senha, self::HASH_FICTICIO);

            return null;
        }

        return Hash::check($senha, $admin->password) ? $admin : null;
    }
}
