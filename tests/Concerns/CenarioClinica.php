<?php

namespace Tests\Concerns;

use App\Models\Agendamento;
use App\Models\CatalogoItem;
use App\Models\Pet;
use App\Models\Prontuario;
use App\Models\Tutor;
use App\Models\User;

/**
 * Clínica vet: cenário mínimo para os testes dos services (direto pelos models, para cada teste
 * provar só a regra dele). Tenant padrão: 3; OUTRO_TENANT prova o isolamento.
 */
trait CenarioClinica
{
    protected const T = 3;

    protected const OUTRO_TENANT = 4;

    private int $sequenciaCrmv = 0;

    protected function usuarioClinica(string $papel, int $tenant = self::T, bool $comCrmv = true): User
    {
        $crmv = $comCrmv && in_array($papel, ['veterinario', 'dono'], true) ? 'CRMV-SP '.(10000 + ++$this->sequenciaCrmv) : null;

        return User::factory()->papel($papel)->doTenant($tenant)->create(['crmv' => $crmv]);
    }

    protected function tutor(int $tenant = self::T, string $nome = 'Ana Souza'): Tutor
    {
        return Tutor::create(['nome' => $nome, 'tenant_id' => $tenant]);
    }

    protected function pet(?Tutor $tutor = null, int $tenant = self::T, array $extra = []): Pet
    {
        $tutor ??= $this->tutor($tenant);

        return Pet::create(['tutor_id' => $tutor->id, 'nome' => 'Thor', 'especie' => 'cao', 'tenant_id' => $tenant, ...$extra]);
    }

    protected function item(array $atributos = [], int $tenant = self::T): CatalogoItem
    {
        return CatalogoItem::create([
            'tipo' => 'servico', 'setor' => 'clinica', 'categoria' => 'consulta', 'nome_comercial' => 'Consulta clínica',
            'preco_centavos' => 15000, 'cobranca_por' => 'unidade', 'controla_estoque' => false, 'estoque' => 0,
            'exige_receita' => false, 'tenant_id' => $tenant, ...$atributos,
        ]);
    }

    protected function produto(array $atributos = [], int $tenant = self::T): CatalogoItem
    {
        return $this->item(['tipo' => 'produto', 'setor' => 'petshop', 'categoria' => 'racao', 'nome_comercial' => 'Ração 10 kg',
            'preco_centavos' => 21000, 'controla_estoque' => true, 'estoque' => 10, ...$atributos], $tenant);
    }

    protected function agendamentoDireto(Pet $pet, User $profissional, CatalogoItem $servico, array $extra = []): Agendamento
    {
        return Agendamento::create([
            'pet_id' => $pet->id, 'profissional_id' => $profissional->id, 'setor' => $servico->setor, 'catalogo_item_id' => $servico->id,
            'inicio' => now()->subHours(2), 'fim' => now()->subHour(), 'status' => 'realizado', 'origem' => 'normal',
            'tenant_id' => $pet->tenant_id, ...$extra,
        ]);
    }

    protected function prontuarioDireto(Pet $pet, User $veterinario, array $extra = []): Prontuario
    {
        return Prontuario::create([
            'pet_id' => $pet->id, 'veterinario_id' => $veterinario->id, 'tipo' => 'consulta',
            'anamnese' => 'Tutor relata apatia há dois dias.', 'diagnostico' => 'Gastroenterite leve.',
            'tenant_id' => $pet->tenant_id, ...$extra,
        ]);
    }
}
