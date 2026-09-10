<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class AiAgentService
{
    public function __construct(private LancamentoService $lancamentoService)
    {
    }

    /**
     * Ponto único de contato com a Chat Completions API do Microsoft Foundry
     * (Azure OpenAI) — chamada HTTP REST stateless, sem SDK, na rota
     * unificada /openai/v1/chat/completions (versionamento implícito).
     *
     * Tools de leitura (consultar_lancamentos) são executadas direto e o
     * resultado volta como tabela crua — uma única chamada à API por
     * consulta. Tools de escrita (criar_lancamento) NUNCA são executadas
     * aqui: o tool_call é devolvido ao front como confirmação pendente.
     */
    public function processar(string $mensagem): array
    {
        $endpoint = rtrim((string) config('services.azure_foundry.endpoint'), '/');
        $apiKey = (string) config('services.azure_foundry.api_key');
        $deployment = (string) config('services.azure_foundry.deployment');

        if ($endpoint === '' || $apiKey === '' || $deployment === '') {
            return [
                'tipo' => 'erro',
                'mensagem' => 'Configuração do Microsoft Foundry ausente (AZURE_FOUNDRY_ENDPOINT/API_KEY/DEPLOYMENT).',
            ];
        }

        try {
            $resposta = Http::withHeaders(['api-key' => $apiKey])
                ->timeout(30)
                ->post("{$endpoint}/openai/v1/chat/completions", [
                    'model' => $deployment,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Você é o assistente do Sentinel, um sistema de contabilidade para micro e pequenas empresas. A data de hoje é '.now()->toDateString().' — use-a para resolver termos relativos como "hoje" e "ontem". Use as tools disponíveis para consultar ou criar lançamentos financeiros. Nunca invente dados — se faltar informação obrigatória para uma tool, pergunte ao usuário em texto antes de chamar a tool. Quando já tiver todos os dados necessários para criar_lancamento, chame a tool diretamente — não peça confirmação você mesmo em texto: o sistema já exibe uma tela de confirmação humana (hold-to-confirm) antes de executar qualquer escrita.',
                        ],
                        ['role' => 'user', 'content' => $mensagem],
                    ],
                    'tools' => $this->tools(),
                    'tool_choice' => 'auto',
                ]);
        } catch (\Throwable $e) {
            Log::error('AiAgentService: falha ao chamar a Chat Completions API', ['erro' => $e->getMessage()]);

            return [
                'tipo' => 'erro',
                'mensagem' => 'Não foi possível contatar o agente de IA agora. Tente novamente.',
            ];
        }

        if ($resposta->failed()) {
            Log::error('AiAgentService: resposta de erro da Chat Completions API', [
                'status' => $resposta->status(),
                'corpo' => $resposta->body(),
            ]);

            return [
                'tipo' => 'erro',
                'mensagem' => 'O agente de IA retornou um erro (HTTP '.$resposta->status().').',
            ];
        }

        $mensagemResposta = $resposta->json('choices.0.message') ?? [];
        $toolCalls = $mensagemResposta['tool_calls'] ?? [];

        if (empty($toolCalls)) {
            return [
                'tipo' => 'texto',
                'mensagem' => $mensagemResposta['content'] ?? '',
            ];
        }

        $chamada = $toolCalls[0];
        $tool = $chamada['function']['name'] ?? '';
        $argumentos = json_decode($chamada['function']['arguments'] ?? '{}', true) ?? [];

        if ($tool === 'criar_lancamento') {
            return [
                'tipo' => 'confirmacao_pendente',
                'tool' => $tool,
                'argumentos' => $argumentos,
            ];
        }

        try {
            $resultado = $this->executarTool($tool, $argumentos);
        } catch (InvalidArgumentException $e) {
            return [
                'tipo' => 'erro',
                'mensagem' => $e->getMessage(),
            ];
        }

        return [
            'tipo' => 'resultado_leitura',
            ...$resultado,
        ];
    }

    /**
     * Definições das tools expostas ao modelo, no formato de function
     * calling da Chat Completions API. Enviadas no parâmetro `tools` da
     * chamada ao Azure OpenAI.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'consultar_lancamentos',
                    'description' => 'Consulta lançamentos financeiros, opcionalmente filtrando por status e período. Ação de leitura — não exige confirmação.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'status' => [
                                'type' => 'string',
                                'enum' => ['pendente', 'conciliado'],
                            ],
                            'data_inicio' => ['type' => 'string', 'format' => 'date'],
                            'data_fim' => ['type' => 'string', 'format' => 'date'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'criar_lancamento',
                    'description' => 'Cria um novo lançamento financeiro. Ação de escrita — exige confirmação humana antes de ser executada.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'descricao' => ['type' => 'string'],
                            'valor' => ['type' => 'number'],
                            'data' => ['type' => 'string', 'format' => 'date'],
                            'tenant_id' => ['type' => 'integer'],
                        ],
                        'required' => ['descricao', 'valor', 'data', 'tenant_id'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Executa uma tool já confirmada (escrita) ou de leitura, delegando
     * para o service de domínio correspondente.
     */
    public function executarTool(string $tool, array $argumentos): array
    {
        return match ($tool) {
            'consultar_lancamentos' => [
                'tool' => $tool,
                'resultado' => $this->lancamentoService->buscarPorFiltro(
                    $argumentos['status'] ?? null,
                    $argumentos['data_inicio'] ?? null,
                    $argumentos['data_fim'] ?? null,
                ),
            ],
            'criar_lancamento' => $this->criarLancamento($argumentos),
            default => throw new InvalidArgumentException("Tool desconhecida: {$tool}"),
        };
    }

    /**
     * Rede de segurança pra dados obrigatórios faltando (ex.: tenant_id) —
     * evita estourar erro de banco cru pro usuário. NÃO é a solução real
     * de identificação de tenant, que fica pra Fase 1.
     */
    private function criarLancamento(array $argumentos): array
    {
        try {
            return [
                'tipo' => 'resultado_escrita',
                'tool' => 'criar_lancamento',
                'resultado' => $this->lancamentoService->criar($argumentos),
            ];
        } catch (\Throwable $e) {
            Log::error('AiAgentService: falha ao criar lançamento', [
                'erro' => $e->getMessage(),
                'argumentos' => $argumentos,
            ]);

            return [
                'tipo' => 'erro',
                'mensagem' => 'Não consegui identificar a empresa desse lançamento, pode especificar? (verifique também se descrição, valor e data foram informados)',
            ];
        }
    }
}
