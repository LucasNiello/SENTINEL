<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AiAgentService;
use App\Services\ConversaAgente;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Bateria de pedidos do agente (Fase 6, C0), no mesmo desenho da rodada de 05/11 (PROGRESSO.md):
 * operador do tenant 1, sessão em memória, ConversaAgente::ler() -> AiAgentService::processar()
 * -> ConversaAgente::guardar(); cada pedido com sessão nova, e a continuação na sessão da sua consulta.
 * Faz chamadas REAIS ao provedor (custa dinheiro): só roda no SQLite de arquivo database/bateria.sqlite.
 * Não guarda nem imprime chave, prompt ou corpo de resposta; só nome da tool, tipo, código e tempos.
 */
class BateriaAgente extends Command
{
    protected $signature = 'sentinel:bateria
        {--modelo= : modelo da Anthropic (ex.: claude-sonnet-5-5); vazio = o configurado}
        {--saida= : CSV de saída, relativo à raiz do projeto}
        {--somente= : números dos pedidos, separados por vírgula (as dependências entram juntas)}
        {--limite=50 : teto de chamadas reais nesta rodada}';

    protected $description = 'Roda a bateria de pedidos do agente contra o provedor real e grava o CSV';

    /** Categorias que param a rodada: chave, permissão, crédito ou pedido recusado (400 de crédito vem como http_error). */
    private const PARAR = ['provider_authentication_error', 'provider_authorization_error', 'provider_billing_error', 'provider_http_error', 'provider_configuration_error'];

