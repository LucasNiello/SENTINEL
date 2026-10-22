<?php

namespace App\Services\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Mecânica de exclusão segura compartilhada pelos services de domínio
 * (RF08-RF12): bloqueio por dependência ativa, mover pra lixeira (soft
 * delete), restaurar e excluir de vez um registro arquivado. O arquivamento
 * por prazo expirado é feito pelo comando ArquivarLixeiraExpirada, não por aqui.
 *
 * Quem usa declara DEPENDENTES (relações cujos registros impedem excluir) e PAIS
 * (relações que precisam estar ativas para o registro voltar da lixeira).
 */
trait ExclusaoSegura
{
    /** RF08: o motivo do bloqueio é exibido ao usuário, então não pode expor o nome técnico da relação. */
    private const ROTULOS_RELACAO = [
        'lancamentos' => 'lançamentos vinculados',
        'notasFiscais' => 'notas fiscais vinculadas',
    ];

    /** Como cada "pai" aparece na frase de recusa da restauração. */
    private const ROTULOS_PAI = [
        'categoriaLancamento' => 'a categoria',
        'cliente' => 'o cliente',
        'fornecedor' => 'o fornecedor',
        'funcionario' => 'o funcionário',
        'lancamento' => 'o lançamento',
    ];

    /**
     * @param  string[]  $relacoesQueBloqueiam  Nomes de relações Eloquent cuja
     *                                           existência impede a exclusão.
     * @return array{bloqueado: bool, motivo?: string, registro?: Model}
     */
    protected function excluirComBloqueio(Model $registro, array $relacoesQueBloqueiam): array
    {
        $motivo = $this->motivoDeBloqueio($registro, $relacoesQueBloqueiam);

        if ($motivo !== null) {
            return ['bloqueado' => true, 'motivo' => $motivo];
        }

        // Uma marca de arquivado que sobrou (ex.: o arquivamento rodou enquanto o registro era restaurado)
        // faria ele cair direto em "arquivado" e pular o prazo da lixeira (RF09/RF11).
        if ($registro->arquivado_em !== null) {
            $registro->forceFill(['arquivado_em' => null])->save(); // o delete() do SoftDeletes só grava deleted_at
        }

        $registro->delete();

        return ['bloqueado' => false, 'registro' => $registro];
    }

    /**
     * RF10: $reautenticadoComoAdmin é o resultado de ReautenticacaoAdmin::confirmar()
     * (senha de um admin do mesmo tenant), conferido por quem chama antes de restaurar. Os services
     * buscam o registro com lockForUpdate: o arquivamento não o muda entre a conferência e a gravação.
     *
     * @return array{restaurado: bool, motivo?: string, registro?: Model}
     */
    protected function restaurar(Model $registro, bool $reautenticadoComoAdmin): array
    {
        if (! $reautenticadoComoAdmin) {
            return ['restaurado' => false, 'motivo' => 'Para restaurar, é preciso a senha de um administrador.'];
        }

        $motivo = $this->motivoParaNaoRestaurar($registro);

        if ($motivo !== null) {
            return ['restaurado' => false, 'motivo' => $motivo];
        }

        $registro->restore();

        return ['restaurado' => true, 'registro' => $registro];
    }

