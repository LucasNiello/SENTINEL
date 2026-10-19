<?php

namespace App\Services;

use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Propostas de escrita à espera da confirmação humana (RNF02), guardadas na
 * sessão sob um token de uso único. Toda proposta tem um desfecho só
 * (confirmada, cancelada ou expirada) e sai da sessão quando ele acontece (R3):
 * os argumentos não ficam para trás.
 *
 * RISCO ACEITO (Lucas, 19/10): a sessão do Laravel não serializa requisições
 * simultâneas — a última a gravar vence. Se um cancelamento (que grava a
 * sessão) terminar logo depois de um /agente/comando que criou outra proposta,
 * essa proposta nova pode sumir: o card dela recebe "Confirmação inválida ou já
 * utilizada." e ela fica sem registro de desfecho. A janela é a duração do
 * cancelamento (dezenas de ms). Falha fechada: nada é gravado e o usuário
 * repete o pedido.
 */
class ConfirmacoesPendentes
{
    private const CHAVE = 'confirmacoes';

    /** Espera mínima entre propor e confirmar — igual ao hold físico do front (DURACAO_HOLD_MS). */
    public const ESPERA_MINIMA_S = 2;

    /**
     * Validade máxima da confirmação — janela da interface (DURACAO_IDLE_MS, 10 s) + folga para a
     * requisição chegar. É também o limite único entre "cancelado" e "expirado" (cancelar, sair e varredura).
     */
    public const VALIDADE_MAXIMA_S = 12;

    /**
     * Guarda a proposta e devolve o token que autoriza o /agente/confirmar.
     *
     * @param  array|null  $propostos  o que a IA propôs, quando $argumentos já traz edições do humano
     *                                 (formulário devolvido com erro): a auditoria compara com ele
     */
    public function guardar(Session $sessao, string $tool, array $argumentos, ?array $propostos = null): string
    {
        $token = (string) Str::uuid();

        $sessao->put(self::CHAVE.".{$token}", [
            'tool' => $tool,
            'argumentos' => $argumentos,
            ...($propostos === null ? [] : ['propostos' => $propostos]),
            // Em segundos, com milissegundos: com segundos inteiros, a fronteira dos 12 s errava em até 1 s.
            'emitido_em' => now()->getTimestampMs() / 1000,
        ]);

        return $token;
    }

    /**
     * O que a IA propôs. Depois de um formulário devolvido com erro, "argumentos" já traz as
     * edições do humano; a proposta original fica em "propostos" — é ela que a auditoria
     * registra como proposto, em qualquer desfecho (RF04).
     */
    public static function propostos(array $pendente): array
    {
        return $pendente['propostos'] ?? $pendente['argumentos'] ?? [];
    }

    /** Tira a proposta da sessão (uso único). Null se não existe ou já saiu. */
    public function retirar(Session $sessao, string $token): ?array
    {
        $pendente = $sessao->pull(self::CHAVE.".{$token}");

        return is_array($pendente) ? $pendente : null;
    }

    /**
     * M1: duas requisições simultâneas leem a mesma sessão e as duas "retiram" o
     * token. O add é atômico no store (chave única no driver database), então só
     * a primeira dá o desfecho (confirmar, cancelar ou expirar). A marca dura o
     * tempo de vida da sessão: uma requisição concorrente pode regravar a sessão
     * com a proposta já concluída, e ela não pode ser auditada de novo depois.
     * Premissa: SESSION_LIFETIME conta inatividade e se renova a cada requisição,
     * então toda tela autenticada nova deve ficar no grupo da varredura
     * (routes/web.php) — senão a sessão vive mais que a marca sem ser varrida.
     */
    public function concluir(string $token): bool
    {
        return Cache::add("agente:token_usado:{$token}", true, now()->addMinutes(max(1, (int) config('session.lifetime'))));
    }

    /** Segundos (com fração) desde a emissão, pelo relógio do servidor — nunca pelo que o front informa. */
    public function decorrido(array $pendente): float
    {
        return now()->getTimestampMs() / 1000 - (float) $pendente['emitido_em'];
    }

    /**
     * Tira da sessão as propostas que passaram da validade e devolve as que ainda
     * não tinham desfecho, para serem auditadas como expiradas.
     *
     * @return array<int, array{tool: string, argumentos: array, emitido_em: float}>
     */
    public function retirarVencidas(Session $sessao): array
    {
        return $this->retirarOnde($sessao, fn (array $pendente) => $this->decorrido($pendente) > self::VALIDADE_MAXIMA_S);
    }

    /**
     * Logout: todas saem da sessão, e as que ainda não tinham desfecho voltam
     * para serem auditadas.
     *
     * @return array<int, array{tool: string, argumentos: array, emitido_em: float}>
     */
    public function retirarTodas(Session $sessao): array
    {
        return $this->retirarOnde($sessao, fn () => true);
    }

    /**
     * Entrada malformada sai sempre, sem auditoria. As já concluídas por outra
     * requisição (ver concluir()) também saem, mas não voltam: já têm desfecho.
     */
    private function retirarOnde(Session $sessao, Closure $sai): array
    {
        $retiradas = [];

        foreach ((array) $sessao->get(self::CHAVE, []) as $token => $pendente) {
            $valida = is_array($pendente) && isset($pendente['tool'], $pendente['emitido_em']);

            if ($valida && ! $sai($pendente)) {
                continue;
            }

            $sessao->forget(self::CHAVE.".{$token}");

            if ($valida && $this->concluir((string) $token)) {
                $retiradas[] = $pendente;
            }
        }

        return $retiradas;
    }
}
