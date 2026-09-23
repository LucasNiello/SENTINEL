<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;

/**
 * Ponto único de "quem está agindo" (RF10): usuário, papel (RBAC) e tenant
 * vêm do usuário logado. Nenhum outro lugar lê Auth::user() para decidir
 * papel ou tenant.
 *
 * Falha fechada: sem usuário logado, sem papel ou sem tenant, lança
 * AuthenticationException — nunca cai num papel ou tenant padrão. Numa
 * requisição HTTP isso vira 401/redirect para o login; num comando artisan
 * ou job (sem usuário), o comando falha em vez de agir em nome de alguém.
 *
 * Cada método lê Auth::user() na hora da chamada (nada fica guardado), então
 * uma troca de usuário ou de papel é vista na chamada seguinte.
 */
class ContextoUsuario
{
    public function usuario(): User
    {
        $usuario = Auth::user();

        if (! $usuario instanceof User) {
            throw new AuthenticationException('Nenhum usuário autenticado.');
        }

        return $usuario;
    }

    /**
     * Papel bruto do usuário. Se é um papel da hierarquia ou não, quem decide
     * é o RBAC (AiAgentService::papelAtualPermite), que nega papel desconhecido.
     */
    public function papel(): string
    {
        $papel = $this->usuario()->papel;

        if ($papel === null) {
            throw new AuthenticationException('Usuário sem papel definido.');
        }

        return $papel;
    }

    public function tenantId(): int
    {
        $tenantId = $this->usuario()->tenant_id;

        if ($tenantId === null) {
            throw new AuthenticationException('Usuário sem tenant definido.');
        }

        return (int) $tenantId;
    }
}
