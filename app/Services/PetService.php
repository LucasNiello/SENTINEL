<?php

namespace App\Services;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Agendamento;
use App\Models\Pet;
use App\Models\Tutor;
use App\Services\Concerns\ConfereTenant;
use App\Services\Concerns\ExclusaoSegura;
use App\Services\Concerns\FiltroPorNome;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Clínica vet: pets. O tutor precisa ser do mesmo tenant; microchip é único por tenant. */
class PetService
{
    use ConfereTenant, ExclusaoSegura, FiltroPorNome;

    private const CAMPOS = ['tutor_id', 'nome', 'especie', 'raca', 'sexo', 'data_nascimento', 'peso_gramas', 'microchip', 'alertas', 'observacoes'];

    private const DEPENDENTES = ['agendamentos', 'prontuarios', 'vendas'];

    /** Tutor na lixeira: o pet não volta antes dele. */
    private const PAIS = ['tutor'];

    public function criar(array $dados, int $tenantId): Pet
    {
        $dados = Arr::only($dados, self::CAMPOS);
        $this->conferir($dados, $tenantId, null);

        return Pet::create([...$dados, 'tenant_id' => $tenantId]);
    }

    public function atualizar(int $id, array $dados, int $tenantId): Pet
    {
        $pet = Pet::where('tenant_id', $tenantId)->findOrFail($id);
        $dados = Arr::only($dados, self::CAMPOS);
        $this->conferir([...$pet->only(self::CAMPOS), ...$dados], $tenantId, $pet->id);

        $pet->update($dados);

        return $pet;
    }

    public function localizar(?int $id, ?string $busca, int $tenantId, string $situacao = 'ativo'): Collection
    {
        return Pet::query()
            ->naSituacao($situacao)
            ->where('tenant_id', $tenantId)
            ->when($id, fn ($query) => $query->whereKey($id), fn ($query) => $this->nomeContem($query, (string) $busca))
            ->limit(6)
            ->get();
    }

    public function buscarPorFiltro(?string $nome, ?int $tutorId, int $tenantId): Collection
    {
        return Pet::query()
            ->where('tenant_id', $tenantId)
            ->when($nome, fn ($query) => $this->nomeContem($query, $nome))
            ->when($tutorId, fn ($query) => $query->where('tutor_id', $tutorId))
            ->orderBy('nome')
            ->get();
    }

    /**
     * Arquivar o cadastro (27/11): o pet continua no banco com todo o histórico; só sai dos seletores. Bloqueia com
     * agendamento futuro em aberto (cancele ou remarque antes). O motivo vai para as observações do pet.
     */
    public function motivoParaNaoArquivar(int $id, int $tenantId): ?string
    {
        $pet = Pet::query()->where('tenant_id', $tenantId)->find($id);
        if ($pet === null) {
            return 'Não encontrei o pet #'.$id.'.';
        }
        if ($pet->inativado_em !== null) {
            return "{$pet->nome} já está arquivado.";
        }
        // Qualquer agendamento em aberto (agendado/confirmado), de hoje cedo ou futuro: só o em aberto pode ser remarcado ou trocado,
        // então isto fecha também esses dois caminhos (o pet arquivado nunca fica com agendamento vivo).
        $abertos = Agendamento::query()->where('tenant_id', $tenantId)->where('pet_id', $pet->id)->whereIn('status', ['agendado', 'confirmado'])->count();

        return $abertos > 0 ? "{$pet->nome} tem {$abertos} agendamento(s) em aberto: cancele, remarque ou marque como realizado/faltou antes de arquivar." : null;
    }

