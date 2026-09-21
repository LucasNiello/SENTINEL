<?php

namespace App\Services\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Mecânica de exclusão segura compartilhada pelos services de domínio
 * (RF08-RF12): bloqueio por dependência ativa, mover pra lixeira (soft
 * delete) e restaurar. O arquivamento por prazo expirado é feito pelo
 * comando ArquivarLixeiraExpirada, não por aqui.
 */
trait ExclusaoSegura
{
    /** RF08: o motivo do bloqueio é exibido ao usuário, então não pode expor o nome técnico da relação. */
    private const ROTULOS_RELACAO = [
        'lancamentos' => 'lançamentos vinculados',
        'notasFiscais' => 'notas fiscais vinculadas',
    ];

    /**
     * @param  string[]  $relacoesQueBloqueiam  Nomes de relações Eloquent cuja
     *                                           existência impede a exclusão.
     * @return array{bloqueado: bool, motivo?: string}
     */
    protected function excluirComBloqueio(Model $registro, array $relacoesQueBloqueiam): array
    {
        foreach ($relacoesQueBloqueiam as $relacao) {
            if ($registro->{$relacao}()->exists()) {
                $rotulo = self::ROTULOS_RELACAO[$relacao] ?? "registros em {$relacao}";

                return [
                    'bloqueado' => true,
                    'motivo' => "Não é possível excluir: existem {$rotulo} a este registro.",
                ];
            }
        }

        $registro->delete();

        return ['bloqueado' => false];
    }

    /**
     * PENDÊNCIA RF10: a reautenticação por senha de administrador ainda não
     * existe (não há sistema de autenticação no projeto). $reautenticadoComoAdmin
     * é um placeholder que o chamador deve preencher quando essa camada existir
     * — por ora, quem decide o valor da flag é responsabilidade de quem chama.
     *
     * @return array{restaurado: bool, motivo?: string}
     */
    protected function restaurar(Model $registro, bool $reautenticadoComoAdmin): array
    {
        if (! $reautenticadoComoAdmin) {
            return ['restaurado' => false, 'motivo' => 'Reautenticação de administrador necessária (RF10 — pendente).'];
        }

        if ($registro->arquivado_em !== null) {
            return ['restaurado' => false, 'motivo' => 'Registro arquivado não pode ser restaurado por este fluxo.'];
        }

        $registro->restore();

        return ['restaurado' => true];
    }
}
