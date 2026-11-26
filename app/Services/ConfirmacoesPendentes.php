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
 *   formulário em edição, sem token de segurar. 25/11 (Lucas): sem prazo
 *   (antes, PRAZO_RASCUNHO_S desde a proposta).
 * - "revisada": criada pelo /agente/revisar depois de validar os valores. Guarda
 *   os valores revisados e o token de confirmação (uso único). Decisão B do Lucas
 *   (13/11): não há janela de 2 a 12 s; 25/11: o token também não vence. O
 *   segurar de 2 s é só gesto visual, nas ações destrutivas.
 *   "Editar" mata esse token sem desfecho; o rascunho continua.
 *
 * Toda proposta tem um desfecho só (confirmada ou cancelada; "expirada" só se o
 * prazo voltar) e, quando ele acontece, o rascunho e os tokens dela saem da
 * sessão (R3).
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
     * Prazo antigo da proposta (decisão B, 13/11: 5 min absolutos desde a proposta). 25/11 (Lucas): não vale mais para
     * nenhuma origem (prazoS() é null); fica como referência e para os testes medirem "depois dos antigos 5 min".
     */
    public const PRAZO_RASCUNHO_S = 300;

    /** Quantos cards de tela (sem prazo) o mesmo usuário mantém na sessão; ao passar, o mais antigo sai (a sessão não incha). */
    private const LIMITE_RASCUNHOS_DE_TELA = 20;

    /**
     * Quanto tempo a marca de desfecho único (concluir) vale. 25/11: o card de TELA não vence, então a pendência pode ficar na
     * sessão (que se renova com o uso) por muito mais que antes; a marca não pode vencer antes dela, senão uma requisição
     * concorrente que ressuscitou a pendência poderia confirmar de novo. 30 dias cobrem qualquer sessão realista.
     */
    private const DIAS_DA_MARCA_DE_DESFECHO = 30;

    /**
     * O prazo, em segundos, de uma proposta de origem $origem; null = sem prazo. O front só cria timer se vier número.
     * 25/11 (decisão do Lucas): nenhuma proposta vence mais, nem a da IA. Quem sai da mesa enquanto o agente pensa não pode
     * ter de pedir de novo. O Confirmar revalida tudo no servidor (vaga, versão, permissão, tenant) e o token continua de uso
     * único; o que limita a sessão é LIMITE_RASCUNHOS_DE_TELA nos cards de tela (o mais antigo sai como "cancelado"), o
     * descarte da pendente a cada pedido novo no chat e o fim da sessão de login.
     * PRAZO_RASCUNHO_S fica só como referência do prazo antigo.
     */
    public static function prazoS(string $origem): ?int
    {
        return null;
    }

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
            // R5 (24/11): o rascunho é de quem o abriu. Usuário e tenant do ContextoUsuario, na hora; nunca do navegador.
            'dono_user_id' => (int) app(ContextoUsuario::class)->usuario()->getKey(),
            'dono_tenant_id' => app(ContextoUsuario::class)->tenantId(),
            'criado_em' => self::agora(),
        ]);

        return $proposta;
    }

    /**
     * Sem prazo, o card de tela esquecido ficaria para sempre: o usuário mantém só os LIMITE_RASCUNHOS_DE_TELA mais novos. Os
     * excedentes (os mais antigos) saem da sessão COM os tokens deles e voltam para o controller auditar o desfecho
     * ("cancelado"): toda proposta termina com um desfecho (R3) e nenhum token de um card despejado continua valendo.
     *
     * @return array<int, array<string, mixed>>
     */
    public function retirarExcedentesDeTela(Session $sessao): array
    {
        // Só card de TELA: a proposta do chat não acumula (um pedido novo descarta a pendente; AgenteController::descartarPendencias).
        $meus = array_filter((array) $sessao->get(self::CHAVE, []), fn ($p) => is_array($p) && ($p['estado'] ?? null) === 'rascunho'
            && self::origem($p) === 'formulario' && $this->pertenceAoUsuario($p));

        if (count($meus) <= self::LIMITE_RASCUNHOS_DE_TELA) {
            return [];
        }

        uasort($meus, fn (array $a, array $b) => ($a['criado_em'] ?? 0) <=> ($b['criado_em'] ?? 0));
        $retiradas = [];
        foreach (array_slice(array_keys($meus), 0, count($meus) - self::LIMITE_RASCUNHOS_DE_TELA) as $chave) {
            $proposta = (string) $chave;
            // Mesma marca de desfecho único das demais saídas (confirmar, cancelar, expirar): só quem concluir audita.
            if ($this->concluir($proposta)) {
                $retiradas[] = [...$meus[$chave], 'proposta_id' => $proposta];
            }
            $this->encerrar($sessao, $proposta);
        }

        return $retiradas;
    }

    /** O rascunho ainda em edição; null se não existe, já teve desfecho ou a chave não é de rascunho. */
    public function rascunho(Session $sessao, string $proposta): ?array
    {
        $pendente = $sessao->get(self::CHAVE.".{$proposta}");

        return is_array($pendente) && ($pendente['estado'] ?? null) === 'rascunho' && $this->pertenceAoUsuario($pendente) ? $pendente : null;
    }

    /** R5: a pendência da chave (rascunho ou token) só se for do usuário e tenant logados; leitura, sem tirar da sessão. */
    public function doUsuario(Session $sessao, string $chave): ?array
    {
        $pendente = $sessao->get(self::CHAVE.".{$chave}");

        return is_array($pendente) && $this->pertenceAoUsuario($pendente) ? $pendente : null;
    }

    /**
     * R5: a pendência é do usuário e do tenant logados? Exige os dois campos gravados e iguais (int estrito). Sem eles
     * (rascunho legado ou malformado) é falso: falha fechada. Quem chama trata falso como "não existe", sem distinguir
     * de uma proposta que nunca houve, para não revelar que ela existe.
     */
    private function pertenceAoUsuario(array $pendente): bool
    {
        $contexto = app(ContextoUsuario::class);

        return is_int($pendente['dono_user_id'] ?? null) && is_int($pendente['dono_tenant_id'] ?? null)
            && $pendente['dono_user_id'] === (int) $contexto->usuario()->getKey()
            && $pendente['dono_tenant_id'] === $contexto->tenantId();
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

        $sessao->put(self::CHAVE.".{$token}", self::montarRevisada($proposta, $rascunho, $revisados));

        return $token;
    }

    /**
     * A pendência "revisada" (o que o confirmar executa), montada só em memória, sem sessão. O /agente/revisar a guarda
     * com um token; o Confirmar em um passo (23/11) a monta e executa na mesma requisição, sem emitir token.
     */
    public static function montarRevisada(string $proposta, array $rascunho, array $revisados): array
    {
        return [
            'estado' => 'revisada',
            'tool' => $rascunho['tool'],
            'argumentos' => $revisados,
            'propostos' => self::propostos($rascunho),
            ...(self::tempoIa($rascunho) === null ? [] : ['tempo_ia_ms' => self::tempoIa($rascunho)]),
            // Vem do rascunho (servidor), nunca dos valores revisados que o navegador mandou.
            ...(self::loteIds($rascunho) === null ? [] : ['lote_ids' => self::loteIds($rascunho)]),
            'proposta' => $proposta,
            // R5: o token é do mesmo dono do rascunho (copiado do servidor, nunca do navegador).
            'dono_user_id' => $rascunho['dono_user_id'] ?? null,
            'dono_tenant_id' => $rascunho['dono_tenant_id'] ?? null,
            'origem' => self::origem($rascunho),
            // Hora da proposta: o token usaria o mesmo prazo dela (expirou()); 25/11, sem prazo, só fica registrada.
            'proposta_criada_em' => $rascunho['criado_em'],
            // Em segundos, com milissegundos (hora da emissão; 25/11, sem prazo).
            'emitido_em' => self::agora(),
        ];
    }

    /**
     * Revisão recusada: o rascunho passa a guardar o que o humano já editou (o card
     * volta com esses valores, e campo sensível em branco mantém o editado, não o
     * da IA). O proposto da IA fica em "propostos".
     */
    public function atualizarRascunho(Session $sessao, string $proposta, array $rascunho, array $revisados): void
    {
        $sessao->put(self::CHAVE.".{$proposta}", [
            ...$rascunho,
            'argumentos' => $revisados,
            'propostos' => self::propostos($rascunho),
        ]);
    }

    /** A proposta tem um token de segurar já vencido? 25/11: nunca (sem prazo); fica para o caso de o prazo voltar. */
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

        if (! is_array($pendente) || ($pendente['estado'] ?? null) !== 'revisada' || ! $this->pertenceAoUsuario($pendente)) {
            return null;
        }

        $sessao->forget(self::CHAVE.".{$token}");

        return $pendente['proposta'] ?? null;
    }

    /** Tira da sessão o token de segurar (uso único). Null se não existe ou não é token de segurar — o rascunho fica. */
    public function retirarRevisada(Session $sessao, string $token): ?array
    {
        $pendente = $sessao->get(self::CHAVE.".{$token}");

        // R5: a conferência do dono vem antes do forget: quem não é o dono não consome o token de ninguém.
        if (! is_array($pendente) || ($pendente['estado'] ?? 'revisada') !== 'revisada' || ! $this->pertenceAoUsuario($pendente)) {
            return null;
        }

        $sessao->forget(self::CHAVE.".{$token}");

        return $pendente;
    }

    /** Tira da sessão a pendência da chave, rascunho ou revisada (o cancelar aceita as duas). */
    public function retirar(Session $sessao, string $chave): ?array
    {
        $atual = $sessao->get(self::CHAVE.".{$chave}");

        // R5: só o dono encerra a proposta; a conferência vem antes de tirar da sessão.
        if (! is_array($atual) || ! $this->pertenceAoUsuario($atual)) {
            return null;
        }

        $sessao->forget(self::CHAVE.".{$chave}");

        return $atual;
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
     * proposta nunca rendem duas gravações. Ela dura DIAS_DA_MARCA_DE_DESFECHO dias
     * (25/11; antes, o tempo de vida da sessão): uma requisição concorrente pode regravar a sessão com a pendência já
     * concluída, e ela não pode ser confirmada nem auditada de novo depois. O card de tela não vence, então a marca tem de
     * sobreviver à pendência, não à varredura.
     */
    public function concluir(string $proposta): bool
    {
        return Cache::add("agente:token_usado:{$proposta}", true, now()->addDays(self::DIAS_DA_MARCA_DE_DESFECHO));
    }

    /** Segundos (com fração) desde a emissão (token) ou desde a proposta (rascunho), pelo relógio do servidor. */
    public function decorrido(array $pendente): float
    {
        return self::agora() - (float) ($pendente['emitido_em'] ?? $pendente['criado_em'] ?? 0);
    }

    /**
     * Passou do prazo? 25/11: nunca (prazoS() é null). Antes, decisão B (13/11): 5 min desde a proposta, absoluto, e
     * o token não tem mais validade própria (eram 12 s). É o limite único entre "cancelado" e "expirado" em todas
     * as rotas (confirmar, cancelar, sair e varredura).
     */
    public function expirou(array $pendente): bool
    {
        // 25/11 (decisão do Lucas): primeiro o card de TELA, depois também a proposta do chat deixaram de ter prazo (prazoS() é
        // sempre null). O Confirmar revalida tudo no servidor (vaga, versão, permissão, tenant), então um card parado não grava
        // dado velho; a sessão de login continua vencendo sozinha. O resto do método fica para o caso de o prazo voltar.
        if (self::prazoS(self::origem($pendente)) === null) {
            return false;
        }

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
     * o prazo da proposta, se houver (decisão B, 13/11: o token não tem prazo próprio; 25/11: sem prazo). Entrada malformada sai sem auditoria; a
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
                // O id da proposta acompanha a pendência (o rascunho não o guarda): o histórico da conversa marca o desfecho nela.
                $retiradas[] = [...$pendente, 'proposta_id' => $proposta];
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