    public function arquivarCadastro(int $id, string $motivo, int $tenantId): Pet
    {
        return DB::transaction(function () use ($id, $motivo, $tenantId) {
            $pet = Pet::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);
            if (($recusa = $this->motivoParaNaoArquivar($pet->id, $tenantId)) !== null) {
                throw new RegraDeNegocioException($recusa);
            }
            $motivo = trim($motivo);
            if ($motivo === '' || mb_strlen($motivo) > 500) {
                throw new RegraDeNegocioException('Informe o motivo do arquivamento (até 500 caracteres).');
            }
            // Não apaga o que já estava nas observações: o motivo vem primeiro, as notas antigas depois (cabendo nos 500 caracteres).
            $notas = trim((string) $pet->observacoes);
            $texto = 'Arquivado: '.$motivo.($notas !== '' ? ' | '.$notas : '');
            $pet->forceFill(['inativado_em' => now(), 'observacoes' => mb_substr($texto, 0, 500)])->save();

            return $pet;
        });
    }

    public function reativarCadastro(int $id, int $tenantId): Pet
    {
        return DB::transaction(function () use ($id, $tenantId) {
            $pet = Pet::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);
            if ($pet->inativado_em === null) {
                throw new RegraDeNegocioException("{$pet->nome} não está arquivado.");
            }
            $pet->forceFill(['inativado_em' => null])->save();

            return $pet;
        });
    }

    /** Pets para escolher na ação de arquivar (ativos) ou reativar (arquivados). */
    public function localizarPorCadastro(?int $id, ?string $busca, int $tenantId, bool $arquivados): Collection
    {
        return Pet::query()->where('tenant_id', $tenantId)
            ->when($arquivados, fn ($q) => $q->whereNotNull('inativado_em'), fn ($q) => $q->whereNull('inativado_em'))
            ->when($id, fn ($q) => $q->whereKey($id), fn ($q) => $this->nomeContem($q, (string) $busca))
            ->limit(6)->get();
    }

    /** @return array{bloqueado: bool, motivo?: string} */
    public function excluir(int $id, int $tenantId): array
    {
        return $this->excluirComBloqueio(Pet::where('tenant_id', $tenantId)->findOrFail($id), self::DEPENDENTES);
    }

    /** @return array{restaurado: bool, motivo?: string} */
    public function restaurarDaLixeira(int $id, int $tenantId, bool $reautenticadoComoAdmin): array
    {
        return $this->restaurar(Pet::onlyTrashed()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id), $reautenticadoComoAdmin);
    }

    /** @return array{excluido: bool, motivo?: string} */
    public function excluirDefinitivo(int $id, int $tenantId, ?int $autorizadoPor): array
    {
        return $this->excluirDeVez(Pet::onlyTrashed()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id), $autorizadoPor);
    }

    private function conferir(array $dados, int $tenantId, ?int $ignorarId): void
    {
        if (! is_string($dados['nome'] ?? null) || trim($dados['nome']) === '') {
            throw new RegraDeNegocioException('Informe o nome do pet.');
        }

        $this->doTenant(Tutor::class, $dados['tutor_id'] ?? null, $tenantId, 'o tutor');

        if (! in_array($dados['especie'] ?? null, Pet::ESPECIES, true)) {
            throw new RegraDeNegocioException('Informe a espécie: '.implode(', ', Pet::ESPECIES).'.');
        }

        if (($dados['sexo'] ?? null) !== null && ! in_array($dados['sexo'], Pet::SEXOS, true)) {
            throw new RegraDeNegocioException('O sexo deve ser "macho" ou "femea".');
        }

        $nascimento = $dados['data_nascimento'] ?? null;
        if ($nascimento instanceof \DateTimeInterface) {
            $nascimento = $nascimento->format('Y-m-d'); // valor já gravado (cast date), no update
        }
        if ($nascimento !== null) {
            // Data real no formato AAAA-MM-DD (31/02 é recusado) e nunca no futuro: sem erro técnico do banco.
            $data = is_string($nascimento) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $nascimento) : false;
            if ($data === false || $data->format('Y-m-d') !== $nascimento) {
                throw new RegraDeNegocioException('A data de nascimento deve ser uma data válida no formato AAAA-MM-DD.');
            }
            if ($nascimento > now()->toDateString()) {
                throw new RegraDeNegocioException('A data de nascimento não pode ser no futuro.');
            }
        }

        if (($dados['peso_gramas'] ?? null) !== null && (! is_int($dados['peso_gramas']) || $dados['peso_gramas'] < 0)) {
            throw new RegraDeNegocioException('O peso deve ser um número inteiro de gramas.');
        }

        $microchip = $dados['microchip'] ?? null;
        // Conta também o pet na lixeira: o índice único do banco recusaria do mesmo jeito.
        if ($microchip !== null && Pet::withTrashed()->where('tenant_id', $tenantId)->where('microchip', $microchip)
            ->when($ignorarId, fn ($query) => $query->whereKeyNot($ignorarId))->exists()) {
            throw new RegraDeNegocioException('Já existe um pet com este microchip.');
        }
    }
}
