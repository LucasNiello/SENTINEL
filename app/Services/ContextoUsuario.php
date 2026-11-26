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

        // 26/11: conta desativada nunca age, mesmo com a sessão aberta (falha fechada; o middleware GarantirContaAtiva
        // já a derruba na requisição seguinte). Só um false explícito conta: sem o campo carregado, vale como ativa.
        if ($usuario->ativo === false) {
            throw new AuthenticationException('Conta desativada.');
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

        return in_array($papel, self::papeisAdministrativosDoTenant($this->tenantId()), true);
    }

    /**
     * T1 (14/11, resposta F): a conta Admin da clínica é o papel 'admin' num tenant da clínica, e só gerencia os PINs
     * (gera e revoga na /supervisao). Não vende, não cobra, não autoriza estorno, não vê a auditoria nem autoriza a
     * lixeira. Papel fora da hierarquia (config('sentinel.papeis')) não vale (falha fechada).
     */
    public function ehAdminDaClinica(): bool
    {
        return $this->papel() === 'admin' && $this->dominio() === 'clinica' && in_array('admin', (array) config('sentinel.papeis', []), true);
    }

    /**
     * Pendência nº 6-6 (13/11): o usuário logado é o dono E 'dono' é papel da hierarquia (config('sentinel.papeis')).
     * Se o papel sair da configuração, quem o tem deixa de valer como dono (falha fechada), como em papeisAdministrativos().
     */
    public function ehDono(): bool
    {
        return $this->papel() === 'dono' && in_array('dono', (array) config('sentinel.papeis', []), true);
    }

    /** O setor que o usuário logado supervisiona, só se o papel dele ainda for da hierarquia (2ª passada, 13/11). */
    public function setorSupervisionado(): ?string
    {
        return in_array($this->papel(), (array) config('sentinel.papeis', []), true) ? $this->usuario()->supervisor_setor : null;
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
     * Os papéis administrativos que valem NESTE tenant: num tenant da clínica, o 'admin' fica de fora (T1, resposta F:
     * ele só gerencia PINs); lá, a senha que autoriza a lixeira e a auditoria são só do dono (item C, 12/11).
     *
     * @return string[]
     */
    public static function papeisAdministrativosDoTenant(int $tenantId): array
    {
        $papeis = self::papeisAdministrativos();

        return self::tenantDaClinica($tenantId) ? array_values(array_diff($papeis, ['admin'])) : $papeis;
    }

    public static function tenantDaClinica(int $tenantId): bool
    {
        return in_array($tenantId, array_map('intval', (array) config('sentinel.tenants_clinica', [])), true);
    }

    /**
     * Domínio do tenant do usuário logado: 'clinica' (config sentinel.tenants_clinica) ou 'contabilidade'.
     * Define quais tools do agente e quais telas existem para ele; sem tenant, falha fechada (AuthenticationException).
     */
    public function dominio(): string
    {
        return self::tenantDaClinica($this->tenantId()) ? 'clinica' : 'contabilidade';
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
