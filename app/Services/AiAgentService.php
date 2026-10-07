<?php

namespace App\Services;

use App\Exceptions\ToolRecusadaException;
use App\Http\Requests\CategoriaLancamentoRequest;
use App\Http\Requests\ClienteRequest;
use App\Http\Requests\FornecedorRequest;
use App\Http\Requests\FuncionarioRequest;
use App\Http\Requests\LancamentoRequest;
use App\Http\Requests\NotaFiscalRequest;
use App\Models\Lancamento;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

class AiAgentService
{
    /**
     * Frases amigáveis para as regras de validação usadas nas tools (mesmo
     * espírito do motivo de bloqueio do RF08: sem termo técnico). Chaves
     * "campo.regra" valem só para aquele campo. Se uma tool nova usar outra
     * regra, acrescente a frase aqui — regra sem frase cairia no texto em inglês
     * padrão do Laravel. Um campo com o MESMO nome e valores diferentes em duas
     * entidades (ex.: "tipo") não entra aqui: cada tool passa a sua frase no
     * terceiro argumento de validar().
     */
    private const MENSAGENS_VALIDACAO = [
        'required' => 'Informe :attribute.',
        'string' => ':Attribute deve ser um texto.',
        // Regra de tamanho: a frase depende do tipo do campo (texto conta caracteres, número compara valor).
        'max' => [
            'string' => ':Attribute aceita no máximo :max caracteres.',
            'numeric' => ':Attribute deve ser no máximo :max.',
        ],
        'email' => 'O e-mail informado não é válido.',
        'numeric' => ':Attribute deve ser um número.',
        'min' => ':Attribute deve ser no mínimo :min.',
        'date' => ':Attribute deve ser uma data válida (AAAA-MM-DD).',
        'date_format' => ':Attribute deve ser uma data válida (AAAA-MM-DD).',
        'exists' => 'Não encontrei :attribute informado.',
        'in' => ':Attribute informado não é válido.',
        'required_if' => 'Informe :attribute.',
        'prohibited_if' => ':Attribute não deve ser informado neste caso.',
        'cliente_id.required_if' => 'Informe o cliente da nota fiscal de saída.',
        'fornecedor_id.required_if' => 'Informe o fornecedor da nota fiscal de entrada.',
        'cliente_id.prohibited_if' => 'Nota fiscal de entrada não leva cliente — informe o fornecedor.',
        'fornecedor_id.prohibited_if' => 'Nota fiscal de saída não leva fornecedor — informe o cliente.',
        'data_demissao.after_or_equal' => 'A data de demissão não pode ser anterior à data de admissão.',
        'valor.gt' => 'O valor deve ser maior que zero.',
    ];

    /** Como cada campo aparece nas frases acima. */
    private const ROTULOS_CAMPOS = [
        'descricao' => 'a descrição',
        'data' => 'a data',
        'nome' => 'o nome',
        'documento' => 'o documento',
        'email' => 'o e-mail',
        'telefone' => 'o telefone',
        'cpf' => 'o CPF',
        'cargo' => 'o cargo',
        'salario' => 'o salário',
        'data_admissao' => 'a data de admissão',
        'data_demissao' => 'a data de demissão',
        'numero' => 'o número',
        'tipo' => 'o tipo',
        'valor' => 'o valor',
        'data_emissao' => 'a data de emissão',
        'cliente_id' => 'o cliente',
        'fornecedor_id' => 'o fornecedor',
        'lancamento_id' => 'o lançamento',
    ];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $catalogo = null;

    public function __construct(
        private LancamentoService $lancamentoService,
        private ClienteService $clienteService,
        private FornecedorService $fornecedorService,
        private FuncionarioService $funcionarioService,
        private NotaFiscalService $notaFiscalService,
        private CategoriaLancamentoService $categoriaLancamentoService,
        private AuditoriaService $auditoria,
        private ContextoUsuario $contexto,
    ) {
    }

