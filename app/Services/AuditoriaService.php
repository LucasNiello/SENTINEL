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
    /**
     * Fonte única dos campos sensíveis (LGPD): campo => método que mascara. A auditoria, a
     * leitura e o formulário do card (RF04, que só mostra a máscara) leem daqui.
     */
    private const MASCARAS = ['cpf' => 'mascararCpf', 'salario' => 'redigir', 'documento' => 'mascararDocumento'];

    /** O que fica no lugar de um valor que não pode aparecer nem em parte (ex.: salário). */
    public const REDIGIDO = '[redigido]';

    /**
     * Resultado NÃO-FINAL: o /agente/revisar recusou os valores e a proposta continua em rascunho.
     * Não é desfecho — a tela de auditoria (Bloco 5) separa estes registros dos desfechos em RESULTADOS_FINAIS.
     */
    public const REVISAO_COM_ERRO = 'revisao_com_erro';

    /** Desfechos: cada proposta termina em exatamente um deles. */
    public const RESULTADOS_FINAIS = ['sucesso', 'erro', 'recusado', 'negado', 'cancelado', 'expirado'];

    /** @return string[] */
    public static function camposSensiveis(): array
    {
        return array_keys(self::MASCARAS);
    }

    public function __construct(private ContextoUsuario $contexto)
    {
    }

    /**
     * @param  string  $resultado  desfechos (finais): 'sucesso' | 'erro' (falha técnica) | 'recusado' (regra de negócio
     *                             na execução ou prazo) | 'negado' (RBAC/whitelist) | 'cancelado' | 'expirado';
     *                             não-final: REVISAO_COM_ERRO (o Revisar recusou os valores; a proposta segue em rascunho)
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
        // para todo resultado (sucesso, erro, recusado, negado, cancelado, expirado) e para os
        // dois lados (proposto e confirmado), pois este é o ponto único de gravação.
        $parametros = self::mascararCamposSensiveis($parametros);
        // Campos que a assinatura não carrega (ver DetalhesAuditoria). Lidos do contêiner, e não
        // recebidos no construtor, para valer em qualquer instância deste service.
        $detalhes = app(DetalhesAuditoria::class)->atuais();
        $propostos = $detalhes['parametros_propostos'] ?? null;
        $anteriores = $detalhes['valores_anteriores'] ?? null;

        return AuditLog::create([
            'user_id' => $this->contexto->usuario()->id,
            'autorizado_por' => $detalhes['autorizado_por'] ?? null,
            'tool' => $tool,
            'origem' => $detalhes['origem'] ?? null,
            'acao' => $acao,
            'entidade_tipo' => $entidadeTipo,
            'entidade_id' => $entidadeId,
            // RF03: liga as linhas de um mesmo lote (uma por item); nulo fora de lote.
            'lote_id' => $detalhes['lote_id'] ?? null,
            'parametros' => $parametros,
            'parametros_propostos' => is_array($propostos) ? self::mascararCamposSensiveis($propostos) : null,
            'valores_anteriores' => is_array($anteriores) ? self::mascararCamposSensiveis($anteriores) : null,
            'tempo_ia_ms' => $detalhes['tempo_ia_ms'] ?? null,
            'resultado' => $resultado,
            'mensagem' => $mensagem === null ? null : Str::limit($mensagem, 250, ''),
            'papel' => $papel,
            'permitido' => $permitido,
            'tenant_id' => $this->contexto->tenantId(),
        ]);
    }

    /**
     * Mascara os campos pessoais/financeiros (LGPD) de um array de parâmetros. Só as chaves
     * de MASCARAS (cpf, salario, documento) são tocadas, e só se existirem; qualquer outro
     * campo passa intacto. Público e estático para valer também fora da auditoria: qualquer
     * log que grave os argumentos de uma tool (ex.: Log::error do AiAgentService) deve passar por aqui.
     *
     * @param  array<string|int, mixed>  $parametros
     * @return array<string|int, mixed>
     */
    public static function mascararCamposSensiveis(array $parametros): array
    {
        return collect($parametros)->map(function ($valor, $campo) {
            $metodo = self::MASCARAS[$campo] ?? null;

            return $metodo === null ? $valor : self::$metodo($valor);
        })->all();
    }

    /** Salário: nem parte do valor fica. */
    private static function redigir(mixed $valor): mixed
    {
        return $valor === null ? null : self::REDIGIDO;
    }

    /**
     * "documento" é CPF ou CNPJ. Com 11 dígitos é CPF (pessoa física): mesma máscara do campo cpf.
     * CNPJ (14 dígitos) e outros formatos passam intactos; não-escalar vira '[redigido]'.
     */
    private static function mascararDocumento(mixed $valor): mixed
    {
        if ($valor === null) {
            return null;
        }

        if (! is_scalar($valor)) {
            return self::REDIGIDO;
        }

        return strlen(preg_replace('/\D/', '', (string) $valor)) === 11 ? self::mascararCpf($valor) : $valor;
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
            return self::REDIGIDO;
        }

        $digitos = preg_replace('/\D/', '', (string) $valor);

        if (strlen($digitos) < 5) {
            return self::REDIGIDO;
        }

        return '***.***.'.substr($digitos, -5, 3).'-'.substr($digitos, -2);
    }
}
