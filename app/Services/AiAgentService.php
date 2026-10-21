<?php

namespace App\Services;

use App\Exceptions\ToolRecusadaException;
use App\Http\Requests\CategoriaLancamentoRequest;
use App\Http\Requests\ClienteRequest;
use App\Http\Requests\FornecedorRequest;
use App\Http\Requests\FuncionarioRequest;
use App\Http\Requests\LancamentoRequest;
use App\Http\Requests\NotaFiscalRequest;
use App\Models\CategoriaLancamento;
use App\Models\Cliente;
use App\Models\Fornecedor;
use App\Models\Funcionario;
use App\Models\Lancamento;
use App\Models\NotaFiscal;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        'integer' => ':Attribute deve ser um número inteiro.',
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
        'id' => 'o número do registro',
        'novo_status' => 'o novo status',
    ];

    /** atualizar_status_lancamento não tem Form Request: estas regras desenham o formulário e validam o executor. */
    private const REGRAS_STATUS_LANCAMENTO = ['id' => 'required|numeric|integer|min:1', 'novo_status' => 'required|in:pendente,conciliado'];

    /** Frases que atualizar_status_lancamento já tinha antes das regras do formulário. */
    private const MENSAGENS_STATUS_LANCAMENTO = [
        'id.required' => 'Informe qual lançamento deve ser atualizado.',
        'id.numeric' => 'Informe qual lançamento deve ser atualizado.',
        'id.integer' => 'Informe qual lançamento deve ser atualizado.',
        'id.min' => 'Informe qual lançamento deve ser atualizado.',
        'novo_status.required' => 'Informe o novo status: "pendente" ou "conciliado".',
        'novo_status.in' => 'Status inválido: use "pendente" ou "conciliado".',
    ];

    /** Até quantos itens a consulta da lixeira devolve (os excluídos mais recentes). */
    private const LIMITE_LIXEIRA = 50;

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
        private DetalhesAuditoria $detalhesAuditoria,
        private FormularioTool $formularios,
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
                            'content' => 'Você é o assistente do Sentinel, um sistema de contabilidade para micro e pequenas empresas. A data de hoje é '.now()->toDateString().' — use-a para resolver termos relativos como "hoje" e "ontem". Use as tools disponíveis para consultar e cadastrar os dados do sistema (lançamentos, clientes, fornecedores etc.) e para atualizar o status de um lançamento (pendente/conciliado). Você só pode executar operações representadas pelas ferramentas disponibilizadas. Se o pedido não corresponder a nenhuma ferramenta, explique claramente que a operação não é suportada. Não invente ferramentas, não afirme ter executado ações e não substitua entidades: usuário e funcionário são conceitos diferentes; cadastro de usuários não está disponível. Propostas no histórico não significam escrita executada: somente a confirmação humana autoriza a execução. Nunca invente dados — se faltar informação obrigatória para uma tool (por exemplo, o nome de um cliente ou o id do lançamento a atualizar), pergunte ao usuário em texto antes de chamar a tool. Campos opcionais ausentes não bloqueiam uma operação: não peça esses campos novamente se os obrigatórios já estiverem presentes. Para criar cliente, apenas nome é obrigatório. Quando já tiver todos os dados necessários para uma tool de escrita (as que criam ou atualizam dados), chame a tool diretamente — não peça confirmação você mesmo em texto: o sistema já exibe uma tela de confirmação humana (hold-to-confirm) antes de executar qualquer escrita. Excluir move o registro para a lixeira; restaurar e excluir de vez pedem a senha de um administrador, que é digitada só no card de confirmação: nunca peça nem aceite senha no chat.',
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

            // Atualizar (Bloco 3): o servidor acha o registro (por id ou nome, no tenant) e o formulário
            // vem com os valores atuais mais o que a IA mudou. Nenhum ou vários: responde sem formulário.
            $propostosPelaIa = $argumentos;

            if ($definicao['localizar'] !== null) {
                $localizado = $this->localizarRegistro($tool, $definicao, $argumentos);

                if (isset($localizado['resposta'])) {
                    return $this->comAviso($localizado['resposta'], $aviso);
                }

                $argumentos = $localizado['argumentos'];
            }

            // RF04: a proposta já chega como formulário (o próprio card de confirmação). Os argumentos
            // voltam crus para o controller guardar na sessão; ele mascara antes de responder ao front.
            // "propostos" é o que a IA pediu de fato (na atualização: id/busca + só os campos que mudam).
            return $this->comAviso([
                'tipo' => 'confirmacao_pendente',
                'tool' => $tool,
                'risco' => $definicao['risco'],
                'argumentos' => $argumentos,
                'propostos' => $propostosPelaIa,
                'formulario' => $this->formulario($tool, $argumentos),
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

        // RF10 (Bloco 4): tool que exige a senha de um admin só roda com a reautenticação feita nesta
        // mesma requisição (o /agente/confirmar confere a senha e abre o escopo com quem autorizou).
        if ($definicao['reautenticar'] && $this->autorizadoPor() === null) {
            $this->auditar($tool, $definicao['acao'], $definicao['entidade'], null, $argumentos, 'negado', 'Sem reautenticação de administrador.', false);

            return [
                'tipo' => 'erro',
                'categoria' => 'authorization_error', 'codigo' => 'AI_REAUTH_REQUIRED', 'http_status' => 403,
                'mensagem' => 'Para continuar, é preciso a senha de um administrador. Nada foi gravado.',
            ];
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
                // Falha de validação: a frase de cada campo, para o formulário do card (RF04).
                ...($e->erros === [] ? [] : ['erros' => $e->erros]),
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

        if ($definicao['tipo'] === 'escrita') {
            return [
                'tipo' => 'resultado_escrita',
                'tool' => $tool,
                // Frase fixa do servidor, sem valores e sem chamar a IA de novo: o que gravou e o que ficou vazio.
                'mensagem' => $this->fraseDeSucesso($definicao, $argumentos),
                // O registro gravado volta ao front com a mesma máscara LGPD da auditoria.
                'resultado' => $resultado instanceof Model ? AuditoriaService::mascararCamposSensiveis($resultado->toArray()) : $resultado,
            ];
        }

        return [
            'tipo' => 'resultado_leitura',
            'tool' => $tool,
            'resultado' => $resultado,
            ...($definicao['colunas'] ? ['colunas' => $definicao['colunas']] : []),
            // RF02 "editável": a linha abre o formulário de atualizar (sem IA), se o papel permitir.
            ...($this->podeEditar($definicao['editavel_por']) ? ['editar' => $definicao['editavel_por']] : []),
        ];
    }

    /** A tool de atualizar existe e o papel atual chega no mínimo dela. */
    private function podeEditar(?string $toolAtualizar): bool
    {
        $atualizar = $toolAtualizar === null ? null : ($this->catalogo()[$toolAtualizar] ?? null);

        return $atualizar !== null && $this->papelAtualPermite($atualizar['papel_minimo']);
    }

    /** A tool pede a senha de um administrador no card (Bloco 4: restaurar e excluir de vez). */
    public function exigeReautenticacao(string $tool): bool
    {
        return (bool) ($this->catalogo()[$tool]['reautenticar'] ?? false);
    }

    /** O admin que reautenticou nesta requisição — posto só pelo /agente/confirmar, depois de conferir a senha. */
    private function autorizadoPor(): ?int
    {
        $id = $this->detalhesAuditoria->atuais()['autorizado_por'] ?? null;

        return is_int($id) && $id >= 1 ? $id : null;
    }

    /**
     * Bloco 3: acha o registro a atualizar por id ou por nome/número, resolvido no
     * SERVIDOR e dentro do tenant (a memória do agente não guarda as linhas das
     * tabelas, então a IA só conhece o nome que o usuário digitou). Um registro:
     * devolve os argumentos do formulário (valores atuais + o que a IA mudou + id).
     * Nenhum: "Não encontrei…". Vários: a lista curta para escolher, sem formulário.
     *
     * @return array{argumentos?: array, resposta?: array}
     */
    private function localizarRegistro(string $tool, array $definicao, array $argumentos): array
    {
        $id = isset($argumentos['id']) && is_numeric($argumentos['id']) && (int) $argumentos['id'] >= 1 ? (int) $argumentos['id'] : null;
        $busca = is_string($argumentos['busca'] ?? null) && trim($argumentos['busca']) !== '' ? trim($argumentos['busca']) : null;
        $recusa = fn (string $mensagem) => ['resposta' => [
            'tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_TOOL_REFUSED', 'http_status' => 422, 'mensagem' => $mensagem,
        ]];

        if ($id === null && $busca === null) {
            // "o número ou o nome" / "ou a descrição"; na nota fiscal a busca já é pelo número.
            $ouTexto = match ($definicao['busca_por']) { 'número' => '', 'descrição' => ' ou a descrição', default => ' ou o '.$definicao['busca_por'] };

            return $recusa('Informe qual '.FormularioTool::semArtigo($definicao['rotulo']).' deve ser '.$definicao['feito'].': o número'.$ouTexto.'.');
        }

        $encontrados = ($definicao['localizar'])($id, $busca, $this->contexto->tenantId());

        if ($encontrados->isEmpty()) {
            return $recusa('Não encontrei '.$definicao['rotulo'].' com '.($id !== null ? 'esse número' : ($definicao['busca_por'] === 'descrição' ? 'essa ' : 'esse ').$definicao['busca_por']).$definicao['onde'].'.');
        }

        if ($encontrados->count() > 1) {
            $colunas = ['id', $definicao['coluna_rotulo']];
            // Bloco 4: na lixeira o botão de cada linha é a própria ação ("Restaurar"), não "Editar".
            $acao = $definicao['rotulo_acao'] !== '' ? ['acao' => ['tool' => $tool, 'rotulo' => $definicao['rotulo_acao']]] : [];

            return ['resposta' => [
                'tipo' => 'resultado_leitura',
                'tool' => $tool,
                'resultado' => $encontrados->map(fn (Model $m) => [...AuditoriaService::mascararCamposSensiveis(Arr::only($m->toArray(), $colunas)), ...$acao])->values()->all(),
                'colunas' => $colunas,
                ...($acao === [] ? ['editar' => $tool] : []),
                'aviso' => 'Encontrei mais de um registro com esse nome. Escolha um na lista ou peça de novo com o número.',
            ]];
        }

        return ['argumentos' => $this->valoresDoFormulario($definicao, $encontrados->first(), $argumentos)];
    }

    /**
     * Valores atuais do registro nos campos do formulário (datas em AAAA-MM-DD), por
     * cima deles o que veio informado (da IA) e, por fim, o id resolvido pelo servidor.
     */
    private function valoresDoFormulario(array $definicao, Model $registro, array $informados = []): array
    {
        $valores = [];

        foreach (array_keys(FormularioTool::campos($definicao['regras'])) as $campo) {
            $atual = $registro->getAttribute($campo);
            $valores[$campo] = $atual instanceof \DateTimeInterface ? $atual->format('Y-m-d') : $atual;

            if (array_key_exists($campo, $informados) && $informados[$campo] !== null) {
                $valores[$campo] = $informados[$campo];
            }
        }

        // "versao": o updated_at de quando o formulário abriu; se mudar até a confirmação, a gravação é recusada.
        return [...$valores, 'id' => (int) $registro->getKey(), 'versao' => (string) $registro->getRawOriginal('updated_at')];
    }

    /**
     * /agente/formulario (Bloco 3): o mesmo formulário da proposta, aberto SEM IA —
     * "Editar" numa linha de tabela (com id) ou um cadastro em branco. Mesmo RBAC e
     * tenant da proposta pelo agente; registro de outro tenant ou na lixeira responde
     * igual a inexistente.
     */
    public function abrirFormulario(string $tool, ?int $id): array
    {
        $definicao = $this->catalogo()[$tool] ?? null;

        if ($definicao === null || $definicao['tipo'] !== 'escrita' || ($definicao['regras'] === [] && $definicao['resumo'] === [])) {
            return ['tipo' => 'erro', 'categoria' => 'unsupported_operation', 'codigo' => 'AI_UNSUPPORTED_OPERATION',
                'http_status' => 422, 'mensagem' => 'Esta operação não é suportada.'];
        }

        if (! $this->papelAtualPermite($definicao['papel_minimo'])) {
            return $this->detalhesAuditoria->com(['origem' => 'formulario'],
                fn () => $this->negar($tool, $definicao, $id === null ? [] : ['id' => $id]));
        }

        $argumentos = [];

        if ($definicao['localizar'] !== null) {
            $localizado = $this->localizarRegistro($tool, $definicao, ['id' => $id]);

            if (isset($localizado['resposta'])) {
                return $localizado['resposta'];
            }

            $argumentos = $localizado['argumentos'];
        }

        return [
            'tipo' => 'confirmacao_pendente',
            'tool' => $tool,
            'risco' => $definicao['risco'],
            'argumentos' => $argumentos,
            'propostos' => [], // sem IA: nada foi proposto, só os valores atuais
            'formulario' => $this->formulario($tool, $argumentos),
        ];
    }

    /**
     * Escrita depois da confirmação humana: a auditoria registra o que a IA propôs
     * ($propostos) ao lado do que foi de fato executado ($argumentos) — RF04.
     * $autorizadoPor: o admin cuja senha o /agente/confirmar acabou de conferir (RF10);
     * fica na auditoria ao lado de quem estava logado e libera as tools que pedem senha.
     */
    public function executarConfirmada(string $tool, array $argumentos, array $propostos, string $origem = 'agente', ?int $autorizadoPor = null): array
    {
        return $this->detalhesAuditoria->com([
            'parametros_propostos' => $propostos,
            'origem' => $origem,
            ...($autorizadoPor === null ? [] : ['autorizado_por' => $autorizadoPor]),
        ], fn () => $this->executarTool($tool, $argumentos));
    }

    /**
     * /agente/revisar (Bloco 2b): a mesma governança da execução — RBAC, tenant do
     * servidor e as regras da tool — sem gravar nada. Null quando a revisão passa;
     * senão a resposta de recusa. RBAC negado e falha de validação ficam na
     * auditoria (com o proposto da IA), como ficavam quando a validação era na confirmação.
     */
    public function revisar(string $tool, array $argumentos, array $propostos, string $origem = 'agente'): ?array
    {
        $definicao = $this->catalogo()[$tool] ?? null;

        if ($definicao === null || $definicao['tipo'] !== 'escrita') {
            return ['tipo' => 'erro', 'categoria' => 'unsupported_operation', 'codigo' => 'AI_UNSUPPORTED_OPERATION',
                'http_status' => 422, 'mensagem' => 'Esta operação não é suportada pelo agente.'];
        }

        return $this->detalhesAuditoria->com(['parametros_propostos' => $propostos, 'origem' => $origem], function () use ($tool, $definicao, $argumentos) {
            if (! $this->papelAtualPermite($definicao['papel_minimo'])) {
                return $this->negar($tool, $definicao, $argumentos);
            }

            $argumentos['tenant_id'] = $this->contexto->tenantId();

            try {
                $this->validar($argumentos, $definicao['regras'], $definicao['mensagens']);

                $recusa = $definicao['conferir'] === null ? null : ($definicao['conferir'])($argumentos);
                if ($recusa !== null) {
                    throw new ToolRecusadaException($recusa['motivo'], [$recusa['campo'] => $recusa['motivo']]);
                }
            } catch (ToolRecusadaException $e) {
                // Não é desfecho: a proposta segue em rascunho para o humano corrigir. Resultado não-final.
                $this->auditar($tool, $definicao['acao'], $definicao['entidade'], null, $argumentos, AuditoriaService::REVISAO_COM_ERRO, $e->getMessage(), true);

                return [
                    'tipo' => 'erro',
                    'categoria' => 'validation_error', 'codigo' => 'AI_TOOL_REFUSED', 'http_status' => 422,
                    'mensagem' => $e->getMessage(),
                    'erros' => $e->erros,
                ];
            }

            return null;
        });
    }

    /** Classe de risco da tool (borda do card); tool fora do catálogo cai na mais severa. */
    public function risco(string $tool): string
    {
        return $this->catalogo()[$tool]['risco'] ?? 'exclusao';
    }

    /**
     * Formulário do card (RF04) a partir das regras da tool, com os valores dados e,
     * se houver, a frase de erro de cada campo. Null para tool sem formulário.
     */
    public function formulario(string $tool, array $valores, array $erros = []): ?array
    {
        $definicao = $this->catalogo()[$tool] ?? null;

        if ($definicao === null || ($definicao['regras'] === [] && $definicao['resumo'] === [])) {
            return null;
        }

        // Decisão do Lucas (20/10): só o admin vê o salário no card, formatado em reais, para conferir o
        // que vai confirmar. Só nesta resposta ao front: log, histórico e auditoria continuam mascarados.
        $revelados = $this->contexto->papel() === 'admin' ? ['salario'] : [];

        $titulo = $definicao['titulo'].(isset($valores['id']) && $definicao['localizar'] !== null ? ' #'.$valores['id'] : '');

        $formulario = $this->formularios->montar($titulo, $definicao['regras'], $valores, $erros, self::ROTULOS_CAMPOS,
            $this->contexto->tenantId(), $revelados);

        // Bloco 4 (lixeira): o card mostra o registro, só para leitura, e pode pedir a senha de um admin.
        // A senha não é campo do formulário: não vai no Revisar nem fica na sessão (ver /agente/confirmar).
        if ($definicao['resumo'] !== []) {
            $formulario['resumo'] = $this->resumo($definicao, $valores);
        }
        if ($definicao['reautenticar']) {
            $formulario['reautenticar'] = true;
        }

        // Recusa que não é de um campo do formulário (ex.: "existem lançamentos vinculados"): vai no topo do card.
        $avulsos = array_diff_key($erros, array_flip(array_column($formulario['campos'], 'nome')));
        if ($avulsos !== []) {
            $formulario['erro'] = reset($avulsos);
        }

        return $formulario;
    }

    /**
     * Bloco 4: o registro que a ação vai mover, para o humano conferir no card — só leitura,
     * com as colunas da consulta e a máscara LGPD. Vazio se ele já não está na situação
     * esperada (ex.: alguém restaurou antes).
     *
     * @return array<int, array{rotulo: string, valor: mixed}>
     */
    private function resumo(array $definicao, array $valores): array
    {
        $id = isset($valores['id']) && is_numeric($valores['id']) ? (int) $valores['id'] : 0;
        $registro = $id >= 1 ? ($definicao['localizar'])($id, null, $this->contexto->tenantId())->first() : null;

        if ($registro === null) {
            return [];
        }

        $dados = [];
        foreach ($definicao['resumo'] as $campo) {
            $valor = $registro->getAttribute($campo);
            $dados[$campo] = $valor instanceof \DateTimeInterface ? $valor->format('Y-m-d') : $valor;
        }

        return array_map(fn (string $campo, mixed $valor) => [
            'rotulo' => Str::ucfirst(FormularioTool::semArtigo(self::ROTULOS_CAMPOS[$campo] ?? str_replace('_', ' ', $campo))),
            'valor' => $valor,
        ], array_keys($dados), AuditoriaService::mascararCamposSensiveis($dados));
    }

    /**
     * RF04: valores editados no card por cima da proposta guardada na sessão. Só
     * entram os campos do formulário (as regras da tool, sem tenant_id); qualquer
     * outro campo enviado é descartado. Campo vazio vira "sem valor", exceto o
     * sensível: o card só mostra a máscara dele, então vazio = manter o da proposta.
     */
    public function aplicarValores(string $tool, array $proposta, array $valores): array
    {
        $regras = $this->catalogo()[$tool]['regras'] ?? [];

        foreach (array_keys(FormularioTool::campos($regras)) as $campo) {
            if (! array_key_exists($campo, $valores)) {
                continue;
            }

            $vazio = $valores[$campo] === null || $valores[$campo] === '';

            if ($vazio && in_array($campo, AuditoriaService::camposSensiveis(), true)) {
                continue;
            }

            $proposta[$campo] = $vazio ? null : $valores[$campo];
        }

        return $proposta;
    }

    /**
     * "Cliente cadastrado. Ficou sem e-mail e telefone." / "Cliente cadastrado com todos os dados."
     * Tool sem campo opcional: só "Status do lançamento atualizado.".
     */
    private function fraseDeSucesso(array $definicao, array $argumentos): string
    {
        $opcionais = FormularioTool::opcionais($definicao['regras'], $argumentos);
        $vazios = array_values(array_filter($opcionais, fn (string $campo) => blank($argumentos[$campo] ?? null)));

        return match (true) {
            $opcionais === [] => $definicao['sucesso'].'.',
            $vazios === [] => $definicao['sucesso'].' com todos os dados.',
            default => $definicao['sucesso'].'. Ficou sem '.Arr::join(array_map(
                fn (string $campo) => FormularioTool::semArtigo(self::ROTULOS_CAMPOS[$campo] ?? $campo), $vazios), ', ', ' e ').'.',
        };
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
                regras: (new LancamentoRequest)->rules(),
                titulo: 'Criar lançamento',
                sucesso: 'Lançamento criado',
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
                // Sem Form Request próprio: as mesmas regras desenham o formulário e validam no executor.
                regras: self::REGRAS_STATUS_LANCAMENTO,
                mensagens: self::MENSAGENS_STATUS_LANCAMENTO,
                // Inexistente, de outro tenant ou já no status pedido: recusado no Revisar, antes do segurar.
                conferir: fn (array $a) => $this->lancamentoService->motivoParaNaoAtualizarStatus((int) $a['id'], (string) $a['novo_status'], (int) $a['tenant_id']),
                titulo: 'Alterar o status do lançamento',
                sucesso: 'Status do lançamento atualizado',
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
                editavelPor: 'atualizar_cliente',
            ),
            $this->definirEscrita(
                nome: 'criar_cliente',
                descricao: 'Cadastra um novo cliente. Ação de escrita — exige confirmação humana antes de ser executada.',
                entidade: 'Cliente',
                papelMinimo: 'operador',
                propriedades: [
                    'nome' => ['type' => 'string'],
                    'documento' => ['type' => 'string', 'description' => 'CPF ou CNPJ.'],
                    'email' => ['type' => 'string'],
                    'telefone' => ['type' => 'string'],
                ],
                obrigatorios: ['nome'],
                formRequest: ClienteRequest::class,
                gravar: $this->clienteService->criar(...),
                mensagemErro: 'Não consegui cadastrar o cliente. Tente novamente.',
                titulo: 'Cadastrar cliente',
                sucesso: 'Cliente cadastrado',
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
                editavelPor: 'atualizar_fornecedor',
            ),
            $this->definirEscrita(
                nome: 'criar_fornecedor',
                descricao: 'Cadastra um novo fornecedor. Ação de escrita — exige confirmação humana antes de ser executada.',
                entidade: 'Fornecedor',
                papelMinimo: 'operador',
                propriedades: [
                    'nome' => ['type' => 'string'],
                    'documento' => ['type' => 'string', 'description' => 'CPF ou CNPJ.'],
                    'email' => ['type' => 'string'],
                    'telefone' => ['type' => 'string'],
                ],
                obrigatorios: ['nome'],
                formRequest: FornecedorRequest::class,
                gravar: $this->fornecedorService->criar(...),
                mensagemErro: 'Não consegui cadastrar o fornecedor. Tente novamente.',
                titulo: 'Cadastrar fornecedor',
                sucesso: 'Fornecedor cadastrado',
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
                editavelPor: 'atualizar_funcionario',
            ),
            $this->definirEscrita(
                nome: 'criar_funcionario',
                descricao: 'Cadastra um novo funcionário. Ação de escrita — exige confirmação humana antes de ser executada.',
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
                formRequest: FuncionarioRequest::class,
                gravar: $this->funcionarioService->criar(...),
                mensagemErro: 'Não consegui cadastrar o funcionário. Tente novamente.',
                titulo: 'Cadastrar funcionário',
                sucesso: 'Funcionário cadastrado',
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
                editavelPor: 'atualizar_nota_fiscal',
            ),
            $this->definirEscrita(
                nome: 'criar_nota_fiscal',
                descricao: 'Cadastra uma nova nota fiscal. Nota de saída exige cliente_id; nota de entrada exige fornecedor_id. Ação de escrita — exige confirmação humana antes de ser executada.',
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
                formRequest: NotaFiscalRequest::class,
                gravar: $this->notaFiscalService->criar(...),
                mensagemErro: 'Não consegui cadastrar a nota fiscal. Tente novamente.',
                titulo: 'Cadastrar nota fiscal',
                sucesso: 'Nota fiscal cadastrada',
                mensagens: ['tipo.in' => 'O tipo deve ser "entrada" ou "saida".'],
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
                editavelPor: 'atualizar_categoria_lancamento',
            ),
            $this->definirEscrita(
                nome: 'criar_categoria_lancamento',
                descricao: 'Cadastra uma nova categoria de lançamento (receita ou despesa). Ação de escrita — exige confirmação humana antes de ser executada.',
                entidade: 'CategoriaLancamento',
                papelMinimo: 'operador',
                propriedades: [
                    'nome' => ['type' => 'string'],
                    'tipo' => ['type' => 'string', 'enum' => ['receita', 'despesa']],
                ],
                obrigatorios: ['nome', 'tipo'],
                formRequest: CategoriaLancamentoRequest::class,
                gravar: $this->categoriaLancamentoService->criar(...),
                mensagemErro: 'Não consegui cadastrar a categoria. Tente novamente.',
                titulo: 'Cadastrar categoria de lançamento',
                sucesso: 'Categoria cadastrada',
                mensagens: ['tipo.in' => 'O tipo deve ser "receita" ou "despesa".'],
            ),
            $this->definirAtualizacao(
                nome: 'atualizar_cliente',
                descricao: 'Altera um cliente existente, achado pelo id ou por parte do nome. Mande só os campos que mudam. Ação de escrita — exige confirmação humana antes de ser executada.',
                entidade: 'Cliente',
                rotulo: 'o cliente',
                papelMinimo: 'operador',
                propriedades: [
                    'nome' => ['type' => 'string', 'description' => 'Nome novo, só se for mudar.'],
                    'documento' => ['type' => 'string', 'description' => 'CPF ou CNPJ.'],
                    'email' => ['type' => 'string'],
                    'telefone' => ['type' => 'string'],
                ],
                formRequest: ClienteRequest::class,
                localizar: $this->clienteService->localizar(...),
                atualizar: $this->clienteService->atualizar(...),
                buscaPor: 'nome',
                colunaRotulo: 'nome',
                mensagemErro: 'Não consegui alterar o cliente. Tente novamente.',
                titulo: 'Alterar cliente',
                sucesso: 'Cliente atualizado',
            ),
            $this->definirAtualizacao(
                nome: 'atualizar_fornecedor',
                descricao: 'Altera um fornecedor existente, achado pelo id ou por parte do nome. Mande só os campos que mudam. Ação de escrita — exige confirmação humana antes de ser executada.',
                entidade: 'Fornecedor',
                rotulo: 'o fornecedor',
                papelMinimo: 'operador',
                propriedades: [
                    'nome' => ['type' => 'string', 'description' => 'Nome novo, só se for mudar.'],
                    'documento' => ['type' => 'string', 'description' => 'CPF ou CNPJ.'],
                    'email' => ['type' => 'string'],
                    'telefone' => ['type' => 'string'],
                ],
                formRequest: FornecedorRequest::class,
                localizar: $this->fornecedorService->localizar(...),
                atualizar: $this->fornecedorService->atualizar(...),
                buscaPor: 'nome',
                colunaRotulo: 'nome',
                mensagemErro: 'Não consegui alterar o fornecedor. Tente novamente.',
                titulo: 'Alterar fornecedor',
                sucesso: 'Fornecedor atualizado',
            ),
            $this->definirAtualizacao(
                nome: 'atualizar_funcionario',
                descricao: 'Altera um funcionário existente, achado pelo id ou por parte do nome. Mande só os campos que mudam. Ação de escrita — exige confirmação humana antes de ser executada.',
                entidade: 'Funcionario',
                rotulo: 'o funcionário',
                papelMinimo: 'admin',
                propriedades: [
                    'nome' => ['type' => 'string', 'description' => 'Nome novo, só se for mudar.'],
                    'cpf' => ['type' => 'string', 'description' => 'CPF no formato 000.000.000-00.'],
                    'cargo' => ['type' => 'string'],
                    'salario' => ['type' => 'number'],
                    'data_admissao' => ['type' => 'string', 'format' => 'date'],
                    'data_demissao' => ['type' => 'string', 'format' => 'date', 'description' => 'Só se o funcionário foi desligado.'],
                ],
                formRequest: FuncionarioRequest::class,
                localizar: $this->funcionarioService->localizar(...),
                atualizar: $this->funcionarioService->atualizar(...),
                buscaPor: 'nome',
                colunaRotulo: 'nome',
                mensagemErro: 'Não consegui alterar o funcionário. Tente novamente.',
                titulo: 'Alterar funcionário',
                sucesso: 'Funcionário atualizado',
            ),
            $this->definirAtualizacao(
                nome: 'atualizar_nota_fiscal',
                descricao: 'Altera uma nota fiscal existente, achada pelo id ou pelo número exato da nota. Mande só os campos que mudam. Nota de saída exige cliente_id; de entrada, fornecedor_id. Ação de escrita — exige confirmação humana antes de ser executada.',
                entidade: 'NotaFiscal',
                rotulo: 'a nota fiscal',
                papelMinimo: 'operador',
                propriedades: [
                    'numero' => ['type' => 'string', 'description' => 'Número novo, só se for mudar.'],
                    'tipo' => ['type' => 'string', 'enum' => ['entrada', 'saida']],
                    'valor' => ['type' => 'number'],
                    'data_emissao' => ['type' => 'string', 'format' => 'date'],
                    'cliente_id' => ['type' => 'integer'],
                    'fornecedor_id' => ['type' => 'integer'],
                    'lancamento_id' => ['type' => 'integer'],
                ],
                formRequest: NotaFiscalRequest::class,
                localizar: $this->notaFiscalService->localizar(...),
                atualizar: $this->notaFiscalService->atualizar(...),
                buscaPor: 'número',
                colunaRotulo: 'numero',
                mensagemErro: 'Não consegui alterar a nota fiscal. Tente novamente.',
                titulo: 'Alterar nota fiscal',
                sucesso: 'Nota fiscal atualizada',
                mensagens: ['tipo.in' => 'O tipo deve ser "entrada" ou "saida".'],
            ),
            $this->definirAtualizacao(
                nome: 'atualizar_categoria_lancamento',
                descricao: 'Altera uma categoria de lançamento existente, achada pelo id ou por parte do nome. Mande só os campos que mudam. Ação de escrita — exige confirmação humana antes de ser executada.',
                entidade: 'CategoriaLancamento',
                rotulo: 'a categoria',
                papelMinimo: 'operador',
                propriedades: [
                    'nome' => ['type' => 'string', 'description' => 'Nome novo, só se for mudar.'],
                    'tipo' => ['type' => 'string', 'enum' => ['receita', 'despesa']],
                ],
                formRequest: CategoriaLancamentoRequest::class,
                localizar: $this->categoriaLancamentoService->localizar(...),
                atualizar: $this->categoriaLancamentoService->atualizar(...),
                buscaPor: 'nome',
                colunaRotulo: 'nome',
                mensagemErro: 'Não consegui alterar a categoria. Tente novamente.',
                titulo: 'Alterar categoria de lançamento',
                sucesso: 'Categoria atualizada',
                mensagens: ['tipo.in' => 'O tipo deve ser "receita" ou "despesa".'],
            ),
            ...$this->definirLixeira(),
        ], null, 'nome');
    }

    /**
     * Bloco 4 — as entidades com lixeira (RF08–RF12). O vínculo tool ↔ entidade ↔ service
     * da lixeira fica todo aqui; trocar de domínio é trocar estas linhas. "colunas": o que o
     * card mostra do registro para o humano conferir (as da consulta, sem o id, que vai no
     * título). "papel": o mínimo para restaurar — o mesmo de criar/alterar a entidade.
     *
     * @return array<string, array<string, mixed>>
     */
    private function lixeiras(): array
    {
        return [
            'lancamento' => ['entidade' => 'Lancamento', 'modelo' => Lancamento::class, 'rotulo' => 'o lançamento', 'servico' => $this->lancamentoService,
                'busca_por' => 'descrição', 'coluna_rotulo' => 'descricao', 'colunas' => ['descricao', 'valor', 'status', 'data'], 'papel' => 'operador'],
            'cliente' => ['entidade' => 'Cliente', 'modelo' => Cliente::class, 'rotulo' => 'o cliente', 'servico' => $this->clienteService,
                'busca_por' => 'nome', 'coluna_rotulo' => 'nome', 'colunas' => ['nome', 'documento', 'email', 'telefone'], 'papel' => 'operador'],
            'fornecedor' => ['entidade' => 'Fornecedor', 'modelo' => Fornecedor::class, 'rotulo' => 'o fornecedor', 'servico' => $this->fornecedorService,
                'busca_por' => 'nome', 'coluna_rotulo' => 'nome', 'colunas' => ['nome', 'documento', 'email', 'telefone'], 'papel' => 'operador'],
            'funcionario' => ['entidade' => 'Funcionario', 'modelo' => Funcionario::class, 'rotulo' => 'o funcionário', 'servico' => $this->funcionarioService,
                'busca_por' => 'nome', 'coluna_rotulo' => 'nome', 'colunas' => ['nome', 'cargo', 'data_admissao', 'data_demissao'], 'papel' => 'admin'],
            'nota_fiscal' => ['entidade' => 'NotaFiscal', 'modelo' => NotaFiscal::class, 'rotulo' => 'a nota fiscal', 'servico' => $this->notaFiscalService,
                'busca_por' => 'número', 'coluna_rotulo' => 'numero', 'colunas' => ['numero', 'tipo', 'valor', 'data_emissao'], 'papel' => 'operador'],
            'categoria_lancamento' => ['entidade' => 'CategoriaLancamento', 'modelo' => CategoriaLancamento::class, 'rotulo' => 'a categoria', 'servico' => $this->categoriaLancamentoService,
                'busca_por' => 'nome', 'coluna_rotulo' => 'nome', 'colunas' => ['nome', 'tipo'], 'papel' => 'operador'],
        ];
    }

    /**
     * As tools da lixeira: consultar_lixeira e, por entidade, excluir_* (vai para a lixeira se
     * nada depender dele, RF08/RF09), restaurar_* (RF10) e excluir_definitivo_* (só arquivado,
     * RF12). Restaurar e excluir de vez pedem a senha de um admin no card ("reautenticar").
     * As regras de dependência e de "pai" ficam nos services; aqui, só o vínculo.
     *
     * @return array<int, array<string, mixed>>
     */
    private function definirLixeira(): array
    {
        $lixeiras = $this->lixeiras();

        $tools = [$this->definirTool(
            nome: 'consultar_lixeira',
            descricao: 'Lista o que está na lixeira (excluído, ainda dá para restaurar) e o que já foi arquivado, opcionalmente por tipo de registro e por situação. Ação de leitura — não exige confirmação.',
            tipo: 'leitura',
            risco: 'leitura',
            acao: 'consultar',
            entidade: 'Lixeira',
            // DECISÃO PENDENTE (Lucas): papel mínimo da lixeira. Padrão do plano aprovado: operador (quem pede para restaurar).
            papelMinimo: 'operador',
            propriedades: [
                'tipo' => ['type' => 'string', 'enum' => array_keys($lixeiras)],
                'situacao' => ['type' => 'string', 'enum' => ['lixeira', 'arquivado']],
            ],
            obrigatorios: [],
            executar: fn (array $a) => $this->itensDaLixeira($lixeiras, $a),
            mensagemErro: 'Não consegui consultar a lixeira agora. Tente novamente.',
            colunas: ['tipo', 'id', 'nome', 'situacao', 'excluido_em', 'restaurar_ate'],
        )];

        foreach ($lixeiras as $sufixo => $lixeira) {
            ['entidade' => $entidade, 'rotulo' => $rotulo, 'servico' => $servico, 'busca_por' => $buscaPor] = $lixeira;
            $nome = FormularioTool::semArtigo($rotulo);
            $o = str_starts_with($rotulo, 'a ') ? 'a' : 'o'; // concordância: "nota fiscal excluída", "cliente excluído"
            $propriedades = [
                'id' => ['type' => 'integer', 'description' => 'Número (id) do registro, se o usuário informou.'],
                'busca' => ['type' => 'string', 'description' => Str::ucfirst($buscaPor).' do registro, quando o número não foi informado.'],
            ];
            $localizarEm = fn (string $situacao) => fn (?int $id, ?string $busca, int $tenantId) => $servico->localizar($id, $busca, $tenantId, $situacao);

            foreach ([
                // [tool, risco, ação, papel, situação em que o registro está, onde, motivo (só leitura), operação, título, sucesso, botão, feito, descrição]
                ["excluir_{$sufixo}", 'exclusao', 'excluir', 'admin', 'ativo', '', 'motivoParaNaoExcluir',
                    fn (int $id, int $t) => $servico->excluir($id, $t),
                    "Excluir {$nome}", Str::ucfirst($nome)." movid{$o} para a lixeira", 'Excluir', "excluíd{$o}",
                    "Move {$rotulo} para a lixeira (exclusão segura: dá para restaurar dentro do prazo), achado pelo id ou por {$buscaPor}. Não exclui se houver registros vinculados. Ação de exclusão — exige confirmação humana antes de ser executada."],
                ["restaurar_{$sufixo}", 'escrita', 'restaurar', $lixeira['papel'], 'lixeira', ' na lixeira', 'motivoParaNaoRestaurar',
                    fn (int $id, int $t) => $servico->restaurarDaLixeira($id, $t, $this->autorizadoPor() !== null),
                    "Restaurar {$nome}", Str::ucfirst($nome)." restaurad{$o} da lixeira", 'Restaurar', "restaurad{$o}",
                    "Restaura {$rotulo} que está na lixeira, achado pelo id ou por {$buscaPor}. Exige a senha de um administrador, digitada no card de confirmação (nunca no chat). Ação de escrita — exige confirmação humana antes de ser executada."],
                ["excluir_definitivo_{$sufixo}", 'exclusao', 'excluir_definitivo', 'admin', 'arquivado', ' entre os arquivados', 'motivoParaNaoExcluirDeVez',
                    fn (int $id, int $t) => $servico->excluirDefinitivo($id, $t, $this->autorizadoPor() !== null),
                    "Excluir de vez: {$nome}", Str::ucfirst($nome)." excluíd{$o} de vez", 'Excluir de vez', "excluíd{$o} de vez",
                    "Exclui de vez, sem volta, {$rotulo} que já foi arquivado (passou do prazo da lixeira), achado pelo id ou por {$buscaPor}. Só administrador; exige a senha de um administrador, digitada no card de confirmação (nunca no chat). Ação de exclusão — exige confirmação humana antes de ser executada."],
            ] as [$tool, $risco, $acao, $papel, $situacao, $onde, $motivo, $operacao, $titulo, $sucesso, $botao, $feito, $descricao]) {
                $localizar = $localizarEm($situacao);
                $naoEncontrei = 'Não encontrei '.$rotulo;

                $tools[] = $this->definirTool(
                    nome: $tool,
                    descricao: $descricao,
                    tipo: 'escrita',
                    risco: $risco,
                    acao: $acao,
                    entidade: $entidade,
                    // DECISÃO PENDENTE (Lucas): papéis da lixeira. Padrão fechado do plano aprovado: excluir e
                    // excluir de vez só admin; restaurar com o papel de criar/alterar a entidade + senha de admin.
                    papelMinimo: $papel,
                    propriedades: $propriedades,
                    obrigatorios: [],
                    executar: fn (array $a) => $this->executarNaLixeira($a, $naoEncontrei, $onde, $operacao),
                    mensagemErro: 'Não consegui concluir a operação na lixeira. Tente novamente.',
                    titulo: $titulo,
                    sucesso: $sucesso,
                    localizar: $localizar,
                    rotulo: $rotulo,
                    buscaPor: $buscaPor,
                    colunaRotulo: $lixeira['coluna_rotulo'],
                    // RF08 e "pai" na lixeira (decisão: recusar dizendo qual é o pai): a recusa aparece já no Revisar.
                    conferir: function (array $a) use ($localizar, $servico, $motivo, $naoEncontrei, $onde): ?array {
                        $id = (int) ($a['id'] ?? 0);
                        $registro = $id >= 1 ? $localizar($id, null, (int) $a['tenant_id'])->first() : null;
                        $frase = $registro === null ? "{$naoEncontrei} #{$id}{$onde}." : $servico->{$motivo}($registro);

                        return $frase === null ? null : ['campo' => 'id', 'motivo' => $frase];
                    },
                    resumo: $lixeira['colunas'],
                    reautenticar: $acao !== 'excluir',
                    feito: $feito,
                    onde: $onde,
                    rotuloAcao: $botao,
                );
            }
        }

        return $tools;
    }

    /**
     * Executor das tools da lixeira: chama o service pelo id da proposta e traduz o "não deu"
     * em recusa com a frase dele (dependência, pai na lixeira, não arquivado, sem senha).
     * Registro que sumiu, mudou de situação ou é de outro tenant: "Não encontrei…".
     *
     * @param  Closure(int, int): array  $operacao
     */
    private function executarNaLixeira(array $argumentos, string $naoEncontrei, string $onde, Closure $operacao): Model
    {
        $id = (int) ($argumentos['id'] ?? 0);

        try {
            $resultado = $operacao($id, (int) $argumentos['tenant_id']);
        } catch (ModelNotFoundException) {
            throw new ToolRecusadaException("{$naoEncontrei} #{$id}{$onde}.");
        }

        if (! isset($resultado['registro'])) {
            throw new ToolRecusadaException($resultado['motivo'] ?? 'Não foi possível concluir a operação.');
        }

        return $resultado['registro'];
    }

    /**
     * consultar_lixeira: o que está na lixeira e o que foi arquivado, do tenant, dos mais
     * recentes para os mais antigos. Cada linha leva a ação que o papel atual pode abrir
     * sem IA: "Restaurar" (na lixeira) ou "Excluir de vez" (arquivado).
     *
     * @return array<int, array<string, mixed>>
     */
    private function itensDaLixeira(array $lixeiras, array $filtros): array
    {
        $tipo = $filtros['tipo'] ?? null;
        $situacao = $filtros['situacao'] ?? null;

        // Filtro fora da lista (a IA não segue o enum à risca): nada, como numa consulta com filtro que não casa.
        if (($tipo !== null && ! array_key_exists($tipo, $lixeiras)) || ! in_array($situacao, [null, 'lixeira', 'arquivado'], true)) {
            return [];
        }

        $dias = (int) config('sentinel.retencao_lixeira_dias');
        $itens = [];

        foreach ($tipo === null ? $lixeiras : [$tipo => $lixeiras[$tipo]] as $sufixo => $lixeira) {
            $registros = ($situacao === null ? $lixeira['modelo']::onlyTrashed() : $lixeira['modelo']::query()->naSituacao($situacao))
                ->where('tenant_id', (int) $filtros['tenant_id'])
                ->orderByDesc('deleted_at')
                ->limit(self::LIMITE_LIXEIRA)
                ->get();

            foreach ($registros as $registro) {
                $arquivado = $registro->arquivado_em !== null;
                $acao = $arquivado ? "excluir_definitivo_{$sufixo}" : "restaurar_{$sufixo}";

                $itens[] = [
                    'tipo' => Str::ucfirst(FormularioTool::semArtigo($lixeira['rotulo'])),
                    'id' => (int) $registro->getKey(),
                    'nome' => $registro->getAttribute($lixeira['coluna_rotulo']),
                    'situacao' => $arquivado ? 'Arquivado' : 'Na lixeira',
                    'excluido_em' => $registro->deleted_at?->format('Y-m-d'),
                    'restaurar_ate' => $arquivado ? null : $registro->deleted_at?->copy()->addDays($dias)->format('Y-m-d'),
                    ...($this->podeEditar($acao) ? ['acao' => ['tool' => $acao, 'rotulo' => $arquivado ? 'Excluir de vez' : 'Restaurar']] : []),
                    '_ordem' => $registro->deleted_at?->getTimestamp() ?? 0,
                ];
            }
        }

        usort($itens, fn (array $a, array $b) => $b['_ordem'] <=> $a['_ordem']);

        return array_map(function (array $item) {
            unset($item['_ordem']);

            return $item;
        }, array_slice($itens, 0, self::LIMITE_LIXEIRA));
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
            throw new ToolRecusadaException($validador->errors()->first(),
                array_map(fn (array $frases) => $frases[0], $validador->errors()->toArray()));
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
        // As regras do formulário (RF04) valem aqui também, com as frases que esta tool já tinha.
        $dados = $this->validar($argumentos, self::REGRAS_STATUS_LANCAMENTO, self::MENSAGENS_STATUS_LANCAMENTO);

        $resultado = $this->lancamentoService->atualizarStatus(
            (int) $dados['id'],
            $dados['novo_status'],
            $argumentos['tenant_id'],
        );

        if (! $resultado['atualizado']) {
            throw new ToolRecusadaException($resultado['motivo']);
        }

        return $resultado['lancamento'];
    }

    /**
     * Criação padrão de uma entidade: o vínculo tool ↔ entidade ↔ Form Request ↔ service
     * fica todo nesta entrada. As MESMAS regras do Form Request validam a execução (via
     * validar(), que escopa exists: pelo tenant) e desenham o formulário do card (RF04).
     * Trocar de domínio é trocar estas entradas, não o motor.
     *
     * @param  class-string<\Illuminate\Foundation\Http\FormRequest>  $formRequest
     * @param  Closure(array): Model  $gravar  método do service que grava os dados já validados
     * @param  array<string, string>  $mensagens  frases de validação específicas desta tool
     */
    private function definirEscrita(
        string $nome,
        string $descricao,
        string $entidade,
        string $papelMinimo,
        array $propriedades,
        array $obrigatorios,
        string $formRequest,
        Closure $gravar,
        string $mensagemErro,
        string $titulo,
        string $sucesso,
        array $mensagens = [],
    ): array {
        $regras = (new $formRequest)->rules();

        return $this->definirTool(
            nome: $nome,
            descricao: $descricao,
            tipo: 'escrita',
            risco: 'escrita',
            acao: 'criar',
            entidade: $entidade,
            papelMinimo: $papelMinimo,
            propriedades: $propriedades,
            obrigatorios: $obrigatorios,
            executar: fn (array $a) => $gravar($this->validar($a, $regras, $mensagens)),
            mensagemErro: $mensagemErro,
            regras: $regras,
            titulo: $titulo,
            sucesso: $sucesso,
            mensagens: $mensagens,
        );
    }

    /**
     * Atualização padrão de uma entidade (Bloco 3): o mesmo vínculo da criação
     * (tool ↔ entidade ↔ Form Request ↔ service) mais como achar o registro. A IA
     * manda "id" ou "busca" e só os campos que mudam; o servidor localiza o registro
     * no tenant e o formulário vem com os valores atuais. O id fica na proposta
     * guardada na sessão, fora do formulário: o humano não troca de registro.
     *
     * @param  Closure(?int, ?string, int): Collection  $localizar
     * @param  Closure(int, array, int): Model  $atualizar
     */
    private function definirAtualizacao(
        string $nome,
        string $descricao,
        string $entidade,
        string $rotulo,
        string $papelMinimo,
        array $propriedades,
        string $formRequest,
        Closure $localizar,
        Closure $atualizar,
        string $buscaPor,
        string $colunaRotulo,
        string $mensagemErro,
        string $titulo,
        string $sucesso,
        array $mensagens = [],
    ): array {
        $regras = (new $formRequest)->rules();

        return $this->definirTool(
            nome: $nome,
            descricao: $descricao,
            tipo: 'escrita',
            risco: 'escrita',
            acao: 'atualizar',
            entidade: $entidade,
            papelMinimo: $papelMinimo,
            propriedades: [
                'id' => ['type' => 'integer', 'description' => 'Número (id) do registro a alterar, se o usuário informou.'],
                'busca' => ['type' => 'string', 'description' => Str::ucfirst($buscaPor).' do registro a alterar, quando o número não foi informado.'],
                ...$propriedades,
            ],
            obrigatorios: [],
            executar: function (array $a) use ($regras, $mensagens, $atualizar, $localizar, $rotulo) {
                $dados = $this->validar($a, $regras, $mensagens);
                $id = (int) ($a['id'] ?? 0);

                // Alterado por outra pessoa depois que o formulário abriu: gravar desfaria a mudança dela, calado.
                $atual = $id >= 1 && is_string($a['versao'] ?? null) ? $localizar($id, null, (int) $a['tenant_id'])->first() : null;
                if ($atual !== null && (string) $atual->getRawOriginal('updated_at') !== $a['versao']) {
                    throw new ToolRecusadaException('Este registro foi alterado por outra pessoa depois que o formulário foi aberto. Nada foi gravado. Abra de novo para ver os dados atuais.');
                }

                try {
                    return $atualizar($id, $dados, (int) $a['tenant_id']);
                } catch (ModelNotFoundException) {
                    // Apagado, foi para a lixeira ou é de outro tenant: para o usuário, não existe.
                    throw new ToolRecusadaException('Não encontrei '.$rotulo.' #'.$id.'.');
                }
            },
            mensagemErro: $mensagemErro,
            regras: $regras,
            titulo: $titulo,
            sucesso: $sucesso,
            mensagens: $mensagens,
            localizar: $localizar,
            rotulo: $rotulo,
            buscaPor: $buscaPor,
            colunaRotulo: $colunaRotulo,
            feito: str_starts_with($rotulo, 'a ') ? 'alterada' : 'alterado',
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $propriedades
     * @param  string[]  $obrigatorios
     * @param  array<string, mixed>  $regras  escrita: regras que desenham e filtram o formulário do card (RF04)
     * @param  string  $titulo  escrita: ação em português no topo do card ("Cadastrar cliente")
     * @param  string  $sucesso  escrita: começo da frase depois de gravar ("Cliente cadastrado")
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
        array $regras = [],
        string $titulo = '',
        string $sucesso = '',
        array $mensagens = [],
        ?string $editavelPor = null,
        ?Closure $localizar = null,
        string $rotulo = '',
        string $buscaPor = '',
        string $colunaRotulo = '',
        ?Closure $conferir = null,
        array $resumo = [],
        bool $reautenticar = false,
        string $feito = 'alterado',
        string $onde = '',
        string $rotuloAcao = '',
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
            'regras' => $regras,
            'titulo' => $titulo,
            'sucesso' => $sucesso,
            'mensagens' => $mensagens, // escrita: frases de validação desta tool (executor e /agente/revisar)
            'editavel_por' => $editavelPor, // leitura: tool de atualizar que a linha da tabela abre (RF02)
            'localizar' => $localizar, // atualizar: acha o registro por id ou busca, no tenant (Bloco 3)
            'rotulo' => $rotulo, // atualizar: "o cliente" (frases de "Não encontrei…")
            'busca_por' => $buscaPor, // atualizar: "nome" ou "número" (a busca por texto)
            'coluna_rotulo' => $colunaRotulo, // atualizar: coluna que nomeia o registro na lista de escolha
            'conferir' => $conferir, // escrita: regra de negócio conferida já no /agente/revisar (antes do segurar)
            'resumo' => $resumo, // lixeira: colunas do registro que o card mostra, só leitura (Bloco 4)
            'reautenticar' => $reautenticar, // lixeira: a confirmação pede a senha de um admin (RF10)
            'feito' => $feito, // "Informe qual cliente deve ser {feito}…"
            'onde' => $onde, // lixeira: "Não encontrei o cliente… {onde}" (" na lixeira")
            'rotulo_acao' => $rotuloAcao, // lixeira: botão da linha na lista de escolha ("Restaurar"); vazio = "Editar"
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

    /**
     * Desfecho de uma proposta de escrita que não executou (cancelada, expirada
     * ou recusada pelo prazo): fica em audit_logs com o que a IA propôs e nada
     * confirmado. Acao e entidade vêm do catálogo, como nas demais auditorias.
     *
     * Nada foi gravado de qualquer jeito, então uma falha ao auditar não vira 500
     * para o usuário: fica no log só com metadados — nunca argumentos nem a
     * mensagem da exceção, que numa QueryException traz o SQL com os valores (AI-003).
     *
     * @param  string  $resultado  'cancelado' | 'expirado' | 'recusado' | 'negado' (senha de admin recusada, com $permitido false)
     */
    public function auditarDesfecho(string $tool, array $argumentosPropostos, string $resultado, string $mensagem, string $origem = 'agente', bool $permitido = true): void
    {
        $definicao = $this->catalogo()[$tool] ?? null;

        try {
            $this->detalhesAuditoria->com(['parametros_propostos' => $argumentosPropostos, 'origem' => $origem], fn () => $this->auditar(
                $tool,
                $definicao['acao'] ?? 'desconhecida',
                $definicao['entidade'] ?? null,
                null,
                [],
                $resultado,
                $mensagem,
                $permitido,
            ));
        } catch (\Illuminate\Auth\AuthenticationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('AiAgentService: falha ao auditar desfecho', [
                'correlation_id' => (string) Str::uuid(),
                'categoria' => 'tool_error',
                'tool' => $tool,
                'resultado' => $resultado,
                'excecao' => $e::class,
                'local' => basename($e->getFile()).':'.$e->getLine(),
            ]);
        }
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
