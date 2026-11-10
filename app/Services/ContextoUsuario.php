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

    /**
     * Papel administrativo (config('sentinel.papeis_administrativos'): admin e, na clínica, dono):
     * vê a auditoria e o salário no card. Papel desconhecido nunca é administrativo.
     */
    public function ehAdministrativo(): bool
    {
        $papel = $this->papel();

        return in_array($papel, self::papeisAdministrativos(), true);
    }

    /**
     * Papéis administrativos que AINDA são papéis válidos (config('sentinel.papeis')). Um papel que saiu da lista
     * de válidos nunca autoriza nada, mesmo que continue em papeis_administrativos. Usada também pela senha
     * de administrador (ReautenticacaoAdmin) e pela exclusão definitiva (ExclusaoSegura).
     *
     * @return string[]
     */
    public static function papeisAdministrativos(): array
    {
        return array_values(array_intersect(
            (array) config('sentinel.papeis_administrativos', []),
            (array) config('sentinel.papeis', []),
        ));
    }

    /**
     * Domínio do tenant do usuário logado: 'clinica' (config sentinel.tenants_clinica) ou 'contabilidade'.
     * Define quais tools do agente e quais telas existem para ele; sem tenant, falha fechada (AuthenticationException).
     */
    public function dominio(): string
    {
        return in_array($this->tenantId(), array_map('intval', (array) config('sentinel.tenants_clinica', [])), true) ? 'clinica' : 'contabilidade';
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
