<?php

namespace App\Services;

use App\Exceptions\RegraDeNegocioException;
use App\Models\User;
use App\Services\Concerns\ConfereTenant;
use Illuminate\Support\Facades\Hash;

/**
 * Clínica vet: quem autoriza estorno (CobrancaService::estornar). Regras (T1, 14/11, respostas A, B e F do Lucas):
 * - só o dono marca e desmarca um supervisor de setor (clinica ou petshop); titular ou substituto não têm
 *   distinção técnica; o dono e o Administrador não são marcados;
 * - o PIN de autorização é gerado e revogado pelo Administrador (papel 'admin' no tenant da clínica) ou pelo dono
 *   (decisão do Lucas, Bloco B de 15/11: o dono gera o próprio PIN e o dos supervisores): 6 dígitos aleatórios
 *   (random_int), mostrados UMA vez a quem gerou; só o hash é guardado. O código novo invalida o anterior; revogar
 *   zera. O PIN vale até alguém gerar outro (resposta A);
 * - têm PIN o dono (sem precisar de setor) e os supervisores de setor; o Administrador nunca tem nem autoriza;
 * - supervisor sem PIN não autoriza; desmarcar ou trocar o setor zera o PIN.
 * Usuário de outro tenant é tratado como inexistente. A conferência do PIN fica no ReautenticacaoAdmin (card do
 * estorno); a tela é a /supervisao (TelasController), sobre as rotas JSON do SupervisaoController.
 */
class SupervisaoService
{
    use ConfereTenant;

    public const PIN_DIGITOS = 6;

    public function marcarSupervisor(int $usuarioId, ?string $setor, int $donoId, int $tenantId): User
    {
        $this->dono($donoId, $tenantId);
        // 2ª passada do Bloco B (15/11): leitura TRAVADA, como no gerarPin. Sem a trava, um PIN gerado em outra aba entre a
        // leitura e o save() sobrevivia à troca de setor (null → null não entra no UPDATE).
        $usuario = User::query()->where('tenant_id', $tenantId)->lockForUpdate()->find($this->idValido($usuarioId))
            ?? throw new RegraDeNegocioException("Não encontrei o usuário #{$usuarioId}.");

        if ($setor !== null && ! in_array($setor, ['clinica', 'petshop'], true)) {
            throw new RegraDeNegocioException('O setor do supervisor deve ser "clinica" ou "petshop".');
        }

        // O dono já tem PIN sem setor; marcá-lo supervisor não muda nada. Desmarcar um dono que já esteja marcado
        // continua permitido ($setor null).
        if ($setor !== null && $usuario->papel === 'dono') {
            throw new RegraDeNegocioException('O dono não é marcado como supervisor: ele autoriza o estorno com o PIN dele.');
        }
        // Resposta F: o Administrador só gerencia PINs, nunca autoriza estorno.
        if ($setor !== null && $usuario->papel === 'admin') {
            throw new RegraDeNegocioException('O Administrador não é marcado como supervisor: ele só gera e revoga os PINs.');
        }

        // Desmarcou ou trocou de setor: o PIN antigo é zerado (não volta a valer numa remarcação).
        if ($usuario->supervisor_setor !== $setor) {
            $usuario->forceFill(['supervisor_setor' => $setor, 'pin_autorizacao' => null])->save();
        }

        return $usuario;
    }

    /**
     * Gera o PIN de quem pode autorizar estorno e devolve o código, para ser mostrado UMA vez a quem gerou. Quem chama
     * nunca grava o código em log, auditoria, sessão ou resposta seguinte. O hash novo substitui o anterior.
     */
    public function gerarPin(int $usuarioId, int $gestorId, int $tenantId): string
    {
        $gestor = $this->gestorDoPin($gestorId, $tenantId);
        $alvo = User::query()->where('tenant_id', $tenantId)->lockForUpdate()->find($this->idValido($usuarioId))
            ?? throw new RegraDeNegocioException("Não encontrei o usuário #{$usuarioId}.");
        $this->conferirDonoComDono($gestor, $alvo);

        if (! self::podeTerPin($alvo)) {
            throw new RegraDeNegocioException('Só o dono e os supervisores de setor têm PIN de autorização.');
        }

        // Diferente da senha de login: quem souber a senha não autoriza estorno com ela.
        for ($tentativa = 0; $tentativa < 10; $tentativa++) {
            $codigo = str_pad((string) random_int(0, 10 ** self::PIN_DIGITOS - 1), self::PIN_DIGITOS, '0', STR_PAD_LEFT);

            if (! Hash::check($codigo, (string) $alvo->password)) {
                $alvo->forceFill(['pin_autorizacao' => Hash::make($codigo)])->save();

                return $codigo;
            }
        }

        throw new RegraDeNegocioException('Não consegui gerar um PIN diferente da senha de login. Tente de novo.');
    }

    public function revogarPin(int $usuarioId, int $gestorId, int $tenantId): User
    {
        $gestor = $this->gestorDoPin($gestorId, $tenantId);
        $alvo = $this->doTenant(User::class, $usuarioId, $tenantId, 'o usuário');
        $this->conferirDonoComDono($gestor, $alvo);
        // Cosmético 3 da 1ª passada: a auditoria não diz "revogado" para quem não tinha PIN.
        if ($alvo->pin_autorizacao === null) {
            throw new RegraDeNegocioException('Esta pessoa não tem PIN para revogar.');
        }
        $alvo->forceFill(['pin_autorizacao' => null])->save();

        return $alvo;
    }

    /**
     * Quem pode ter PIN: o dono (sem setor) e o supervisor de setor, com papel da hierarquia (config('sentinel.papeis'));
     * o Administrador nunca, mesmo se um dado antigo o deixou marcado.
     */
    public static function podeTerPin(User $usuario): bool
    {
        return in_array($usuario->papel, (array) config('sentinel.papeis', []), true)
            && $usuario->papel !== 'admin'
            && ($usuario->papel === 'dono' || $usuario->supervisor_setor !== null);
    }

    private function dono(int $donoId, int $tenantId): User
    {
        // nº 6-6: 'dono' só vale enquanto for papel da hierarquia (config('sentinel.papeis')); fora dela, ninguém é dono.
        $papeis = array_values(array_intersect(['dono'], (array) config('sentinel.papeis', [])));

        return $this->usuarioDoTenant($donoId, $tenantId, $papeis, 'o dono', 'Só o dono marca supervisores.');
    }

    /** Decisão do Lucas (15/11): o dono gera e revoga o próprio PIN e o dos supervisores; o de outro dono, não (o Admin sim). */
    private function conferirDonoComDono(User $gestor, User $alvo): void
    {
        if ($gestor->papel === 'dono' && $alvo->papel === 'dono' && (int) $gestor->id !== (int) $alvo->id) {
            throw new RegraDeNegocioException('O dono gera e revoga só o próprio PIN e o dos supervisores. O PIN de outro dono é com o Administrador.');
        }
    }

    private function gestorDoPin(int $gestorId, int $tenantId): User
    {
        // Quem gera e revoga PIN: o Administrador ou o dono da clínica, papéis da hierarquia, num tenant da clínica (fora
        // dela, ninguém). Gerir o PIN não é autorizar: o Administrador continua sem PIN (podeTerPin).
        $papeis = ContextoUsuario::tenantDaClinica($tenantId) ? array_values(array_intersect(['admin', 'dono'], (array) config('sentinel.papeis', []))) : [];

        return $this->usuarioDoTenant($gestorId, $tenantId, $papeis, 'o dono ou o Administrador', 'Só o dono e o Administrador geram e revogam PIN.');
    }
}
