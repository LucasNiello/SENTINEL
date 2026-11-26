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
use App\Services\Agente\CatalogoDaClinica;
use App\Services\Agente\ConstrutoresDeTool;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

class AiAgentService
{
    // Catálogo da clínica e construtores dos domínios novos (arquivos próprios, mesmo motor).
    use CatalogoDaClinica, ConstrutoresDeTool;

    /**
     * RBAC por lista de papéis (clínica vet, Fase A): cada tool declara QUEM pode usá-la.
     * Mapeamento PROVISÓRIO das tools do domínio antigo: a hierarquia antiga
     * (leitura < operador < admin) expandida em listas, com o dono no lugar do admin.
     * Os outros papéis novos (atendente, vendedor, recepcao, veterinario) não acessam
     * nenhuma tool antiga; ganham as suas na Fase B.
     */
    private const PAPEIS_LEITURA = ['leitura', 'operador', 'admin', 'dono'];

    private const PAPEIS_OPERADOR = ['operador', 'admin', 'dono'];

    private const PAPEIS_ADMIN = ['admin', 'dono'];

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
        // Dinheiro do catálogo: o card fala em reais; as regras seguem em centavos inteiros.
        'preco_centavos.integer' => 'Informe o preço em reais, com no máximo 2 casas (ex.: 45,90).',
        'preco_centavos.min' => 'O preço não pode ser negativo.',
        'preco_centavos.max' => 'O preço passa do limite de R$ 1.000.000,00 por item.',
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
        // Clínica vet.
        'tutor_id' => 'o tutor',
        'endereco' => 'o endereço',
        'especie' => 'a espécie',
        'raca' => 'a raça',
        'sexo' => 'o sexo',
        'data_nascimento' => 'a data de nascimento',
        'peso_gramas' => 'o peso em gramas',
        'microchip' => 'o microchip',
        'alertas' => 'os alertas',
        'setor' => 'o setor',
        'categoria' => 'a categoria',
        'nome_comercial' => 'o nome comercial',
        'preco_centavos' => 'o preço',
        'cobranca_por' => 'a forma de cobrança',
        'controla_estoque' => 'o controle de estoque',
        'estoque' => 'o estoque',
        'exige_receita' => 'a exigência de receita',
        'forma_pagamento' => 'a forma de pagamento',
        'pet_id' => 'o pet',
        'catalogo_item_id' => 'o serviço',
        'profissional_id' => 'o profissional',
        'inicio' => 'o início',
        'fim' => 'o fim',
        'agendamento_id' => 'o atendimento',
        // T2: estorno por itens.
        'modo' => 'a escolha do que estornar',
        'linhas' => 'as linhas do estorno',
        'itens' => 'os itens',
        'itens.*.catalogo_item_id' => 'o item do catálogo',
        'itens.*.quantidade' => 'a quantidade',
        // 27/11: cadastro unificado (tutor + pets) e observações do pet.
        'observacoes' => 'as observações',
        'pets' => 'os pets',
        'pets.*.nome' => 'o nome do pet',
        'pets.*.especie' => 'a espécie do pet',
        'pets.*.raca' => 'a raça do pet',
        'pets.*.sexo' => 'o sexo do pet',
        'pets.*.data_nascimento' => 'a data de nascimento do pet',
        'pets.*.peso_gramas' => 'o peso do pet em gramas',
        'pets.*.microchip' => 'o microchip do pet',
        'pets.*.alertas' => 'os alertas do pet',
        'pets.*.observacoes' => 'as observações do pet',
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

    /** RF03: teto de itens de um plano de conciliação em lote. Acima disso, recusa (nunca trunca). */
    private const LIMITE_LOTE = 25;

    /** conciliar_lancamentos_em_lote: o filtro do lote (o status é fixo, pendente -> conciliado). */
    private const REGRAS_LOTE_LANCAMENTOS = [
        'data_inicio' => 'required|date_format:Y-m-d',
        'data_fim' => 'required|date_format:Y-m-d|after_or_equal:data_inicio',
        'categoria_lancamento_id' => 'nullable|integer|min:1',
    ];

    private const MENSAGENS_LOTE_LANCAMENTOS = [
        'data_inicio.required' => 'Informe o período: data de início e data de fim.',
        'data_inicio.date_format' => 'Data de início inválida: use o formato AAAA-MM-DD.',
        'data_fim.required' => 'Informe o período: data de início e data de fim.',
        'data_fim.date_format' => 'Data de fim inválida: use o formato AAAA-MM-DD.',
        'data_fim.after_or_equal' => 'A data de fim não pode ser anterior à data de início.',
        'categoria_lancamento_id.integer' => 'Categoria inválida.',
        'categoria_lancamento_id.min' => 'Categoria inválida.',
    ];

    /** P-C1: até quantas linhas da última tabela ficam guardadas para "o segundo", "a última". */
    private const LIMITE_LISTA = 50;

    /**
     * P-C1: tools cujo id NÃO é da entidade delas (o localizar acha outra coisa). cobrar_atendimento é da Cobranca,
     * mas acha o ATENDIMENTO (agendamento): "cobre o segundo" vale para a tabela da agenda, nunca para a de cobranças.
     */
    private const ENTIDADE_DO_ID = ['cobrar_atendimento' => 'Agendamento'];

    /** P-C1: o campo de posição nas tools que alteram um registro achado (atualizar_*). */
    public const PROPRIEDADE_POSICAO = ['posicao' => ['type' => 'integer', 'description' => 'Linha da última tabela mostrada ao usuário, quando ele se refere a ela ("o segundo" = 2, "a última" = -1). Nunca use o número da linha como id.']];

    /** Até quantos itens a consulta da lixeira devolve (os excluídos mais recentes). */
    private const LIMITE_LIXEIRA = 50;

