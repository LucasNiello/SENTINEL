<?php

namespace App\Services;

use App\Exceptions\ToolRecusadaException;
use App\Http\Requests\CategoriaLancamentoRequest;
use App\Http\Requests\ClienteRequest;
use App\Http\Requests\FornecedorRequest;
use App\Http\Requests\FuncionarioRequest;
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
        'max' => ':Attribute aceita no máximo :max caracteres.',
        'email' => 'O e-mail informado não é válido.',
        'numeric' => ':Attribute deve ser um número.',
        'min' => ':Attribute deve ser no mínimo :min.',
        'date' => ':Attribute deve ser uma data válida (AAAA-MM-DD).',
        'exists' => 'Não encontrei :attribute informado.',
        'in' => ':Attribute informado não é válido.',
        'required_if' => 'Informe :attribute.',
        'prohibited_if' => ':Attribute não deve ser informado neste caso.',
        'cliente_id.required_if' => 'Informe o cliente da nota fiscal de saída.',
        'fornecedor_id.required_if' => 'Informe o fornecedor da nota fiscal de entrada.',
        'cliente_id.prohibited_if' => 'Nota fiscal de entrada não leva cliente — informe o fornecedor.',
        'fornecedor_id.prohibited_if' => 'Nota fiscal de saída não leva fornecedor — informe o cliente.',
        'data_demissao.after_or_equal' => 'A data de demissão não pode ser anterior à data de admissão.',
    ];

    /** Como cada campo aparece nas frases acima. */
    private const ROTULOS_CAMPOS = [
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
                            'content' => 'Você é o assistente do Sentinel, um sistema de contabilidade para micro e pequenas empresas. A data de hoje é '.now()->toDateString().' — use-a para resolver termos relativos como "hoje" e "ontem". Use as tools disponíveis para consultar e cadastrar os dados do sistema (lançamentos, clientes, fornecedores etc.) e para atualizar o status de um lançamento (pendente/conciliado). Nunca invente dados — se faltar informação obrigatória para uma tool (por exemplo, o nome de um cliente ou o id do lançamento a atualizar), pergunte ao usuário em texto antes de chamar a tool. Quando já tiver todos os dados necessários para uma tool de escrita (as que criam ou atualizam dados), chame a tool diretamente — não peça confirmação você mesmo em texto: o sistema já exibe uma tela de confirmação humana (hold-to-confirm) antes de executar qualquer escrita.',
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
        $argumentos = json_decode($chamada['function']['arguments'] ?? '{}', true);
        $argumentos = is_array($argumentos) ? $argumentos : [];

        // O tenant nunca vem do modelo: o servidor injeta o tenant atual na execução.
        unset($argumentos['tenant_id']);

        $definicao = $this->catalogo()[$tool] ?? null;

        if ($definicao !== null && $definicao['tipo'] === 'escrita') {
            if (! $this->papelAtualPermite($definicao['papel_minimo'])) {
                return $this->negar($tool, $definicao, $argumentos);
            }

            return [
                'tipo' => 'confirmacao_pendente',
                'tool' => $tool,
                'argumentos' => $argumentos,
            ];
        }

        try {
            return $this->executarTool($tool, $argumentos);
        } catch (InvalidArgumentException $e) {
            return [
                'tipo' => 'erro',
                'mensagem' => $e->getMessage(),
            ];
        }
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
                'argumentos' => AuditoriaService::mascararCamposSensiveis($argumentos),
            ]);

            $this->auditar($tool, $definicao['acao'], $definicao['entidade'], null, $argumentos, 'erro', $definicao['mensagem_erro'], true);

            return [
                'tipo' => 'erro',
                'mensagem' => $definicao['mensagem_erro'],
            ];
        }

        // Leitura devolve só as colunas declaradas na tool (nada de tenant_id, timestamps, etc.).
        if ($resultado instanceof Collection && $definicao['colunas']) {
            $resultado = $resultado->map(fn (Model $m) => Arr::only($m->toArray(), $definicao['colunas']))->values()->all();
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
                acao: 'criar',
                entidade: 'Lancamento',
                papelMinimo: 'operador',
                propriedades: [
                    'descricao' => ['type' => 'string'],
                    'valor' => ['type' => 'number'],
                    'data' => ['type' => 'string', 'format' => 'date'],
                ],
                obrigatorios: ['descricao', 'valor', 'data'],
                executar: fn (array $a) => $this->lancamentoService->criar($a),
                mensagemErro: 'Não consegui criar o lançamento — verifique se descrição, valor e data foram informados.',
            ),
            $this->definirTool(
                nome: 'atualizar_status_lancamento',
                descricao: 'Altera o status de um lançamento existente para "pendente" ou "conciliado". Ação de escrita — exige confirmação humana antes de ser executada.',
                tipo: 'escrita',
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
        string $acao,
        string $entidade,
        string $papelMinimo,
        array $propriedades,
        array $obrigatorios,
        Closure $executar,
        string $mensagemErro,
        array $colunas = [],
    ): array {
        return [
            'nome' => $nome,
            'tipo' => $tipo,
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
