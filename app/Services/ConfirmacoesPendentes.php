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
 *   os valores revisados e o token de confirmação (uso único). Decisão B do Lucas
 *   (13/11): não há mais janela de 2 a 12 s; o token vale até o fim dos 5 min da
 *   proposta. O segurar de 2 s virou só gesto visual, nas ações destrutivas.
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

    /**
     * Prazo da proposta, contado desde a proposta da IA e absoluto: vale para o rascunho (não reinicia no "Editar")
     * e para o token de confirmação dele. É o limite único entre "cancelado" e "expirado" (decisão B, 13/11).
     */
    public const PRAZO_RASCUNHO_S = 300;

    /** Guarda o que a IA propôs como rascunho e devolve o id da proposta. */
    public function guardarRascunho(Session $sessao, string $tool, array $argumentos, ?array $propostos = null, string $origem = 'agente', ?int $tempoIaMs = null, ?array $loteIds = null): string
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
            // B1 (RNF04): quanto a IA levou para propor; vai para a auditoria do desfecho.
            ...($tempoIaMs === null ? [] : ['tempo_ia_ms' => $tempoIaMs]),
            // RF03: os ids que o servidor resolveu do filtro do lote, congelados; o Confirmar executa só eles.
            ...($loteIds === null ? [] : ['lote_ids' => array_values(array_map('intval', $loteIds))]),
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
            ...(self::tempoIa($rascunho) === null ? [] : ['tempo_ia_ms' => self::tempoIa($rascunho)]),
            // Vem do rascunho (servidor), nunca dos valores revisados que o navegador mandou.
            ...(self::loteIds($rascunho) === null ? [] : ['lote_ids' => self::loteIds($rascunho)]),
            'proposta' => $proposta,
            'origem' => self::origem($rascunho),
            // Os 5 min do rascunho são absolutos: o token também vence quando eles acabam (expirou()).
            'proposta_criada_em' => $rascunho['criado_em'],
            // Em segundos, com milissegundos (o prazo é o da proposta; ver expirou()).
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

    /** Tempo da chamada à IA que fez a proposta (B1, RNF04); null quando veio sem IA. */
    public static function tempoIa(array $pendente): ?int
    {
        $tempo = $pendente['tempo_ia_ms'] ?? null;

        return is_int($tempo) && $tempo >= 0 ? $tempo : null;
    }

    /** RF03: os ids congelados do lote; null quando a proposta não é de lote. */
    public static function loteIds(array $pendente): ?array
    {
        $ids = $pendente['lote_ids'] ?? null;

        return is_array($ids) && $ids !== [] ? $ids : null;
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

    /** Segundos (com fração) desde a emissão (token) ou desde a proposta (rascunho), pelo relógio do servidor. */
    public function decorrido(array $pendente): float
    {
        return self::agora() - (float) ($pendente['emitido_em'] ?? $pendente['criado_em'] ?? 0);
    }

    /**
     * Passou do prazo: 5 min desde a proposta, absoluto, para o rascunho e para o token dele. Decisão B (13/11):
     * o token não tem mais validade própria (eram 12 s). É o limite único entre "cancelado" e "expirado" em todas
     * as rotas (confirmar, cancelar, sair e varredura).
     */
    public function expirou(array $pendente): bool
    {
        if (($pendente['estado'] ?? 'revisada') === 'rascunho') {
            return $this->decorrido($pendente) > self::PRAZO_RASCUNHO_S;
        }

        // Token sem a hora da proposta (entrada antiga ou malformada): conta desde a emissão, com o mesmo prazo.
        $inicio = (float) ($pendente['proposta_criada_em'] ?? $pendente['emitido_em'] ?? 0);

        return self::agora() - $inicio > self::PRAZO_RASCUNHO_S;
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
     * o prazo da proposta (decisão B, 13/11: o token não tem prazo próprio). Entrada malformada sai sem auditoria; a
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