    public function handle(AiAgentService $agente, ConversaAgente $conversa): int
    {
        $banco = (string) config('database.connections.'.config('database.default').'.database');
        if (config('database.default') !== 'sqlite' || ! str_ends_with(str_replace('\\', '/', $banco), 'database/bateria.sqlite')) {
            $this->error('A bateria só roda no SQLite de arquivo database/bateria.sqlite (DB_CONNECTION=sqlite, DB_DATABASE=<raiz>/database/bateria.sqlite).');

            return self::FAILURE;
        }

        if ($this->option('modelo')) {
            if (config('services.ia_provedor') !== 'anthropic') {
                $this->error('--modelo só vale com SENTINEL_IA_PROVEDOR=anthropic.');

                return self::FAILURE;
            }
            config(['services.anthropic.model' => (string) $this->option('modelo')]);
        }

        $operador = User::query()->where('papel', 'operador')->where('tenant_id', 1)->orderBy('id')->first();
        if ($operador === null) {
            $this->error('Sem operador no tenant 1: rode migrate e db:seed neste banco.');

            return self::FAILURE;
        }
        Auth::setUser($operador);

        $pedidos = $this->lerPedidos(base_path('documentacao/testes/fase6/BATERIA_PEDIDOS.csv'));
        if ($this->option('somente')) {
            $escolhidos = array_map('intval', explode(',', (string) $this->option('somente')));
            $comDependencias = $escolhidos;
            foreach ($escolhidos as $n) {
                if (($pedidos[$n]['depende_de'] ?? '') !== '') $comDependencias[] = (int) $pedidos[$n]['depende_de'];
            }
            $pedidos = array_filter($pedidos, fn ($p) => in_array($p['n'], $comDependencias, true));
        }

        // Medição por chamada HTTP ao provedor: tempo, nome da tool escolhida e tokens (para a conta do crédito).
        $chamada = ['inicio' => null, 'ms' => 0, 'qtd' => 0, 'tool' => null];
        $tokens = ['entrada' => 0, 'saida' => 0];
        $total = 0;
        Event::listen(RequestSending::class, function () use (&$chamada, &$total) {
            $chamada['inicio'] = hrtime(true);
            $chamada['qtd']++;
            $total++;
        });
        $fim = function () use (&$chamada) {
            if ($chamada['inicio'] !== null) $chamada['ms'] += (int) round((hrtime(true) - $chamada['inicio']) / 1e6);
            $chamada['inicio'] = null;
        };
        Event::listen(ConnectionFailed::class, $fim);
        Event::listen(ResponseReceived::class, function (ResponseReceived $e) use ($fim, &$chamada, &$tokens) {
            $fim();
            $corpo = $e->response->json();
            if (! is_array($corpo)) return;
            $tokens['entrada'] += (int) ($corpo['usage']['input_tokens'] ?? $corpo['usage']['prompt_tokens'] ?? 0);
            $tokens['saida'] += (int) ($corpo['usage']['output_tokens'] ?? $corpo['usage']['completion_tokens'] ?? 0);
            $nome = $corpo['choices'][0]['message']['tool_calls'][0]['function']['name'] ?? null;
            foreach ($corpo['content'] ?? [] as $bloco) {
                if (is_array($bloco) && ($bloco['type'] ?? null) === 'tool_use') { $nome ??= $bloco['name'] ?? null; break; }
            }
            $chamada['tool'] ??= is_string($nome) ? $nome : null;
        });

        $sessoes = [];
        $resultados = [];
        $linhas = [];
        $limite = (int) $this->option('limite');

        foreach ($pedidos as $p) {
            if ($total >= $limite) {
                $this->warn("Teto de {$limite} chamadas atingido antes do pedido {$p['n']}. Rodada interrompida.");
                break;
            }

            $dependencia = $p['depende_de'] === '' ? null : (int) $p['depende_de'];
            $sessao = $dependencia !== null && isset($sessoes[$dependencia]) ? $sessoes[$dependencia] : new Store('bateria', new ArraySessionHandler(120));
            $chamada = ['inicio' => null, 'ms' => 0, 'qtd' => 0, 'tool' => null];
            $ultimoLog = (int) AuditLog::query()->max('id');

            try {
                $resposta = $agente->processar($p['pedido'], $conversa->ler($sessao));
            } catch (\Throwable $e) {
                $resposta = $agente->erroTecnicoTool($e);
            }
            $conversa->guardar($sessao, $p['pedido'], $resposta);
            $sessoes[$p['n']] = $sessao;
            $resultados[$p['n']] = $resposta;

            $tipo = (string) ($resposta['tipo'] ?? '');
            $novos = AuditLog::query()->where('id', '>', $ultimoLog);
            // Como em 05/11: sem chamada à IA (negado antes dela), a tool obtida é a da negativa auditada.
            $obtido = $chamada['tool'] ?? ($resposta['tool'] ?? (clone $novos)->orderBy('id')->value('tool'));
            $acertou = $this->acertou($p, $tipo, $obtido, $resposta, $dependencia === null ? null : ($resultados[$dependencia] ?? null));
            $tempoAuditoria = (clone $novos)->max('tempo_ia_ms');
            $id = $resposta['argumentos']['id'] ?? null;
            $texto = fn (string $s) => '"'.str_replace(['"', "\r", "\n"], ['""', ' ', ' '], mb_substr($s, 0, 160)).'"';
            $erro = match ($tipo) {
                'texto' => $texto('texto: '.($resposta['mensagem'] ?? '')),
                'erro', 'negado' => $texto(trim(($resposta['codigo'] ?? '').' '.($resposta['mensagem'] ?? ''))),
                default => '',
            };

            $linhas[] = [
                $p['n'], $p['grupo'], '"'.str_replace('"', '""', $p['pedido']).'"', '"'.$p['esperado_tool'].' / '.$p['esperado_tipo'].'"',
                $obtido === null ? '(nenhuma)' : ($id === null ? $obtido : '"'.$obtido.' id='.$id.'"'), $acertou ? 'S' : 'N', $tipo, $erro,
                $chamada['qtd'] > 0 ? $chamada['ms'] : '', $chamada['qtd'], $tempoAuditoria ?? '',
            ];
            $this->line(sprintf('%2d %-12s %s %-32s %-22s %s', $p['n'], $p['grupo'], $acertou ? 'S' : 'N', $obtido ?? '-', $tipo, $resposta['codigo'] ?? ''));

            if (in_array($resposta['categoria'] ?? null, self::PARAR, true)) {
                $this->error("Parada: {$resposta['categoria']} no pedido {$p['n']}. Nenhuma outra chamada nesta rodada.");
                break;
            }
            usleep(300_000);
        }

        $saida = $this->option('saida');
        if ($saida) {
            $cabecalho = 'n;grupo;pedido;esperado;obtido;acertou;tipo_resposta;erro;tempo_ia_ms;chamadas_ia;tempo_ia_ms_auditoria';
            file_put_contents(base_path((string) $saida), $cabecalho."\n".implode("\n", array_map(fn ($l) => implode(';', $l), $linhas))."\n");
        }

        $this->resumo($linhas, $total, $tokens);

        return self::SUCCESS;
    }

