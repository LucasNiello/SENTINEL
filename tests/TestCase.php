<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        // Executa antes dos traits de teste, inclusive RefreshDatabase.
        // Recusar uma configuração insegura é preferível a apagar dados locais.
        if (! $app->environment('testing')
            || $app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Testes exigem APP_ENV=testing e SQLite :memory:. Nenhum banco local pode ser usado.');
        }

        return $app;
    }

    /**
     * Teste não depende de build: sem isto, telas com @vite (vitrine, login v4)
     * quebram quando public/build/manifest.json não existe.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // O provedor é só a Anthropic; os testes dão credencial falsa e as respostas simuladas passam pelo conversor abaixo.
        config(['services.anthropic.api_key' => 'k', 'services.anthropic.model' => 'claude-haiku-5-5']);
        Http::globalResponseMiddleware(fn (\Psr\Http\Message\ResponseInterface $r) => self::comoAnthropic($r));
    }

    /**
     * Os testes do agente simulam a IA no formato interno de chat (choices/message/tool_calls). Este é o ÚNICO ponto
     * que converte esse corpo para a Messages API da Anthropic, o que o adaptador do AiAgentService realmente recebe.
     * Corpo que já é da Anthropic (type=message) ou sem choices passa como veio; formato inválido vira content inválido.
     */
    private static function comoAnthropic(\Psr\Http\Message\ResponseInterface $r): \Psr\Http\Message\ResponseInterface
    {
        $texto = (string) $r->getBody();
        $r->getBody()->rewind();
        $corpo = json_decode($texto, true);

        if ($r->getStatusCode() < 200 || $r->getStatusCode() >= 300 || ! is_array($corpo) || ! array_key_exists('choices', $corpo)) {
            return $r;
        }

        $invalido = ['type' => 'message', 'content' => 'invalido', 'stop_reason' => null];
        $escolha = $corpo['choices'][0] ?? null;
        $mensagem = is_array($escolha) ? ($escolha['message'] ?? null) : null;
        $saida = null;

        if (is_array($mensagem) && ! array_is_list($mensagem)) {
            $blocos = [];
            $ok = true;
            $conteudo = $mensagem['content'] ?? null;

            if (is_string($conteudo)) {
                $blocos[] = ['type' => 'text', 'text' => $conteudo];
            } elseif ($conteudo !== null) {
                $ok = false;
            }

            $chamadas = $mensagem['tool_calls'] ?? [];
            if (! is_array($chamadas)) {
                $ok = false;
                $chamadas = [];
            }
            foreach ($chamadas as $chamada) {
                $funcao = is_array($chamada) ? ($chamada['function'] ?? null) : null;
                if (! is_array($funcao)) {
                    $blocos[] = ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => null, 'input' => null];
                    continue;
                }
                // Argumentos que não são texto JSON viram um input que não é objeto, que o interpretador recusa.
                $entrada = is_string($funcao['arguments'] ?? null) ? json_decode($funcao['arguments'], false) : 'invalido';
                $blocos[] = ['type' => 'tool_use', 'id' => $chamada['id'] ?? 'toolu_1', 'name' => $funcao['name'] ?? null, 'input' => $entrada];
            }

            $fim = $escolha['finish_reason'] ?? null;
            $parada = match (true) {
                $fim === 'tool_calls' || ($fim === 'stop' && $chamadas !== []) || ($fim === null && $chamadas !== []) => 'tool_use',
                $fim === 'stop' || $fim === null => 'end_turn',
                $fim === 'length' => 'max_tokens',
                $fim === 'content_filter' => 'refusal',
                default => $fim,
            };
            $saida = $ok ? ['type' => 'message', 'role' => 'assistant', 'content' => $blocos, 'stop_reason' => $parada] : $invalido;
        }

        return new \GuzzleHttp\Psr7\Response($r->getStatusCode(), ['Content-Type' => 'application/json'], json_encode($saida ?? $invalido));
    }

    /**
     * Loga um usuário com o papel e o tenant dados (RF10: papel e tenant vêm
     * do usuário logado, não mais de valores fixos em config/sentinel.php).
     */
    protected function logarComo(string $papel = 'admin', int $tenantId = 1): User
    {
        $usuario = User::factory()->papel($papel)->doTenant($tenantId)->create();

        $this->actingAs($usuario);

        return $usuario;
    }
}
