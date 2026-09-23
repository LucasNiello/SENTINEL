<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * RF10: confere a senha de um administrador antes de uma ação sensível
 * (restaurar da lixeira). Um operador pode chamar um admin para autorizar,
 * e um admin logado digita a própria senha.
 *
 * O resultado é o que alimenta restaurarDaLixeira(..., $reautenticadoComoAdmin).
 * A restauração ainda não é exposta pela interface nem por tool (Fase 4):
 * aqui fica só o mecanismo.
 */
class ReautenticacaoAdmin
{
    /**
     * Hash bcrypt fixo (custo 12, o mesmo de BCRYPT_ROUNDS) usado quando o admin
     * não é encontrado: a conferência custa o mesmo tempo que a de um admin real.
     */
    private const HASH_FICTICIO = '$2y$12$ezHBDHIf9Iqph0jkQPb3x.xurvG.vVzAgs.I82I7jHRKAqxIFXJgi';

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

            return false;
        }

        return Hash::check($senha, $admin->password);
    }
}
