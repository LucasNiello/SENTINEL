<?php

namespace App\Services;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Mensagem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Clínica vet: caixa de entrada SIMULADA (só seed: nada é enviado nem recebido de verdade). A equipe lê as
 * mensagens, pede ao agente o que cada uma pede (P-B5: o texto vira um pedido normal, com Revisar e segurar) e
 * marca a mensagem como tratada.
 */
class MensagemService
{
    /** Mensagens do tenant, mais novas primeiro, com o nome do tutor quando ele foi identificado. Até 100. */
    public function buscarPorFiltro(?string $status, int $tenantId): Collection
    {
        return Mensagem::query()
            ->leftJoin('tutores', 'tutores.id', '=', 'mensagens.tutor_id')
            ->where('mensagens.tenant_id', $tenantId)
            ->when($status, fn ($query) => $query->where('mensagens.status', $status))
            ->orderByDesc('mensagens.created_at')
            ->limit(100)
            ->get(['mensagens.*', 'tutores.nome as tutor']);
    }

    /** Por id (a busca por texto não existe: a mensagem é aberta pela linha da tabela). Só as novas. */
    public function localizar(?int $id, ?string $busca, int $tenantId): Collection
    {
        return Mensagem::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'nova')
            ->when($id, fn ($query) => $query->whereKey($id), fn ($query) => $query->whereRaw('1 = 0'))
            ->limit(6)
            ->get();
    }

    /** Registro de outro tenant: ModelNotFoundException (inexistente). */
    public function marcarTratada(int $id, int $tenantId): Mensagem
    {
        return DB::transaction(function () use ($id, $tenantId) {
            $mensagem = Mensagem::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);

            if ($mensagem->status === 'tratada') {
                throw new RegraDeNegocioException('Esta mensagem já foi marcada como tratada.');
            }

            $mensagem->update(['status' => 'tratada']);

            return $mensagem;
        });
    }
}
