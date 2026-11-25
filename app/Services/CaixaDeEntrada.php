<?php

namespace App\Services;

use App\Models\Mensagem;
use App\Models\Tutor;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Caixa de entrada da clínica, SIMULADA com o Mailpit local (o fluxo futuro seria e-mail, WhatsApp etc.): lê pela API do
 * Mailpit os e-mails NÃO LIDOS enviados para o contato da clínica (config sentinel.contato) e cria uma "Mensagem nova" por
 * e-mail, no tenant configurado.
 * - Só e-mail PARA o contato; remetente igual ao contato (os avisos do próprio Sentinel) é ignorado.
 * - Tutor: vinculado só pelo e-mail do remetente, só dentro do tenant e só se houver exatamente um. O "De" de um e-mail é
 *   livre (sem SPF/DKIM no Mailpit), então o vínculo é contexto, nunca prova de identidade.
 * - O conteúdo é DADO: texto puro (HTML removido), sem caracteres de controle, com teto de tamanho. Nada aqui chama o agente
 *   nem executa o que o e-mail diz; a pessoa lê, e qualquer escrita continua passando por Revisar → Confirmar.
 * - Sem duplicar: cada e-mail tem um origem_id único por tenant (índice único); marcar como lido é só otimização, e a falha
 *   dele não gera duplicata (a próxima rodada só tenta marcar de novo).
 * - Mailpit fora do ar ou resposta estranha: devolve 0 e deixa um log só com metadados. Nunca lança.
 */
class CaixaDeEntrada
{
    private const POR_VEZ = 25;

    /** @return int quantas mensagens novas foram criadas */
    public function importar(): int
    {
        $config = (array) config('sentinel.contato');
        $contato = strtolower(trim((string) ($config['email'] ?? '')));
        $tenantId = (int) ($config['tenant'] ?? 0);
        if ($contato === '' || ! ContextoUsuario::tenantDaClinica($tenantId)) {
            return 0;
        }

        $criadas = 0;
        $ids = [];

        try {
            $http = Http::baseUrl((string) $config['mailpit_url'])->acceptJson()->connectTimeout(1)->timeout(3);
            $lista = $http->get('/api/v1/search', ['query' => "to:{$contato} is:unread", 'limit' => self::POR_VEZ])->throw()->json('messages');

            foreach (is_array($lista) ? $lista : [] as $resumo) {
                $id = is_array($resumo) && is_string($resumo['ID'] ?? null) ? $resumo['ID'] : null;
                if ($id === null || ! $this->paraOContato($resumo['To'] ?? [], $contato)) {
                    continue; // não é para o contato: nem é tocado
                }
                $remetente = strtolower(trim((string) ($resumo['From']['Address'] ?? '')));
                if ($remetente === '' || $remetente === $contato) {
                    $ids[] = $id; // aviso do próprio Sentinel (ou sem remetente): não vira mensagem, só sai da fila
                    continue;
                }

                try {
                    $detalhe = $http->get('/api/v1/message/'.rawurlencode($id))->throw()->json();
                    $criadas += $this->criar($id, is_array($detalhe) ? $detalhe : [], $remetente, $tenantId) ? 1 : 0;
                    $ids[] = $id;
                } catch (Throwable $e) {
                    $this->registrar('mensagem', $e); // não deu para ler: fica não lido e é tentado de novo
                }
            }

            if ($ids !== []) {
                try {
                    $http->put('/api/v1/messages', ['IDs' => $ids, 'Read' => true])->throw();
                } catch (Throwable $e) {
                    $this->registrar('marcar_lido', $e); // sem problema: o origem_id impede duplicar na próxima rodada
                }
            }
        } catch (Throwable $e) {
            $this->registrar('mailpit', $e);
        }

        return $criadas;
    }

