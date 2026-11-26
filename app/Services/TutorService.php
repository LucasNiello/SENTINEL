<?php

namespace App\Services;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Tutor;
use Closure;
use Illuminate\Support\Facades\DB;
use App\Services\Concerns\ExclusaoSegura;
use App\Services\Concerns\FiltroPorNome;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/** Clínica vet: tutores (mesmo padrão do ClienteService, com lixeira). */
class TutorService
{
    use ExclusaoSegura, FiltroPorNome;

    private const CAMPOS = ['nome', 'documento', 'email', 'telefone', 'endereco'];

    /** Lixeira (RF08/RF12): relações cujos registros impedem excluir. */
    private const DEPENDENTES = ['pets', 'vendas', 'mensagens'];

    private const PAIS = [];

    public function criar(array $dados, int $tenantId): Tutor
    {
        $this->exigirNome($dados['nome'] ?? null);

        return Tutor::create([...Arr::only($dados, self::CAMPOS), 'tenant_id' => $tenantId]);
    }

    /**
     * Cadastro unificado (27/11): o tutor e os pets dele numa transação só, tudo ou nada. Erro de um pet recusa o conjunto e
     * aponta qual ("Pet 2 (Rex): ..."). $aoGravar($registro, $tipo) roda dentro da transação, uma vez por registro (auditoria).
     *
     * @param  array<int, array<string, mixed>>  $pets
     * @return array{tutor: Tutor, pets: array<int, Pet>}
     */
    public function criarComPets(array $dados, array $pets, int $tenantId, ?Closure $aoGravar = null): array
    {
        $this->exigirNome($dados['nome'] ?? null);
        $pets = array_values($pets);
        $chips = [];
        foreach ($pets as $i => $pet) {
            $chip = is_array($pet) ? trim((string) ($pet['microchip'] ?? '')) : '';
            if ($chip !== '') {
                if (isset($chips[$chip])) {
                    throw new RegraDeNegocioException('Pet '.($i + 1).': o microchip repete o do pet '.($chips[$chip] + 1).'.');
                }
                $chips[$chip] = $i;
            }
        }

        return DB::transaction(function () use ($dados, $pets, $tenantId, $aoGravar) {
            $tutor = $this->criar($dados, $tenantId);
            $aoGravar?->__invoke($tutor, 'tutor');
            $gravados = [];
            foreach ($pets as $i => $pet) {
                $pet = is_array($pet) ? array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v, $pet) : [];
                if (isset($pet['peso_gramas']) && is_string($pet['peso_gramas']) && preg_match('/^\d+$/',trim($pet['peso_gramas'])) === 1) {
                    $pet['peso_gramas'] = (int) trim($pet['peso_gramas']);
                }
                try {
                    $novo = app(PetService::class)->criar([...$pet, 'tutor_id' => $tutor->id], $tenantId);
                } catch (RegraDeNegocioException $e) {
                    throw new RegraDeNegocioException('Pet '.($i + 1).(isset($pet['nome']) ? " ({$pet['nome']})" : '').': '.$e->getMessage());
                }
                $aoGravar?->__invoke($novo, 'pet');
                $gravados[] = $novo;
            }

            return ['tutor' => $tutor, 'pets' => $gravados];
        });
    }

    /** Registro de outro tenant ou na lixeira: ModelNotFoundException (inexistente). */
    public function atualizar(int $id, array $dados, int $tenantId): Tutor
    {
        $tutor = Tutor::where('tenant_id', $tenantId)->findOrFail($id);

        if (array_key_exists('nome', $dados)) {
            $this->exigirNome($dados['nome']);
        }

        $tutor->update(Arr::only($dados, self::CAMPOS));

        return $tutor;
    }

    public function localizar(?int $id, ?string $busca, int $tenantId, string $situacao = 'ativo'): Collection
    {
        return Tutor::query()
            ->naSituacao($situacao)
            ->where('tenant_id', $tenantId)
            ->when($id, fn ($query) => $query->whereKey($id), fn ($query) => $this->nomeContem($query, (string) $busca))
            ->limit(6)
            ->get();
    }

    public function buscarPorFiltro(?string $nome, int $tenantId): Collection
    {
        return Tutor::query()
            ->where('tenant_id', $tenantId)
            ->when($nome, fn ($query) => $this->nomeContem($query, $nome))
            ->orderBy('nome')
            ->get();
    }

    /** @return array{bloqueado: bool, motivo?: string} */
    public function excluir(int $id, int $tenantId): array
    {
        return $this->excluirComBloqueio(Tutor::where('tenant_id', $tenantId)->findOrFail($id), self::DEPENDENTES);
    }

    /** @return array{restaurado: bool, motivo?: string} */
    public function restaurarDaLixeira(int $id, int $tenantId, bool $reautenticadoComoAdmin): array
    {
        return $this->restaurar(Tutor::onlyTrashed()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id), $reautenticadoComoAdmin);
    }

    /** @return array{excluido: bool, motivo?: string} */
    public function excluirDefinitivo(int $id, int $tenantId, ?int $autorizadoPor): array
    {
        return $this->excluirDeVez(Tutor::onlyTrashed()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id), $autorizadoPor);
    }

    private function exigirNome(mixed $nome): void
    {
        if (! is_string($nome) || trim($nome) === '') {
            throw new RegraDeNegocioException('Informe o nome do tutor.');
        }
    }
}
