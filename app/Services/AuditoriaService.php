<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Str;

/**
 * Ponto único de gravação em audit_logs (RF06/RF07). Toda execução ou
 * tentativa negada de tool do AiAgentService passa por aqui.
 */
class AuditoriaService
{
    public function __construct(private ContextoUsuario $contexto)
    {
    }

    /**
     * @param  string  $resultado  'sucesso' | 'erro' (falha técnica) | 'recusado' (regra de negócio) | 'negado' (RBAC/whitelist)
     */
    public function registrar(
        string $tool,
        string $acao,
        ?string $entidadeTipo,
        ?int $entidadeId,
        array $parametros,
        string $resultado,
        ?string $mensagem,
        string $papel,
        bool $permitido,
    ): AuditLog {
        // LGPD: CPF e salário não ficam em claro no log de auditoria. O registro real está na
        // tabela da entidade; aqui basta provar que a ação ocorreu e permitir conferência. Vale
        // para todo resultado (sucesso, erro, recusado, negado), pois este é o ponto único de
        // gravação.
        $parametros = self::mascararCamposSensiveis($parametros);

        return AuditLog::create([
            'user_id' => $this->contexto->usuario()->id,
            'tool' => $tool,
            'acao' => $acao,
            'entidade_tipo' => $entidadeTipo,
            'entidade_id' => $entidadeId,
            'parametros' => $parametros,
            'resultado' => $resultado,
            'mensagem' => $mensagem === null ? null : Str::limit($mensagem, 250, ''),
            'papel' => $papel,
            'permitido' => $permitido,
            'tenant_id' => $this->contexto->tenantId(),
        ]);
    }

    /**
     * Mascara os campos pessoais/financeiros (LGPD) de um array de parâmetros. Só as chaves
     * 'cpf' e 'salario' são tocadas, e só se existirem; qualquer outro campo passa intacto.
     * Público e estático para valer também fora da auditoria: qualquer log que grave os
     * argumentos de uma tool (ex.: Log::error do AiAgentService) deve passar por aqui.
     *
     * @param  array<string|int, mixed>  $parametros
     * @return array<string|int, mixed>
     */
    public static function mascararCamposSensiveis(array $parametros): array
    {
        return collect($parametros)->map(fn ($valor, $campo) => match ($campo) {
            'cpf' => self::mascararCpf($valor),
            'salario' => $valor === null ? null : '[redigido]',
            default => $valor,
        })->all();
    }

    /**
     * Mantém só os 5 últimos dígitos (3 + os 2 verificadores): ***.***.333-44. Sempre no mesmo
     * formato, com ou sem pontuação na entrada. Não numérico ou curto demais vira '[redigido]'
     * (nunca devolve o valor bruto); idempotente se o valor já vier mascarado.
     */
    private static function mascararCpf(mixed $valor): mixed
    {
        if ($valor === null) {
            return null;
        }

        if (! is_scalar($valor)) {
            return '[redigido]';
        }

        $digitos = preg_replace('/\D/', '', (string) $valor);

        if (strlen($digitos) < 5) {
            return '[redigido]';
        }

        return '***.***.'.substr($digitos, -5, 3).'-'.substr($digitos, -2);
    }
}