    /**
     * Ponto único de contato com a Chat Completions API do Microsoft Foundry
     * (Azure OpenAI) — chamada HTTP REST stateless, sem SDK, na rota
     * unificada /openai/v1/chat/completions (versionamento implícito).
     *
     * Tools de leitura (consultar_lancamentos) são executadas direto e o
     * resultado volta como tabela crua — uma única chamada à API por
     * consulta. Tools de escrita (criar_lancamento) NUNCA são executadas
     * aqui: o tool_call é devolvido ao front como confirmação pendente
     * (depois de checado o RBAC — sem permissão, nem chega a propor).
     */
    public function processar(string $mensagem, array $historico = []): array
    {
        $endpoint = rtrim((string) config('services.azure_foundry.endpoint'), '/');
        $apiKey = (string) config('services.azure_foundry.api_key');
        $deployment = (string) config('services.azure_foundry.deployment');

        if ($endpoint === '' || $apiKey === '' || $deployment === '') {
            return $this->falha('provider_configuration_error', 'AI_PROVIDER_UNAVAILABLE', 503);
        }

        try {
            $resposta = Http::withHeaders(['api-key' => $apiKey])
                ->timeout(30)
                ->post("{$endpoint}/openai/v1/chat/completions", [
                    'model' => $deployment,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Você é o assistente do Sentinel, um sistema de contabilidade para micro e pequenas empresas. A data de hoje é '.now()->toDateString().' — use-a para resolver termos relativos como "hoje" e "ontem". Use as tools disponíveis para consultar e cadastrar os dados do sistema (lançamentos, clientes, fornecedores etc.) e para atualizar o status de um lançamento (pendente/conciliado). Você só pode executar operações representadas pelas ferramentas disponibilizadas. Se o pedido não corresponder a nenhuma ferramenta, explique claramente que a operação não é suportada. Não invente ferramentas, não afirme ter executado ações e não substitua entidades: usuário e funcionário são conceitos diferentes; cadastro de usuários não está disponível. Propostas no histórico não significam escrita executada: somente a confirmação humana autoriza a execução. Nunca invente dados — se faltar informação obrigatória para uma tool (por exemplo, o nome de um cliente ou o id do lançamento a atualizar), pergunte ao usuário em texto antes de chamar a tool. Campos opcionais ausentes não bloqueiam uma operação: não peça esses campos novamente se os obrigatórios já estiverem presentes. Para criar cliente, apenas nome é obrigatório. Quando já tiver todos os dados necessários para uma tool de escrita (as que criam ou atualizam dados), chame a tool diretamente — não peça confirmação você mesmo em texto: o sistema já exibe uma tela de confirmação humana (hold-to-confirm) antes de executar qualquer escrita.',
                        ],
                        ...$historico,
                        ['role' => 'user', 'content' => $mensagem],
                    ],
                    'tools' => $this->tools(),
                    'tool_choice' => 'auto',
                ]);
        } catch (\Throwable $e) {
            $anterior = $e->getPrevious();
            $timeout = $e instanceof \GuzzleHttp\Exception\NetworkTimeoutException
                || $e instanceof \GuzzleHttp\Exception\ConnectTimeoutException
                || $e instanceof \GuzzleHttp\Exception\ResponseTimeoutException
                || $anterior instanceof \GuzzleHttp\Exception\NetworkTimeoutException
                || $anterior instanceof \GuzzleHttp\Exception\ConnectTimeoutException
                || $anterior instanceof \GuzzleHttp\Exception\ResponseTimeoutException
                || ($e instanceof ConnectionException && preg_match('/^cURL error 28:/', $e->getMessage()) === 1);

            return $this->falha($timeout ? 'provider_timeout' : 'provider_connection_error',
                $timeout ? 'AI_PROVIDER_TIMEOUT' : 'AI_PROVIDER_UNAVAILABLE', $timeout ? 504 : 503, [], $e);
        }

        if (! $resposta->successful()) {
            $status = $resposta->status();
            $categoria = match ($status) {
                401 => 'provider_authentication_error',
                403 => 'provider_authorization_error',
                429 => 'provider_rate_limit',
                408, 504 => 'provider_timeout',
                default => 'provider_http_error',
            };
            $localStatus = match (true) {
                in_array($status, [408, 504], true) => 504,
                $status === 429 || $status >= 500 => 503,
                default => 502,
            };

            return $this->falha($categoria, $localStatus === 504 ? 'AI_PROVIDER_TIMEOUT' : 'AI_PROVIDER_UNAVAILABLE',
                $localStatus, ['status' => $status]);
        }

        // N1: o corpo da Azure é dado externo. Fora do formato esperado (message que não é objeto, choices
        // vazio, HTML com 200, name/arguments que não são texto...) vira erro amigável, nunca 500.
        $mensagemResposta = $resposta->json('choices.0.message');

        // message precisa ser objeto: uma lista com itens (ex.: ["oi"]) também está fora do formato.
        if (is_array($mensagemResposta) && $mensagemResposta !== [] && array_is_list($mensagemResposta)) {
            $mensagemResposta = null;
        }

        $toolCalls = is_array($mensagemResposta) ? ($mensagemResposta['tool_calls'] ?? []) : null;

        if (! is_array($mensagemResposta) || ! is_array($toolCalls)) {
            return $this->respostaForaDoFormato($resposta->status());
        }

        $fim = $resposta->json('choices.0.finish_reason');
        if ($fim === 'length' || $fim === 'content_filter') {
            return $this->falha('provider_response_error', $fim === 'length' ? 'AI_RESPONSE_TRUNCATED' : 'AI_CONTENT_FILTERED', 502,
                ['status' => $resposta->status()], null, $fim === 'length'
                    ? 'A resposta do agente ficou incompleta. Reformule o pedido.'
                    : 'O serviço de IA não pôde responder a esse pedido. Reformule a mensagem.');
        }
        if (($fim !== null && ! in_array($fim, ['stop', 'tool_calls'], true))
            || ($fim === 'tool_calls' && empty($toolCalls)) || ($fim === 'stop' && ! empty($toolCalls))
            || (! empty($toolCalls) && ! array_is_list($toolCalls))) {
            return $this->respostaForaDoFormato($resposta->status());
        }

        if (empty($toolCalls)) {
            $conteudo = $mensagemResposta['content'] ?? null;

            if ($conteudo !== null && ! is_string($conteudo)) {
                return $this->respostaForaDoFormato($resposta->status());
            }

            return [
                'tipo' => 'texto',
                'mensagem' => $conteudo ?? '',
            ];
        }

        $chamada = $toolCalls[0] ?? null;
        $funcao = is_array($chamada) ? ($chamada['function'] ?? null) : null;
        $tool = is_array($funcao) ? ($funcao['name'] ?? null) : null;
        $argumentosJson = is_array($funcao) ? ($funcao['arguments'] ?? null) : null;

        if (! is_string($tool) || ! is_string($argumentosJson)) {
            return $this->respostaForaDoFormato($resposta->status());
        }

        // Só o primeiro pedido é atendido; os demais não podem sumir calados.
        $aviso = count($toolCalls) > 1
            ? 'Havia '.count($toolCalls).' pedidos nesta mensagem e só o primeiro foi atendido. Peça os outros em seguida, um de cada vez.'
            : null;

        // Valida todas as chamadas antes de processar a primeira; nunca converte JSON inválido em consulta ampla.
        foreach ($toolCalls as $pedido) {
            $f = is_array($pedido) ? ($pedido['function'] ?? null) : null;
            if (! is_array($f) || ! is_string($f['name'] ?? null) || ! is_string($f['arguments'] ?? null)) {
                return $this->respostaForaDoFormato($resposta->status());
            }
            try {
                $objeto = json_decode($f['arguments'], false, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $this->respostaForaDoFormato($resposta->status());
            }
            if (! $objeto instanceof \stdClass) {
                return $this->respostaForaDoFormato($resposta->status());
            }
            $args = (array) $objeto;
            // Compatibilidade defensiva: tenant é sempre removido e derivado do backend.
            unset($args['tenant_id']);
            if ($f['name'] === 'criar_lancamento') unset($args['status']);
            $schema = $this->catalogo()[$f['name']]['definicao']['function']['parameters']['properties'] ?? null;
            if ($schema !== null && array_diff(array_keys($args), array_keys($schema)) !== []) {
                return $this->respostaForaDoFormato($resposta->status());
            }
        }
        $argumentos = (array) json_decode($argumentosJson, false, 32, JSON_THROW_ON_ERROR);
        unset($argumentos['tenant_id']);
        if ($tool === 'criar_lancamento') unset($argumentos['status']);
        $definicao = $this->catalogo()[$tool] ?? null;

        if ($definicao !== null && $definicao['tipo'] === 'escrita') {
            if (! $this->papelAtualPermite($definicao['papel_minimo'])) {
                return $this->comAviso($this->negar($tool, $definicao, $argumentos), $aviso);
            }

            return $this->comAviso([
                'tipo' => 'confirmacao_pendente',
                'tool' => $tool,
                'risco' => $definicao['risco'],
                'argumentos' => $argumentos,
            ], $aviso);
        }

        try {
            return $this->comAviso($this->executarTool($tool, $argumentos), $aviso);
        } catch (InvalidArgumentException $e) {
            return $this->comAviso([
                'tipo' => 'erro',
                'mensagem' => 'Esta operação não é suportada pelo agente.',
                'categoria' => 'unsupported_operation', 'codigo' => 'AI_UNSUPPORTED_OPERATION', 'http_status' => 422,
            ], $aviso);
        } catch (\Illuminate\Auth\AuthenticationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->erroTecnicoTool($e);
        }
    }

    /**
     * Acrescenta o campo "aviso" à resposta quando o modelo pediu mais de uma
     * tool na mesma mensagem (só a primeira é processada).
     *
     * @param  array<string, mixed>  $resposta
     * @return array<string, mixed>
     */
    private function comAviso(array $resposta, ?string $aviso): array
    {
        return $aviso === null ? $resposta : [...$resposta, 'aviso' => $aviso];
    }

    /**
     * N1: resposta 2xx da Chat Completions API fora do formato esperado. O log leva só o status
     * (o corpo pode trazer dados do usuário); o usuário recebe uma frase amigável.
     *
     * @return array{tipo: string, mensagem: string}
     */
    private function respostaForaDoFormato(int $status): array
    {
        return $this->falha('provider_response_error', 'AI_PROVIDER_RESPONSE_INVALID', 502,
            ['status' => $status], null, 'O agente de IA respondeu de um jeito inesperado. Tente novamente.');
    }

    public function erroTecnicoTool(Throwable $e): array
    {
        return $this->falha('tool_error', 'AI_TOOL_ERROR', 500, [], $e,
            'Não foi possível concluir a operação no momento. Tente novamente.');
    }

    /** Logs usam somente metadados definidos pelo servidor, nunca corpo, prompt ou mensagem de exception. */
    private function falha(string $categoria, string $codigo, int $status, array $metadados = [], ?Throwable $e = null, ?string $mensagem = null): array
    {
        $correlacao = (string) Str::uuid();
        $contexto = ['correlation_id' => $correlacao, 'categoria' => $categoria, 'provider' => 'azure', ...$metadados];
        if ($e !== null) {
            $contexto += ['classe' => $e::class, 'codigo' => $e->getCode(), 'arquivo' => basename($e->getFile()), 'linha' => $e->getLine()];
        }
        Log::warning('AiAgentService: falha controlada', $contexto);

        return ['tipo' => 'erro', 'categoria' => $categoria, 'codigo' => $codigo,
            'correlation_id' => $correlacao, 'http_status' => $status,
            'mensagem' => $mensagem ?? ($status === 504
                ? 'O serviço de IA demorou demais para responder. Tente novamente.'
                : 'Não foi possível acessar o serviço de IA no momento. Tente novamente.')];
    }

    /**
     * Definições das tools expostas ao modelo, no formato de function
     * calling da Chat Completions API. Enviadas no parâmetro `tools` da
     * chamada ao Azure OpenAI.
     *
     * Derivadas de catalogo() — a fonte única de tools: o que não está lá
     * não é oferecido ao modelo nem executável.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tools(): array
    {
        return array_values(array_map(fn (array $tool) => $tool['definicao'], $this->catalogo()));
    }

    /**
     * Ponto único de execução de tools (leitura direta ou escrita já
     * confirmada). Aqui passam, nesta ordem:
     *   1. whitelist  — nome fora do catálogo é recusado (exceção);
     *   2. RBAC       — papel atual abaixo do mínimo: nega e audita;
     *   3. tenant     — tenant_id sobrescrito pelo servidor;
     *   4. execução + auditoria na mesma transação (a escrita só persiste
     *      se o registro em audit_logs também persistir).
     *
     * @return array<string, mixed> tipo: resultado_leitura | resultado_escrita | negado | erro
     *
     * @throws InvalidArgumentException tool fora do catálogo
     */
    public function executarTool(string $tool, array $argumentos): array
    {
        $definicao = $this->catalogo()[$tool] ?? null;

        if ($definicao === null) {
            $this->auditar($tool, 'desconhecida', null, null, $argumentos, 'negado', 'Tool fora do catálogo.', false);

            throw new InvalidArgumentException("Tool desconhecida: {$tool}");
        }

        if (! $this->papelAtualPermite($definicao['papel_minimo'])) {
            return $this->negar($tool, $definicao, $argumentos);
        }

        $argumentos['tenant_id'] = $this->contexto->tenantId();

        try {
            $resultado = DB::transaction(function () use ($tool, $definicao, $argumentos) {
                $retorno = $definicao['executar']($argumentos);

                $this->auditar(
                    $tool,
                    $definicao['acao'],
                    $definicao['entidade'],
                    $retorno instanceof Model ? (int) $retorno->getKey() : null,
                    $argumentos,
                    'sucesso',
                    null,
                    true,
                );

                return $retorno;
            });
        } catch (ToolRecusadaException $e) {
            // Regra de negócio: a mensagem já é uma frase amigável escrita para o usuário.
            $this->auditar($tool, $definicao['acao'], $definicao['entidade'], null, $argumentos, 'recusado', $e->getMessage(), true);

            return [
                'tipo' => 'erro',
                'categoria' => 'validation_error', 'codigo' => 'AI_TOOL_REFUSED', 'http_status' => 422,
                'mensagem' => $e->getMessage(),
            ];
        } catch (Throwable $e) {
            // LGPD: nem a mensagem da exceção (uma QueryException traz o SQL com os valores, ex. CPF
            // e salário) nem os argumentos crus vão para o log ou para a auditoria. Registramos só
            // onde e de que tipo foi a falha; a mensagem ao usuário e à auditoria é a frase genérica da tool.
            Log::error('AiAgentService: falha ao executar tool', [
                'tool' => $tool,
                'excecao' => $e::class,
                'codigo' => $e->getCode(),
                'local' => basename($e->getFile()).':'.$e->getLine(),
                'correlation_id' => $correlacao = (string) Str::uuid(),
                'categoria' => 'tool_error',
            ]);

            $this->auditar($tool, $definicao['acao'], $definicao['entidade'], null, $argumentos, 'erro', $definicao['mensagem_erro'], true);

            return [
                'tipo' => 'erro',
                'mensagem' => $definicao['mensagem_erro'],
                'categoria' => 'tool_error', 'codigo' => 'AI_TOOL_ERROR',
                'correlation_id' => $correlacao, 'http_status' => 500,
            ];
        }

        // Leitura devolve só as colunas declaradas na tool (nada de tenant_id, timestamps, etc.).
        // A mesma máscara LGPD da auditoria vale para o que a leitura exibe (ex.: CPF no documento de cliente/fornecedor).
        if ($resultado instanceof Collection && $definicao['colunas']) {
            $resultado = $resultado->map(fn (Model $m) => AuditoriaService::mascararCamposSensiveis(Arr::only($m->toArray(), $definicao['colunas'])))->values()->all();
        }

        return [
            'tipo' => $definicao['tipo'] === 'escrita' ? 'resultado_escrita' : 'resultado_leitura',
            'tool' => $tool,
            'resultado' => $resultado,
            ...($definicao['colunas'] ? ['colunas' => $definicao['colunas']] : []),
        ];
    }

    /**
     * FONTE ÚNICA das tools. Cada entrada reúne o schema enviado ao modelo,
     * o tipo (leitura/escrita), o papel mínimo de RBAC, a entidade auditada
     * e o executor. tools() e executarTool() derivam daqui — não há segunda
     * lista para manter em sincronia. Para criar uma tool nova, adicione
     * uma entrada em definirTool() abaixo.
     *
     * Regras que valem para toda tool: tipo 'escrita' passa por confirmação
     * humana antes de executar; o executor recebe `tenant_id` já injetado
     * pelo servidor (nunca declare tenant_id no schema).
     *
     * @return array<string, array<string, mixed>>
     */
    private function catalogo(): array
    {
        return $this->catalogo ??= array_column([
            $this->definirTool(
                nome: 'consultar_lancamentos',
                descricao: 'Consulta lançamentos financeiros, opcionalmente filtrando por status e período. Ação de leitura — não exige confirmação.',
                tipo: 'leitura',
                risco: 'leitura',
                acao: 'consultar',
                entidade: 'Lancamento',
                papelMinimo: 'leitura',
                propriedades: [
                    'status' => ['type' => 'string', 'enum' => ['pendente', 'conciliado']],
                    'data_inicio' => ['type' => 'string', 'format' => 'date'],
                    'data_fim' => ['type' => 'string', 'format' => 'date'],
                ],
                obrigatorios: [],
                executar: fn (array $a) => $this->lancamentoService->buscarPorFiltro(
                    $a['status'] ?? null,
                    $a['data_inicio'] ?? null,
                    $a['data_fim'] ?? null,
                    $a['tenant_id'],
                ),
                mensagemErro: 'Não consegui consultar os lançamentos agora. Tente novamente.',
                colunas: ['id', 'descricao', 'valor', 'status', 'data'],
            ),
            $this->definirTool(
                nome: 'criar_lancamento',
                descricao: 'Cria um novo lançamento financeiro. Ação de escrita — exige confirmação humana antes de ser executada.',
                tipo: 'escrita',
                risco: 'escrita',
                acao: 'criar',
                entidade: 'Lancamento',
                papelMinimo: 'operador',
                propriedades: [
                    'descricao' => ['type' => 'string'],
                    'valor' => ['type' => 'number'],
                    'data' => ['type' => 'string', 'format' => 'date'],
                ],
                obrigatorios: ['descricao', 'valor', 'data'],
                executar: fn (array $a) => $this->lancamentoService->criar($this->validar($a, (new LancamentoRequest)->rules())),
                mensagemErro: 'Não consegui criar o lançamento — verifique se descrição, valor e data foram informados.',
            ),
            $this->definirTool(
                nome: 'atualizar_status_lancamento',
                descricao: 'Altera o status de um lançamento existente para "pendente" ou "conciliado". Ação de escrita — exige confirmação humana antes de ser executada.',
                tipo: 'escrita',
                risco: 'escrita',
                acao: 'atualizar',
                entidade: 'Lancamento',
                papelMinimo: 'operador',
                propriedades: [
                    'id' => ['type' => 'integer', 'description' => 'Id do lançamento a atualizar.'],
                    'novo_status' => ['type' => 'string', 'enum' => ['pendente', 'conciliado']],
                ],
                obrigatorios: ['id', 'novo_status'],
                executar: fn (array $a) => $this->atualizarStatusLancamento($a),
                mensagemErro: 'Não consegui atualizar o status do lançamento. Tente novamente.',
            ),
            $this->definirTool(
                nome: 'consultar_clientes',
                descricao: 'Consulta clientes cadastrados, opcionalmente filtrando por parte do nome. Ação de leitura — não exige confirmação.',
                tipo: 'leitura',
                risco: 'leitura',
                acao: 'consultar',
                entidade: 'Cliente',
                papelMinimo: 'leitura',
                propriedades: [
                    'nome' => ['type' => 'string', 'description' => 'Parte do nome do cliente.'],
                ],
                obrigatorios: [],
                executar: fn (array $a) => $this->clienteService->buscarPorFiltro($a['nome'] ?? null, $a['tenant_id']),
                mensagemErro: 'Não consegui consultar os clientes agora. Tente novamente.',
                colunas: ['id', 'nome', 'documento', 'email', 'telefone'],
            ),
            $this->definirTool(
                nome: 'criar_cliente',
                descricao: 'Cadastra um novo cliente. Ação de escrita — exige confirmação humana antes de ser executada.',
                tipo: 'escrita',
                risco: 'escrita',
                acao: 'criar',
                entidade: 'Cliente',
                papelMinimo: 'operador',
                propriedades: [
                    'nome' => ['type' => 'string'],
                    'documento' => ['type' => 'string', 'description' => 'CPF ou CNPJ.'],
                    'email' => ['type' => 'string'],
                    'telefone' => ['type' => 'string'],
                ],
                obrigatorios: ['nome'],
                executar: fn (array $a) => $this->clienteService->criar($this->validar($a, (new ClienteRequest)->rules())),
                mensagemErro: 'Não consegui cadastrar o cliente. Tente novamente.',
            ),
            $this->definirTool(
                nome: 'consultar_fornecedores',
                descricao: 'Consulta fornecedores cadastrados, opcionalmente filtrando por parte do nome. Ação de leitura — não exige confirmação.',
                tipo: 'leitura',
                risco: 'leitura',
                acao: 'consultar',
                entidade: 'Fornecedor',
                papelMinimo: 'leitura',
                propriedades: [
                    'nome' => ['type' => 'string', 'description' => 'Parte do nome do fornecedor.'],
                ],
                obrigatorios: [],
                executar: fn (array $a) => $this->fornecedorService->buscarPorFiltro($a['nome'] ?? null, $a['tenant_id']),
                mensagemErro: 'Não consegui consultar os fornecedores agora. Tente novamente.',
                colunas: ['id', 'nome', 'documento', 'email', 'telefone'],
            ),
            $this->definirTool(
                nome: 'criar_fornecedor',
                descricao: 'Cadastra um novo fornecedor. Ação de escrita — exige confirmação humana antes de ser executada.',
                tipo: 'escrita',
                risco: 'escrita',
                acao: 'criar',
                entidade: 'Fornecedor',
                papelMinimo: 'operador',
                propriedades: [
                    'nome' => ['type' => 'string'],
                    'documento' => ['type' => 'string', 'description' => 'CPF ou CNPJ.'],
                    'email' => ['type' => 'string'],
                    'telefone' => ['type' => 'string'],
                ],
                obrigatorios: ['nome'],
                executar: fn (array $a) => $this->fornecedorService->criar($this->validar($a, (new FornecedorRequest)->rules())),
                mensagemErro: 'Não consegui cadastrar o fornecedor. Tente novamente.',
            ),
            $this->definirTool(
                nome: 'consultar_funcionarios',
                descricao: 'Consulta funcionários cadastrados, opcionalmente filtrando por parte do nome. Ação de leitura — não exige confirmação.',
                tipo: 'leitura',
                risco: 'leitura',
                acao: 'consultar',
                entidade: 'Funcionario',
                papelMinimo: 'leitura',
                propriedades: [
                    'nome' => ['type' => 'string', 'description' => 'Parte do nome do funcionário.'],
                ],
                obrigatorios: [],
                executar: fn (array $a) => $this->funcionarioService->buscarPorFiltro($a['nome'] ?? null, $a['tenant_id']),
                mensagemErro: 'Não consegui consultar os funcionários agora. Tente novamente.',
                // cpf e salario ficam de fora de propósito: a consulta é aberta ao papel "leitura" e são dados pessoais/financeiros (LGPD).
                colunas: ['id', 'nome', 'cargo', 'data_admissao', 'data_demissao'],
            ),
            $this->definirTool(
                nome: 'criar_funcionario',
                descricao: 'Cadastra um novo funcionário. Ação de escrita — exige confirmação humana antes de ser executada.',
                tipo: 'escrita',
                risco: 'escrita',
                acao: 'criar',
                entidade: 'Funcionario',
                papelMinimo: 'admin',
                propriedades: [
                    'nome' => ['type' => 'string'],
                    'cpf' => ['type' => 'string', 'description' => 'CPF no formato 000.000.000-00.'],
                    'cargo' => ['type' => 'string'],
                    'salario' => ['type' => 'number'],
                    'data_admissao' => ['type' => 'string', 'format' => 'date'],
                    'data_demissao' => ['type' => 'string', 'format' => 'date', 'description' => 'Só se o funcionário já foi desligado.'],
                ],
                obrigatorios: ['nome', 'cpf', 'salario', 'data_admissao'],
                executar: fn (array $a) => $this->funcionarioService->criar($this->validar($a, (new FuncionarioRequest)->rules())),
                mensagemErro: 'Não consegui cadastrar o funcionário. Tente novamente.',
            ),
            $this->definirTool(
                nome: 'consultar_notas_fiscais',
                descricao: 'Consulta notas fiscais, opcionalmente filtrando por tipo (entrada ou saida). Ação de leitura — não exige confirmação.',
                tipo: 'leitura',
                risco: 'leitura',
                acao: 'consultar',
                entidade: 'NotaFiscal',
                papelMinimo: 'leitura',
                propriedades: [
                    'tipo' => ['type' => 'string', 'enum' => ['entrada', 'saida']],
                ],
                obrigatorios: [],
                executar: fn (array $a) => $this->notaFiscalService->buscarPorFiltro($a['tipo'] ?? null, $a['tenant_id']),
                mensagemErro: 'Não consegui consultar as notas fiscais agora. Tente novamente.',
                colunas: ['id', 'numero', 'tipo', 'valor', 'data_emissao', 'cliente_id', 'fornecedor_id', 'lancamento_id'],
            ),
            $this->definirTool(
                nome: 'criar_nota_fiscal',
                descricao: 'Cadastra uma nova nota fiscal. Nota de saída exige cliente_id; nota de entrada exige fornecedor_id. Ação de escrita — exige confirmação humana antes de ser executada.',
                tipo: 'escrita',
                risco: 'escrita',
                acao: 'criar',
                entidade: 'NotaFiscal',
                papelMinimo: 'operador',
                propriedades: [
                    'numero' => ['type' => 'string'],
                    'tipo' => ['type' => 'string', 'enum' => ['entrada', 'saida']],
                    'valor' => ['type' => 'number'],
                    'data_emissao' => ['type' => 'string', 'format' => 'date'],
                    'cliente_id' => ['type' => 'integer', 'description' => 'Id do cliente. Obrigatório só em nota de saída.'],
                    'fornecedor_id' => ['type' => 'integer', 'description' => 'Id do fornecedor. Obrigatório só em nota de entrada.'],
                    'lancamento_id' => ['type' => 'integer', 'description' => 'Id do lançamento relacionado (opcional).'],
                ],
                obrigatorios: ['numero', 'tipo', 'valor', 'data_emissao'],
                executar: fn (array $a) => $this->notaFiscalService->criar($this->validar(
                    $a,
                    (new NotaFiscalRequest)->rules(),
                    ['tipo.in' => 'O tipo deve ser "entrada" ou "saida".'],
                )),
                mensagemErro: 'Não consegui cadastrar a nota fiscal. Tente novamente.',
            ),
            $this->definirTool(
                nome: 'consultar_categorias_lancamento',
                descricao: 'Consulta categorias de lançamento, opcionalmente filtrando por tipo (receita ou despesa). Ação de leitura — não exige confirmação.',
                tipo: 'leitura',
                risco: 'leitura',
                acao: 'consultar',
                entidade: 'CategoriaLancamento',
                papelMinimo: 'leitura',
                propriedades: [
                    'tipo' => ['type' => 'string', 'enum' => ['receita', 'despesa']],
                ],
                obrigatorios: [],
                executar: fn (array $a) => $this->categoriaLancamentoService->buscarPorFiltro($a['tipo'] ?? null, $a['tenant_id']),
                mensagemErro: 'Não consegui consultar as categorias agora. Tente novamente.',
                colunas: ['id', 'nome', 'tipo'],
            ),
            $this->definirTool(
                nome: 'criar_categoria_lancamento',
                descricao: 'Cadastra uma nova categoria de lançamento (receita ou despesa). Ação de escrita — exige confirmação humana antes de ser executada.',
                tipo: 'escrita',
                risco: 'escrita',
                acao: 'criar',
                entidade: 'CategoriaLancamento',
                papelMinimo: 'operador',
                propriedades: [
                    'nome' => ['type' => 'string'],
                    'tipo' => ['type' => 'string', 'enum' => ['receita', 'despesa']],
                ],
                obrigatorios: ['nome', 'tipo'],
                executar: fn (array $a) => $this->categoriaLancamentoService->criar($this->validar(
                    $a,
                    (new CategoriaLancamentoRequest)->rules(),
                    ['tipo.in' => 'O tipo deve ser "receita" ou "despesa".'],
                )),
                mensagemErro: 'Não consegui cadastrar a categoria. Tente novamente.',
            ),
        ], null, 'nome');
    }

    /**
     * Valida os argumentos de uma tool com as MESMAS regras do Form Request da
     * entidade (fonte única) e traduz a primeira falha em frase amigável.
     *
     * @param  array<string, mixed>  $regras
     * @param  array<string, string>  $mensagens  frases específicas desta tool (somam-se às de MENSAGENS_VALIDACAO)
     * @return array<string, mixed> dados validados
     *
     * @throws ToolRecusadaException
     */
    private function validar(array $argumentos, array $regras, array $mensagens = []): array
    {
        $regras = $this->escoparPorTenant($regras, (int) $argumentos['tenant_id']);

        $validador = Validator::make($argumentos, $regras, [...self::MENSAGENS_VALIDACAO, ...$mensagens], self::ROTULOS_CAMPOS);

        if ($validador->fails()) {
            throw new ToolRecusadaException($validador->errors()->first());
        }

        return $validador->validated();
    }

    /**
     * Toda regra `exists:tabela,coluna` (chave estrangeira) passa a exigir que
     * o registro seja do tenant atual e não esteja na lixeira — uma tool nunca
     * cria vínculo com dado de outro tenant (RNF01), mesmo que o modelo mande
     * um id arbitrário. Vale para qualquer Form Request usado por uma tool,
     * sem depender de cada regra lembrar de filtrar. Pressupõe que as tabelas
     * referenciadas têm tenant_id e deleted_at (todas as do domínio têm).
     *
     * @param  array<string, mixed>  $regras
     * @return array<string, mixed>
     */
    private function escoparPorTenant(array $regras, int $tenantId): array
    {
        return array_map(function ($regra) use ($tenantId) {
            if (! is_string($regra)) {
                return $regra;
            }

            return array_map(function (string $parte) use ($tenantId) {
                if (! str_starts_with($parte, 'exists:')) {
                    return $parte;
                }

                [$tabela, $coluna] = array_pad(explode(',', substr($parte, 7)), 2, 'id');

                return Rule::exists($tabela, $coluna)->where('tenant_id', $tenantId)->whereNull('deleted_at');
            }, explode('|', $regra));
        }, $regras);
    }

    /**
     * Executor de atualizar_status_lancamento. Reaproveita o LancamentoService
     * (marcarConciliado/desmarcarConciliado) e traduz "não atualizou" em recusa
     * com frase amigável.
     */
    private function atualizarStatusLancamento(array $argumentos): Lancamento
    {
        $id = (int) ($argumentos['id'] ?? 0);

        if ($id < 1) {
            throw new ToolRecusadaException('Informe qual lançamento deve ser atualizado.');
        }

        $resultado = $this->lancamentoService->atualizarStatus(
            $id,
            (string) ($argumentos['novo_status'] ?? ''),
            $argumentos['tenant_id'],
        );

        if (! $resultado['atualizado']) {
            throw new ToolRecusadaException($resultado['motivo']);
        }

        return $resultado['lancamento'];
    }

    /**
     * @param  array<string, array<string, mixed>>  $propriedades
     * @param  string[]  $obrigatorios
     * @return array<string, mixed>
     */
    private function definirTool(
        string $nome,
        string $descricao,
        string $tipo,
        string $risco,
        string $acao,
        string $entidade,
        string $papelMinimo,
        array $propriedades,
        array $obrigatorios,
        Closure $executar,
        string $mensagemErro,
        array $colunas = [],
    ): array {
        // "tipo" decide se há confirmação humana (RNF02); "risco" só pinta o card. Os dois precisam concordar:
        // leitura <-> risco leitura; escrita <-> risco escrita ou exclusao. Combinação incoerente quebra cedo.
        if (! in_array($tipo, ['leitura', 'escrita'], true)
            || ! in_array($risco, ['leitura', 'escrita', 'exclusao'], true)
            || ($risco !== 'leitura') !== ($tipo === 'escrita')) {
            throw new InvalidArgumentException("Tool {$nome}: tipo '{$tipo}' e risco '{$risco}' incoerentes.");
        }

        return [
            'nome' => $nome,
            'tipo' => $tipo,
            // Classe de risco pelo EFEITO da tool (leitura | escrita | exclusao): a interface usa na borda do card.
            'risco' => $risco,
            'acao' => $acao,
            'entidade' => $entidade,
            'papel_minimo' => $papelMinimo,
            'executar' => $executar,
            'mensagem_erro' => $mensagemErro,
            'colunas' => $colunas, // só leitura: colunas que o front exibe na tabela
            'definicao' => [
                'type' => 'function',
                'function' => [
                    'name' => $nome,
                    'description' => $descricao,
                    'parameters' => [
                        'type' => 'object',
                        'properties' => $propriedades,
                        'additionalProperties' => false,
                        ...($obrigatorios ? ['required' => $obrigatorios] : []),
                    ],
                ],
            ],
        ];
    }

    /**
     * RBAC: a hierarquia vem de config('sentinel.papeis'); o papel atual, do
     * usuário logado (ContextoUsuario). Papel atual ou mínimo fora da
     * hierarquia nega (falha fechada).
     */
    private function papelAtualPermite(string $papelMinimo): bool
    {
        $hierarquia = (array) config('sentinel.papeis', []);
        $atual = array_search($this->contexto->papel(), $hierarquia, true);
        $minimo = array_search($papelMinimo, $hierarquia, true);

        return $atual !== false && $minimo !== false && $atual >= $minimo;
    }

    /**
     * Nega a execução por falta de permissão e grava a tentativa negada.
     *
     * @param  array<string, mixed>  $definicao
     * @return array{tipo: string, mensagem: string}
     */
    private function negar(string $tool, array $definicao, array $argumentos): array
    {
        $this->auditar(
            $tool,
            $definicao['acao'],
            $definicao['entidade'],
            null,
            $argumentos,
            'negado',
            'Papel '.$this->contexto->papel().' abaixo do mínimo exigido ('.$definicao['papel_minimo'].').',
            false,
        );

        return [
            'tipo' => 'negado',
            'categoria' => 'authorization_error', 'codigo' => 'AI_FORBIDDEN', 'http_status' => 403,
            'mensagem' => 'Você não tem permissão para executar esta ação.',
        ];
    }

    private function auditar(
        string $tool,
        string $acao,
        ?string $entidadeTipo,
        ?int $entidadeId,
        array $parametros,
        string $resultado,
        ?string $mensagem,
        bool $permitido,
    ): void {
        $this->auditoria->registrar(
            tool: $tool,
            acao: $acao,
            entidadeTipo: $entidadeTipo,
            entidadeId: $entidadeId,
            parametros: $parametros,
            resultado: $resultado,
            mensagem: $mensagem,
            papel: $this->contexto->papel(),
            permitido: $permitido,
        );
    }
}
