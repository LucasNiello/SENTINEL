<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Consulta da auditoria (RF06/RF07, Etapa A1): só admin e só o tenant do usuário logado —
 * as duas regras ficam aqui, no service, e não só na rota. Registro de outro tenant é
 * tratado como inexistente. O que sai passa de novo pela máscara LGPD (defesa em
 * profundidade: o registro já foi gravado mascarado).
 */
class ConsultaAuditoria
{
    /** Nome com que a própria consulta aparece na auditoria (tentativa negada, exportação). */
    public const TOOL = 'consultar_auditoria';

    public const POR_PAGINA = 25;

    /** Filtro "grupo": desfechos (RESULTADOS_FINAIS) ou só as revisões recusadas (não-final). */
    public const GRUPOS = ['desfechos', 'revisoes'];

    public function __construct(private ContextoUsuario $contexto, private AuditoriaService $auditoria)
    {
    }

    /** @return string[] valores aceitos no filtro "resultado" */
    public static function resultados(): array
    {
        return [...AuditoriaService::RESULTADOS_FINAIS, AuditoriaService::REVISAO_COM_ERRO];
    }

    /**
     * Só admin. Quem não é fica na auditoria como "negado" (como numa tool negada) e recebe
     * AuthorizationException. Sem usuário, papel ou tenant: AuthenticationException (ContextoUsuario).
     *
     * @throws AuthorizationException
     */
    public function autorizar(string $acao): void
    {
        $papel = $this->contexto->papel();
        $this->contexto->tenantId();

        if ($papel === 'admin') {
            return;
        }

        app(DetalhesAuditoria::class)->com(['origem' => 'manual'], fn () => $this->auditoria->registrar(
            self::TOOL, $acao, 'AuditLog', null, [], 'negado', "Papel {$papel} abaixo do mínimo exigido (admin).", $papel, false,
        ));

        throw new AuthorizationException;
    }

    /**
     * @param  array{de?: string, ate?: string, usuario?: int, tool?: string, resultado?: string, grupo?: string, pagina?: int}  $filtros  já validados
     * @return array{registros: array<int, array<string, mixed>>, pagina: int, paginas: int, total: int, por_pagina: int}
     *
     * @throws AuthorizationException
     */
    public function listar(array $filtros): array
    {
        $this->autorizar('consultar');

        $pagina = $this->consulta($filtros)->paginate(self::POR_PAGINA, ['*'], 'pagina', (int) ($filtros['pagina'] ?? 1));

        return [
            'registros' => array_map(fn (AuditLog $log) => $this->item($log), $pagina->items()),
            'pagina' => $pagina->currentPage(),
            'paginas' => $pagina->lastPage(),
            'total' => $pagina->total(),
            'por_pagina' => self::POR_PAGINA,
        ];
    }

    /**
     * Um registro com o proposto (o que a IA pediu) ao lado do confirmado (o que foi
     * executado), mascarados. Outro tenant ou inexistente: ModelNotFoundException.
     *
     * @throws AuthorizationException
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function detalhe(int $id): array
    {
        $this->autorizar('consultar');

        $log = AuditLog::query()->where('tenant_id', $this->contexto->tenantId())->with('usuario:id,name')->findOrFail($id);
        // Tool fora do catálogo: os argumentos são o que a IA mandou, sem esquema — nada deles é exibido.
        $desconhecida = $log->acao === 'desconhecida';

        return [
            ...$this->item($log),
            'mensagem' => $log->mensagem,
            'tempo_ia_ms' => $log->tempo_ia_ms,
            'autorizado_por' => $log->autorizado_por === null ? null
                : User::query()->where('tenant_id', $log->tenant_id)->whereKey($log->autorizado_por)->value('name'),
            'proposto' => $desconhecida && $log->parametros_propostos !== null ? AuditoriaService::REDIGIDO : $this->mascarar($log->parametros_propostos),
            // A2: o "antes" dos campos que a atualização mudou (nulo fora de atualização).
            'anterior' => $this->mascarar($log->valores_anteriores),
            'confirmado' => $desconhecida ? AuditoriaService::REDIGIDO
                : $this->mascarar(Arr::except((array) $log->parametros, ['tenant_id'])),
        ];
    }

    /** Sempre do tenant do usuário; os demais filtros só restringem. Mais recentes primeiro. */
    private function consulta(array $filtros): Builder
    {
        $grupo = $filtros['grupo'] ?? null;

        return AuditLog::query()
            ->where('tenant_id', $this->contexto->tenantId())
            ->with('usuario:id,name')
            ->when($filtros['de'] ?? null, fn (Builder $q, string $de) => $q->where('created_at', '>=', Carbon::createFromFormat('Y-m-d', $de)->startOfDay()))
            ->when($filtros['ate'] ?? null, fn (Builder $q, string $ate) => $q->where('created_at', '<=', Carbon::createFromFormat('Y-m-d', $ate)->endOfDay()))
            ->when($filtros['usuario'] ?? null, fn (Builder $q, $usuario) => $q->where('user_id', (int) $usuario))
            ->when($filtros['tool'] ?? null, fn (Builder $q, string $tool) => $q->where('tool', $tool))
            ->when($filtros['resultado'] ?? null, fn (Builder $q, string $resultado) => $q->where('resultado', $resultado))
            ->when($grupo === 'desfechos', fn (Builder $q) => $q->whereIn('resultado', AuditoriaService::RESULTADOS_FINAIS))
            ->when($grupo === 'revisoes', fn (Builder $q) => $q->where('resultado', AuditoriaService::REVISAO_COM_ERRO))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /** Linha da lista: quem, quando, o quê e o desfecho — sem parâmetros. */
    private function item(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'data' => $log->created_at?->format('Y-m-d H:i:s'),
            'usuario' => $log->usuario?->name,
            'papel' => $log->papel,
            'tool' => $log->tool,
            'acao' => $log->acao,
            'entidade_tipo' => $log->entidade_tipo,
            'entidade_id' => $log->entidade_id,
            'resultado' => $log->resultado,
            'final' => in_array($log->resultado, AuditoriaService::RESULTADOS_FINAIS, true),
            'permitido' => $log->permitido,
            'origem' => $log->origem,
        ];
    }

    /**
     * A mesma máscara da gravação, mas em todos os níveis e sem distinguir maiúsculas: o que
     * sai para a tela não depende de o registro ter sido gravado no formato esperado.
     */
    private function mascarar(?array $parametros): ?array
    {
        if ($parametros === null) {
            return null;
        }

        $saida = [];

        foreach ($parametros as $campo => $valor) {
            $chave = is_string($campo) ? mb_strtolower($campo) : $campo;
            // Chave sensível primeiro: com valor array, a máscara dela dá '[redigido]' (descer exporia o conteúdo).
            $saida[$campo] = in_array($chave, AuditoriaService::camposSensiveis(), true) || ! is_array($valor)
                ? AuditoriaService::mascararCamposSensiveis([$chave => $valor])[$chave]
                : $this->mascarar($valor);
        }

        return $saida;
    }
}