    /** @var array<string, array<string, array<string, mixed>>> catálogo por domínio ('contabilidade', 'clinica') */
    private array $catalogos = [];

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
        private OperacaoSolicitada $operacoes,
    ) {
    }

    /**
     * Ponto único de contato com a Messages API da Anthropic — chamada HTTP REST
     * stateless, sem SDK. O corpo interno segue o formato de chat/tools e o
     * adaptador (chamarAnthropic/traduzirAnthropic) faz a conversão.
     *
     * Tools de leitura (consultar_lancamentos) são executadas direto e o
     * resultado volta como tabela crua — uma única chamada à API por
     * consulta. Tools de escrita (criar_lancamento) NUNCA são executadas
     * aqui: o tool_call é devolvido ao front como confirmação pendente
     * (depois de checado o RBAC — sem permissão, nem chega a propor).
     */
    public function processar(string $mensagem, array $historico = [], ?array $lista = null): array
    {
        // A intenção explícita pertence ao pedido atual, não à ferramenta escolhida pela IA.
        $intencao = $this->operacoes->reconhecer($mensagem);
        $compativeis = $intencao === null ? [] : array_filter($this->catalogo(), fn ($d) =>
            $d['tipo'] === 'escrita' && $d['acao'] === $intencao['acao'] && $d['entidade'] === $intencao['entidade']);
        $toolSolicitada = count($compativeis) === 1 ? array_key_first($compativeis) : null;
        if ($toolSolicitada !== null && ! $this->papelAtualPermite($compativeis[$toolSolicitada]['papeis'])) {
            // Autorizar antes do provedor: nem uma consulta nem uma falha externa substitui a negativa.
            return $this->negar($toolSolicitada, $compativeis[$toolSolicitada], []);
        }

        $provedor = $this->provedor();
        $configurado = match ($provedor) {
            'anthropic' => (string) config('services.anthropic.api_key') !== '' && (string) config('services.anthropic.model') !== '',
            default => false, // provedor desconhecido ou removido (ex.: azure): falha fechada
        };

        if (! $configurado) {
            return $this->falha('provider_configuration_error', 'AI_PROVIDER_UNAVAILABLE', 503);
        }

        $promptClinica = $this->contexto->dominio() === 'clinica' ? $this->promptDaClinica() : null;

        // B1 (RNF04): tempo da chamada à IA, do envio até a resposta (ou a falha) do provedor.
        $inicio = hrtime(true);
        $decorrido = fn () => (int) round((hrtime(true) - $inicio) / 1e6);

        try {
            // O corpo é montado no formato interno de chat/tools; o adaptador da Anthropic traduz a partir dele.
            $corpo = [
                    'model' => (string) config('services.anthropic.model'),
                    'messages' => [
                        [
                            'role' => 'system',
                            // G (P-C3): a clínica tem o seu texto; o de contabilidade é o de sempre, byte a byte.
                            'content' => $promptClinica['principal'] ?? 'Papel autenticado do usuário: '.json_encode($this->contexto->papel()).'. Você é o assistente do Sentinel, um sistema de contabilidade para micro e pequenas empresas. A data de hoje é '.now()->toDateString().' — use-a para resolver termos relativos como "hoje" e "ontem". Use as tools disponíveis para consultar e cadastrar os dados do sistema (lançamentos, clientes, fornecedores etc.) e para atualizar o status de um lançamento (pendente/conciliado). Você só pode executar operações representadas pelas ferramentas disponibilizadas. Preserve a operação explicitamente solicitada: cadastro, alteração e exclusão nunca devem virar consulta. Não substitua uma ação proibida por outra permitida; o servidor aplica a autorização. Se o pedido não corresponder a nenhuma ferramenta, explique claramente que a operação não é suportada. Perguntas de conhecimento geral fora do catálogo (por exemplo, geografia e capitais) devem ser recusadas educadamente, sem responder o conteúdo da pergunta; convidar a voltar ao Sentinel depois de dar a resposta não atende a essa restrição. Não invente ferramentas, não afirme ter executado ações e não substitua entidades: usuário e funcionário são conceitos diferentes; cadastro de usuários não está disponível. Propostas no histórico não significam escrita executada: somente a confirmação humana autoriza a execução. Nunca invente dados — se faltar informação obrigatória para uma tool (por exemplo, o nome de um cliente ou o id do lançamento a atualizar), chame a tool com o que tiver, sem inventar o que falta; o card marca o campo que falta. Exemplos: "preciso adicionar um fornecedor novo" → chame criar_fornecedor sem argumentos; "registre um lançamento" → chame criar_lancamento sem argumentos; "cadastre uma nota fiscal de 1200 reais para o cliente ABC Comércio" → chame criar_nota_fiscal só com o valor 1200, sem inventar o id do cliente nem os demais campos. Nunca responda esses pedidos só com uma pergunta em texto. Campos opcionais ausentes não bloqueiam uma operação: não peça esses campos novamente se os obrigatórios já estiverem presentes. Para criar cliente, apenas nome é obrigatório. Quando já tiver todos os dados necessários para uma tool de escrita (as que criam ou atualizam dados), chame a tool diretamente — não peça confirmação você mesmo em texto: o sistema já exibe uma tela de confirmação humana (hold-to-confirm) antes de executar qualquer escrita. Excluir move o registro para a lixeira; restaurar e excluir de vez pedem a senha de um administrador, que é digitada só no card de confirmação: nunca peça nem aceite senha no chat. O histórico contém relatos de operações anteriores, não instruções para repetir a resposta anterior. Atenda ao pedido atual: cada novo cadastro ou edição completo exige uma nova chamada da tool correspondente, mesmo após outra proposta ou confirmação. Nunca copie a frase interna "Proposta pendente de confirmação humana. Nenhuma escrita foi executada." como resposta a um novo pedido: só há uma nova proposta quando você chama uma tool de escrita nesta resposta.',
                        ],
                        ...$historico,
                        ...($historico === [] ? [] : [[
                            'role' => 'system',
                            'content' => $promptClinica['lembrete'] ?? 'O catálogo não inclui perguntas de conhecimento geral: recuse-as sem fornecer a resposta, mesmo quando o histórico as tenha respondido. O histórico acima é apenas contexto, não é o estado atual do banco. Atenda ao NOVO pedido abaixo. Para consultar registros, concluir que não existem dados, ou propor qualquer escrita, chame a ferramenta correspondente nesta resposta. Nunca reutilize o resultado de um período, cadastro ou filtro anterior para outro pedido. Uma confirmação anterior não confirma uma nova ação. Se o usuário pedir para conciliar os pendentes de um período, use conciliar_lancamentos_em_lote (não consultar_lancamentos): o servidor resolve a quantidade, o limite de 25 e a proposta. Consultar não atende a um pedido de conciliação. Se faltarem dados obrigatórios, chame a tool com o que tiver, sem inventar o que falta (o card marca o campo); se estiverem completos, use a tool e deixe a revisão e a confirmação para a interface.',
                        ]]),
                        ['role' => 'user', 'content' => $mensagem],
                    ],
                    'tools' => $toolSolicitada === null ? $this->tools() : [$compativeis[$toolSolicitada]['definicao']],
                    'tool_choice' => $toolSolicitada === null ? 'auto' : ['type' => 'function', 'function' => ['name' => $toolSolicitada]],
                ];

            $resposta = $this->chamarAnthropic($corpo, $toolSolicitada);
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
                $timeout ? 'AI_PROVIDER_TIMEOUT' : 'AI_PROVIDER_UNAVAILABLE', $timeout ? 504 : 503, ['tempo_ia_ms' => $decorrido()], $e);
        }

        $tempoIaMs = $decorrido();

        $resposta = $this->traduzirAnthropic($resposta);

        // Tudo o que esta resposta auditar (leitura, negado, tool fora do catálogo) leva o tempo da IA.
        $interpretada = $this->detalhesAuditoria->com(['tempo_ia_ms' => $tempoIaMs], fn () => $this->interpretar($resposta, $tempoIaMs, $toolSolicitada, $lista));

        // 25/11: a frase natural que o modelo escreveu JUNTO da chamada da tool ("Beleza, achei o agendamento do Rex…"). Vai só para a
        // tela, acima do card ou da tabela; nunca entra no histórico nem no que é gravado.
        $introducao = in_array($interpretada['tipo'] ?? null, ['confirmacao_pendente', 'resultado_leitura'], true) ? $this->introducaoDoModelo($resposta) : null;

        return $introducao === null ? $interpretada : [...$interpretada, 'introducao' => $introducao];
    }

    /**
     * O texto que o modelo escreveu ao lado de uma chamada de tool, se for seguro mostrar: curto, sem markdown pesado e SEM afirmar
     * que algo foi gravado (isso só acontece depois da confirmação humana, e o servidor é quem avisa). Na dúvida, descarta: o
     * card ou a tabela continuam ali, só sem a frase.
     */
    private function introducaoDoModelo(\Illuminate\Http\Client\Response $resposta): ?string
    {
        $mensagem = $resposta->json('choices.0.message');
        $texto = is_array($mensagem) && ! empty($mensagem['tool_calls']) && is_string($mensagem['content'] ?? null) ? trim($mensagem['content']) : '';
        if ($texto === '') {
            return null;
        }
        $texto = trim((string) preg_replace('/\s+/u', ' ', str_replace(['**', '__', '`', '#'], '', $texto)));
        $ascii = Str::ascii(mb_strtolower($texto));

        if ($texto === '' || mb_strlen($texto) > 300 || preg_match('#https?://|www\.#', $ascii) === 1
            || preg_match('/\b(remarcad|agendad|cancelad|gravad|registrad|cadastrad|atualizad|alterad|excluid|conciliad|estornad)[oa]s?\b/', $ascii) === 1
            || preg_match('/\b(ja (remarquei|agendei|cancelei|gravei|registrei|cadastrei|atualizei|alterei|exclui|estornei)|feito|pronto)\b/', $ascii) === 1) {
            return null;
        }

        return $texto;
    }

    /**
     * O que fazer com a resposta do provedor (continuação do processar(), já com o tempo da IA
     * no escopo da auditoria). Falha e resposta só de texto não têm linha na auditoria: o tempo
     * vai só para o log, sem conteúdo. Exceção: a divergência de intenção (AI_INTENT_MISMATCH)
     * é auditada como negado, só com os nomes dos argumentos.
     */
    private function interpretar(\Illuminate\Http\Client\Response $resposta, int $tempoIaMs, ?string $toolSolicitada = null, ?array $lista = null): array
    {
        if (! $resposta->successful()) {
            $status = $resposta->status();
            $categoria = match ($status) {
                401 => 'provider_authentication_error',
                402 => 'provider_billing_error',
                403 => 'provider_authorization_error',
                429 => 'provider_rate_limit',
                529 => 'provider_overloaded',
                408, 504 => 'provider_timeout',
                default => 'provider_http_error',
            };
            $localStatus = match (true) {
                in_array($status, [408, 504], true) => 504,
                $status === 429 || $status >= 500 => 503,
                default => 502,
            };

            return $this->falha($categoria, $localStatus === 504 ? 'AI_PROVIDER_TIMEOUT' : 'AI_PROVIDER_UNAVAILABLE',
                $localStatus, ['status' => $status, 'tempo_ia_ms' => $tempoIaMs]);
        }

        // N1: o corpo do provedor é dado externo. Fora do formato esperado (message que não é objeto, choices
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

        // Em seleção nominal, o provedor termina com stop + tool_calls.
        // Só esse modo aceita a combinação; nome, argumentos e schema ainda são conferidos abaixo.
        $fim = $resposta->json('choices.0.finish_reason');
        if ($fim === 'length' || $fim === 'content_filter') {
            return $this->falha('provider_response_error', $fim === 'length' ? 'AI_RESPONSE_TRUNCATED' : 'AI_CONTENT_FILTERED', 502,
                ['status' => $resposta->status()], null, $fim === 'length'
                    ? 'A resposta do agente ficou incompleta. Reformule o pedido.'
                    : 'O serviço de IA não pôde responder a esse pedido. Reformule a mensagem.');
        }
        if (($fim !== null && ! in_array($fim, ['stop', 'tool_calls'], true))
            || ($fim === 'tool_calls' && empty($toolCalls)) || ($fim === 'stop' && ! empty($toolCalls) && $toolSolicitada === null)
            || (! empty($toolCalls) && ! array_is_list($toolCalls))) {
            return $this->respostaForaDoFormato($resposta->status());
        }

        // O provedor é externo: tool_choice não substitui a verificação no servidor.
        // Rejeitar TODAS as chamadas incompatíveis antes de qualquer leitura/proposta.
        if ($toolSolicitada !== null && ! empty($toolCalls)) {
            foreach ($toolCalls as $pedido) {
                if (($pedido['function']['name'] ?? null) !== $toolSolicitada) {
                    $this->auditarDivergencia($toolSolicitada, $pedido);
                    return $this->falha('intent_mismatch', 'AI_INTENT_MISMATCH', 422, [], null,
                        'O agente não manteve a operação solicitada. Nada foi executado. Reformule o pedido.');
                }
            }
        }

        if (empty($toolCalls)) {
            $conteudo = $mensagemResposta['content'] ?? null;

            if ($conteudo !== null && ! is_string($conteudo)) {
                return $this->respostaForaDoFormato($resposta->status());
            }

            // 25/11: o modelo respondeu SÓ em texto afirmando que gravou ("Agendamento remarcado.") sem chamar tool: é falso (nada foi
            // gravado). O servidor troca a frase, para o usuário nunca acreditar numa gravação que não aconteceu.
            if (is_string($conteudo) && self::afirmaGravacaoSemTool($conteudo)) {
                Log::warning('AiAgentService: texto afirmava gravação sem tool (trocado)', ['tempo_ia_ms' => $tempoIaMs, 'categoria' => 'claim_without_tool']);

                return [
                    'tipo' => 'texto',
                    'mensagem' => 'Calma, ainda não alterei nada! Me diz o que você quer fazer — por exemplo: “remarque o agendamento do Thor para o dia 21”.',
                ];
            }

            // Só o tempo: nada do texto (pode trazer dado pessoal) vai para o log.
            Log::info('AiAgentService: resposta em texto', ['tempo_ia_ms' => $tempoIaMs]);

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
            // O mesmo vale DENTRO das listas de objetos (os itens da venda): chave fora do esquema não entra nem na sessão
            // nem na auditoria. Sem isso, um "preco_centavos" ou "tenant_id" dentro de um item ficaria gravado como proposto.
            if ($schema !== null && $this->aninhadoForaDoEsquema(json_decode($f['arguments'], true, 32), $schema)) {
                return $this->respostaForaDoFormato($resposta->status());
            }
        }
        // O topo já foi conferido como objeto acima; aqui tudo vira array, inclusive o que é aninhado (os itens da venda).
        $argumentos = (array) json_decode($argumentosJson, true, 32, JSON_THROW_ON_ERROR);
        unset($argumentos['tenant_id']);
        if ($tool === 'criar_lancamento') unset($argumentos['status']);
        $definicao = $this->catalogo()[$tool] ?? null;

        if ($definicao !== null && $definicao['tipo'] === 'escrita') {
            if (! $this->papelAtualPermite($definicao['papeis'])) {
                return $this->comAviso($this->negar($tool, $definicao, $argumentos), $aviso);
            }

            // Atualizar (Bloco 3): o servidor acha o registro (por id ou nome, no tenant) e o formulário
            // vem com os valores atuais mais o que a IA mudou. Nenhum ou vários: responde sem formulário.
            $propostosPelaIa = $argumentos;

            // P-C1: "edite o segundo", "a última": a IA manda só a posição na última tabela mostrada, e o servidor
            // troca pelo id congelado na sessão (depois do RBAC, antes de localizar). id e busca vindos junto são descartados.
            if (isset($argumentos['posicao'])) {
                $resolvido = $this->resolverPosicao($tool, $definicao, $argumentos, $lista);

                if (isset($resolvido['resposta'])) {
                    return $this->comAviso($resolvido['resposta'], $aviso);
                }

                $argumentos = $resolvido['argumentos'];
            }
            unset($argumentos['posicao']);

            if ($definicao['localizar'] !== null) {
                $localizado = $this->localizarRegistro($tool, $definicao, $argumentos, $lista);

                if (isset($localizado['resposta'])) {
                    $encontrados = $localizado['resposta'];

                    // Vários com esse nome: a lista que o usuário vai escolher também vale para "o segundo".
                    if (($encontrados['tipo'] ?? null) === 'resultado_leitura') {
                        $encontrados['lista'] = $this->listaParaGuardar($tool, $definicao, $encontrados['resultado'] ?? []);
                        $encontrados = $this->semVersaoNasLinhas($encontrados);
                    }

                    return $this->comAviso($encontrados, $aviso);
                }

                $argumentos = $localizado['argumentos'];
            }

            if ($definicao['preparar'] !== null) {
                $argumentos = ($definicao['preparar'])($argumentos, $propostosPelaIa, $this->contexto->tenantId());
            }

            // RF03: o servidor resolve o filtro do lote UMA vez; os ids ficam no rascunho e o Confirmar só executa eles.
            $loteIds = null;

            if ($tool === 'conciliar_lancamentos_em_lote') {
                $resolvido = $this->resolverLote($argumentos);

                if (isset($resolvido['resposta'])) {
                    return $this->comAviso($resolvido['resposta'], $aviso);
                }

                $loteIds = $resolvido['ids'];
            }

            // RF04: a proposta já chega como formulário (o próprio card de confirmação). Os argumentos
            // voltam crus para o controller guardar na sessão; ele mascara antes de responder ao front.
            // "propostos" é o que a IA pediu de fato (na atualização: id/busca + só os campos que mudam).
            return $this->comAviso([
                'tipo' => 'confirmacao_pendente',
                'tool' => $tool,
                'risco' => $definicao['risco'],
                'segurar' => $this->exigeSegurar($tool, $argumentos),
                'segurar_quando' => $this->condicaoSegurar($tool),
                'passo_unico' => $this->confirmaEmUmPasso($tool),
                'argumentos' => $argumentos,
                'propostos' => $propostosPelaIa,
                'formulario' => $this->formulario($tool, $argumentos, [], $loteIds),
                // Vai com a proposta para a sessão: o desfecho (confirmado, cancelado...) é auditado depois. O controller tira antes de responder.
                'tempo_ia_ms' => $tempoIaMs,
                // RF03: ids resolvidos pelo servidor; o controller guarda no rascunho e tira antes de responder.
                ...($loteIds === null ? [] : ['lote_ids' => $loteIds]),
            ], $aviso);
        }

        try {
            $resposta = $this->executarTool($tool, $argumentos);

            // P-C1: a tabela mostrada fica congelada na sessão (ids em ordem), e o histórico ganha uma frase sem dados.
            if (($resposta['tipo'] ?? null) === 'resultado_leitura') {
                $resposta['lista'] = $this->listaParaGuardar($tool, $definicao, $resposta['resultado'] ?? []);
                // A "versao" fica só no servidor (na lista guardada): as linhas que vão ao navegador não a levam.
                $resposta = $this->semVersaoNasLinhas($resposta);
            }

            // 26/11: a agenda responde em frase (montada aqui, só com o que o servidor leu) e diz qual linha a tela realça.
            if ($tool === 'consultar_agenda' && ($resposta['tipo'] ?? null) === 'resultado_leitura') {
                $resposta = $this->apresentarAgenda($resposta, $argumentos);
            }

            // A3 (RF02): tabela de consulta pode ser exportada. Os argumentos voltam para o controller
            // guardar no servidor; ele tira esta chave antes de responder (o front só recebe um id).
            if (($resposta['tipo'] ?? null) === 'resultado_leitura' && ($definicao['tipo'] ?? null) === 'leitura' && $definicao['colunas'] !== []) {
                $resposta['consulta'] = $argumentos;
            }

            return $this->comAviso($resposta, $aviso);
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
     * G (P-C3): o system prompt da clínica veterinária (principal e lembrete do meio), com as mesmas regras do de
     * contabilidade adaptadas ao domínio. Só o papel e a data entram como dado; nada do usuário além disso.
     *
     * @return array{principal: string, lembrete: string}
     */
    private function promptDaClinica(): array
    {
        return [
            'principal' => 'Papel autenticado do usuário: '.json_encode($this->contexto->papel()).'. Você é o assistente do Sentinel, um sistema de gestão para uma clínica veterinária com petshop (banho e tosa). A data de hoje é '.now()->toDateString().' — use-a para resolver termos relativos como "hoje", "amanhã" e "ontem". Use as tools disponíveis para consultar e cadastrar tutores, pets e itens do catálogo, consultar a agenda, agendar, remarcar, trocar o serviço e mudar o status de um agendamento, cobrar atendimentos, receber e cancelar cobranças, registrar vendas avulsas, e consultar as mensagens dos tutores e marcá-las como tratadas. Você só pode executar operações representadas pelas ferramentas disponibilizadas. Preserve a operação explicitamente solicitada: cadastro, alteração, cobrança, recebimento, cancelamento e exclusão nunca devem virar consulta. Não substitua uma ação proibida por outra permitida; o servidor aplica a autorização. Se o pedido não corresponder a nenhuma ferramenta (por exemplo, registrar prontuário ou receitar), explique claramente que a operação não é suportada. Perguntas de conhecimento geral fora do catálogo (por exemplo, geografia, ou diagnóstico, dose e tratamento de animais) devem ser recusadas educadamente, sem responder o conteúdo da pergunta; convidar a voltar ao Sentinel depois de dar a resposta não atende a essa restrição. Não invente ferramentas, não afirme ter executado ações e não substitua entidades: tutor é a pessoa responsável e pet é o animal; todo pet pertence a um tutor. Cadastro de usuários e de profissionais não está disponível. Propostas no histórico não significam escrita executada: somente a confirmação humana autoriza a execução. Nunca invente dados — se faltar informação obrigatória para uma tool (por exemplo, o tutor, o nome ou a espécie de um pet), chame a tool com o que tiver, sem inventar o que falta; o card marca o campo que falta. Exemplos: "preciso cadastrar um tutor novo" → chame criar_tutor sem argumentos; "quero cadastrar um pet" → chame criar_pet sem argumentos; "cadastre um pet chamado Rex" → chame criar_pet só com o nome Rex, sem inventar o tutor nem a espécie. Nunca responda esses pedidos só com uma pergunta em texto. Cada pedido recebe uma única resposta sua, e o resultado de uma consulta vai para a tela, não volta para você. Por isso, quando o pedido é uma ação (agendar um atendimento novo, remarcar (mudar o horário de um agendamento que já existe: use remarcar_agendamento e nunca agende de novo no lugar dele), trocar o serviço de um agendamento marcado errado ("não era banho, era consulta": use alterar_servico_agendamento, que altera o mesmo agendamento), ver ou abrir a ficha de um agendamento (use remarcar_agendamento com o número ou a busca), encaixe de emergência, mudar o status de um agendamento, cadastrar, alterar, vender, cobrar, receber, cancelar ou estornar), chame nesta resposta a tool dessa ação, mesmo sem saber os números: nunca chame uma consulta antes para descobrir o pet, o tutor, o serviço, o profissional, o item ou o registro. Para achar o registro de uma ação, use o campo busca com o nome do pet ou do tutor; números que você não sabe ficam em branco (o card mostra as listas para a pessoa escolher), e nunca invente um número. Quando o usuário se refere a uma linha da última tabela mostrada ("o último", "o primeiro", "esse", "ele", "o Rex" que está na tabela), use o campo posicao (ou busca, se ele disse um nome): você não enxerga as linhas, mas o servidor resolve a referência pela tabela que o usuário viu; nunca responda pedindo que ele repita nem diga que não consegue ler a linha. Agendamento sempre abre a ficha para a pessoa preencher: nunca discuta opções em texto (por exemplo "remarcar ou cancelar?") nem diga que não consegue mostrar ou trocar o agendamento; chame a tool com o que souber, e o que faltar vira campo na ficha. Isso vale também para marcar ou remarcar sem dizer o horário (ou o dia): chame agendar_atendimento ou remarcar_agendamento com o início em branco, nunca responda em texto pedindo a hora; a ficha resolve com o calendário, no passo de escolher o horário. Para agendar, só o início é informado (o servidor calcula o fim); sem profissional dito ("qualquer veterinário"), deixe o profissional em branco: a ficha mostra o calendário; ao remarcar, se o usuário descreve o agendamento (por exemplo "o retorno do dr. rafael"), ponha essas palavras no campo busca. Para mudar só o DIA de um agendamento ("pro dia 21", "amanhã", "mesmo horário"), mande dia (AAAA-MM-DD) ou dia_do_mes e NUNCA invente o horário, porque você não o conhece: o servidor mantém o horário do agendamento (hora só se o usuário disser). "Agenda do Thor" é consultar_agenda com o campo pet, e não consultar_pets. TOM: converse como um colega prestativo e simpático, em português do Brasil, na primeira pessoa, sem jargão de sistema nem linguagem de formulário. Sempre que chamar uma tool, escreva TAMBÉM uma frase curta e natural (uma só) dizendo o que entendeu e o que vai mostrar, por exemplo "Achei o agendamento do Rex de hoje, dá uma conferida no card antes de confirmar." ou "Vou te mostrar os agendamentos do Diego para você marcar quais cancelar." ou "Certo, vou abrir a ficha para você escolher o novo horário." Varie o jeito de começar: não repita a mesma palavra de abertura (como "Beleza") em respostas seguidas. Nessa frase NUNCA diga que algo foi remarcado, agendado, cancelado ou gravado (isso só acontece depois da confirmação humana), não cite números de registro, CPF, telefone nem valores, e não repita o que a tabela já mostra. Em consultar_agenda e em cancelar_agendamentos_em_lote passe TODOS os filtros que o usuário citar: tutor (o cliente, dono do animal), pet (o animal), profissional (nome), dia ou data_inicio/data_fim e setor; "os agendamentos do cliente Diego" é tutor "Diego", e nunca a agenda inteira: não liste mais do que ele pediu. Para cancelar VÁRIOS agendamentos de uma vez ("cancele todos", "cancele tudo menos o dia 25"), chame cancelar_agendamentos_em_lote: ela só mostra a lista para o usuário MARCAR quais cancelar (nunca cancele às cegas, nunca peça ids); "menos o dia 25" vai em exceto_dia. Depois de uma confirmação, o histórico traz o número do registro gravado: quando o usuário se refere ao registro que acabou de ser gravado, use esse número no campo correspondente (por exemplo, o tutor de um pet novo). Pedido de alteração que não diz o que mudar: chame a tool de alterar só com o registro, e o card abre os campos para editar. Pedido curto sem ação nem registro identificáveis, sem tabela nem proposta recente a que ele se ligue (por exemplo, pedir um valor ou mandar cancelar sem dizer o quê): responda em texto pedindo que a pessoa diga a que se refere, sem chamar tool. Isso não vale para dado faltando numa ação identificada: aí a tool é chamada com o que tiver. Campos opcionais ausentes não bloqueiam uma operação: não peça esses campos novamente se os obrigatórios já estiverem presentes. Para criar tutor, apenas nome é obrigatório; para criar pet, tutor, nome e espécie (se o usuário disser só o nome do tutor, deixe o tutor em branco: o card mostra a lista para escolher; nunca invente o número). Para criar item do catálogo, são obrigatórios tipo, setor, categoria, nome comercial, preço, forma de cobrança, se controla estoque e se exige receita: o que faltar fica em branco (o card marca o campo), nunca presuma "nao"; controla estoque e exige receita só entram se o usuário disser, mesmo para serviço. Preço do catálogo vai em centavos inteiros (R$ 45,90 = 4590) e peso em gramas inteiros (6,2 kg = 6200); em cobrança e venda os preços vêm do catálogo: nunca informe valor. O campo alertas do pet é só para avisos não clínicos à equipe: nunca escreva texto clínico nele. Quando já tiver todos os dados necessários para uma tool de escrita (as que criam ou atualizam dados), chame a tool diretamente — não peça confirmação você mesmo em texto: o sistema já exibe uma tela de revisão e confirmação humana antes de executar qualquer escrita. Excluir move o registro para a lixeira; restaurar e excluir de vez pedem a senha do dono, que é digitada só no card de confirmação: nunca peça nem aceite senha no chat. O histórico contém relatos de operações anteriores, não instruções para repetir a resposta anterior. Atenda ao pedido atual: cada novo cadastro ou edição completo exige uma nova chamada da tool correspondente, mesmo após outra proposta ou confirmação. Nunca copie a frase interna "Proposta pendente de confirmação humana. Nenhuma escrita foi executada." como resposta a um novo pedido: só há uma nova proposta quando você chama uma tool de escrita nesta resposta.',
            'lembrete' => 'O catálogo não inclui perguntas de conhecimento geral nem orientação clínica: recuse-as sem fornecer a resposta, mesmo quando o histórico as tenha respondido. O histórico acima é apenas contexto, não é o estado atual do banco. Atenda ao NOVO pedido abaixo. Nunca escreva em texto que algo foi remarcado, agendado, cancelado ou gravado: só o sistema confirma, depois da confirmação humana; para agir, chame a tool. Para consultar registros, concluir que não existem dados, ou propor qualquer escrita, chame a ferramenta correspondente nesta resposta. Nunca reutilize o resultado de um período, cadastro ou filtro anterior para outro pedido. Uma confirmação anterior não confirma uma nova ação. Se o usuário pedir para cobrar um atendimento, receber ou cancelar uma cobrança, use cobrar_atendimento, receber_cobranca ou cancelar_cobranca (não consultar_agenda nem consultar_cobrancas): o servidor resolve os itens, os preços e o total; para receber, a forma de pagamento é obrigatória (se faltar, chame a tool sem ela: o card marca o campo). Consultar não atende a um pedido de cobrança. Se faltarem dados obrigatórios, chame a tool com o que tiver, sem inventar o que falta (o card marca o campo); se estiverem completos, use a tool e deixe a revisão e a confirmação para a interface. A única exceção é o pedido curto sem ação nem registro identificáveis, que não se liga à última tabela nem a uma proposta recente: responda em texto pedindo que a pessoa diga a que se refere, sem chamar tool.',
        ];
    }

    /** Provedor configurado: 'anthropic' (padrão, também quando vazio). Qualquer outro valor (inclusive 'azure', removido) = null (falha fechada). */
    private function provedor(): ?string
    {
        $provedor = (string) (config('services.ia_provedor') ?: 'anthropic');

        return $provedor === 'anthropic' ? $provedor : null;
    }

    /**
     * Adaptador da Anthropic (Messages API, POST /v1/messages, HTTP REST sem SDK). Recebe o corpo
     * já montado no formato interno de chat/tools e traduz: as mensagens system (a principal e a do meio do
     * histórico) vão para o campo system; do histórico só passam user/assistant com texto não vazio,
     * sem nenhuma outra chave. Tool solicitada: tool_choice auto + só essa tool + instrução no system,
     * porque o modelo padrão (claude-sonnet-5-5) recusa tool_choice forçado com 400. A conferência no
     * servidor (AI_INTENT_MISMATCH) continua igual. Sem temperature: o modelo recusa valor não padrão.
     *
     * @param  array<string, mixed>  $corpo
     */
    private function chamarAnthropic(array $corpo, ?string $toolSolicitada): \Illuminate\Http\Client\Response
    {
        $sistema = [];
        $mensagens = [];
        $houveConversa = false;

        foreach ($corpo['messages'] as $item) {
            $papel = is_array($item) ? ($item['role'] ?? null) : null;
            $conteudo = is_array($item) ? ($item['content'] ?? null) : null;

            // Texto vazio é recusado pela API; não é conteúdo.
            if (! is_string($conteudo) || trim($conteudo) === '') {
                continue;
            }

            if ($papel === 'system') {
                // A do meio (depois do histórico) fala em "histórico acima" e "pedido abaixo": no campo system,
                // a referência tem de dizer a que mensagem ela vale.
                $sistema[] = $houveConversa
                    ? "Regra para o pedido atual (a última mensagem do usuário; o \"histórico\" são as mensagens anteriores a ela):\n".$conteudo
                    : $conteudo;
            } elseif ($papel === 'user' || $papel === 'assistant') {
                // Rede de segurança (23/11): dois turnos seguidos do mesmo papel viram UMA mensagem na API, e o modelo atende
                // o primeiro pedido. O último vale (é o pedido atual); o anterior é descartado, nunca fundido. Se isto
                // disparar, o histórico perdeu um fechamento de turno: é sinal de outro bug (ver o log).
                if ($mensagens !== [] && $mensagens[array_key_last($mensagens)]['role'] === $papel) {
                    array_pop($mensagens);
                    Log::warning('AiAgentService: histórico com dois turnos seguidos do mesmo papel (o anterior foi descartado)', [
                        'correlation_id' => (string) Str::uuid(),
                        'categoria' => 'history_alternation_error',
                        'papel' => $papel,
                    ]);
                }
                $mensagens[] = ['role' => $papel, 'content' => $conteudo];
                $houveConversa = true;
            }
        }

        // A primeira mensagem tem de ser do usuário.
        while ($mensagens !== [] && $mensagens[0]['role'] !== 'user') {
            array_shift($mensagens);
        }

        if ($toolSolicitada !== null) {
            $sistema[] = "Para este pedido, use a ferramenta {$toolSolicitada} nesta resposta; não a troque por outra operação nem por consulta.";
        }

        $tools = array_map(function (array $definicao) {
            $funcao = $definicao['function'];
            $esquema = $funcao['parameters'] ?? ['type' => 'object'];

            // Lista vazia em PHP vira [] no JSON; o esquema exige objeto.
            if (($esquema['properties'] ?? null) === []) {
                $esquema['properties'] = new \stdClass;
            }

            return [
                'name' => $funcao['name'],
                ...(isset($funcao['description']) ? ['description' => $funcao['description']] : []),
                'input_schema' => $esquema,
            ];
        }, $corpo['tools']);

        $esforco = (string) config('services.anthropic.effort');

        return Http::withHeaders([
            'x-api-key' => (string) config('services.anthropic.api_key'),
            'anthropic-version' => (string) config('services.anthropic.versao'),
        ])
            ->timeout(30)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => (string) config('services.anthropic.model'),
                'max_tokens' => (int) config('services.anthropic.max_tokens'),
                'system' => implode("\n\n", $sistema),
                'messages' => $mensagens,
                'tools' => $tools,
                'tool_choice' => ['type' => 'auto'],
                ...($esforco === '' ? [] : ['output_config' => ['effort' => $esforco]]),
            ]);
    }

    /**
     * Converte a resposta 2xx da Anthropic para o MESMO formato interno que o interpretar() já lê
     * interno (choices.0.message com content/tool_calls e finish_reason). Não 2xx passa como veio
     * (o interpretar trata o status). O corpo é decodificado com objetos: input {} continua "{}" e
     * input que não é objeto continua não sendo, para o interpretar recusar. Fora do formato (sem
     * content em lista, stop_reason ausente ou desconhecido, end_turn com tool_use) vira resposta
     * que o interpretar recusa; nunca null, que ele aceitaria.
     */
    private function traduzirAnthropic(\Illuminate\Http\Client\Response $resposta): \Illuminate\Http\Client\Response
    {
        if (! $resposta->successful()) {
            return $resposta;
        }

        $corpo = json_decode($resposta->body(), false, 64);
        $mensagem = null;
        $fim = 'fora_do_formato';

        if ($corpo instanceof \stdClass && ($corpo->type ?? null) === 'message' && is_array($corpo->content ?? null)) {
            $textos = [];
            $chamadas = [];

            $malformado = false;

            foreach ($corpo->content as $bloco) {
                $tipo = $bloco instanceof \stdClass ? ($bloco->type ?? null) : null;

                // thinking, fallback e outros blocos não são resposta ao usuário: ignorados.
                // Bloco de texto sem texto não some calado: a resposta inteira fica fora do formato.
                if ($tipo === 'text') {
                    is_string($bloco->text ?? null) ? $textos[] = $bloco->text : $malformado = true;
                } elseif ($tipo === 'tool_use') {
                    $chamadas[] = ['id' => $bloco->id ?? null, 'type' => 'function', 'function' => [
                        'name' => $bloco->name ?? null,
                        'arguments' => json_encode($bloco->input ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]];
                }
            }

            $mensagem = ['role' => 'assistant', 'content' => $textos === [] ? null : implode("\n\n", $textos), 'tool_calls' => $chamadas];
            // Fim normal sem texto e sem tool (content vazio, só thinking) não é resposta: fora do formato.
            $fim = $malformado ? 'fora_do_formato' : match ($corpo->stop_reason ?? null) {
                'end_turn', 'stop_sequence' => $chamadas === [] && $textos !== [] ? 'stop' : 'fora_do_formato',
                'tool_use' => 'tool_calls',
                'max_tokens' => 'length',
                'refusal' => 'content_filter',
                default => 'fora_do_formato',
            };
        }

        return new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(
            $resposta->status(),
            ['Content-Type' => 'application/json'],
            (string) json_encode(['choices' => [['message' => $mensagem, 'finish_reason' => $fim]]]),
        ));
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
     * P-C1: a tabela que o usuário acabou de ver, para guardar na sessão (ConversaAgente::guardarLista): a entidade e
     * os ids EM ORDEM, até LIMITE_LISTA linhas. A frase vai para o histórico no lugar da tabela: só a quantidade e
     * como se referir a uma linha, sem id, nome nem documento. Linha sem id fica null (não desloca as posições).
     * A lixeira mistura entidades: ela não vale para posição (entidade 'Lixeira' nunca casa com uma tool).
     *
     * @return array{entidade: ?string, linhas: array<int, ?int>, frase: string}
     */
    private function listaParaGuardar(string $tool, ?array $definicao, mixed $resultado): array
    {
        $resultado = is_array($resultado) && array_is_list($resultado) ? $resultado : [];
        $linhas = array_map(function ($linha) {
            $id = is_array($linha) ? ($linha['id'] ?? null) : null;

            return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;
        }, array_slice($resultado, 0, self::LIMITE_LISTA));
        $n = count($linhas);

        return [
            'entidade' => self::ENTIDADE_DO_ID[$tool] ?? $definicao['entidade'] ?? null,
            'linhas' => $linhas,
            // 25/11: a tabela de cancelar em lote tem checkboxes; só os ids dela entram no endpoint de gravação.
            // Também a lista de "qual deles cancelar?" (alterar_status_agendamento → cancelado): ela traz a "versao" de cada linha.
            'selecionavel' => $this->ehListaParaMarcar($tool, $resultado) ? 'cancelar_agendamentos_em_lote' : null,
            // A versão de cada linha como o usuário a VIU (a gravação recusa o que mudou depois); nunca vai ao navegador.
            'versoes' => $this->ehListaParaMarcar($tool, $resultado)
                ? collect($resultado)->filter(fn ($l) => is_array($l) && isset($l['id'], $l['versao']))->mapWithKeys(fn (array $l) => [(int) $l['id'] => (string) $l['versao']])->all()
                : [],
            'frase' => $n === 0
                ? "A consulta ({$tool}) não encontrou registros."
                : "Mostrei ao usuário uma tabela com {$n} ".($n === 1 ? 'linha' : 'linhas')." ({$tool}). Ela fica guardada no servidor: para alterar uma linha dela (\"edite o segundo\", \"a última\"), use o campo posicao (1 = primeira, -1 = última), nunca o número da linha como id.",
        ];
    }

    /** A lista tem caixa de marcar (cancelar agendamentos): a tool do lote, ou o "qual deles?" do cancelamento, cujas linhas levam a "versao". */
    private function ehListaParaMarcar(string $tool, mixed $resultado): bool
    {
        if ($tool === 'cancelar_agendamentos_em_lote') {
            return true;
        }

        return $tool === 'alterar_status_agendamento' && is_array($resultado) && $resultado !== []
            && collect($resultado)->every(fn ($l) => is_array($l) && isset($l['id'], $l['versao']));
    }

    /** A "versao" fica só no servidor (na lista guardada): as linhas que vão ao navegador não a levam. */
    private function semVersaoNasLinhas(array $resposta): array
    {
        if (is_array($resposta['resultado'] ?? null)) {
            $resposta['resultado'] = array_map(fn ($l) => is_array($l) ? Arr::except($l, 'versao') : $l, $resposta['resultado']);
        }

        return $resposta;
    }

    /**
     * P-C1: troca a "posicao" (1..N, ou -1 = última) pelo id congelado na última tabela mostrada a este usuário neste
     * tenant (a sessão confere a identidade e o prazo). A IA nunca dá o id: id e busca que vierem junto são descartados.
     * Tabela de outra entidade, posição fora dela ou sem tabela: recusa (422), sem rascunho e sem auditoria, como as
     * recusas de localizarRegistro(). Depois disto o registro ainda é localizado no tenant (e na situação certa).
     *
     * @return array{argumentos: array<string, mixed>}|array{resposta: array<string, mixed>}
     */
    private function resolverPosicao(string $tool, array $definicao, array $argumentos, ?array $lista): array
    {
        $recusa = fn (string $mensagem) => ['resposta' => [
            'tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_TOOL_REFUSED', 'http_status' => 422, 'mensagem' => $mensagem,
        ]];
        $linhas = is_array($lista['linhas'] ?? null) ? array_values($lista['linhas']) : [];
        $posicao = $argumentos['posicao'];

        if ($linhas === []) {
            return $recusa('Não tenho uma tabela recente para saber qual é esse registro. Consulte de novo ou diga o nome.');
        }
        if (($lista['entidade'] ?? null) !== (self::ENTIDADE_DO_ID[$tool] ?? $definicao['entidade'])) {
            return $recusa('A última tabela mostrada é de outro tipo de registro. Consulte de novo ou diga o nome.');
        }

        $indice = is_int($posicao) ? ($posicao === -1 ? count($linhas) - 1 : $posicao - 1) : null;
        $id = $indice !== null && $indice >= 0 ? ($linhas[$indice] ?? null) : null;

        if (! is_int($id)) {
            $total = count($linhas);

            return $recusa("A última tabela tem {$total} ".($total === 1 ? 'linha' : 'linhas').'; essa posição não existe nela.');
        }

        unset($argumentos['posicao'], $argumentos['busca']);

        return ['argumentos' => [...$argumentos, 'id' => $id]];
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
        $contexto = ['correlation_id' => $correlacao, 'categoria' => $categoria, 'provider' => $this->provedor() ?? 'desconhecido', ...$metadados];
        // B1 (RNF04): dentro do interpretar() (resposta truncada, filtrada ou fora do formato) o tempo vem do escopo.
        $tempoIaMs = $contexto['tempo_ia_ms'] ?? $this->detalhesAuditoria->atuais()['tempo_ia_ms'] ?? null;
        if ($tempoIaMs !== null) {
            $contexto['tempo_ia_ms'] = $tempoIaMs;
        }
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
     * chamada ao provedor de IA.
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
            // LGPD: sem esquema, não há como saber qual valor é pessoal (o CPF pode vir em qualquer chave).
            // Fica só o nome de cada argumento, nunca o valor — e nome com dígito ou fora do formato de
            // identificador (onde caberia um CPF ou e-mail) também é redigido, assim como o nome da tool.
            // O proposto (se a confirmação abriu escopo com ele) também não vai: sem esquema, nada de valores.
            $this->detalhesAuditoria->com(['parametros_propostos' => null], fn () => $this->auditar($this->identificador($tool, '[tool redigida]'), 'desconhecida',
                null, null, $this->soNomesDosArgumentos($argumentos), 'negado', 'Tool fora do catálogo.', false));

            throw new InvalidArgumentException("Tool desconhecida: {$tool}");
        }

        if (! $this->papelAtualPermite($definicao['papeis'])) {
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

        // Máscara não é dado: a IA (que só lê valores mascarados) ou o card podem devolver "(19) 9****-*321" ou
        // "123.***.**9-00"; isso nunca é gravado. O campo some e vale o atual (atualizar) ou vazio (criar).
        $argumentos = AuditoriaService::semValoresMascarados($argumentos);
        $argumentos['tenant_id'] = $this->contexto->tenantId();

        try {
            // Escopo próprio: o que o executor anotar (ex.: valores_anteriores) vale só para a auditoria
            // de sucesso abaixo e some se a execução falhar.
            $resultado = $this->detalhesAuditoria->com([], fn () => DB::transaction(function () use ($tool, $definicao, $argumentos) {
                $retorno = $definicao['executar']($argumentos);

                // RF03: o lote já auditou uma linha por item (com lote_id) dentro do executor.
                if ($tool === 'conciliar_lancamentos_em_lote') {
                    return $retorno;
                }

                // 25/11: "qualquer profissional" escolhido pelo servidor: a auditoria registra QUEM ficou, e que foi automático.
                $escolhido = $this->profissionalEscolhidoPeloServidor($tool, $retorno, $argumentos);
                $parametros = $escolhido === null ? $argumentos
                    : [...$argumentos, 'profissional_id' => $escolhido, 'profissional_escolhido_pelo_servidor' => true];

                $this->auditar(
                    $tool,
                    $definicao['acao'],
                    $definicao['entidade'],
                    $retorno instanceof Model ? (int) $retorno->getKey() : null,
                    $parametros,
                    'sucesso',
                    null,
                    true,
                );

                return $retorno;
            }));
        } catch (ToolRecusadaException $e) {
            // Regra de negócio: a mensagem já é uma frase amigável escrita para o usuário.
            // RF03: lote recusado = uma linha só, com os ids (já nos argumentos) e o motivo de cada item que mudou.
            $parametros = $tool === 'conciliar_lancamentos_em_lote' && $e->erros !== [] ? [...$argumentos, 'motivos' => $e->erros] : $argumentos;
            $this->auditar($tool, $definicao['acao'], $definicao['entidade'], null, $parametros, 'recusado', $e->getMessage(), true);

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
                'mensagem' => $tool === 'conciliar_lancamentos_em_lote'
                    ? $resultado['conciliados'].($resultado['conciliados'] === 1 ? ' lançamento conciliado.' : ' lançamentos conciliados.')
                    : $this->fraseDeSucesso($definicao, $argumentos).$this->fraseDoProfissionalEscolhido($tool, $resultado, $argumentos),
                // O registro gravado volta ao front com a mesma máscara LGPD da auditoria.
                'resultado' => $resultado instanceof Model ? AuditoriaService::mascararCamposSensiveis($resultado->toArray()) : $resultado,
            ];
        }

        return [
            'tipo' => 'resultado_leitura',
            'tool' => $tool,
            'resultado' => $resultado,
            ...($definicao['colunas'] ? ['colunas' => $definicao['colunas']] : []),
            // 25/11: lista para MARCAR quais cancelar (checkbox). Nada é gravado aqui: quem grava é o endpoint, só com os ids marcados.
            ...($tool === 'cancelar_agendamentos_em_lote' ? ['selecao' => ['url' => '/agente/agenda/cancelar-lote', 'rotulo' => 'Cancelar selecionados', 'segurar' => true]] : []),
            // RF02 "editável": a linha abre o formulário de atualizar (sem IA), se o papel permitir.
            ...($this->podeEditar($definicao['editavel_por']) ? ['editar' => $definicao['editavel_por']] : []),
        ];
    }

    /**
     * Agenda (26/11): acrescenta a frase da resposta e as linhas a realçar; pedido por gravação ou alteração mostra essa coluna.
     *
     * @param  array<string, mixed>  $resposta
     * @param  array<string, mixed>  $filtros
     * @return array<string, mixed>
     */
    private function apresentarAgenda(array $resposta, array $filtros): array
    {
        $linhas = is_array($resposta['resultado'] ?? null) ? array_values($resposta['resultado']) : [];
        $filtros = \App\Services\AgendaService::sanearFiltros($filtros);
        $apresentacao = \App\Services\Agente\FraseDaAgenda::montar($linhas, $filtros);
        $colunas = is_array($resposta['colunas'] ?? null) ? $resposta['colunas'] : [];
        foreach (['gravacao' => 'gravado_em', 'alteracao' => 'alterado_em'] as $tipo => $coluna) {
            if (($filtros['tipo_data'] ?? null) === $tipo || ($filtros['ordenar'] ?? null) === $tipo) {
                $depois = array_search('horario', $colunas, true);
                array_splice($colunas, $depois === false ? 1 : $depois + 1, 0, [$coluna]);
            }
        }

        return [...$resposta, 'colunas' => $colunas, 'frase' => $apresentacao['frase'], 'destacar' => $apresentacao['destacar']];
    }

    /** A tool de atualizar existe e o papel atual chega no mínimo dela. */
    private function podeEditar(?string $toolAtualizar): bool
    {
        $atualizar = $toolAtualizar === null ? null : ($this->catalogo()[$toolAtualizar] ?? null);

        return $atualizar !== null && $this->papelAtualPermite($atualizar['papeis']);
    }

    /**
     * Tudo o que não envolve segredo: a tool existe, o usuário tem tenant e o papel atual chega
     * no mínimo dela. O /agente/confirmar só confere a senha de admin se isto passar — senão o
     * card viraria um verificador de senha para quem nem pode executar a ação.
     */
    public function podeExecutar(string $tool): bool
    {
        $definicao = $this->catalogo()[$tool] ?? null;
        $this->contexto->tenantId(); // sem tenant: AuthenticationException (falha fechada), antes de qualquer senha

        return $definicao !== null && $this->papelAtualPermite($definicao['papeis']);
    }

    /** A tool pede a senha de um administrador no card (Bloco 4: restaurar e excluir de vez). */
    public function exigeReautenticacao(string $tool): bool
    {
        return (bool) ($this->catalogo()[$tool]['reautenticar'] ?? false);
    }

    /** 15/11: o Confirmar desta proposta pede CRMV + senha do próprio usuário (cirurgia, internação)? Pelos valores da sessão. */
    public function exigeCrmv(string $tool, array $valores): bool
    {
        $regra = $this->catalogo()[$tool]['exige_crmv'] ?? null;

        return $regra !== null && (bool) $regra($valores, $this->contexto->tenantId());
    }

    /** H: que conferência o card faz antes de executar: 'admin' (senha de admin) ou 'estorno' (PIN do supervisor ou do dono). */
    public function tipoReautenticacao(string $tool): ?string
    {
        $reautenticar = $this->catalogo()[$tool]['reautenticar'] ?? false;

        return $reautenticar === 'estorno' ? 'estorno' : ($reautenticar === true ? 'admin' : null);
    }

    /** O admin que reautenticou nesta requisição — posto só pelo /agente/confirmar, depois de conferir a senha. */
    private function autorizadoPor(): ?int
    {
        $id = $this->detalhesAuditoria->atuais()['autorizado_por'] ?? null;

        return is_int($id) && $id >= 1 ? $id : null;
    }

    /**
     * 25/11: o pedido é SÓ "o último" ("o último", "o mais recente", "o último agendamento que criei"), sem nome de pet, tutor etc.?
     * Com uma tabela recente da mesma entidade, isso vale para a última linha dela (localizarRegistro).
     */
    private static function soPedeOUltimo(string $busca): bool
    {
        $palavras = array_map(fn (string $p) => Str::ascii($p), (array) preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($busca), -1, PREG_SPLIT_NO_EMPTY));
        $marcas = array_intersect($palavras, ['ultimo', 'ultima', 'recente']);

        // "o último que CRIEI" é o último CRIADO (não a última linha da tabela): com isso, a tabela não decide.
        if (array_intersect($palavras, ['criei', 'criado', 'criada', 'criamos']) !== []) {
            return false;
        }

        return $marcas !== [] && array_diff($palavras, AgendaService::PALAVRAS_DO_MAIS_RECENTE, ['o', 'a', 'os', 'as', 'da', 'de', 'do', 'que', 'eu', 'e', 'ele', 'esse', 'essa']) === [];
    }

    /** "Achei 4 agendamentos que batem com “Rex”. Qual deles você quer? …": quantos, de quê, e o que fazer a seguir. */
    private function fraseDeVariosEncontrados(array $definicao, int $quantos, ?string $busca): string
    {
        $coisa = FormularioTool::semArtigo((string) ($definicao['rotulo'] ?? 'o registro'));
        $plural = ['agendamento' => 'agendamentos', 'cliente' => 'clientes', 'fornecedor' => 'fornecedores', 'funcionário' => 'funcionários', 'lançamento' => 'lançamentos',
            'tutor' => 'tutores', 'pet' => 'pets', 'cobrança' => 'cobranças', 'venda' => 'vendas', 'mensagem' => 'mensagens'][$coisa] ?? 'registros';
        $alvo = $busca !== null && $busca !== '' ? ' que batem com “'.$busca.'”' : '';
        $dica = $coisa === 'agendamento' ? 'me diga o dia e o horário' : 'me diga o número';

        return "Achei {$quantos} {$plural}{$alvo}. Qual deles você quer? É só clicar em “Escolher” na linha certa (ou {$dica}).";
    }

    /**
     * Os ids da última tabela mostrada a este usuário, SE for da mesma entidade da tool (e ainda dentro do prazo, que a sessão confere).
     *
     * @return int[]
     */
    private function linhasDaUltimaTabela(string $tool, array $definicao, ?array $lista): array
    {
        $linhas = is_array($lista['linhas'] ?? null) ? array_values(array_filter($lista['linhas'], 'is_int')) : [];

        return $linhas !== [] && ($lista['entidade'] ?? null) === (self::ENTIDADE_DO_ID[$tool] ?? $definicao['entidade']) ? $linhas : [];
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
    private function localizarRegistro(string $tool, array $definicao, array $argumentos, ?array $lista = null): array
    {
        $id = isset($argumentos['id']) && is_numeric($argumentos['id']) && (int) $argumentos['id'] >= 1 ? (int) $argumentos['id'] : null;
        $busca = is_string($argumentos['busca'] ?? null) && trim($argumentos['busca']) !== '' ? trim($argumentos['busca']) : null;
        $recusa = fn (string $mensagem) => ['resposta' => [
            'tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_TOOL_REFUSED', 'http_status' => 422, 'mensagem' => $mensagem,
        ]];

        // 25/11: a IA não vê as linhas das tabelas, então a referência ("o Rex", "o último", "esse", "ele") é resolvida AQUI, pela última
        // tabela mostrada da mesma entidade (ainda no prazo dela). Sem tabela, tudo segue como antes.
        $daTabela = $this->linhasDaUltimaTabela($tool, $definicao, $lista);
        if ($id === null && $daTabela !== []) {
            if ($busca === null && count($daTabela) === 1) {
                $id = $daTabela[0]; // sem dizer qual: tabela de uma linha só
            } elseif ($busca !== null && self::soPedeOUltimo($busca)) {
                $id = $daTabela[array_key_last($daTabela)]; // "o último" = a última linha da tabela que ele viu
                $busca = null;
            }
        }

        if ($id === null && $busca === null) {
            // "o número ou o nome" / "ou a descrição"; na nota fiscal a busca já é pelo número.
            $ouTexto = match ($definicao['busca_por']) { 'número' => '', 'descrição' => ' ou a descrição', default => ' ou o '.$definicao['busca_por'] };

            // 25/11: pergunta, como uma pessoa faria ("Qual cliente você quer alterar? Me diga o número ou o nome.").
            $verbo = preg_replace(['/ad[oa]( de vez)?$/u', '/íd[oa]( de vez)?$/u', '/id[oa]( de vez)?$/u'], ['ar$1', 'ir$1', 'er$1'], (string) $definicao['feito']);

            return $recusa('Qual '.FormularioTool::semArtigo($definicao['rotulo']).' você quer '.$verbo.'? Me diga o número'.$ouTexto.'.');
        }

        $encontrados = ($definicao['localizar'])($id, $busca, $this->contexto->tenantId());

        if ($encontrados->isEmpty()) {
            return $recusa('Não encontrei '.$definicao['rotulo'].' com '.($id !== null ? 'esse número' : ($definicao['busca_por'] === 'descrição' ? 'essa ' : 'esse ').$definicao['busca_por']).$definicao['onde'].'.');
        }

        // Achou vários pelo nome: entre eles, os que estão na tabela que o usuário acabou de ver (um só = é esse).
        if ($id === null && $encontrados->count() > 1 && $daTabela !== []) {
            $naTabela = $encontrados->filter(fn (Model $m) => in_array((int) $m->getKey(), $daTabela, true))->values();
            if ($naTabela->isNotEmpty()) {
                $encontrados = $naTabela;
            }
        }

        // 25/11: "cancela a consulta do Thor" com mais de um Thor: em vez de "Escolher", a MESMA lista com caixa de marcar do cancelar em
        // lote (pode marcar mais de um; nada às cegas: o usuário marca e segura o botão). Só para cancelamento de agendamento.
        if ($encontrados->count() > 1 && $tool === 'alterar_status_agendamento' && ($argumentos['novo_status'] ?? null) === 'cancelado') {
            $agenda = app(AgendaService::class);
            $tenantId = $this->contexto->tenantId();

            return ['resposta' => [
                'tipo' => 'resultado_leitura',
                'tool' => $tool,
                'resultado' => $encontrados->map(fn (Model $m) => $agenda->linhaParaCancelar($m, $tenantId))->values()->all(),
                'colunas' => ['id', 'horario', 'pet', 'tutor', 'servico', 'profissional', 'status', 'observacao'],
                'selecao' => ['url' => '/agente/agenda/cancelar-lote', 'rotulo' => 'Cancelar selecionados', 'segurar' => true],
                'aviso' => 'Achei '.$encontrados->count().' agendamentos'.($busca !== null && $busca !== '' ? ' que batem com “'.$busca.'”' : '')
                    .'. Marque na caixinha o que você quer cancelar (pode ser mais de um) e segure o botão para confirmar.',
            ]];
        }

        if ($encontrados->count() > 1) {
            $colunas = $definicao['colunas_lista'] ?? ['id', $definicao['coluna_rotulo']];
            // Bloco 4: na lixeira o botão de cada linha é a própria ação ("Restaurar"), não "Editar".
            $acao = $definicao['rotulo_acao'] !== '' ? ['acao' => ['tool' => $tool, 'rotulo' => $definicao['rotulo_acao']]] : [];

            return ['resposta' => [
                'tipo' => 'resultado_leitura',
                'tool' => $tool,
                'resultado' => $encontrados->map(fn (Model $m) => [...AuditoriaService::mascararCamposSensiveis(Arr::only($m->toArray(), $colunas)), ...$this->colunasCalculadasDaLista($m, $colunas), ...$acao])->values()->all(),
                'colunas' => $colunas,
                ...($acao === [] ? ['editar' => $tool] : []),
                // 25/11: conversa, não "aviso de sistema": diz quantos achou, de quê, e como seguir.
                'aviso' => $this->fraseDeVariosEncontrados($definicao, $encontrados->count(), $busca),
            ]];
        }

        return ['argumentos' => $this->valoresDoFormulario($definicao, $encontrados->first(), $argumentos)];
    }

    /**
     * 24/11 (R4): a lista de escolha de cobranças/vendas pagas distingue os candidatos com a data (pago em, ou criação) e o
     * total, formatados. Só dado que o registro já tem; coluna que já é atributo do modelo (ex.: "data" do lançamento) não é sobrescrita.
     *
     * @param  string[]  $colunas
     * @return array<string, string>
     */
    private function colunasCalculadasDaLista(Model $m, array $colunas): array
    {
        $extra = [];

        if (in_array('data', $colunas, true) && ! array_key_exists('data', $m->getAttributes())) {
            $quando = $m->getAttribute('pago_em') ?? $m->getAttribute('created_at');
            if ($quando instanceof \DateTimeInterface) {
                $extra['data'] = $quando->format('d/m/Y H:i');
            }
        }
        if (in_array('total', $colunas, true) && ! array_key_exists('total', $m->getAttributes()) && is_numeric($m->getAttribute('total_centavos'))) {
            $extra['total'] = ResultadosClinica::reais((int) $m->getAttribute('total_centavos'));
        }

        return $extra;
    }

    /**
     * RF03: resolve o filtro de conciliar_lancamentos_em_lote no tenant. Devolve ['ids' => [...]]
     * (1 a LIMITE_LOTE) ou ['resposta' => ...] sem nada na sessão: filtro inválido, nenhum
     * pendente ou mais pendentes que o teto (recusa pedindo filtro menor; nunca trunca).
     */
    private function resolverLote(array $argumentos): array
    {
        $texto = fn (string $mensagem) => ['resposta' => ['tipo' => 'texto', 'tool' => 'conciliar_lancamentos_em_lote', 'mensagem' => $mensagem]];
        $validador = Validator::make($argumentos, self::REGRAS_LOTE_LANCAMENTOS, self::MENSAGENS_LOTE_LANCAMENTOS);

        if ($validador->fails()) {
            return ['resposta' => [
                'tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_TOOL_REFUSED', 'http_status' => 422,
                'mensagem' => $validador->errors()->first(),
            ]];
        }

        $filtro = $validador->validated();
        $ids = $this->lancamentoService->idsPendentesNoPeriodo(
            $filtro['data_inicio'],
            $filtro['data_fim'],
            isset($filtro['categoria_lancamento_id']) ? (int) $filtro['categoria_lancamento_id'] : null,
            $this->contexto->tenantId(),
            self::LIMITE_LOTE + 1,
        );

        if ($ids === []) {
            return $texto('Não há lançamentos pendentes nesse período'.(isset($filtro['categoria_lancamento_id']) ? ' e categoria' : '').'. Nada a conciliar.');
        }

        if (count($ids) > self::LIMITE_LOTE) {
            return $texto('Esse filtro tem mais de '.self::LIMITE_LOTE.' lançamentos pendentes. Restrinja o período ou informe uma categoria para conciliar até '.self::LIMITE_LOTE.' por vez.');
        }

        return ['ids' => $ids];
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
            // Data com hora (o início de um agendamento) mantém a hora; só data (meia-noite) segue como AAAA-MM-DD.
            $valores[$campo] = $atual instanceof \DateTimeInterface
                ? $atual->format($atual->format('H:i:s') === '00:00:00' ? 'Y-m-d' : 'Y-m-d H:i')
                : $atual;

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
    public function abrirFormulario(string $tool, ?int $id, ?int $tutorId = null): array
    {
        $definicao = $this->catalogo()[$tool] ?? null;

        // RF03: o lote só nasce de um pedido ao agente (o servidor resolve o filtro); não há formulário em branco dele.
        if ($definicao === null || $definicao['tipo'] !== 'escrita' || ($definicao['regras'] === [] && $definicao['resumo'] === [] && $definicao['resumir'] === null)
            || $tool === 'conciliar_lancamentos_em_lote') {
            return ['tipo' => 'erro', 'categoria' => 'unsupported_operation', 'codigo' => 'AI_UNSUPPORTED_OPERATION',
                'http_status' => 422, 'mensagem' => 'Esta operação não é suportada.'];
        }

        if (! $this->papelAtualPermite($definicao['papeis'])) {
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

        // 27/11: "Adicionar pet" na página Clientes abre o cadastro do pet já com o tutor escolhido (a lista de vínculo do card só mostra
        // registros do tenant, e o Revisar valida o vínculo de novo).
        if ($tutorId !== null && $tool === 'criar_pet' && $definicao['localizar'] === null) {
            $argumentos['tutor_id'] = $tutorId;
        }

        if ($definicao['preparar'] !== null) {
            $argumentos = ($definicao['preparar'])($argumentos, [], $this->contexto->tenantId());
        }

        return [
            'tipo' => 'confirmacao_pendente',
            'tool' => $tool,
            'risco' => $definicao['risco'],
            'segurar' => $this->exigeSegurar($tool, $argumentos),
            'segurar_quando' => $this->condicaoSegurar($tool),
            'passo_unico' => $this->confirmaEmUmPasso($tool),
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
    public function executarConfirmada(string $tool, array $argumentos, array $propostos, string $origem = 'agente', ?int $autorizadoPor = null, ?int $tempoIaMs = null, ?array $loteIds = null, ?string $conferencia = null): array
    {
        // RF03: os ids do lote vêm só da proposta guardada na sessão (o controller passa); nada do navegador.
        unset($argumentos['ids']);
        if ($tool === 'conciliar_lancamentos_em_lote' && $loteIds !== null) {
            $argumentos['ids'] = $loteIds;
        }

        return $this->detalhesAuditoria->com([
            'parametros_propostos' => $propostos,
            'origem' => $origem,
            'tempo_ia_ms' => $tempoIaMs,
            ...($autorizadoPor === null ? [] : ['autorizado_por' => $autorizadoPor]),
            // Adendo final (15/11): que conferência autorizou ("crmv+senha", "pin", "senha de admin"); vai na mensagem da auditoria.
            ...($autorizadoPor === null || $conferencia === null ? [] : ['conferencia' => $conferencia]),
        ], fn () => $this->executarTool($tool, $argumentos));
    }

    /**
     * Exportar (A3, RF02): reexecuta uma consulta guardada no servidor com as regras de AGORA —
     * mesma whitelist, RBAC (papel mínimo da tabela), tenant do usuário, colunas e máscara LGPD da
     * tabela na tela. Nega e audita como qualquer tool; o sucesso fica na auditoria como "exportar".
     *
     * @return array{tipo: 'exportacao', colunas: string[], linhas: array<int, array<string, mixed>>}|array<string, mixed>
     */
    public function exportar(string $tool, array $argumentos, string $origem = 'agente'): array
    {
        $definicao = $this->catalogo()[$tool] ?? null;

        if ($definicao === null || $definicao['tipo'] !== 'leitura' || $definicao['colunas'] === []) {
            return ['tipo' => 'erro', 'categoria' => 'unsupported_operation', 'codigo' => 'AI_UNSUPPORTED_OPERATION',
                'http_status' => 422, 'mensagem' => 'Esta tabela não pode ser exportada.'];
        }

        $definicao['acao'] = 'exportar';
        unset($argumentos['tenant_id']);

        return $this->detalhesAuditoria->com(['origem' => $origem], function () use ($tool, $definicao, $argumentos) {
            if (! $this->papelAtualPermite($definicao['papeis'])) {
                return $this->negar($tool, $definicao, $argumentos);
            }

            $argumentos['tenant_id'] = $this->contexto->tenantId();

            try {
                $linhas = collect(($definicao['executar'])($argumentos))
                    ->map(function ($linha) use ($definicao) {
                        $dados = AuditoriaService::mascararCamposSensiveis(Arr::only($linha instanceof Model ? $linha->toArray() : (array) $linha, $definicao['colunas']));

                        return array_map(fn (string $coluna) => $dados[$coluna] ?? null, array_combine($definicao['colunas'], $definicao['colunas']));
                    })->values()->all();

                // Sem auditoria, sem arquivo: se gravar o registro falhar, a exportação também falha.
                $this->auditar($tool, 'exportar', $definicao['entidade'], null, $argumentos, 'sucesso',
                    count($linhas).' linha(s) exportada(s) em CSV.', true);
            } catch (Throwable $e) {
                Log::error('AiAgentService: falha ao exportar', [
                    'tool' => $tool,
                    'excecao' => $e::class,
                    'local' => basename($e->getFile()).':'.$e->getLine(),
                    'correlation_id' => $correlacao = (string) Str::uuid(),
                    'categoria' => 'tool_error',
                ]);
                $this->auditar($tool, 'exportar', $definicao['entidade'], null, $argumentos, 'erro', 'Falha ao exportar.', true);

                return ['tipo' => 'erro', 'categoria' => 'tool_error', 'codigo' => 'AI_TOOL_ERROR', 'correlation_id' => $correlacao,
                    'http_status' => 500, 'mensagem' => 'Não consegui exportar esta tabela agora. Tente novamente.'];
            }

            return ['tipo' => 'exportacao', 'colunas' => $definicao['colunas'], 'linhas' => $linhas];
        });
    }

    /**
     * Modo manual (B2, RF05/RNF05): a mesma consulta da tool, SEM chamar a IA — para quando a
     * a IA está fora do ar ou o usuário prefere a tela. Só tool de leitura do catálogo; os
     * filtros são conferidos contra o esquema da própria tool (nenhuma chave a mais, enum e data
     * no formato) e a execução é o executarTool de sempre: RBAC, tenant, colunas, máscara LGPD e
     * auditoria (origem "manual").
     */
    public function consultarManual(string $tool, array $filtros, string $origem = 'manual'): array
    {
        $definicao = $this->catalogo()[$tool] ?? null;

        if ($definicao === null || $definicao['tipo'] !== 'leitura' || $definicao['colunas'] === []) {
            return ['tipo' => 'erro', 'categoria' => 'unsupported_operation', 'codigo' => 'AI_UNSUPPORTED_OPERATION',
                'http_status' => 422, 'mensagem' => 'Esta consulta não está disponível.'];
        }

        $propriedades = $definicao['definicao']['function']['parameters']['properties'];
        $regras = array_map(fn (array $p) => match (true) {
            isset($p['enum']) => ['nullable', 'string', Rule::in($p['enum'])],
            ($p['format'] ?? null) === 'date' => ['nullable', 'date_format:Y-m-d'],
            $p['type'] === 'integer' => ['nullable', 'integer', 'min:1'],
            default => ['nullable', 'string', 'max:100'],
        }, $propriedades);
        $validador = Validator::make($filtros, $regras);

        if (array_diff(array_keys($filtros), array_keys($propriedades)) !== [] || $validador->fails()) {
            return ['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_VALIDATION_ERROR',
                'http_status' => 422, 'mensagem' => 'Algum filtro está inválido. Confira e tente de novo.'];
        }

        $argumentos = array_filter($validador->validated(), fn ($valor) => $valor !== null && $valor !== '');

        return $this->detalhesAuditoria->com(['origem' => $origem], function () use ($tool, $argumentos) {
            $resposta = $this->executarTool($tool, $argumentos);

            // Igual ao agente (A3): o controller guarda a consulta no servidor e o front só recebe o id do Exportar.
            if (($resposta['tipo'] ?? null) === 'resultado_leitura') {
                $resposta['consulta'] = $argumentos;
            }

            return $resposta;
        });
    }

    /**
     * /agente/revisar (Bloco 2b): a mesma governança da execução — RBAC, tenant do
     * servidor e as regras da tool — sem gravar nada. Null quando a revisão passa;
     * senão a resposta de recusa. RBAC negado e falha de validação ficam na
     * auditoria (com o proposto da IA), como ficavam quando a validação era na confirmação.
     */
    public function revisar(string $tool, array $argumentos, array $propostos, string $origem = 'agente', ?int $tempoIaMs = null, ?array $loteIds = null): ?array
    {
        $definicao = $this->catalogo()[$tool] ?? null;

        if ($definicao === null || $definicao['tipo'] !== 'escrita') {
            return ['tipo' => 'erro', 'categoria' => 'unsupported_operation', 'codigo' => 'AI_UNSUPPORTED_OPERATION',
                'http_status' => 422, 'mensagem' => 'Esta operação não é suportada pelo agente.'];
        }

        return $this->detalhesAuditoria->com(['parametros_propostos' => $propostos, 'origem' => $origem, 'tempo_ia_ms' => $tempoIaMs], function () use ($tool, $definicao, $argumentos, $loteIds) {
            if (! $this->papelAtualPermite($definicao['papeis'])) {
                return $this->negar($tool, $definicao, $argumentos);
            }

            $argumentos['tenant_id'] = $this->contexto->tenantId();

            try {
                $this->validar($argumentos, $definicao['regras_revisar'] ?? $definicao['regras'], $definicao['mensagens']);

                $recusa = $definicao['conferir'] === null ? null : ($definicao['conferir'])($argumentos);
                if ($recusa !== null) {
                    throw new ToolRecusadaException($recusa['motivo'], [$recusa['campo'] => $recusa['motivo']]);
                }

                // RF03: revalida CADA item do lote (tenant, existe, ainda pendente); um falhou, o plano volta inteiro.
                if ($tool === 'conciliar_lancamentos_em_lote') {
                    if ($loteIds === null) {
                        throw new ToolRecusadaException('Não encontrei a lista do lote. Nada foi gravado. Peça de novo.');
                    }

                    $motivos = $this->lancamentoService->motivosParaNaoConciliarLote($loteIds,
                        $this->lancamentoService->doLote($loteIds, $argumentos['tenant_id']));

                    if ($motivos !== []) {
                        $frase = $this->fraseDoLote($motivos, 'Peça de novo para montar outro plano.');
                        $erros = ['_lote' => $frase];
                        foreach ($motivos as $id => $motivo) {
                            $erros["lote_{$id}"] = $motivo;
                        }

                        throw new ToolRecusadaException($frase, $erros);
                    }
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
     * Decisão B do Lucas (13/11): o Confirmar pede o gesto de segurar 2 s (só visual, sem trava no servidor) nas ações
     * destrutivas; as demais escritas confirmam com um clique. Destrutivas: risco 'exclusao' (excluir para a lixeira,
     * excluir de vez, cancelar cobrança, estornar), a conciliação em lote e cancelar um agendamento (pelo valor
     * REVISADO do novo status, que o humano pode ter mudado no card). Tool fora do catálogo cai em segurar.
     */
    public function exigeSegurar(string $tool, array $argumentos): bool
    {
        if ($this->risco($tool) === 'exclusao' || $tool === 'conciliar_lancamentos_em_lote') {
            return true;
        }

        $condicao = $this->condicaoSegurar($tool);

        return $condicao !== null && ($argumentos[$condicao['campo']] ?? null) === $condicao['valor'];
    }

    /**
     * 23/11 (Confirmar em um passo): o card já abre sabendo se vai pedir o segurar. Quando isso depende de um campo
     * que o humano pode mudar no card, a condição vai junto: { campo, valor } — segura quando o campo vale o valor.
     * null = sem condição (o "segurar" booleano da abertura vale sozinho). Única fonte da regra condicional.
     *
     * @return array{campo: string, valor: string}|null
     */
    public function condicaoSegurar(string $tool): ?array
    {
        return $tool === 'alterar_status_agendamento' ? ['campo' => 'novo_status', 'valor' => 'cancelado'] : null;
    }

    /**
     * 25/11: o card dessas tools não tem a etapa Revisar na tela: o Confirmar manda {proposta, valores} direto para
     * /agente/confirmar (AgenteController::confirmarEmUmPasso), que revisa e grava na mesma requisição; conflito (vaga, versão,
     * CRMV) volta como 422 com o formulário. Só a agenda; estorno, PIN, lote e exclusão seguem como estão.
     */
    public function confirmaEmUmPasso(string $tool): bool
    {
        return in_array($tool, ['agendar_atendimento', 'remarcar_agendamento', 'alterar_servico_agendamento', 'alterar_status_agendamento'], true);
    }

    /** Tools cujo Revisar devolve o card refeito com os avisos do que foi revisado (agendar/remarcar: quantos já ocupam o horário). */
    public function avisaNoRevisar(string $tool): bool
    {
        return in_array($tool, ['agendar_atendimento', 'remarcar_agendamento', 'alterar_servico_agendamento', 'alterar_status_agendamento'], true);
    }

    /**
     * Formulário do card (RF04) a partir das regras da tool, com os valores dados e,
     * se houver, a frase de erro de cada campo. Null para tool sem formulário.
     */
    public function formulario(string $tool, array $valores, array $erros = [], ?array $loteIds = null): ?array
    {
        $definicao = $this->catalogo()[$tool] ?? null;

        if ($definicao === null || ($definicao['regras'] === [] && $definicao['resumo'] === [] && $definicao['resumir'] === null)) {
            return null;
        }

        if ($tool === 'conciliar_lancamentos_em_lote') {
            return $this->planoDoLote($definicao, $valores, $loteIds ?? [], $erros);
        }

        // Decisão do Lucas (20/10): só o admin vê o salário no card, formatado em reais, para conferir o
        // que vai confirmar. Só nesta resposta ao front: log, histórico e auditoria continuam mascarados.
        $revelados = $this->contexto->ehAdministrativo() ? ['salario'] : [];

        $titulo = $definicao['titulo'].(isset($valores['id']) && $definicao['localizar'] !== null ? ' #'.$valores['id'] : '');

        $formulario = $this->formularios->montar($titulo, $definicao['regras'], $valores, $erros, self::ROTULOS_CAMPOS,
            $this->contexto->tenantId(), $revelados);

        // Bloco 4 (lixeira): o card mostra o registro, só para leitura, e pode pedir a senha de um admin.
        // A senha não é campo do formulário: não vai no Revisar nem fica na sessão (ver /agente/confirmar).
        if ($definicao['resumo'] !== []) {
            $formulario['resumo'] = $this->resumo($definicao, $valores);
        }
        if ($definicao['resumir'] !== null) {
            try {
                $resumido = ($definicao['resumir'])($valores, $this->contexto->tenantId());
                // Forma longa: ['resumo' => linhas, 'avisos' => frases]. Aviso não bloqueia: só aparece no card, em aviso.
                $formulario['resumo'] = array_key_exists('resumo', $resumido) ? $resumido['resumo'] : $resumido;
                if (($resumido['avisos'] ?? []) !== []) {
                    $formulario['avisos'] = array_values($resumido['avisos']);
                }
                // 15/11: a ficha de agendamento (agendar, remarcar, trocar o serviço): o front monta o molde com o calendário.
                if (isset($resumido['ficha'])) {
                    $formulario['ficha'] = $resumido['ficha'];
                }
                // T2: o editor de itens do estorno (quantidade por linha) substitui o campo "linhas" (que seria um texto).
                if (isset($resumido['itens_estorno'])) {
                    $formulario['itens_estorno'] = array_values($resumido['itens_estorno']);
                    $formulario['campos'] = array_values(array_filter($formulario['campos'], fn (array $c) => $c['nome'] !== 'linhas'));
                }
            } catch (ToolRecusadaException $e) {
                $formulario['resumo'] = [];
                $erros = ['_acao' => $e->getMessage(), ...$erros];
            }
        }
        if ($definicao['reautenticar']) {
            $formulario['reautenticar'] = true;
            if ($definicao['reautenticar'] === 'estorno') {
                $formulario['tipo_autorizacao'] = 'estorno'; // a tela troca o rótulo: supervisor (PIN) ou dono (senha)
            }
        } elseif ($definicao['exige_crmv'] !== null && filled($this->contexto->usuario()->crmv) && $this->exigeCrmv($tool, $valores)) {
            // 15/11: cirurgia e internação. O próprio usuário digita o CRMV e a senha (campos fora do formulário, como na lixeira).
            $formulario['reautenticar'] = true;
            $formulario['tipo_autorizacao'] = 'crmv';
        }

        // 27/11: cadastro unificado: "pets.*.x" vira a lista de pets do card (com o erro em cada pet), não campos soltos.
        if (array_key_exists('pets', $definicao['regras'])) {
            [$formulario, $erros] = $this->comListaDePets($formulario, $definicao['regras'], $valores, $erros);
        }

        // Recusa que não é de um campo do formulário (ex.: "existem lançamentos vinculados"): vai no topo do card.
        $avulsos = array_diff_key($erros, array_flip(array_column($formulario['campos'], 'nome')));
        if ($avulsos !== []) {
            $formulario['erro'] = reset($avulsos);
        }

        return $formulario;
    }

    /**
     * 27/11: troca os campos "pets" e "pets.*.x" do card por uma lista repetível (formulario.lista): o molde de cada pet (campos
     * com opções), os pets atuais e o erro de cada um. O erro de um pet sai de $erros (não vira aviso solto no topo).
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function comListaDePets(array $formulario, array $regras, array $valores, array $erros): array
    {
        $regrasDoPet = [];
        foreach ($regras as $nome => $regra) {
            if (str_starts_with($nome, 'pets.*.')) {
                $regrasDoPet[substr($nome, 7)] = $regra;
            }
        }
        $modelo = $this->formularios->montar('', $regrasDoPet, [], [], self::ROTULOS_CAMPOS, $this->contexto->tenantId())['campos'];
        $modelo = array_map(fn (array $c) => array_diff_key($c, ['valor' => 1, 'faltando' => 1]), $modelo);
        $formulario['campos'] = array_values(array_filter($formulario['campos'], fn (array $c) => ! str_starts_with($c['nome'], 'pets')));

        $itens = [];
        foreach (array_values(is_array($valores['pets'] ?? null) ? $valores['pets'] : []) as $i => $pet) {
            $itens[] = ['valores' => is_array($pet) ? array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $pet) : [], 'erros' => []];
        }
        $comErro = false;
        foreach ($erros as $chave => $frase) {
            if (preg_match('/^pets\.(\d+)\.(\w+)$/', (string) $chave, $m) === 1) {
                $itens[(int) $m[1]]['erros'][$m[2]] = $frase;
                $itens[(int) $m[1]]['valores'] ??= [];
                unset($erros[$chave]);
                $comErro = true;
            } elseif ($chave === 'pets' || str_starts_with((string) $chave, 'pets.')) {
                unset($erros[$chave]);
                $erros['_pets'] = $frase;
            }
        }
        if ($comErro) {
            $erros['_pets'] = 'Confira os dados dos pets destacados. Nada foi gravado.';
        }
        $formulario['lista'] = ['campo' => 'pets', 'rotulo' => 'Pets', 'max' => \App\Http\Requests\ClienteComPetsRequest::MAX_PETS, 'modelo' => $modelo, 'itens' => $itens];

        return [$formulario, $erros];
    }

    /**
     * RF03: o card do lote. Nenhum campo editável: o filtro aparece só para leitura e a lista
     * sai do BANCO a partir dos ids da sessão (nada da IA entra nela). Item com motivo de
     * recusa (Revisar) volta marcado; item que sumiu aparece só com o número.
     *
     * @param  int[]  $ids
     */
    private function planoDoLote(array $definicao, array $filtro, array $ids, array $erros): array
    {
        $lancamentos = $this->lancamentoService->doLote($ids, $this->contexto->tenantId());
        $reais = fn ($valor) => 'R$ '.number_format((float) $valor, 2, ',', '.');
        $categoria = isset($filtro['categoria_lancamento_id'])
            ? CategoriaLancamento::query()->where('tenant_id', $this->contexto->tenantId())->find($filtro['categoria_lancamento_id'])
            : null;

        // AAAA-MM-DD (ou data do Eloquent) -> DD/MM/AAAA.
        $formatar = fn ($data) => $data instanceof \DateTimeInterface ? $data->format('d/m/Y')
            : (is_string($data) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $data, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : '—');

        $itens = array_map(function (int $id) use ($lancamentos, $erros, $reais, $formatar) {
            $lancamento = $lancamentos->get($id);

            return [
                'id' => $id,
                'descricao' => $lancamento?->descricao ?? '—',
                'valor' => $lancamento === null ? '—' : $reais($lancamento->valor),
                'data' => $formatar($lancamento?->data),
                'antes' => $lancamento?->status ?? '—',
                'depois' => 'conciliado',
                'erro' => $erros["lote_{$id}"] ?? null,
            ];
        }, $ids);

        return [
            'titulo' => $definicao['titulo'],
            'campos' => [],
            'resumo' => [
                ['rotulo' => 'Período', 'valor' => $formatar($filtro['data_inicio'] ?? '').' a '.$formatar($filtro['data_fim'] ?? '')],
                ['rotulo' => 'Categoria', 'valor' => isset($filtro['categoria_lancamento_id']) ? ($categoria?->nome ?? '#'.$filtro['categoria_lancamento_id']) : 'Todas'],
                ['rotulo' => 'Lançamentos', 'valor' => (string) count($ids)],
                ['rotulo' => 'Valor total', 'valor' => $reais($lancamentos->sum('valor'))],
            ],
            'itens' => $itens,
            // A frase do topo vem do Revisar ("_lote": quantos itens mudaram); cada item traz a sua.
            ...($erros === [] ? [] : ['erro' => $erros['_lote'] ?? reset($erros)]),
        ];
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
        // RF03: o lote não tem campo editável; o que o navegador mandar é ignorado (vale o da sessão).
        if ($tool === 'conciliar_lancamentos_em_lote') {
            return $proposta;
        }

        $regras = $this->catalogo()[$tool]['regras'] ?? [];

        foreach (array_keys(FormularioTool::campos($regras)) as $campo) {
            if (! array_key_exists($campo, $valores)) {
                continue;
            }

            $vazio = $valores[$campo] === null || $valores[$campo] === '';

            $sensivel = in_array($campo, AuditoriaService::camposSensiveis(), true);

            // Vazio ou a própria máscara que o card mostrou: "manter" o valor da proposta.
            if ($sensivel && ($vazio || \App\Support\Mascara::mascarado($valores[$campo]))) {
                continue;
            }

            // Dinheiro: o card trabalha em reais ("80,00"); a proposta e as regras, em centavos inteiros. Texto que não
            // é dinheiro continua como veio, e a validação responde com a frase do campo.
            $novo = $vazio ? null : $valores[$campo];
            if (is_string($novo) && str_ends_with($campo, '_centavos')) {
                $novo = \App\Support\Dinheiro::paraCentavos($novo) ?? $novo;
            }

            $proposta[$campo] = $novo;
        }

        return $proposta;
    }

    /**
     * "Cliente cadastrado. Ficou sem e-mail e telefone." / "Cliente cadastrado com todos os dados."
     * Tool sem campo opcional: só "Status do lançamento atualizado.".
     */
    /**
     * Resposta só de texto que AFIRMA uma gravação concluída ("Agendamento remarcado.", "Feito, cancelei.", "O cadastro foi
     * atualizado."). Conservadora de propósito: só frases CURTAS cujo núcleo é o verbo de gravação no particípio ou na 1ª pessoa,
     * para não pegar explicações legítimas (que citam o histórico, fazem perguntas ou descrevem uma proposta).
     */
    public static function afirmaGravacaoSemTool(string $texto): bool
    {
        $t = \Illuminate\Support\Str::ascii(mb_strtolower(trim($texto)));
        if ($t === '' || mb_strlen($t) > 90 || str_contains($t, '?') || str_contains($t, 'proposta') || str_contains($t, 'confirm')
            || count(preg_split('/\s+/', $t, -1, PREG_SPLIT_NO_EMPTY) ?: []) > 8) {
            return false;
        }
        // Só particípios e 1ª pessoa do passado (nunca o radical do infinitivo: "Para excluir, use a lixeira." é legítimo).
        $verbos = 'remarcad|agendad|cancelad|gravad|registrad|cadastrad|atualizad|alterad|excluid|conciliad|estornad|salvei|remarquei|agendei|cancelei|gravei|registrei|cadastrei|atualizei|alterei|estornei|conciliei';

        // "agendamento remarcado.", "pronto, remarquei.", "feito: o pet foi cadastrado!" (sem negação)
        return preg_match('/\b('.$verbos.')\w*\b/', $t) === 1 && preg_match('/\b(nao|nenhum|nada|ainda|precisa|preciso|posso|consigo|quer|deseja|esta|estao|era|sera|voce|se|caso)\b/', $t) !== 1;
    }

    /** "Qualquer profissional" (agenda): diz quem o servidor escolheu. Vazio nos demais casos. */
    private function fraseDoProfissionalEscolhido(string $tool, mixed $resultado, array $argumentos): string
    {
        $id = $this->profissionalEscolhidoPeloServidor($tool, $resultado, $argumentos);
        if ($id === null) {
            return '';
        }
        $nome = \App\Models\User::query()->where('tenant_id', $this->contexto->tenantId())->whereKey($id)->value('name');

        return $nome === null ? '' : ' Profissional escolhido: '.$nome.'.';
    }

    /**
     * "Qualquer profissional" (25/11): o id do profissional QUE O SERVIDOR escolheu, ou null. Só nas tools de agenda que recebem o
     * profissional (agendar, remarcar, trocar o serviço), com o profissional em branco. No agendar é sempre escolha dele; no
     * remarcar e na troca de serviço só conta se o profissional MUDOU (mantendo o atual, ninguém foi escolhido: nada a registrar).
     */
    private function profissionalEscolhidoPeloServidor(string $tool, mixed $resultado, array $argumentos): ?int
    {
        if (! $resultado instanceof \App\Models\Agendamento || ! blank($argumentos['profissional_id'] ?? null)) {
            return null;
        }
        if ($tool === 'agendar_atendimento' || (in_array($tool, ['remarcar_agendamento', 'alterar_servico_agendamento'], true) && $resultado->wasChanged('profissional_id'))) {
            return (int) $resultado->profissional_id;
        }

        return null;
    }

    private function fraseDeSucesso(array $definicao, array $argumentos): string
    {
        // O profissional em branco da agenda é "qualquer" (o servidor escolhe), não um dado que ficou faltando.
        $opcionais = array_values(array_diff(FormularioTool::opcionais($definicao['regras'], $argumentos), ['profissional_id']));
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
    /** Título legível da tool (o mesmo dos cards), para telas de leitura que mostram ações sem expor o identificador interno. */
    public function tituloDaTool(string $tool): ?string
    {
        $titulo = $this->catalogo()[$tool]['titulo'] ?? null;

        return is_string($titulo) && $titulo !== '' ? $titulo : null;
    }

    private function catalogo(): array
    {
        // O catálogo é o do domínio do tenant do usuário logado (config sentinel.tenants_clinica). Tenant fora da
        // lista nunca vê as tools da clínica, e o tenant da clínica não vê as de contabilidade.
        $dominio = $this->contexto->dominio();

        // Revisão de 15/11 (C1): a descrição da venda leva o catálogo DO TENANT, então o cache é por domínio e tenant.
        return $this->catalogos[$dominio.':'.$this->contexto->tenantId()] ??= array_column($dominio === 'clinica' ? $this->toolsDaClinica() : $this->toolsDeContabilidade(), null, 'nome');
    }

    /** @return array<int, array<string, mixed>> as tools do domínio antigo (contabilidade) */
    private function toolsDeContabilidade(): array
    {
        return [
            $this->definirTool(
                nome: 'consultar_lancamentos',
                descricao: 'Consulta lançamentos financeiros, opcionalmente filtrando por status e período. Ação de leitura — não exige confirmação.',
                tipo: 'leitura',
                risco: 'leitura',
                acao: 'consultar',
                entidade: 'Lancamento',
                papeis: self::PAPEIS_LEITURA,
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
                papeis: self::PAPEIS_OPERADOR,
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
                papeis: self::PAPEIS_OPERADOR,
                propriedades: [
                    'id' => ['type' => 'integer', 'description' => 'Id do lançamento a atualizar.'],
                    ...self::PROPRIEDADE_POSICAO,
                    'novo_status' => ['type' => 'string', 'enum' => ['pendente', 'conciliado']],
                ],
                // P-C1: o id pode vir da posição na última tabela; sem nenhum dos dois, o Revisar pede o lançamento.
                obrigatorios: ['novo_status'],
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
                nome: 'conciliar_lancamentos_em_lote',
                descricao: 'Concilia de uma vez os lançamentos PENDENTES de um período (e, opcionalmente, de uma categoria): pendente -> conciliado. Até '.self::LIMITE_LOTE.' lançamentos por vez. Ação de escrita — exige confirmação humana antes de ser executada.',
                tipo: 'escrita',
                risco: 'escrita',
                acao: 'atualizar',
                entidade: 'Lancamento',
                papeis: self::PAPEIS_OPERADOR,
                propriedades: [
                    'data_inicio' => ['type' => 'string', 'format' => 'date'],
                    'data_fim' => ['type' => 'string', 'format' => 'date'],
                    'categoria_lancamento_id' => ['type' => 'integer', 'description' => 'Id da categoria (opcional).'],
                ],
                obrigatorios: ['data_inicio', 'data_fim'],
                executar: fn (array $a) => $this->conciliarLancamentosEmLote($a),
                mensagemErro: 'Não consegui conciliar os lançamentos. Nada foi gravado.',
                regras: self::REGRAS_LOTE_LANCAMENTOS,
                mensagens: self::MENSAGENS_LOTE_LANCAMENTOS,
                titulo: 'Conciliar lançamentos em lote',
                sucesso: 'Lançamentos conciliados',
            ),
            $this->definirTool(
                nome: 'consultar_clientes',
                descricao: 'Consulta clientes cadastrados, opcionalmente filtrando por parte do nome. Ação de leitura — não exige confirmação.',
                tipo: 'leitura',
                risco: 'leitura',
                acao: 'consultar',
                entidade: 'Cliente',
                papeis: self::PAPEIS_LEITURA,
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
                papeis: self::PAPEIS_OPERADOR,
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
                papeis: self::PAPEIS_LEITURA,
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
                papeis: self::PAPEIS_OPERADOR,
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
                papeis: self::PAPEIS_LEITURA,
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
                papeis: self::PAPEIS_ADMIN,
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
                papeis: self::PAPEIS_LEITURA,
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
                papeis: self::PAPEIS_OPERADOR,
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
                papeis: self::PAPEIS_LEITURA,
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
                papeis: self::PAPEIS_OPERADOR,
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
                papeis: self::PAPEIS_OPERADOR,
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
                papeis: self::PAPEIS_OPERADOR,
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
                papeis: self::PAPEIS_ADMIN,
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
                papeis: self::PAPEIS_OPERADOR,
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
                papeis: self::PAPEIS_OPERADOR,
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
            ...$this->definirLixeira($this->lixeiras()),
        ];
    }

    /**
     * Bloco 4 — as entidades com lixeira (RF08–RF12). O vínculo tool ↔ entidade ↔ service
     * da lixeira fica todo aqui; trocar de domínio é trocar estas linhas. "colunas": o que o
     * card mostra do registro para o humano conferir (as da consulta, sem o id, que vai no
     * título). "papeis": quem pode restaurar — os mesmos de criar/alterar a entidade.
     *
     * @return array<string, array<string, mixed>>
     */
    private function lixeiras(): array
    {
        return [
            'lancamento' => ['entidade' => 'Lancamento', 'modelo' => Lancamento::class, 'rotulo' => 'o lançamento', 'servico' => $this->lancamentoService,
                'busca_por' => 'descrição', 'coluna_rotulo' => 'descricao', 'colunas' => ['descricao', 'valor', 'status', 'data'], 'papeis' => self::PAPEIS_OPERADOR],
            'cliente' => ['entidade' => 'Cliente', 'modelo' => Cliente::class, 'rotulo' => 'o cliente', 'servico' => $this->clienteService,
                'busca_por' => 'nome', 'coluna_rotulo' => 'nome', 'colunas' => ['nome', 'documento', 'email', 'telefone'], 'papeis' => self::PAPEIS_OPERADOR],
            'fornecedor' => ['entidade' => 'Fornecedor', 'modelo' => Fornecedor::class, 'rotulo' => 'o fornecedor', 'servico' => $this->fornecedorService,
                'busca_por' => 'nome', 'coluna_rotulo' => 'nome', 'colunas' => ['nome', 'documento', 'email', 'telefone'], 'papeis' => self::PAPEIS_OPERADOR],
            'funcionario' => ['entidade' => 'Funcionario', 'modelo' => Funcionario::class, 'rotulo' => 'o funcionário', 'servico' => $this->funcionarioService,
                'busca_por' => 'nome', 'coluna_rotulo' => 'nome', 'colunas' => ['nome', 'cargo', 'data_admissao', 'data_demissao'], 'papeis' => self::PAPEIS_ADMIN],
            'nota_fiscal' => ['entidade' => 'NotaFiscal', 'modelo' => NotaFiscal::class, 'rotulo' => 'a nota fiscal', 'servico' => $this->notaFiscalService,
                'busca_por' => 'número', 'coluna_rotulo' => 'numero', 'colunas' => ['numero', 'tipo', 'valor', 'data_emissao'], 'papeis' => self::PAPEIS_OPERADOR],
            'categoria_lancamento' => ['entidade' => 'CategoriaLancamento', 'modelo' => CategoriaLancamento::class, 'rotulo' => 'a categoria', 'servico' => $this->categoriaLancamentoService,
                'busca_por' => 'nome', 'coluna_rotulo' => 'nome', 'colunas' => ['nome', 'tipo'], 'papeis' => self::PAPEIS_OPERADOR],
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
    private function definirLixeira(array $lixeiras): array
    {
        $tools = [$this->definirTool(
            nome: 'consultar_lixeira',
            descricao: 'Lista o que está na lixeira (excluído, ainda dá para restaurar) e o que já foi arquivado, opcionalmente por tipo de registro e por situação. Ação de leitura — não exige confirmação.',
            tipo: 'leitura',
            risco: 'leitura',
            acao: 'consultar',
            entidade: 'Lixeira',
            // DECISÃO PENDENTE (Lucas): papel mínimo da lixeira. Padrão do plano aprovado: operador (quem pede para restaurar).
            papeis: self::PAPEIS_OPERADOR,
            propriedades: [
                'tipo' => ['type' => 'string', 'enum' => array_keys($lixeiras)],
                'situacao' => ['type' => 'string', 'enum' => ['lixeira', 'arquivado']],
                'nome' => ['type' => 'string', 'description' => 'Parte do nome (ou da descrição/número) do registro, quando o pedido cita um específico (ex.: "Fábio Rocha"). Sem isso, lista tudo.'],
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
                // [tool, risco, ação, papéis, situação em que o registro está, onde, motivo (só leitura), operação, título, sucesso, botão, feito, descrição]
                ["excluir_{$sufixo}", 'exclusao', 'excluir', self::PAPEIS_ADMIN, 'ativo', '', 'motivoParaNaoExcluir',
                    fn (int $id, int $t) => $servico->excluir($id, $t),
                    "Excluir {$nome}", Str::ucfirst($nome)." movid{$o} para a lixeira", 'Excluir', "excluíd{$o}",
                    "Move {$rotulo} para a lixeira (exclusão segura: dá para restaurar dentro do prazo), achado pelo id ou por {$buscaPor}. Não exclui se houver registros vinculados. Ação de exclusão — exige confirmação humana antes de ser executada."],
                ["restaurar_{$sufixo}", 'escrita', 'restaurar', $lixeira['papeis'], 'lixeira', ' na lixeira', 'motivoParaNaoRestaurar',
                    fn (int $id, int $t) => $servico->restaurarDaLixeira($id, $t, $this->autorizadoPor() !== null),
                    "Restaurar {$nome}", Str::ucfirst($nome)." restaurad{$o} da lixeira", 'Restaurar', "restaurad{$o}",
                    "Restaura {$rotulo} que está na lixeira, achado pelo id ou por {$buscaPor}. Exige a senha de um administrador, digitada no card de confirmação (nunca no chat). Ação de escrita — exige confirmação humana antes de ser executada."],
                ["excluir_definitivo_{$sufixo}", 'exclusao', 'excluir_definitivo', self::PAPEIS_ADMIN, 'arquivado', ' entre os arquivados', 'motivoParaNaoExcluirDeVez',
                    fn (int $id, int $t) => $servico->excluirDefinitivo($id, $t, $this->autorizadoPor()),
                    "Excluir de vez: {$nome}", Str::ucfirst($nome)." excluíd{$o} de vez", 'Excluir de vez', "excluíd{$o} de vez",
                    "Exclui de vez, sem volta, {$rotulo} que já foi arquivado (passou do prazo da lixeira), achado pelo id ou por {$buscaPor}. Só administrador; exige a senha de um administrador, digitada no card de confirmação (nunca no chat). Ação de exclusão — exige confirmação humana antes de ser executada."],
            ] as [$tool, $risco, $acao, $papeis, $situacao, $onde, $motivo, $operacao, $titulo, $sucesso, $botao, $feito, $descricao]) {
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
                    // excluir de vez só admin; restaurar com o "papeis" da lixeira de cada domínio + senha de admin
                    // (contabilidade: o papel de criar/alterar a entidade; clínica: só o dono, item C de 12/11).
                    papeis: $papeis,
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
        $nome = is_string($filtros['nome'] ?? null) ? trim($filtros['nome']) : '';

        // Filtro fora da lista (a IA não segue o enum à risca): nada, como numa consulta com filtro que não casa.
        if (($tipo !== null && ! array_key_exists($tipo, $lixeiras)) || ! in_array($situacao, [null, 'lixeira', 'arquivado'], true)) {
            return [];
        }

        $dias = (int) config('sentinel.retencao_lixeira_dias');
        $itens = [];

        foreach ($tipo === null ? $lixeiras : [$tipo => $lixeiras[$tipo]] as $sufixo => $lixeira) {
            $registros = ($situacao === null ? $lixeira['modelo']::onlyTrashed() : $lixeira['modelo']::query()->naSituacao($situacao))
                ->where('tenant_id', (int) $filtros['tenant_id'])
                // 23/11: "a lixeira do Fábio Rocha" filtra pelo nome (coluna fixa da entidade; %, _ e ! escapados, como nos services).
                ->when($nome !== '', fn ($consulta) => $consulta->whereRaw($lixeira['coluna_rotulo']." like ? escape '!'",
                    ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $nome).'%']))
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

                $regraExists = Rule::exists($tabela, $coluna)->where('tenant_id', $tenantId);

                // Só as tabelas com lixeira têm deleted_at (agendamentos, cobranças, vendas, prontuários... não têm).
                return Schema::hasColumn($tabela, 'deleted_at') ? $regraExists->whereNull('deleted_at') : $regraExists;
            }, explode('|', $regra));
        }, $regras);
    }

    /**
     * Cancelar agendamentos em lote (25/11): grava SÓ os ids que o usuário marcou na lista (o controller já conferiu que eles
     * estavam na última tabela mostrada). TUDO OU NADA, numa transação: cada item passa pelo MESMO executor de
     * alterar_status_agendamento (papel, setor, tenant, CRMV da cirurgia, cobrança, prontuário, status), com uma linha de
     * auditoria por item e o mesmo lote_id. Se qualquer item for recusado, nada é gravado, uma linha "recusado" guarda os ids e os
     * motivos, e a resposta diz qual item barrou (o usuário desmarca e tenta de novo).
     *
     * @param  int[]  $ids
     * @param  array<int, string>  $versoes  o updated_at de cada agendamento COMO O USUÁRIO O VIU na lista; o que mudou depois é recusado
     *                                       (sem a versão de um id, a recusa vale: falha fechada)
     * @return array<string, mixed>
     */
    public function cancelarAgendamentosEmLote(array $ids, array $versoes = []): array
    {
        // Travas SEMPRE em ordem de id: dois lotes que se cruzam não se esperam em círculo (impasse).
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $tool = 'cancelar_agendamentos_em_lote';
        $definicao = $this->catalogo()[$tool] ?? null;
        $tenantId = $this->contexto->tenantId();

        if ($definicao === null || ! $this->podeExecutar('alterar_status_agendamento')) {
            return $this->negar($tool, $definicao ?? ['acao' => 'consultar', 'entidade' => 'Agendamento'], ['ids' => $ids]);
        }

        $loteId = (string) Str::uuid();
        $erros = [];

        try {
            DB::transaction(function () use ($ids, $versoes, $tenantId, $loteId, &$erros) {
                foreach ($ids as $id) {
                    $agendamento = \App\Models\Agendamento::query()->where('tenant_id', $tenantId)->lockForUpdate()->find($id);
                    if ($agendamento === null) {
                        $erros[$id] = 'Não encontrei este agendamento.';

                        continue;
                    }

                    // A versão é a de quando o usuário VIU a linha (não a de agora): remarcado ou mudado por outra pessoa depois de
                    // marcar, o item é recusado em vez de cancelar o que ele não viu.
                    $resposta = $this->detalhesAuditoria->com(['lote_id' => $loteId], fn () => $this->executarConfirmada('alterar_status_agendamento',
                        ['id' => $id, 'novo_status' => 'cancelado', 'versao' => (string) ($versoes[$id] ?? '')], []));

                    if (($resposta['tipo'] ?? null) !== 'resultado_escrita') {
                        // Erro TÉCNICO (5xx): para tudo aqui (desfaz a transação), em vez de seguir gravando os próximos itens.
                        if ((int) ($resposta['http_status'] ?? 0) >= 500) {
                            throw new \RuntimeException('falha técnica no lote');
                        }
                        $erros[$id] = (string) ($resposta['mensagem'] ?? 'Não foi possível cancelar este agendamento.');
                    }
                }

                if ($erros !== []) {
                    throw new ToolRecusadaException('lote recusado');
                }
            });
        } catch (ToolRecusadaException) {
            // Tudo desfeito (inclusive as linhas de auditoria dos itens): fica UMA linha, com os ids e o motivo de cada recusa.
            $this->detalhesAuditoria->com(['lote_id' => $loteId], fn () => $this->auditar($tool, 'atualizar', 'Agendamento', null,
                ['ids' => $ids, 'motivos' => $erros], 'recusado', 'Lote recusado: nada foi cancelado.', true));

            return ['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_BATCH_REFUSED', 'http_status' => 422,
                'mensagem' => 'Não cancelei nada: '.(count($erros) === 1 ? 'um dos agendamentos não pode ser cancelado' : count($erros).' dos agendamentos não podem ser cancelados')
                    .'. O motivo está na linha. Desmarque '.(count($erros) === 1 ? 'esse' : 'esses').' e tente de novo.',
                'erros' => $erros];
        } catch (Throwable $e) {
            // Falha técnica: nada foi gravado (transação desfeita). Fica UMA linha de auditoria do que foi tentado (RF06/RF07).
            try {
                $this->detalhesAuditoria->com(['lote_id' => $loteId], fn () => $this->auditar($tool, 'atualizar', 'Agendamento', null,
                    ['ids' => $ids], 'erro', 'Falha técnica no lote: nada foi cancelado.', true));
            } catch (Throwable) {
                // a auditoria não derruba a resposta ao usuário
            }

            return $this->erroTecnicoTool($e);
        }

        $n = count($ids);

        return ['tipo' => 'resultado_escrita', 'tool' => $tool, 'lote_id' => $loteId, 'cancelados' => $ids,
            'mensagem' => $n === 1 ? 'Pronto, cancelei o agendamento que você marcou.' : 'Pronto, cancelei os '.$n.' agendamentos que você marcou.'];
    }

    /**
     * Executor de conciliar_lancamentos_em_lote (RF03). Roda dentro da transação do executarTool():
     * concilia exatamente os ids congelados no rascunho (nunca refaz o filtro), com os registros
     * travados e reconferidos; se algum mudou desde o Revisar, recusa e nada grava. Uma linha de
     * auditoria por item, todas com o mesmo lote_id; a auditoria geral do executarTool() não roda.
     *
     * @return array{lote_id: string, conciliados: int}
     */
    private function conciliarLancamentosEmLote(array $argumentos): array
    {
        $filtro = $this->validar($argumentos, self::REGRAS_LOTE_LANCAMENTOS, self::MENSAGENS_LOTE_LANCAMENTOS);
        $ids = $argumentos['ids'] ?? null;

        if (! is_array($ids) || $ids === [] || count($ids) > self::LIMITE_LOTE) {
            throw new ToolRecusadaException('Não encontrei a lista do lote. Nada foi gravado. Peça de novo.');
        }

        $resultado = $this->lancamentoService->conciliarLote($ids, $argumentos['tenant_id']);

        if ($resultado['motivos'] !== []) {
            $erros = [];
            foreach ($resultado['motivos'] as $id => $motivo) {
                $erros["lote_{$id}"] = $motivo;
            }

            throw new ToolRecusadaException($this->fraseDoLote($resultado['motivos'], 'Nada foi gravado.'), $erros);
        }

        $loteId = (string) Str::uuid();

        foreach ($resultado['conciliados'] as $id) {
            $this->detalhesAuditoria->com(['lote_id' => $loteId, 'valores_anteriores' => ['status' => 'pendente']], fn () => $this->auditar(
                'conciliar_lancamentos_em_lote', 'atualizar', 'Lancamento', $id,
                [...$filtro, 'id' => $id, 'novo_status' => 'conciliado'], 'sucesso', null, true,
            ));
        }

        return ['lote_id' => $loteId, 'conciliados' => count($resultado['conciliados'])];
    }

    /** RF03: frase do topo do card quando itens do lote não podem ser conciliados. */
    private function fraseDoLote(array $motivos, string $final): string
    {
        $inicio = count($motivos) === 1
            ? reset($motivos)
            : count($motivos).' lançamentos do lote mudaram (ex.: '.reset($motivos).')';

        return rtrim($inicio, '.').'. O lote não foi conciliado. '.$final;
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

        // A2: o service só muda o lançamento cujo status conferido era o OUTRO valor, então num sucesso o
        // "antes" é exatamente esse outro — o mesmo que ele conferiu, sem uma segunda leitura que divergisse.
        $this->detalhesAuditoria->anotar('valores_anteriores', ['status' => $dados['novo_status'] === 'conciliado' ? 'pendente' : 'conciliado']);

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
        array $papeis,
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
            papeis: $papeis,
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
        array $papeis,
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
        ?Closure $resumir = null, // 15/11: o que o card mostra só para leitura (ex.: os pets do tutor, o tutor do pet)
    ): array {
        $regras = (new $formRequest)->rules();

        return $this->definirTool(
            nome: $nome,
            descricao: $descricao,
            tipo: 'escrita',
            risco: 'escrita',
            acao: 'atualizar',
            entidade: $entidade,
            papeis: $papeis,
            propriedades: [
                'id' => ['type' => 'integer', 'description' => 'Número (id) do registro a alterar, se o usuário informou.'],
                'busca' => ['type' => 'string', 'description' => Str::ucfirst($buscaPor).' do registro a alterar, quando o número não foi informado.'],
                ...self::PROPRIEDADE_POSICAO,
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
                    $registro = $atualizar($id, $dados, (int) $a['tenant_id']);
                    // A2: o "antes" só dos campos que mudaram (o update() do model guarda em getPrevious()).
                    $this->detalhesAuditoria->anotar('valores_anteriores', Arr::except($registro->getPrevious(), ['updated_at']));

                    return $registro;
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
            resumir: $resumir,
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
        array $papeis,
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
        bool|string $reautenticar = false, // true = senha de admin (lixeira); 'estorno' = PIN do supervisor ou do dono (H, T1)
        string $feito = 'alterado',
        string $onde = '',
        string $rotuloAcao = '',
        ?Closure $resumir = null,
        ?array $regrasRevisar = null,
        ?array $colunasLista = null,
        ?Closure $preparar = null,
        ?Closure $exigeCrmv = null,
    ): array {
        // "tipo" decide se há confirmação humana (RNF02); "risco" só pinta o card. Os dois precisam concordar:
        // leitura <-> risco leitura; escrita <-> risco escrita ou exclusao. Combinação incoerente quebra cedo.
        if (! in_array($tipo, ['leitura', 'escrita'], true)
            || ! in_array($risco, ['leitura', 'escrita', 'exclusao'], true)
            || ($risco !== 'leitura') !== ($tipo === 'escrita')) {
            throw new InvalidArgumentException("Tool {$nome}: tipo '{$tipo}' e risco '{$risco}' incoerentes.");
        }

        // RBAC por lista: vazia ou com papel fora de config('sentinel.papeis') quebra cedo — um erro de
        // digitação ("recepção") nunca vira, calado, uma tool que ninguém usa ou que alguém usa sem querer.
        $desconhecidos = array_diff($papeis, (array) config('sentinel.papeis', []));
        if ($papeis === [] || $desconhecidos !== [] || ! array_is_list($papeis)) {
            throw new InvalidArgumentException("Tool {$nome}: lista de papéis vazia ou com papel desconhecido (".implode(', ', $desconhecidos).').');
        }

        return [
            'nome' => $nome,
            'tipo' => $tipo,
            // Classe de risco pelo EFEITO da tool (leitura | escrita | exclusao): a interface usa na borda do card.
            'risco' => $risco,
            'acao' => $acao,
            'entidade' => $entidade,
            'papeis' => $papeis,
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
            'resumir' => $resumir, // ação (definirAcao): o que vai acontecer, só leitura no card (linhas, total, pet...)
            // ação (definirAcao): o Revisar valida estas regras (todas as do Form Request), não só as do card; assim nada
            // inválido (ex.: 21 itens, atendimento "abc") passa pelo Revisar para quebrar só depois do segurar.
            'regras_revisar' => $regrasRevisar,
            // 15/11 (agenda): colunas da lista de escolha quando há vários candidatos (null = id + coluna_rotulo).
            'colunas_lista' => $colunasLista,
            // 15/11 (agenda): fn(array $argumentos, array $propostosPelaIa, int $tenantId): array, depois do localizar,
            // na proposta pelo agente e na ficha aberta sem IA. Só ajusta valores do formulário (ex.: limpa o profissional
            // quando o serviço novo é de outro setor); RBAC, tenant e regras continuam no Revisar e na execução.
            'preparar' => $preparar,
            // 15/11 (cirurgia e internação): fn(array $valores, int $tenantId): bool. Se true, o card pede CRMV + senha do
            // próprio usuário e o /agente/confirmar confere antes de executar (a regra é conferida de novo no service).
            'exige_crmv' => $exigeCrmv,
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
     * RBAC por lista: o papel atual (do usuário logado, via ContextoUsuario) precisa
     * estar em config('sentinel.papeis') E na lista da tool. Papel desconhecido nega
     * tudo (falha fechada), mesmo que alguém o escreva numa lista.
     *
     * @param  string[]  $papeis
     */
    private function papelAtualPermite(array $papeis): bool
    {
        $atual = $this->contexto->papel();

        // T1 (resposta F, 1ª passada do reviewer): o Administrador da clínica só gerencia PINs. Nenhuma tool vale para
        // ele, nem as da lixeira que o domínio antigo dá ao 'admin' (consultar, excluir, excluir de vez): o RBAC de
        // todas as rotas /agente/*, da tela /lixeira e do menu passa por aqui.
        if ($this->contexto->ehAdminDaClinica()) {
            return false;
        }

        return in_array($atual, (array) config('sentinel.papeis', []), true) && in_array($atual, $papeis, true);
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
            'Papel '.$this->contexto->papel().' sem permissão para esta ação.',
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
    public function auditarDesfecho(string $tool, array $argumentosPropostos, string $resultado, string $mensagem, string $origem = 'agente', bool $permitido = true, ?int $entidadeId = null, ?int $tempoIaMs = null): void
    {
        $definicao = $this->catalogo()[$tool] ?? null;

        try {
            // Tool que saiu do catálogo com a proposta na sessão: sem esquema, o proposto não é gravado.
            $this->detalhesAuditoria->com(['parametros_propostos' => $definicao === null ? null : $argumentosPropostos, 'origem' => $origem, 'tempo_ia_ms' => $tempoIaMs], fn () => $this->auditar(
                $tool,
                $definicao['acao'] ?? 'desconhecida',
                $definicao['entidade'] ?? null,
                $entidadeId,
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

    /**
     * Uma propriedade "lista de objetos" do esquema (ex.: itens) recebeu algum objeto com chave fora das propriedades
     * declaradas dele. Só olha o que o esquema declara como lista de objetos; o resto é validado pelas regras da tool.
     *
     * @param  array<string, mixed>  $esquema  as propriedades do topo da tool
     */
    private function aninhadoForaDoEsquema(mixed $argumentos, array $esquema): bool
    {
        if (! is_array($argumentos)) {
            return false;
        }

        foreach ($esquema as $nome => $propriedade) {
            $permitidas = $propriedade['items']['properties'] ?? null;
            if (($propriedade['type'] ?? null) !== 'array' || ! is_array($permitidas) || ! is_array($argumentos[$nome] ?? null)) {
                continue;
            }

            foreach ($argumentos[$nome] as $elemento) {
                if (is_array($elemento) && array_diff(array_keys($elemento), array_keys($permitidas)) !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Nome fora do formato de identificador (onde caberia um CPF ou e-mail) é redigido. */
    private function identificador(mixed $nome, string $redigido): string
    {
        return is_string($nome) && preg_match('/^[a-z_]{1,40}$/D', $nome) === 1 ? $nome : $redigido;
    }

    /** LGPD: só o nome de cada argumento (até 20), nunca o valor. */
    private function soNomesDosArgumentos(array $argumentos): array
    {
        $chaves = array_slice(array_map(fn ($chave) => $this->identificador($chave, '[chave redigida]'), array_keys($argumentos)), 0, 20);

        return array_fill_keys($chaves, AuditoriaService::REDIGIDO);
    }

    /**
     * RF06: a IA devolveu outra operação que não a pedida. Fica a tool pedida, o nome da devolvida
     * e só os nomes dos argumentos, como na tool fora do catálogo. JSON inválido: nenhum nome.
     * Sem definição no catálogo, audita como desconhecida: a auditoria nunca derruba a resposta.
     */
    private function auditarDivergencia(string $toolSolicitada, mixed $pedido): void
    {
        // mixed: o provedor é externo e um item de tool_calls pode não ser array; o ?? cobre isso, nunca vira 500.
        $bruto = $pedido['function']['arguments'] ?? null;
        $argumentos = is_string($bruto) ? json_decode($bruto, true, 32) : null;
        $definicao = $this->catalogo()[$toolSolicitada] ?? null;
        $devolvida = $this->identificador($pedido['function']['name'] ?? null, '[tool redigida]');

        $this->detalhesAuditoria->com(['parametros_propostos' => null], fn () => $this->auditar($toolSolicitada,
            $definicao['acao'] ?? 'desconhecida', $definicao['entidade'] ?? null, null,
            $this->soNomesDosArgumentos(is_array($argumentos) ? $argumentos : []),
            'negado', "A IA devolveu outra operação ({$devolvida}).", false));
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
