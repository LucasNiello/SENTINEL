<?php

namespace App\Services;

use Illuminate\Contracts\Session\Session;

/** Contexto textual limitado; propostas nunca são registradas como ações executadas. */
class ConversaAgente
{
    private const CHAVE = 'agente_conversa';
    private const MAX_MENSAGENS = 16; // contexto limitado, sem marcadores internos enviados à IA
    private const MAX_CARACTERES = 8000;
    private const AVISO_PROPOSTA = 'Proposta pendente de confirmação humana. Nenhuma escrita foi executada.';

    public function __construct(private ContextoUsuario $contexto) {}

    private function identidade(): string
    {
        return $this->contexto->usuario()->getKey().':'.$this->contexto->tenantId();
    }

    public function ler(Session $sessao): array
    {
        $dados = $sessao->get(self::CHAVE, []);
        if (($dados['identidade'] ?? null) !== $this->identidade()) {
            $sessao->forget(self::CHAVE);
            return [];
        }
        // É um marcador da interface, não uma resposta da IA. Reenviá-lo como
        // fala do assistente induz o modelo a copiá-lo no próximo cadastro,
        // sem chamar uma tool e, portanto, sem produzir um formulário.
        // Preservar o pedido do usuário mantém o contexto de complementação.
        $internosLegados = [
            self::AVISO_PROPOSTA,
            'A consulta foi concluída. Os dados não são guardados no histórico.',
            'Não há lançamentos pendentes nesse período. Nada a conciliar.',
        ];
        return array_values(array_filter($dados['mensagens'] ?? [], fn ($m) =>
            ! ($m['interno'] ?? false)
            && ! (($m['role'] ?? null) === 'assistant' && in_array($m['content'] ?? null, $internosLegados, true))));
    }

    public function guardar(Session $sessao, string $mensagem, array $resposta): void
    {
        $texto = match ($resposta['tipo'] ?? null) {
            'texto' => $resposta['mensagem'] ?? '',
            'confirmacao_pendente' => self::AVISO_PROPOSTA,
            'resultado_leitura' => 'A consulta foi concluída. Os dados não são guardados no histórico.',
            default => null,
        };
        if ($texto === null) return;
        $interno = isset($resposta['tool']) || in_array($resposta['tipo'] ?? null, ['confirmacao_pendente', 'resultado_leitura'], true);
        $this->acrescentar($sessao, $mensagem, $texto, $interno);
    }

    /**
     * Escrita confirmada no card: o agente passa a saber que ela aconteceu. Entra como
     * a confirmação do humano e a frase fixa do servidor, sem valores do registro.
     */
    public function registrarResultado(Session $sessao, string $frase): void
    {
        $this->acrescentar($sessao, 'Confirmei a proposta no card.', $frase);
    }

    private function acrescentar(Session $sessao, string $mensagem, string $texto, bool $interno = false): void
    {
        $historico = $this->ler($sessao);
        $historico[] = ['role' => 'user', 'content' => $this->sanitizar($mensagem)];
        $historico[] = ['role' => 'assistant', 'content' => $this->sanitizar($texto)];
        if ($interno) $historico[array_key_last($historico)]['interno'] = true;
        $historico = array_slice($historico, -self::MAX_MENSAGENS);
        while (count($historico) > 2 && array_sum(array_map(fn ($m) => mb_strlen($m['content']), $historico)) > self::MAX_CARACTERES) {
            array_splice($historico, 0, 2);
        }
        $sessao->put(self::CHAVE, ['identidade' => $this->identidade(), 'mensagens' => $historico]);
    }

    private function sanitizar(string $texto): string
    {
        // Credenciais não são necessárias aos fluxos de cadastro disponíveis.
        if (preg_match('/senha|password|api.?key|app.?key|csrf|cookie|session.?id|bearer/i', $texto)) {
            return '[Mensagem com credenciais omitida do histórico]';
        }
        foreach ([config('services.azure_foundry.api_key'), config('app.key')] as $segredo) {
            if (is_string($segredo) && $segredo !== '') $texto = str_replace($segredo, '[credencial omitida]', $texto);
        }
        $texto = preg_replace('/\b\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}\b/', '[documento omitido]', $texto);
        $texto = preg_replace('/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/', '[documento omitido]', $texto);
        // Salário: o valor logo depois de "salário" ou "remuneração", até 30 caracteres adiante, com ou sem R$
        // (aprovado pelo Lucas em 20/10). Limites conhecidos: "ganha 4500" (sem a palavra) e o segundo número de
        // "entre 4000 e 5000" passam. "Vencimento" ficou de fora: num app financeiro é data de vencimento.
        $texto = preg_replace('/\b(sal[aá]rios?|remunera[cç](?:[aã]o|[oõ]es))\b(\D{0,30}?)(?:R\$\s*)?\d(?:[\d.,]*\d)?/iu', '$1$2[valor omitido]', $texto);
        return mb_substr($texto, 0, 1000);
    }
}
