<?php

namespace App\Services;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Tutor;
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
