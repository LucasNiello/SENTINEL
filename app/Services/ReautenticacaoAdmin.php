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

    /** CRMV + senha (cirurgia e internação, 15/11): o mesmo limite do login, 5 tentativas erradas por minuto (usuário + IP). */
    public const LIMITE_CRMV_POR_MINUTO = 5;

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
        return $this->comLimite($email, $senha, fn (string $e, string $s) => $this->adminQueConfere($e, $s));
    }

    /**
     * H (clínica): quem autoriza um ESTORNO, pelo mesmo card e com os MESMOS limites (os contadores são os de
     * autorizar(): errar aqui também conta lá). O segredo é o PIN de autorização (gerado pelo Administrador ou pelo dono, T1 de
     * 14/11; o dono desde 15/11) de um supervisor ou do dono, do mesmo tenant de quem está logado. A senha de login NÃO serve, nem a do
     * dono; o Administrador não autoriza, mesmo com um código. Se a pessoa pode autorizar ESTE estorno (setor,
     * autoaprovação, papel válido) é conferido de novo no service do estorno.
     *
     * @return array{admin: int}|array{falha: 'faltando'|'limite'|'invalido'}  "admin" = quem autorizou
     */
    public function autorizarEstorno(mixed $email, mixed $segredo): array
    {
        return $this->comLimite($email, $segredo, fn (string $e, string $s) => $this->autorizadorDeEstorno($e, $s));
    }

    /**
     * Cirurgia e internação (decisão do Lucas, 15/11): o próprio usuário logado confirma com o CRMV do perfil e a senha de
     * login. Os DOIS precisam ser dele: CRMV de outro veterinário ou senha de outra pessoa não servem. Limite igual ao do
     * login (5 erradas por minuto, por usuário + IP); a tentativa conta antes de conferir e volta no acerto. A senha só
     * existe nesta chamada: nunca vai para log, auditoria, sessão ou IA (a auditoria só registra o autorizado_por).
     *
     * @return array{admin: int}|array{falha: 'faltando'|'limite'|'invalido'}  "admin" = o próprio usuário, reautenticado
     */
    public function autorizarComCrmv(mixed $crmv, mixed $senha): array
    {
        $usuario = $this->contexto->usuario();

        if (! is_string($crmv) || ! is_string($senha) || trim($crmv) === '' || $senha === '') {
            return ['falha' => 'faltando'];
        }

        try {
            $chave = 'reautenticacao:crmv:'.$usuario->id.'|'.(string) request()->ip();
            if (RateLimiter::hit($chave, 60) > self::LIMITE_CRMV_POR_MINUTO) {
                $this->devolverTentativa60s($chave);

                return ['falha' => 'limite'];
            }

            $crmvConfere = strlen($crmv) <= 40 && strlen($senha) <= 255 && filled($usuario->crmv)
                && self::crmvNormalizado($crmv) === self::crmvNormalizado((string) $usuario->crmv);
            // A senha é conferida mesmo com o CRMV errado: o tempo de resposta não diz qual dos dois errou.
            $senhaConfere = Hash::check($senha, $crmvConfere ? (string) $usuario->password : self::HASH_FICTICIO);

            if (! $crmvConfere || ! $senhaConfere) {
                return ['falha' => 'invalido'];
            }

            $this->devolverTentativa60s($chave);

            return ['admin' => (int) $usuario->id];
        } catch (AuthenticationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('ReautenticacaoAdmin: falha ao conferir o CRMV', [
                'correlation_id' => (string) Str::uuid(),
                'excecao' => $e::class,
                'local' => basename($e->getFile()).':'.$e->getLine(),
            ]);

            return ['falha' => 'invalido'];
        }
    }

    /** Como devolverTentativa(), na janela de 60 s do CRMV (se a chave venceu no meio, não recria com a janela de 10 min). */
    private function devolverTentativa60s(string $chave): void
    {
        if (RateLimiter::attempts($chave) > 0) {
            RateLimiter::decrement($chave, 60);
        }
    }

    /** "crmv-sp 12.345" e "CRMV-SP 12345" são o mesmo: só letras e números, em maiúsculas, sem acento. */
    private static function crmvNormalizado(string $crmv): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', Str::upper(Str::ascii(trim($crmv))));
    }

    /**
     * A conferência com os dois limites de tentativas erradas por janela.
     *
     * @param  \Closure(string, string): ?User  $conferir
     * @return array{admin: int}|array{falha: 'faltando'|'limite'|'invalido'}
     */
    private function comLimite(mixed $email, mixed $senha, \Closure $conferir): array
    {
        $usuarioId = $this->contexto->usuario()->id;

        if (! is_string($email) || ! is_string($senha) || trim($email) === '' || $senha === '') {
            return ['falha' => 'faltando'];
        }

        try {
            $porUsuario = "reautenticacao:usuario:{$usuarioId}";
            $porAdmin = $this->chavePorAlvo($usuarioId, $email);

            // A tentativa conta ANTES de conferir a senha (incremento atômico no cache): pedidos em
            // paralelo não passam todos pela conferência antes de o primeiro erro ser contado.
            $tentativasDoUsuario = RateLimiter::hit($porUsuario, self::JANELA_S);
            $tentativasNoAdmin = RateLimiter::hit($porAdmin, self::JANELA_S);

            if ($tentativasDoUsuario > self::LIMITE_POR_USUARIO || $tentativasNoAdmin > self::LIMITE_POR_ADMIN) {
                // Barrada sem conferir senha: não é tentativa errada, então não fica nos contadores.
                $this->devolverTentativa($porUsuario, $porAdmin);

                return ['falha' => 'limite'];
            }

            $admin = strlen($email) <= 255 && strlen($senha) <= 255 ? $conferir(trim($email), $senha) : null;

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

    /**
     * Chave do limite por quem autoriza (LIMITE_POR_ADMIN). Pendência nº 5-4 (13/11): o e-mail entra em minúsculas e
     * SEM ACENTO, porque a collation do MySQL (utf8mb4_unicode_ci) acha "vét@…" e "VET@…" como a mesma pessoa; com o
     * texto cru na chave, cada variação ganhava um contador novo e só sobrava o limite de 10 por usuário. Não se busca
     * a pessoa no banco: com a chave por id, a diferença entre 429 e 403 dizia se o e-mail é de alguém do tenant.
     * Entra só como HMAC: se alguém digitar a senha no campo do e-mail, ela não fica legível no cache.
     */
    public function chavePorAlvo(int $usuarioId, string $email): string
    {
        return "reautenticacao:admin:{$usuarioId}:".hash_hmac('sha256', Str::lower(Str::ascii(trim($email))), (string) config('app.key'));
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

    /** O dono e o supervisor (marcado) pelo PIN; o Administrador e qualquer outro: null, no mesmo tempo. */
    private function autorizadorDeEstorno(string $email, string $segredo): ?User
    {
        $usuario = User::query()
            ->where('email', $email)
            ->where('tenant_id', $this->contexto->tenantId())
            ->where('ativo', true) // 26/11: conta desativada não autoriza (nem pelo PIN)
            ->whereIn('papel', (array) config('sentinel.papeis', []))
            ->first();

        // T1: a senha de login do dono deixou de valer; o dono confere pelo PIN, como o supervisor.
        $hash = $usuario !== null && SupervisaoService::podeTerPin($usuario) ? $usuario->pin_autorizacao : null;

        if ($hash === null) {
            Hash::check($segredo, self::HASH_FICTICIO);

            return null;
        }

        // O PIN gerado são só dígitos (SupervisaoService::gerarPin); espaços nas pontas, digitados sem querer, não contam.
        return Hash::check(trim($segredo), $hash) ? $usuario : null;
    }

    private function adminQueConfere(string $email, string $senha): ?User
    {
        $tenantId = $this->contexto->tenantId();

        $admin = User::query()
            ->where('email', $email)
            ->whereIn('papel', ContextoUsuario::papeisAdministrativosDoTenant($tenantId))
            ->where('tenant_id', $tenantId)
            ->where('ativo', true) // 26/11: administrador desativado não autoriza
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