    /** @return array<int, array<string, mixed>> */
    private function lerPedidos(string $arquivo): array
    {
        $linhas = array_values(array_filter(array_map('trim', file($arquivo))));
        $cabecalho = str_getcsv(array_shift($linhas), ';', '"', '');
        $pedidos = [];
        foreach ($linhas as $linha) {
            $p = array_combine($cabecalho, array_pad(str_getcsv($linha, ';', '"', ''), count($cabecalho), ''));
            $p['n'] = (int) $p['n'];
            $pedidos[$p['n']] = $p;
        }

        return $pedidos;
    }

    /** Critério de 05/11 (PROGRESSO.md, item 6 do desenho). */
    private function acertou(array $p, string $tipo, ?string $obtido, array $resposta, ?array $consulta): bool
    {
        if ($p['esperado_tool'] === '-') {
            return $tipo === 'texto' && $obtido === null && str_contains((string) ($resposta['mensagem'] ?? ''), '?');
        }
        if ($obtido !== $p['esperado_tool'] || ! in_array($tipo, explode('|', $p['esperado_tipo']), true)) {
            return false;
        }
        if ($p['grupo'] !== 'continuacao') {
            return true;
        }

        // Continuação: o id proposto tem de ser o da linha certa da consulta anterior.
        $ids = array_column($consulta['resultado'] ?? [], 'id');
        $esperado = match ((int) $p['n']) {
            27 => $ids[1] ?? null,
            28 => $ids[0] ?? null,
            29 => $ids[2] ?? null,
            30 => $ids === [] ? null : $ids[array_key_last($ids)],
            default => null,
        };

        return $esperado !== null && (int) ($resposta['argumentos']['id'] ?? 0) === (int) $esperado;
    }

    private function resumo(array $linhas, int $total, array $tokens): void
    {
        $grupos = [];
        foreach ($linhas as $l) {
            $grupos[$l[1]]['total'] = ($grupos[$l[1]]['total'] ?? 0) + 1;
            $grupos[$l[1]]['acertos'] = ($grupos[$l[1]]['acertos'] ?? 0) + ($l[5] === 'S' ? 1 : 0);
        }
        $tempos = array_values(array_filter(array_map(fn ($l) => $l[8], $linhas), fn ($t) => $t !== ''));
        sort($tempos);
        $percentil = function (float $q) use ($tempos): ?int {
            if ($tempos === []) return null;
            $pos = ($q / 100) * (count($tempos) - 1);
            $baixo = (int) floor($pos);
            $alto = (int) ceil($pos);

            return (int) round($tempos[$baixo] + ($tempos[$alto] - $tempos[$baixo]) * ($pos - $baixo));
        };

        $acertos = array_sum(array_column($grupos, 'acertos'));
        $this->newLine();
        $this->info('Acertos: '.$acertos.'/'.count($linhas).' | '.implode(', ', array_map(fn ($g, $v) => "{$g} {$v['acertos']}/{$v['total']}", array_keys($grupos), $grupos)));
        $this->info('tempo_ia_ms ('.count($tempos).' pedidos com IA): p50 '.$percentil(50).' | p90 '.$percentil(90).' | máx '.($tempos === [] ? '-' : max($tempos)));
        $this->info("Chamadas reais: {$total} | tokens entrada {$tokens['entrada']} / saída {$tokens['saida']}");
        DB::disconnect();
    }
}
