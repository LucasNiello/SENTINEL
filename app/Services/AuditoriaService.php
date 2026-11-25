<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\Mascara;
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
    private const MASCARAS = [
        'cpf' => 'mascararCpf', 'salario' => 'redigir', 'documento' => 'mascararDocumento', 'telefone' => 'mascararTelefone',
        // Clínica vet: texto clínico do prontuário (e o texto livre do adendo e das mensagens) nunca fica em claro
        // na auditoria nem no CSV; só o fato de que o campo existiu.
        'anamnese' => 'redigir', 'exame_fisico' => 'redigir', 'diagnostico' => 'redigir', 'conduta' => 'redigir', 'texto' => 'redigir',
    ];

    /** O que fica no lugar de um valor que não pode aparecer nem em parte (ex.: salário). */
    public const REDIGIDO = Mascara::REDIGIDO;

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
     *                             na execução ou prazo) | 'negado' (RBAC/whitelist/divergência de intenção) | 'cancelado' | 'expirado';
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
        // Adendo final (15/11): o tipo de conferência que autorizou (crmv+senha, pin, senha de admin), no detalhe da mensagem.
        if (is_string($detalhes['conferencia'] ?? null)) {
            $mensagem = $mensagem === null ? 'conferência: '.$detalhes['conferencia'] : Str::limit($mensagem, 220, '').' (conferência: '.$detalhes['conferencia'].')';
        }

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

            if ($metodo !== null) {
                return self::$metodo($valor);
            }

            // Também dentro das listas e objetos aninhados (ex.: os itens de uma venda): CPF ali não fica em claro.
            return is_array($valor) ? self::mascararCamposSensiveis($valor) : $valor;
        })->all();
    }

    /**
     * O inverso da máscara: tira de $dados todo campo sensível (cpf, documento, telefone, salário…) cujo valor é uma
     * máscara ("(19) 9****-*321", "123.***.**9-00", "[redigido]"). Máscara nunca é dado: a chave some e o valor atual é
     * mantido (atualizar) ou fica vazio (criar). Desce nas listas aninhadas, como a própria máscara.
     *
     * @param  array<string|int, mixed>  $dados
     * @return array<string|int, mixed>
     */
    public static function semValoresMascarados(array $dados): array
    {
        $limpo = [];
        foreach ($dados as $campo => $valor) {
            if (is_string($campo) && isset(self::MASCARAS[$campo]) && Mascara::mascarado($valor)) {
                continue;
            }
            $limpo[$campo] = is_array($valor) ? self::semValoresMascarados($valor) : $valor;
        }

        return $limpo;
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

    /** CPF: 3 primeiros e 3 últimos dígitos (123.***.**9-00); a regra é de App\Support\Mascara, a única do projeto. */
    private static function mascararCpf(mixed $valor): mixed
    {
        return Mascara::cpf($valor);
    }

    /** Telefone: (19) 9****-*321, pela mesma Mascara. */
    private static function mascararTelefone(mixed $valor): mixed
    {
        return Mascara::telefone($valor);
    }
}
