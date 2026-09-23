<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Papel de RBAC (leitura | operador | admin). Sem este estado o usuário
     * fica sem papel e o sistema nega tudo (falha fechada) — não há padrão.
     */
    public function papel(string $papel): static
    {
        return $this->state(fn (array $attributes) => [
            'papel' => $papel,
        ]);
    }

    /**
     * Tenant do usuário. Sem este estado o usuário fica sem tenant e não
     * consegue logar nem agir (falha fechada) — não há padrão.
     */
    public function doTenant(int $tenantId): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
