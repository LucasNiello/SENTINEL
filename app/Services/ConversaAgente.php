<?php

namespace App\Services;

use Illuminate\Contracts\Session\Session;

/** Contexto textual limitado; propostas nunca são registradas como ações executadas. */
class ConversaAgente
{
    private const CHAVE = 'agente_conversa';
    private const MAX_MENSAGENS = 16; // oito pares user/assistant
    private const MAX_CARACTERES = 8000;

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
        return $dados['mensagens'] ?? [];
    }

    public function guardar(Session $sessao, string $mensagem, array $resposta): void
    {
        $texto = match ($resposta['tipo'] ?? null) {
            'texto' => $resposta['mensagem'] ?? '',
            'confirmacao_pendente' => 'Proposta pendente de confirmação humana. Nenhuma escrita foi executada.',
            'resultado_leitura' => 'A consulta foi concluída. Os dados não são guardados no histórico.',
            default => null,
        };
        if ($texto === null) return;
        $historico = $this->ler($sessao);
        $historico[] = ['role' => 'user', 'content' => $this->sanitizar($mensagem)];
        $historico[] = ['role' => 'assistant', 'content' => $this->sanitizar($texto)];
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
        return mb_substr($texto, 0, 1000);
    }
}