    private function criar(string $id, array $detalhe, string $remetente, int $tenantId): bool
    {
        $origem = 'mailpit:'.$id;
        if (Mensagem::query()->where('tenant_id', $tenantId)->where('origem_id', $origem)->exists()) {
            return false;
        }

        $nome = $this->limpo((string) ($detalhe['From']['Name'] ?? ''), 80);
        $texto = $this->montarTexto((string) ($detalhe['Subject'] ?? ''), (string) ($detalhe['Text'] ?? ''), (string) ($detalhe['HTML'] ?? ''));

        $mensagem = new Mensagem([
            'tutor_id' => $this->tutorDo($remetente, $tenantId),
            'remetente' => Str::limit($nome !== '' ? "{$nome} <{$remetente}>" : $remetente, 150, ''),
            'origem_id' => $origem,
            'texto' => $texto !== '' ? $texto : '(e-mail sem texto)',
            'status' => 'nova',
            'tenant_id' => $tenantId,
        ]);
        // A hora do e-mail (a "recebida em" da tabela); sem ela ou no futuro, agora.
        $chegada = $this->momento($detalhe['Date'] ?? null);
        if ($chegada !== null) {
            $mensagem->created_at = $chegada;
        }

        try {
            $mensagem->save();
        } catch (UniqueConstraintViolationException) {
            return false; // outra importação gravou o mesmo e-mail um instante antes
        }

        return true;
    }

    /** @param  mixed  $para  lista "To" do Mailpit; true se o contato está entre os destinatários */
    private function paraOContato(mixed $para, string $contato): bool
    {
        foreach (is_array($para) ? $para : [] as $destinatario) {
            if (is_array($destinatario) && strtolower(trim((string) ($destinatario['Address'] ?? ''))) === $contato) {
                return true;
            }
        }

        return false;
    }

    /** Exatamente um tutor do tenant com esse e-mail: vincula. Nenhum ou mais de um: sem vínculo. */
    private function tutorDo(string $email, int $tenantId): ?int
    {
        $ids = Tutor::query()->where('tenant_id', $tenantId)->whereRaw('lower(email) = ?', [$email])->limit(2)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    /** "Assunto: … — corpo", tudo texto puro numa linha e com teto (sentinel.contato.limite_texto). */
    private function montarTexto(string $assunto, string $texto, string $html): string
    {
        // Mesmo o "texto puro" passa por semHtml: quem escreve pode colar <script> ou tags num e-mail sem HTML.
        $corpo = $this->limpo($this->semHtml(trim($texto) !== '' ? $texto : $html), 100000);
        $assunto = $this->limpo($this->semHtml($assunto), 120);
        $completo = $assunto !== '' && $corpo !== '' ? "Assunto: {$assunto} — {$corpo}" : ($assunto !== '' ? "Assunto: {$assunto}" : $corpo);

        // O teto vale para o texto TODO, reticências incluídas.
        $teto = max(2, (int) config('sentinel.contato.limite_texto', 600));

        return mb_strlen($completo) > $teto ? rtrim(mb_substr($completo, 0, $teto - 1)).'…' : $completo;
    }

    /**
     * Tira script/style, tags bem formadas (<b>, </p>, <br/>, <!-- -->) e decodifica entidades. Só remove o que parece tag
     * (strip_tags apagaria o resto do texto num "preço <10 reais" solto).
     */
    private function semHtml(string $html): string
    {
        // Decodifica ANTES de tirar as tags: "&lt;script&gt;" tem de virar "<script>" para ser removido (e não depois dele).
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = (string) preg_replace('#<(script|style|head)\b.*?</\1\s*>#is', ' ', $html);
        $html = (string) preg_replace('#<!--.*?-->#s', ' ', $html);

        return (string) preg_replace('#</?[a-z][a-z0-9:-]*(\s[^<>]*)?/?>#i', ' ', $html);
    }

    /** UTF-8 válido, sem controle, espaços colapsados, até $max caracteres. */
    private function limpo(string $valor, int $max): string
    {
        $valor = trim((string) preg_replace('/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}\s]+/u', ' ', mb_scrub($valor, 'UTF-8')));

        return Str::limit($valor, $max, '');
    }

    private function momento(mixed $valor): ?CarbonImmutable
    {
        try {
            // O Date do e-mail vem com o fuso de quem enviou (Z, +0000...): converte para o da aplicação antes de gravar.
            $momento = is_string($valor) && $valor !== '' ? CarbonImmutable::parse($valor)->setTimezone((string) config('app.timezone')) : null;

            return $momento !== null && $momento->lessThanOrEqualTo(now()->addMinute()) ? $momento : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Só metadados: a etapa que falhou e a classe da exceção, nunca endereço, texto do e-mail nem a mensagem do erro. */
    private function registrar(string $etapa, Throwable $e): void
    {
        Log::warning('CaixaDeEntrada: falha ao ler o Mailpit', [
            'categoria' => 'caixa_entrada_error',
            'etapa' => $etapa,
            'excecao' => $e::class,
            'local' => basename($e->getFile()).':'.$e->getLine(),
        ]);
    }
}
