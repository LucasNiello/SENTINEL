<?php

namespace App\Services;

use App\Exceptions\RegraDeNegocioException;
use App\Models\User;
use App\Services\Concerns\ConfereTenant;
use Illuminate\Support\Facades\Hash;

/**
 * Clínica vet: quem autoriza estorno (CobrancaService::estornar). Regras:
 * - só o dono marca e desmarca um supervisor de setor (clinica ou petshop); titular ou substituto não
 *   têm distinção técnica;
 * - o PIN de autorização é diferente da senha de login e é definido só pelo próprio supervisor; o dono
 *   pode apenas zerá-lo, nunca vê-lo nem defini-lo. Mínimo de 6 caracteres; só o hash é guardado;
 * - supervisor sem PIN não autoriza; desmarcar ou trocar o setor zera o PIN.
 * Usuário de outro tenant é tratado como inexistente. As telas e a conferência do PIN ficam para a Fase B.
 */
class SupervisaoService
{
    use ConfereTenant;

    public const PIN_MINIMO = 6;

    public function marcarSupervisor(int $usuarioId, ?string $setor, int $donoId, int $tenantId): User
    {
        $this->dono($donoId, $tenantId);
        $usuario = $this->doTenant(User::class, $usuarioId, $tenantId, 'o usuário');

        if ($setor !== null && ! in_array($setor, ['clinica', 'petshop'], true)) {
            throw new RegraDeNegocioException('O setor do supervisor deve ser "clinica" ou "petshop".');
        }

        // O dono autoriza estorno pela senha de login; marcá-lo supervisor criaria um "tem PIN" que nunca vale.
        // Desmarcar um dono que já esteja marcado continua permitido ($setor null).
        if ($setor !== null && $usuario->papel === 'dono') {
            throw new RegraDeNegocioException('O dono não é marcado como supervisor: ele autoriza o estorno com a senha de login.');
        }

        // Desmarcou ou trocou de setor: o PIN antigo é zerado (não volta a valer numa remarcação).
        if ($usuario->supervisor_setor !== $setor) {
            $usuario->forceFill(['supervisor_setor' => $setor, 'pin_autorizacao' => null])->save();
        }

        return $usuario;
    }

    /** $usuarioId é o próprio usuário logado (quem chama passa o id do ContextoUsuario, nunca outro). */
    public function definirProprioPin(int $usuarioId, string $pin, int $tenantId): void
    {
        $usuario = $this->doTenant(User::class, $usuarioId, $tenantId, 'o usuário');

        if ($usuario->supervisor_setor === null) {
            throw new RegraDeNegocioException('Só um supervisor de setor define PIN de autorização.');
        }
        // O PIN medido, comparado com a senha e guardado é sempre o MESMO texto, sem espaços nas pontas (quem confere o PIN usa o mesmo trim).
        $pin = trim($pin);
        if (mb_strlen($pin) < self::PIN_MINIMO) {
            throw new RegraDeNegocioException('O PIN precisa de pelo menos '.self::PIN_MINIMO.' caracteres (espaços não contam).');
        }
        // Diferente da senha de login: quem souber a senha não autoriza estorno com ela.
        if (Hash::check($pin, (string) $usuario->password)) {
            throw new RegraDeNegocioException('O PIN não pode ser igual à sua senha de login.');
        }

        $usuario->forceFill(['pin_autorizacao' => Hash::make($pin)])->save();
    }

    public function resetarPin(int $usuarioId, int $donoId, int $tenantId): void
    {
        $this->dono($donoId, $tenantId);

        $this->doTenant(User::class, $usuarioId, $tenantId, 'o usuário')->forceFill(['pin_autorizacao' => null])->save();
    }

    private function dono(int $donoId, int $tenantId): User
    {
        return $this->usuarioDoTenant($donoId, $tenantId, ['dono'], 'o dono', 'Só o dono marca supervisores e zera PIN.');
    }
}