    /**
     * RF12: exclusão física, sempre manual (nenhuma rotina chama isto), só de registro
     * ARQUIVADO e com a senha de um admin. Dependente em qualquer estado (ativo, na
     * lixeira ou arquivado) bloqueia: a chave estrangeira (restrictOnDelete) recusaria.
     * $autorizadoPor: o admin cuja senha foi conferida (ReautenticacaoAdmin); o service confere de novo
     * que ele é admin do tenant do registro — não basta quem chama dizer "sim".
     *
     * @return array{excluido: bool, motivo?: string, registro?: Model}
     */
    protected function excluirDeVez(Model $registro, ?int $autorizadoPor): array
    {
        $adminDoTenant = $autorizadoPor !== null && User::query()->whereKey($autorizadoPor)
            ->where('papel', 'admin')->where('tenant_id', $registro->getAttribute('tenant_id'))->exists();

        if (! $adminDoTenant) {
            return ['excluido' => false, 'motivo' => 'Para excluir de vez, é preciso a senha de um administrador.'];
        }

        $motivo = $this->motivoParaNaoExcluirDeVez($registro);

        if ($motivo !== null) {
            return ['excluido' => false, 'motivo' => $motivo];
        }

        try {
            $registro->forceDelete();
        } catch (QueryException $e) {
            // Em geral, um dependente que apareceu entre a conferência e a exclusão (a FK recusou). Nada foi
            // apagado. No log, só metadados: a mensagem de uma QueryException traz o SQL com os valores (AI-003).
            Log::warning('ExclusaoSegura: o banco recusou a exclusão definitiva', [
                'correlation_id' => (string) Str::uuid(),
                'codigo' => $e->getCode(),
                'entidade' => class_basename($registro),
            ]);

            return ['excluido' => false, 'motivo' => 'Não foi possível excluir de vez agora: pode haver registros vinculados a este. Nada foi apagado.'];
        }

        return ['excluido' => true, 'registro' => $registro];
    }

    /** RF08, só leitura: o motivo de não poder ir para a lixeira, ou null. Usado também no /agente/revisar. */
    public function motivoParaNaoExcluir(Model $registro): ?string
    {
        return $this->motivoDeBloqueio($registro, self::DEPENDENTES);
    }

    /**
     * Só leitura: o motivo de não poder voltar da lixeira, ou null. Registro arquivado não
     * volta; "pai" na lixeira ou arquivado também impede (o registro voltaria apontando para
     * algo excluído), e a frase diz qual é o pai.
     */
    public function motivoParaNaoRestaurar(Model $registro): ?string
    {
        if ($registro->arquivado_em !== null) {
            return 'Este registro já foi arquivado e não pode mais ser restaurado.';
        }

        foreach (self::PAIS as $relacao) {
            $pai = $registro->{$relacao}()->withTrashed()->first();

            if ($pai === null || ! $pai->trashed()) {
                continue;
            }

            $rotulo = self::ROTULOS_PAI[$relacao] ?? 'o registro vinculado';
            $nome = $pai->nome ?? $pai->descricao ?? $pai->numero ?? null;
            $quem = $rotulo.' #'.$pai->getKey().($nome !== null ? " ({$nome})" : '');

            return $pai->arquivado_em !== null
                ? "Não é possível restaurar: {$quem} está ".(str_starts_with($rotulo, 'a ') ? 'arquivada' : 'arquivado').'.'
                : "Não é possível restaurar: {$quem} está na lixeira. Restaure primeiro {$rotulo}.";
        }

        return null;
    }

    /** RF12, só leitura: o motivo de não poder excluir de vez, ou null. */
    public function motivoParaNaoExcluirDeVez(Model $registro): ?string
    {
        if (! $registro->trashed() || $registro->arquivado_em === null) {
            return 'Só dá para excluir de vez um registro arquivado.';
        }

        foreach (self::DEPENDENTES as $relacao) {
            if ($registro->{$relacao}()->withTrashed()->exists()) {
                $rotulo = self::ROTULOS_RELACAO[$relacao] ?? 'registros vinculados';

                return "Não é possível excluir de vez: existem {$rotulo} a este registro, incluindo itens na lixeira ou arquivados.";
            }
        }

        return null;
    }

    /** @param  string[]  $relacoes */
    private function motivoDeBloqueio(Model $registro, array $relacoes): ?string
    {
        foreach ($relacoes as $relacao) {
            if ($registro->{$relacao}()->exists()) {
                $rotulo = self::ROTULOS_RELACAO[$relacao] ?? "registros em {$relacao}";

                return "Não é possível excluir: existem {$rotulo} a este registro.";
            }
        }

        return null;
    }
}
