<?php

namespace App\Services;

use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Propostas de escrita à espera da confirmação humana (RNF02), guardadas na
 * sessão. Cada proposta passa por dois estados (RF04, Bloco 2b):
 *
 * - "rascunho": criado pelo /agente/comando com o que a IA propôs. É o
 *   formulário em edição, sem token de segurar, com prazo de PRAZO_RASCUNHO_S
 *   contado desde a proposta (não reinicia no "Editar").
 * - "revisada": criada pelo /agente/revisar depois de validar os valores. Guarda
 *   os valores revisados e o token de segurar, com a janela de sempre (2 a 12 s).
 *   "Editar" mata esse token sem desfecho; o rascunho continua.
 *
 * Toda proposta tem um desfecho só (confirmada, cancelada ou expirada) e, quando
 * ele acontece, o rascunho e os tokens dela saem da sessão (R3).
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

    /** Espera mínima entre revisar e confirmar — igual ao hold físico do front (DURACAO_HOLD_MS). */
    public const ESPERA_MINIMA_S = 2;

    /**
     * Validade máxima do token de segurar — janela da interface (DURACAO_IDLE_MS, 10 s) + folga para a
     * requisição chegar. É também o limite único entre "cancelado" e "expirado" de uma proposta revisada.
     */
    public const VALIDADE_MAXIMA_S = 12;

    /** Prazo do rascunho (formulário em edição), contado desde a proposta da IA; não reinicia no "Editar". */
    public const PRAZO_RASCUNHO_S = 300;

    /** Guarda o que a IA propôs como rascunho e devolve o id da proposta. */
    public function guardarRascunho(Session $sessao, string $tool, array $argumentos, ?array $propostos = null, string $origem = 'agente'): string
    {
        $proposta = (string) Str::uuid();

        $sessao->put(self::CHAVE.".{$proposta}", [
            'estado' => 'rascunho',
            'tool' => $tool,
            'argumentos' => $argumentos,
            // O que a IA pediu de fato (atualizar: id/busca + campos que mudam); [] quando o formulário veio sem IA.
            ...($propostos === null ? [] : ['propostos' => $propostos]),
            // Por onde a ação entrou (coluna origem de audit_logs): agente ou formulario.
            'origem' => $origem,
            'criado_em' => self::agora(),
        ]);

        return $proposta;
    }

    /** O rascunho ainda em edição; null se não existe, já teve desfecho ou a chave não é de rascunho. */
    public function rascunho(Session $sessao, string $proposta): ?array
    {
        $pendente = $sessao->get(self::CHAVE.".{$proposta}");

        return is_array($pendente) && ($pendente['estado'] ?? null) === 'rascunho' ? $pendente : null;
    }

    /**
     * Revisão aprovada: guarda os valores revisados e emite o token de segurar. O
     * confirmar usa só estes valores, nunca o que o navegador reenviar. Um token
     * anterior da mesma proposta morre: só um token vivo por proposta.
     */
    public function emitirToken(Session $sessao, string $proposta, array $rascunho, array $revisados): string
    {
        $this->retirarTokens($sessao, $proposta);
        $token = (string) Str::uuid();

        $sessao->put(self::CHAVE.".{$token}", [
            'estado' => 'revisada',
            'tool' => $rascunho['tool'],
            'argumentos' => $revisados,
            'propostos' => self::propostos($rascunho),
            'proposta' => $proposta,
            'origem' => self::origem($rascunho),
            // Os 5 min do rascunho são absolutos: o token também vence quando eles acabam (expirou()).
            'proposta_criada_em' => $rascunho['criado_em'],
            // Em segundos, com milissegundos: com segundos inteiros, a fronteira dos 12 s errava em até 1 s.
            'emitido_em' => self::agora(),
        ]);

        return $token;
    }

    /**
     * Revisão recusada: o rascunho passa a guardar o que o humano já editou (o card
     * volta com esses valores, e campo sensível em branco mantém o editado, não o
     * da IA). O proposto da IA fica em "propostos"; o prazo não muda.
     */
    public function atualizarRascunho(Session $sessao, string $proposta, array $rascunho, array $revisados): void
    {
        $sessao->put(self::CHAVE.".{$proposta}", [
            ...$rascunho,
            'argumentos' => $revisados,
            'propostos' => self::propostos($rascunho),
        ]);
    }

    /** A proposta tem um token de segurar já vencido? (o desfecho dela é "expirado", dado pela varredura). */
    public function temTokenVencido(Session $sessao, string $proposta): bool
    {
        foreach ((array) $sessao->get(self::CHAVE, []) as $pendente) {
            if (is_array($pendente) && ($pendente['proposta'] ?? null) === $proposta && $this->expirou($pendente)) {
                return true;
            }
        }

        return false;
    }

    /** "Editar": o token de segurar morre sem desfecho. Devolve a proposta dele, ou null se não era um token de segurar. */
    public function invalidarToken(Session $sessao, string $token): ?string
    {
        $pendente = $sessao->get(self::CHAVE.".{$token}");

        if (! is_array($pendente) || ($pendente['estado'] ?? null) !== 'revisada') {
            return null;
        }

        $sessao->forget(self::CHAVE.".{$token}");

        return $pendente['proposta'] ?? null;
    }

    /** Tira da sessão o token de segurar (uso único). Null se não existe ou não é token de segurar — o rascunho fica. */
    public function retirarRevisada(Session $sessao, string $token): ?array
    {
        $pendente = $sessao->get(self::CHAVE.".{$token}");

        if (! is_array($pendente) || ($pendente['estado'] ?? 'revisada') !== 'revisada') {
            return null;
        }

        $sessao->forget(self::CHAVE.".{$token}");

        return $pendente;
    }

    /** Tira da sessão a pendência da chave, rascunho ou revisada (o cancelar aceita as duas). */
    public function retirar(Session $sessao, string $chave): ?array
    {
        $pendente = $sessao->pull(self::CHAVE.".{$chave}");

        return is_array($pendente) ? $pendente : null;
    }

    /** Desfecho da proposta: o rascunho e todos os tokens dela saem da sessão (R3). */
    public function encerrar(Session $sessao, string $proposta): void
    {
        $sessao->forget(self::CHAVE.".{$proposta}");
        $this->retirarTokens($sessao, $proposta);
    }

    /** Id da proposta a que a pendência pertence: o token de segurar aponta para o rascunho dele. */
    public static function proposta(string $chave, array $pendente): string
    {
        return (string) ($pendente['proposta'] ?? $chave);
    }

    /**
     * O que a IA propôs. Depois da revisão, "argumentos" traz os valores revisados
     * pelo humano; a proposta original fica em "propostos" — é ela que a auditoria
     * registra como proposto, em qualquer desfecho (RF04).
     */
    public static function propostos(array $pendente): array
    {
        return $pendente['propostos'] ?? $pendente['argumentos'] ?? [];
    }

    /** Por onde a proposta entrou (coluna origem de audit_logs): agente (padrão) ou formulario. */
    public static function origem(array $pendente): string
    {
        return (string) ($pendente['origem'] ?? 'agente');
    }

    /**
     * O registro que a proposta ia alterar (atualizar e lixeira): o id que o SERVIDOR resolveu e
     * guardou nos argumentos. Vai para a auditoria em todo desfecho sem execução; cadastro não tem.
     */
    public static function entidadeId(array $pendente): ?int
    {
        $id = $pendente['argumentos']['id'] ?? null;

        return is_numeric($id) && (int) $id >= 1 ? (int) $id : null;
    }

    /**
     * M1: duas requisições simultâneas leem a mesma sessão e as duas "retiram" a
     * mesma pendência. O add é atômico no store (chave única no driver database),
     * então só a primeira dá o desfecho da proposta (confirmar, cancelar ou
     * expirar). A marca é por PROPOSTA, não por token: dois tokens da mesma
     * proposta nunca rendem duas gravações. Ela dura o tempo de vida da sessão:
     * uma requisição concorrente pode regravar a sessão com a pendência já
     * concluída, e ela não pode ser auditada de novo depois.
     * Premissa: SESSION_LIFETIME conta inatividade e se renova a cada requisição,
     * então toda tela autenticada nova deve ficar no grupo da varredura
     * (routes/web.php) — senão a sessão vive mais que a marca sem ser varrida.
     */
    public function concluir(string $proposta): bool
    {
        return Cache::add("agente:token_usado:{$proposta}", true, now()->addMinutes(max(1, (int) config('session.lifetime'))));
    }

    /** Segundos (com fração) desde a revisão (token) ou desde a proposta (rascunho), pelo relógio do servidor. */
    public function decorrido(array $pendente): float
    {
        return self::agora() - (float) ($pendente['emitido_em'] ?? $pendente['criado_em'] ?? 0);
    }

    /**
     * Passou do prazo: 5 min desde a proposta (absoluto, vale também para o token
     * dela) ou, no token de segurar, 12 s desde a revisão — o que vencer primeiro.
     * É o limite único entre "cancelado" e "expirado" em todas as rotas
     * (confirmar, cancelar, sair e varredura).
     */
    public function expirou(array $pendente): bool
    {
        if (($pendente['estado'] ?? 'revisada') === 'rascunho') {
            return $this->decorrido($pendente) > self::PRAZO_RASCUNHO_S;
        }

        $propostaCriadaEm = $pendente['proposta_criada_em'] ?? null;

        return $this->decorrido($pendente) > self::VALIDADE_MAXIMA_S
            || ($propostaCriadaEm !== null && self::agora() - (float) $propostaCriadaEm > self::PRAZO_RASCUNHO_S);
    }

    /**
     * Tira da sessão as propostas que passaram do prazo e devolve as que ainda
     * não tinham desfecho, para serem auditadas como expiradas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function retirarVencidas(Session $sessao): array
    {
        return $this->retirarOnde($sessao, fn (array $pendente) => $this->expirou($pendente));
    }

    /**
     * Logout: todas saem da sessão, e as que ainda não tinham desfecho voltam
     * para serem auditadas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function retirarTodas(Session $sessao): array
    {
        return $this->retirarOnde($sessao, fn () => true);
    }

    /**
     * Uma pendência por proposta volta (a primeira que sair), e todas as da proposta
     * saem da sessão. Tokens de segurar vêm antes dos rascunhos: o token já carrega
     * os dois prazos (o dele e o do rascunho). Entrada malformada sai sem auditoria; a
     * proposta já concluída por outra requisição (ver concluir()) sai sem voltar.
     */
    private function retirarOnde(Session $sessao, Closure $sai): array
    {
        $entradas = (array) $sessao->get(self::CHAVE, []);
        $revisadas = array_filter($entradas, fn ($pendente) => ! is_array($pendente) || ($pendente['estado'] ?? 'revisada') === 'revisada');
        $encerradas = [];
        $retiradas = [];

        foreach ($revisadas + $entradas as $chave => $pendente) {
            $chave = (string) $chave;
            $valida = is_array($pendente) && isset($pendente['tool']) && (isset($pendente['emitido_em']) || isset($pendente['criado_em']));

            if (! $valida) {
                $sessao->forget(self::CHAVE.".{$chave}");

                continue;
            }

            $proposta = self::proposta($chave, $pendente);

            if (isset($encerradas[$proposta]) || ! $sai($pendente)) {
                continue;
            }

            $encerradas[$proposta] = true;

            if ($this->concluir($proposta)) {
                $retiradas[] = $pendente;
            }
        }

        foreach (array_keys($encerradas) as $proposta) {
            $this->encerrar($sessao, (string) $proposta);
        }

        return $retiradas;
    }

    /** Tira da sessão os tokens de segurar de uma proposta. */
    private function retirarTokens(Session $sessao, string $proposta): void
    {
        foreach ((array) $sessao->get(self::CHAVE, []) as $chave => $pendente) {
            if (is_array($pendente) && ($pendente['proposta'] ?? null) === $proposta) {
                $sessao->forget(self::CHAVE.".{$chave}");
            }
        }
    }

    /** Em segundos, com milissegundos. */
    private static function agora(): float
    {
        return now()->getTimestampMs() / 1000;
    }
}
