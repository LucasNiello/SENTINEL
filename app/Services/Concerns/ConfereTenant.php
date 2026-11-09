<?php

namespace App\Services\Concerns;

use App\Exceptions\RegraDeNegocioException;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Clínica vet: toda referência que chega nos dados (pet_id, catalogo_item_id, profissional_id...)
 * precisa ser do MESMO tenant. Registro de outro tenant é tratado como inexistente — a frase é a
 * mesma de um id que não existe, para não revelar o que há em outra empresa.
 */
trait ConfereTenant
{
    /**
     * @template T of Model
     *
     * @param  class-string<T>  $modelo
     * @return T
     */
    private function doTenant(string $modelo, mixed $id, int $tenantId, string $rotulo): Model
    {
        $id = $this->idValido($id);
        $registro = $id === null ? null : $modelo::query()->where('tenant_id', $tenantId)->find($id);

        if ($registro === null) {
            throw new RegraDeNegocioException('Não encontrei '.$rotulo.($id === null ? '' : " #{$id}").'.');
        }

        return $registro;
    }

    /**
     * Usuário do tenant com um dos papéis exigidos pela regra de domínio (ex.: o profissional da clínica
     * é veterinário ou dono). Não substitui o RBAC das tools: é a regra do dado.
     *
     * @param  string[]  $papeis
     */
    private function usuarioDoTenant(mixed $id, int $tenantId, array $papeis, string $rotulo, string $recusa): User
    {
        $usuario = $this->doTenant(User::class, $id, $tenantId, $rotulo);

        if (! in_array($usuario->papel, $papeis, true)) {
            throw new RegraDeNegocioException($recusa);
        }

        return $usuario;
    }

    private function idValido(mixed $id): ?int
    {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    /** Teto técnico: quantidade x preço (até R$ 1.000.000,00) nunca sai do inteiro nem da coluna. */
    private const QUANTIDADE_MAXIMA = 100_000;

    /** Quantidade inteira e positiva (nunca float, nunca zero), até o teto. */
    private function quantidadeValida(mixed $quantidade): int
    {
        if (! is_int($quantidade) || $quantidade < 1) {
            throw new RegraDeNegocioException('A quantidade deve ser um número inteiro maior que zero.');
        }
        if ($quantidade > self::QUANTIDADE_MAXIMA) {
            throw new RegraDeNegocioException('A quantidade passa do limite de '.self::QUANTIDADE_MAXIMA.' por item.');
        }

        return $quantidade;
    }
}
