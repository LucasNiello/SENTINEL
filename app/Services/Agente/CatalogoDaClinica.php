<?php

namespace App\Services\Agente;

use App\Http\Requests\AgendarRequest;
use App\Http\Requests\AlterarServicoAgendamentoRequest;
use App\Http\Requests\AlterarStatusAgendamentoRequest;
use App\Http\Requests\CatalogoItemAtualizacaoRequest;
use App\Http\Requests\CatalogoItemRequest;
use App\Http\Requests\EntradaEstoqueRequest;
use App\Http\Requests\EstornarCobrancaRequest;
use App\Http\Requests\EstornarVendaRequest;
use App\Http\Requests\PetRequest;
use App\Http\Requests\ReceberCobrancaRequest;
use App\Http\Requests\RemarcarRequest;
use App\Http\Requests\RegistrarVendaRequest;
use App\Http\Requests\ArquivarPetRequest;
use App\Http\Requests\RegistroIdRequest;
use App\Http\Requests\TutorRequest;
use App\Models\Agendamento;
use App\Models\CatalogoItem;
use App\Models\Cobranca;
use App\Models\Mensagem;
use App\Models\Pet;
use App\Models\Tutor;
use App\Models\User;
use App\Models\Venda;
use App\Services\AgendaService;
use App\Services\CatalogoService;
use App\Services\CobrancaService;
use App\Services\MensagemService;
use App\Services\PetService;
use App\Services\TutorService;
use App\Services\VendaService;

/**
 * Catálogo de tools da clínica veterinária + petshop (domínio 'clinica', tenants em config('sentinel.tenants_clinica')).
 * Fica fora do AiAgentService só para o arquivo não crescer: usa os mesmos construtores e o mesmo motor. A lista de
 * papéis de CADA tool está aqui, à vista: papel desconhecido continua negando tudo (definirTool quebra cedo se a
 * lista tiver papel que não existe).
 *
 * Quem acessa o quê (proposta de 10/11/2026, confirmar com o Lucas): toda a equipe consulta tutores, pets e
 * catálogo e mantém o cadastro de tutores e pets; o catálogo (preços!) é só do dono; excluir, restaurar e excluir
 * de vez são só do dono (item C, 12/11), e restaurar e excluir de vez pedem a senha do dono no card.
 */
trait CatalogoDaClinica
{
    private const PAPEIS_EQUIPE = ['atendente', 'vendedor', 'recepcao', 'veterinario', 'dono'];

    private const PAPEIS_DO_DONO = ['dono'];

    /** Arquivar/reativar o cadastro do pet (27/11, sugestão a confirmar com o Lucas): recepção, veterinário e dono. */
    private const PAPEIS_DO_ARQUIVO_DE_PET = ['recepcao', 'veterinario', 'dono'];

    /** Quem vê e mexe na agenda (tabela da FASE oito, 15/11): o vendedor não. Mesma lista no chat e na tela /agenda. */
    private const PAPEIS_DA_AGENDA = ['atendente', 'recepcao', 'veterinario', 'dono'];

    /** As colunas da tabela da agenda: a lista de escolha (vários candidatos) usa as mesmas (15/11). */
    private const COLUNAS_DA_AGENDA = ['id', 'horario', 'pet', 'tutor', 'servico', 'profissional', 'setor', 'status'];

    /** Caixa de entrada: quantos pets do tutor vão no pedido ao agente e o limite do campo do chat (AgenteController). */
    private const PETS_NO_PEDIDO = 5;

    private const LIMITE_DO_PEDIDO = 1000;

    /** Quantos itens do catálogo cabem na descrição da venda (numerosDoCatalogo). */
    private const LIMITE_CATALOGO_NA_DESCRICAO = 80;

    /** @return array<int, array<string, mixed>> */
    private function toolsDaClinica(): array
    {
        $tutores = app(TutorService::class);
        $pets = app(PetService::class);
        $catalogo = app(CatalogoService::class);

        return [
            ...$this->definirCadastro(
                entidade: 'Tutor',
                singular: 'tutor',
                plural: 'tutores',
                rotulo: 'o tutor',
                rotuloPlural: 'tutores',
                servico: $tutores,
                requestCriar: TutorRequest::class,
                propriedadesCriar: $this->propriedadesDoTutor(),
                obrigatorios: ['nome'],
                propriedadesAtualizar: $this->propriedadesDoTutor('Nome novo, só se for mudar.'),
                filtros: ['nome' => ['type' => 'string', 'description' => 'Parte do nome do tutor.'], 'id' => ['type' => 'integer', 'description' => 'Número de um registro específico.'],],
                buscar: fn (array $a) => $tutores->buscarPorFiltro($a['nome'] ?? null, $a['tenant_id'], isset($a['id']) ? (int) $a['id'] : null),
                gravar: fn (array $dados, int $tenantId) => $tutores->criar($dados, $tenantId),
                colunas: ['id', 'nome', 'documento', 'email', 'telefone', 'endereco'],
                colunaRotulo: 'nome',
                papeisLeitura: self::PAPEIS_EQUIPE,
                papeisEscrita: self::PAPEIS_EQUIPE,
                tituloCriar: 'Cadastrar tutor',
                sucessoCriar: 'Tutor cadastrado',
                tituloAtualizar: 'Alterar tutor',
                sucessoAtualizar: 'Tutor atualizado',
                resumirAtualizar: fn (array $v, int $tenantId) => $this->petsDoTutorNoCard((int) ($v['id'] ?? 0), $tenantId),
            ),
            ...$this->definirCadastro(
                entidade: 'Pet',
                singular: 'pet',
                plural: 'pets',
                rotulo: 'o pet',
                rotuloPlural: 'pets',
                servico: $pets,
                requestCriar: PetRequest::class,
                propriedadesCriar: $this->propriedadesDoPet(),
                obrigatorios: ['tutor_id', 'nome', 'especie'],
                propriedadesAtualizar: $this->propriedadesDoPet('Nome novo, só se for mudar.'),
                filtros: [
                    'nome' => ['type' => 'string', 'description' => 'Parte do nome do pet.'],
                    'tutor_id' => ['type' => 'integer', 'description' => 'Id do tutor, para listar só os pets dele.'],
                    'id' => ['type' => 'integer', 'description' => 'Número de um registro específico.'],
                ],
                buscar: fn (array $a) => $pets->buscarPorFiltro($a['nome'] ?? null, isset($a['tutor_id']) ? (int) $a['tutor_id'] : null, $a['tenant_id'], isset($a['id']) ? (int) $a['id'] : null),
                gravar: fn (array $dados, int $tenantId) => $pets->criar($dados, $tenantId),
                colunas: ['id', 'nome', 'especie', 'raca', 'sexo', 'data_nascimento', 'peso_gramas', 'tutor_id', 'alertas', 'observacoes', 'inativado_em'],
                colunaRotulo: 'nome',
                papeisLeitura: self::PAPEIS_EQUIPE,
                papeisEscrita: self::PAPEIS_EQUIPE,
                tituloCriar: 'Cadastrar pet',
                sucessoCriar: 'Pet cadastrado',
                tituloAtualizar: 'Alterar pet',
                sucessoAtualizar: 'Pet atualizado',
                resumirAtualizar: fn (array $v, int $tenantId) => $this->tutorDoPetNoCard((int) ($v['id'] ?? 0), $tenantId),
            ),
            // 27/11: cadastro unificado do cliente: o tutor e os pets dele de uma vez, tudo ou nada. Auditoria: uma linha por registro,
            // todas com o mesmo lote_id.
            $this->definirAcao(
                nome: 'cadastrar_cliente_com_pets',
                descricao: 'Cadastra um cliente (tutor) e, de uma vez, os pets dele (de 0 a 10). Use quando o pedido traz o tutor e os animais juntos ("cadastre a Maria com o cachorro Thor e a gata Mel"); para só um tutor ou só um pet de tutor que já existe, use criar_tutor ou criar_pet. Tudo é gravado junto ou nada é gravado. Nunca invente dados: o que faltar fica em branco e o card marca o campo.',
                acao: 'cadastrar',
                entidade: 'Tutor',
                papeis: self::PAPEIS_EQUIPE,
                propriedades: [
                    ...$this->propriedadesDoTutor(),
                    'pets' => ['type' => 'array', 'description' => 'Os pets do cliente.', 'items' => ['type' => 'object', 'properties' => [
                        'nome' => ['type' => 'string'],
                        'especie' => ['type' => 'string', 'enum' => Pet::ESPECIES],
                        'raca' => ['type' => 'string'],
                        'sexo' => ['type' => 'string', 'enum' => Pet::SEXOS],
                        'data_nascimento' => ['type' => 'string', 'format' => 'date'],
                        'peso_gramas' => ['type' => 'integer', 'description' => 'Peso em GRAMAS inteiros (6200 = 6,2 kg).'],
                        'microchip' => ['type' => 'string'],
                        'alertas' => ['type' => 'string'],
                        'observacoes' => ['type' => 'string'],
                    ]]],
                ],
                obrigatorios: ['nome'],
                formRequest: \App\Http\Requests\ClienteComPetsRequest::class,
                executar: function (array $d, int $tenantId) use ($tutores) {
                    $loteId = (string) \Illuminate\Support\Str::uuid();
                    // O lote vale também para a linha geral da tool (o escopo é o do executor): N+1 linhas, todas com o mesmo lote_id.
                    app(\App\Services\DetalhesAuditoria::class)->anotar('lote_id', $loteId);
                    $gravado = $tutores->criarComPets(
                        \Illuminate\Support\Arr::except($d, ['pets', 'tenant_id']), $d['pets'] ?? [], $tenantId,
                        fn ($registro, string $tipo) => app(\App\Services\AuditoriaService::class)->registrar(
                            tool: $tipo === 'tutor' ? 'criar_tutor' : 'criar_pet', acao: 'criar', entidadeTipo: $tipo === 'tutor' ? 'Tutor' : 'Pet',
                            entidadeId: $registro->id, parametros: ['id' => $registro->id, 'nome' => $registro->nome], resultado: 'sucesso',
                            mensagem: 'Cadastro unificado do cliente (lote '.$loteId.').', papel: $this->contexto->papel(), permitido: true));

                    return $gravado['tutor'];
                },
                mensagemErro: 'Não consegui cadastrar o cliente. Nada foi gravado.',
                titulo: 'Cadastrar cliente e pets',
                sucesso: 'Cliente cadastrado',
            ),
            // 27/11: arquivar o cadastro do pet (sem apagar; sai dos seletores, o histórico fica). Segurar 2 s (risco exclusao).
            $this->definirAcao(
                nome: 'arquivar_pet',
                descricao: 'Arquiva o cadastro de um pet (falecido, mudou etc.) sem apagar nada: ele sai das listas de escolha e o histórico continua. Exige o motivo em observacoes. Pet com agendamento futuro em aberto não é arquivado. Achado pelo id ou pelo nome do pet.',
                acao: 'arquivar',
                entidade: 'Pet',
                papeis: self::PAPEIS_DO_ARQUIVO_DE_PET,
                propriedades: ['observacoes' => ['type' => 'string', 'description' => 'Motivo do arquivamento (ex.: falecido, mudou de cidade).']],
                obrigatorios: ['observacoes'],
                formRequest: ArquivarPetRequest::class,
                executar: fn (array $d, int $tenantId) => $pets->arquivarCadastro((int) $d['id'], (string) $d['observacoes'], $tenantId),
                mensagemErro: 'Não consegui arquivar o pet. Nada foi gravado.',
                titulo: 'Arquivar o cadastro do pet',
                sucesso: 'Pet arquivado',
                conferir: function (array $a) use ($pets): ?array {
                    $motivo = $pets->motivoParaNaoArquivar((int) ($a['id'] ?? 0), (int) $a['tenant_id']);

                    return $motivo === null ? null : ['campo' => 'id', 'motivo' => $motivo];
                },
                risco: 'exclusao',
                localizar: fn (?int $id, ?string $busca, int $tenantId) => $pets->localizarPorCadastro($id, $busca, $tenantId, false),
                rotulo: 'o pet',
                colunaRotulo: 'nome',
                rotuloAcao: 'Arquivar',
                campos: ['observacoes'],
                resumir: fn (array $v, int $tenantId) => $this->tutorDoPetNoCard((int) ($v['id'] ?? 0), $tenantId),
            ),
            $this->definirAcao(
                nome: 'reativar_pet',
                descricao: 'Reativa o cadastro de um pet arquivado: ele volta às listas de escolha. Achado pelo id ou pelo nome do pet.',
                acao: 'reativar',
                entidade: 'Pet',
                papeis: self::PAPEIS_DO_ARQUIVO_DE_PET,
                propriedades: [],
                obrigatorios: [],
                formRequest: RegistroIdRequest::class,
                executar: fn (array $d, int $tenantId) => $pets->reativarCadastro((int) $d['id'], $tenantId),
                mensagemErro: 'Não consegui reativar o pet. Nada foi gravado.',
                titulo: 'Reativar o cadastro do pet',
                sucesso: 'Pet reativado',
                conferir: fn (array $a) => $pets->localizarPorCadastro((int) ($a['id'] ?? 0), null, (int) $a['tenant_id'], true)->isEmpty()
                    ? ['campo' => 'id', 'motivo' => 'Não encontrei o pet arquivado #'.(int) ($a['id'] ?? 0).'.'] : null,
                localizar: fn (?int $id, ?string $busca, int $tenantId) => $pets->localizarPorCadastro($id, $busca, $tenantId, true),
                rotulo: 'o pet arquivado',
                colunaRotulo: 'nome',
                rotuloAcao: 'Reativar',
                campos: [],
                resumir: fn (array $v, int $tenantId) => $this->tutorDoPetNoCard((int) ($v['id'] ?? 0), $tenantId),
            ),
            ...$this->definirCadastro(
                entidade: 'CatalogoItem',
                singular: 'item_catalogo',
                plural: 'catalogo',
                rotulo: 'o item do catálogo',
                rotuloPlural: 'itens do catálogo',
                servico: $catalogo,
                requestCriar: CatalogoItemRequest::class,
                propriedadesCriar: [
                    'tipo' => ['type' => 'string', 'enum' => CatalogoItem::TIPOS],
                    'setor' => ['type' => 'string', 'enum' => CatalogoItem::SETORES],
                    'categoria' => ['type' => 'string', 'description' => 'Clínica: '.implode(', ', CatalogoItem::CATEGORIAS['clinica']).'. Petshop: '.implode(', ', CatalogoItem::CATEGORIAS['petshop']).'.'],
                    'nome_comercial' => ['type' => 'string'],
                    'preco_centavos' => ['type' => 'integer', 'description' => 'Preço em CENTAVOS inteiros (4590 = R$ 45,90). Nunca decimal.'],
                    'cobranca_por' => ['type' => 'string', 'enum' => CatalogoItem::COBRANCA_POR],
                    'controla_estoque' => ['type' => 'string', 'enum' => ['sim', 'nao'], 'description' => 'Só preencha se o usuário disser se o item controla estoque; se ele não disse, deixe de fora, mesmo para serviço: quem cadastra escolhe no card. Nunca presuma.'],
                    'estoque' => ['type' => 'integer', 'description' => 'Estoque inicial, só produto que controla estoque.'],
                    'exige_receita' => ['type' => 'string', 'enum' => ['sim', 'nao'], 'description' => 'Só preencha se o usuário disser se o item exige receita; se ele não disse, deixe de fora: quem cadastra escolhe no card. Nunca presuma. Só item de farmácia da clínica pode ser "sim".'],
                ],
                obrigatorios: ['tipo', 'setor', 'categoria', 'nome_comercial', 'preco_centavos', 'cobranca_por', 'controla_estoque', 'exige_receita'],
                propriedadesAtualizar: [
                    'categoria' => ['type' => 'string'],
                    'nome_comercial' => ['type' => 'string', 'description' => 'Nome novo, só se for mudar.'],
                    'preco_centavos' => ['type' => 'integer', 'description' => 'Preço novo em CENTAVOS inteiros; vale só daqui para frente.'],
                ],
                filtros: [
                    'setor' => ['type' => 'string', 'enum' => CatalogoItem::SETORES],
                    'categoria' => ['type' => 'string'],
                    'nome' => ['type' => 'string', 'description' => 'Parte do nome comercial.'],
                    'id' => ['type' => 'integer', 'description' => 'Número de um registro específico.'],
                ],
                buscar: fn (array $a) => $catalogo->buscarPorFiltro($a['setor'] ?? null, $a['categoria'] ?? null, $a['nome'] ?? null, $a['tenant_id'], isset($a['id']) ? (int) $a['id'] : null)
                    ->map(function (CatalogoItem $i) {
                        $negativo = $i->controla_estoque && $i->estoque < 0;

                        return [
                            ...$i->only(['id', 'nome_comercial', 'setor', 'categoria', 'tipo', 'preco_centavos', 'estoque', 'controla_estoque', 'exige_receita']),
                            'alerta' => $negativo ? 'Estoque negativo: confira a prateleira' : null,
                            ...($negativo ? ['destaque' => 'aviso'] : []),
                        ];
                    })->all(),
                gravar: fn (array $dados, int $tenantId) => $catalogo->criar([
                    ...$dados,
                    // O formulário e a IA falam "sim"/"nao"; o service guarda verdadeiro/falso.
                    // Obrigatórios e já validados (sem padrão); só o estoque inicial tem padrão (0, falha segura).
                    'controla_estoque' => $dados['controla_estoque'] === 'sim',
                    'exige_receita' => $dados['exige_receita'] === 'sim',
                    'estoque' => $dados['estoque'] ?? 0,
                ], $tenantId),
                colunas: ['id', 'nome_comercial', 'setor', 'categoria', 'tipo', 'preco_centavos', 'estoque', 'controla_estoque', 'exige_receita', 'alerta'],
                colunaRotulo: 'nome_comercial',
                papeisLeitura: self::PAPEIS_EQUIPE,
                papeisEscrita: self::PAPEIS_DO_DONO,
                tituloCriar: 'Cadastrar item do catálogo',
                sucessoCriar: 'Item do catálogo cadastrado',
                tituloAtualizar: 'Alterar item do catálogo',
                sucessoAtualizar: 'Item do catálogo atualizado',
                requestAtualizar: CatalogoItemAtualizacaoRequest::class,
            ),
            $this->definirAcao(
                nome: 'dar_entrada_estoque',
                descricao: 'Dá entrada de unidades de um produto com controle de estoque, somando ao saldo atual. Localize pelo ID ou nome comercial. Quantidade inteira de 1 a 100000; motivo opcional de até 200 caracteres. Nunca substitui o saldo.',
                acao: 'entrada_estoque',
                entidade: 'CatalogoItem',
                papeis: self::PAPEIS_DO_DONO,
                propriedades: [
                    'quantidade' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000],
                    'motivo' => ['type' => 'string', 'maxLength' => 200],
                ],
                obrigatorios: ['quantidade'],
                formRequest: EntradaEstoqueRequest::class,
                executar: fn (array $d, int $tenantId) => $catalogo->entradaDeEstoque($d['id'], $d['quantidade'], $tenantId),
                mensagemErro: 'Não consegui registrar a entrada de estoque. Nada foi gravado.',
                titulo: 'Entrada de estoque',
                sucesso: 'Entrada de estoque registrada',
                conferir: function (array $a): ?array {
                    $item = CatalogoItem::where('tenant_id', $a['tenant_id'])->find($a['id'] ?? 0);

                    return $item !== null && $item->tipo === 'produto' && $item->controla_estoque
                        ? null : ['campo' => 'id', 'motivo' => 'A entrada exige um produto deste tenant com controle de estoque.'];
                },
                localizar: $catalogo->localizar(...),
                rotulo: 'o produto',
                buscaPor: 'nome comercial',
                colunaRotulo: 'nome_comercial',
                rotuloAcao: 'Dar entrada',
                campos: ['quantidade', 'motivo'],
                resumir: function (array $v, int $tenantId): array {
                    $item = CatalogoItem::where('tenant_id', $tenantId)->findOrFail($v['id']);

                    return [['rotulo' => 'Produto', 'valor' => $item->nome_comercial], ['rotulo' => 'Saldo atual', 'valor' => $item->estoque]];
                },
            ),
            ...$this->toolsDeAtendimento(),
            ...$this->definirLixeira($this->lixeirasDaClinica()),
        ];
    }

    /**
     * Agenda (consultar, mudar status, agendar e encaixe de emergência: P-C4), cobrança (P-B4: cobrar, receber,
     * cancelar), venda avulsa e caixa de entrada (P-B5). Fora da P-C4 (ficam para depois): data e hora separadas no
     * card, duração vinda do serviço e sugestão de horários livres.
     *
     * @return array<int, array<string, mixed>>
     */
    private function toolsDeAtendimento(): array
    {
        $agenda = app(AgendaService::class);
        $cobrancas = app(CobrancaService::class);
        $vendas = app(VendaService::class);
        $mensagens = app(MensagemService::class);
        $quemCobra = ['atendente', 'recepcao', 'dono'];

        return [
            // ---------------------------------------------------------------- agenda
            $this->definirTool(
                nome: 'consultar_agenda',
                descricao: 'Mostra a agenda da clínica e do banho e tosa num período (padrão: de hoje até 6 dias depois), com horário, pet, tutor, serviço, profissional e status. Datas no formato AAAA-MM-DD. Também responde perguntas pontuais: "qual foi o último agendamento realizado" (status realizado, ordenar marcado, sentido desc, limite 1), "o próximo do Thor" (pet, quando futuro, ordenar marcado, limite 1), "o último que foi gravado" (ordenar gravacao, sentido desc, limite 1), "os banhos de outubro" (servico banho, datas), "o que foi agendado ontem" (tipo_data gravacao, dia de ontem). Sem pedir só "o último", mostre a lista inteira. Ação de leitura — não exige confirmação.',
                tipo: 'leitura',
                risco: 'leitura',
                acao: 'consultar',
                entidade: 'Agendamento',
                papeis: self::PAPEIS_DA_AGENDA,
                propriedades: [
                    'data_inicio' => ['type' => 'string', 'format' => 'date'],
                    'data_fim' => ['type' => 'string', 'format' => 'date'],
                    'setor' => ['type' => 'string', 'enum' => CatalogoItem::SETORES],
                    'profissional_id' => ['type' => 'integer', 'description' => 'Id do veterinário ou do atendente.'],
                    'pet_id' => ['type' => 'integer'],
                    'dia' => ['type' => 'string', 'format' => 'date', 'description' => 'Um dia só (AAAA-MM-DD): "hoje", "amanhã", "dia 25". Vale no lugar de data_inicio/data_fim.'],
                    'pet' => ['type' => 'string', 'description' => 'Nome do pet/animal ("agenda do Thor"), quando o número não é conhecido: o servidor procura.'],
                    'tutor' => ['type' => 'string', 'description' => 'Nome do CLIENTE (tutor, o dono do animal): "os agendamentos do Diego". Filtra só os agendamentos dele.'],
                    'profissional' => ['type' => 'string', 'description' => 'Nome do veterinário ou atendente ("os da estetica1"), quando o número não é conhecido.'],
                    'status' => ['type' => 'string', 'enum' => Agendamento::STATUS],
                    // 26/11: filtros para o pedido ser entendido ("o último realizado", "banhos do Thor em outubro", "gravados ontem").
                    'tipo_data' => ['type' => 'string', 'enum' => array_keys(AgendaService::TIPOS_DE_DATA), 'description' => 'A que data se referem dia/data_inicio/data_fim: "marcado" = o dia marcado do atendimento (padrão); "gravacao" = quando o agendamento foi criado no sistema ("gravados ontem", "marcados ontem"); "alteracao" = a última vez que foi mudado (ex.: quando virou realizado).'],
                    'servico' => ['type' => 'string', 'description' => 'Nome ou categoria do serviço: "banho", "tosa", "consulta", "cirurgia", "vacina".'],
                    'hora_de' => ['type' => 'string', 'description' => 'Horário do atendimento a partir de (HH:MM, 24 h): "à tarde" = 12:00.'],
                    'hora_ate' => ['type' => 'string', 'description' => 'Horário do atendimento até (HH:MM, 24 h): "de manhã" = até 11:59.'],
                    'dia_semana' => ['type' => 'string', 'enum' => ['segunda', 'terca', 'quarta', 'quinta', 'sexta', 'sabado', 'domingo']],
                    'quando' => ['type' => 'string', 'enum' => ['passado', 'futuro'], 'description' => 'Só os que já passaram ou só os que ainda vão acontecer (em relação a agora).'],
                    'ordenar' => ['type' => 'string', 'enum' => array_keys(AgendaService::TIPOS_DE_DATA), 'description' => 'Por qual data ordenar: "marcado" (dia do atendimento), "gravacao" (ordem em que foram criados) ou "alteracao" (última mudança). "O último realizado" = ordenar marcado, sentido desc, limite 1, status realizado; "o último gravado" = ordenar gravacao.'],
                    'sentido' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'description' => 'asc = do mais antigo ao mais novo; desc = do mais novo ao mais antigo ("último", "mais recente").'],
                    'limite' => ['type' => 'integer', 'description' => 'Quantos mostrar (1 a 50): "o último" = 1, "os 3 próximos" = 3. Sem dizer, mostra todos.'],
                    'id' => ['type' => 'integer', 'description' => 'Número de um agendamento específico.'],
                    'origem' => ['type' => 'string', 'enum' => Agendamento::ORIGENS, 'description' => 'encaixe_emergencia = os encaixes de emergência.'],
                    'cobrado' => ['type' => 'string', 'enum' => ['sim', 'nao'], 'description' => 'sim = já tem cobrança; nao = ainda sem cobrança ("realizados sem cobrança").'],
                ],
                obrigatorios: [],
                executar: fn (array $a) => $agenda->buscarAvancado([...$a, 'setor' => $this->setorDaAgendaPedido($a['setor'] ?? null)], (int) $a['tenant_id'])
                    ->map(fn (Agendamento $ag) => [
                        'id' => (int) $ag->id,
                        'horario' => $ag->inicio->format('d/m/Y H:i').'–'.$ag->fim->format('H:i'),
                        'pet' => $ag->getAttribute('pet'),
                        'tutor' => $ag->getAttribute('tutor'),
                        'servico' => $ag->getAttribute('servico'),
                        'profissional' => $ag->getAttribute('profissional'),
                        'setor' => $ag->setor,
                        'status' => $ag->status,
                        // Só no pedido pontual ("o último", "o gravado ontem"): a frase usa a gravação, e a tabela a mostra quando o pedido a usa
                        // (AiAgentService::apresentarAgenda). A consulta comum mantém as colunas de sempre.
                        ...(isset($a['ordenar']) || isset($a['limite']) || isset($a['id']) || isset($a['tipo_data']) ? [
                            'gravado_em' => $ag->created_at?->format('d/m/Y H:i'),
                            'alterado_em' => $ag->updated_at?->format('d/m/Y H:i'),
                        ] : []),
                    ])->all(),
                mensagemErro: 'Não consegui consultar a agenda agora. Tente novamente.',
                colunas: ['id', 'horario', 'pet', 'tutor', 'servico', 'profissional', 'setor', 'status'],
            ),
            // Cancelar vários agendamentos (25/11, decisão do Lucas): NUNCA às cegas. Esta tool só MOSTRA os agendamentos em aberto do
            // período numa lista com checkbox; o usuário revisa e MARCA quais cancelar (segurar 2 s). Quem grava é o endpoint
            // /agente/agenda/cancelar-lote, só com ids que estavam nesta lista, tudo ou nada, uma linha de auditoria por item.
            $this->definirTool(
                nome: 'cancelar_agendamentos_em_lote',
                descricao: 'Para cancelar VÁRIOS agendamentos de uma vez ("cancele todos de hoje", "cancele tudo menos o dia 25"): mostra ao usuário os agendamentos em aberto do período numa lista com caixas de seleção, e ELE marca quais cancelar. Nada é cancelado por esta tool: ela só monta a lista. Período em AAAA-MM-DD (padrão: hoje até 6 dias depois); setor e pet (nome) são filtros opcionais; exceto_dia = um dia que deve ser MANTIDO (fica fora da lista). Para cancelar um só, use alterar_status_agendamento. Ação de leitura — a confirmação é do usuário, marcando e segurando o botão.',
                tipo: 'leitura',
                risco: 'leitura',
                acao: 'consultar',
                entidade: 'Agendamento',
                papeis: self::PAPEIS_DA_AGENDA,
                propriedades: [
                    'data_inicio' => ['type' => 'string', 'format' => 'date'],
                    'data_fim' => ['type' => 'string', 'format' => 'date'],
                    'setor' => ['type' => 'string', 'enum' => CatalogoItem::SETORES],
                    'dia' => ['type' => 'string', 'format' => 'date', 'description' => 'Um dia só (AAAA-MM-DD): "os de hoje", "os do dia 25". Vale no lugar de data_inicio/data_fim.'],
                    'tutor' => ['type' => 'string', 'description' => 'Nome do CLIENTE (tutor, dono do animal): "cancele os agendamentos do cliente Diego" → tutor "Diego". Só os agendamentos dele entram na lista.'],
                    'pet' => ['type' => 'string', 'description' => 'Nome do pet/animal, se o usuário restringiu a um pet.'],
                    'profissional' => ['type' => 'string', 'description' => 'Nome do veterinário ou atendente, se o usuário restringiu a um profissional.'],
                    'exceto_dia' => ['type' => 'string', 'format' => 'date', 'description' => 'Dia que o usuário quer MANTER ("menos o dia 25"): os agendamentos dele não entram na lista.'],
                ],
                obrigatorios: [],
                executar: function (array $a) use ($agenda) {
                    try {
                        $linhas = $agenda->candidatosParaCancelarEmLote($a['dia'] ?? $a['data_inicio'] ?? null, $a['dia'] ?? $a['data_fim'] ?? null, $this->setorDaAgendaPedido($a['setor'] ?? null),
                            is_string($a['pet'] ?? null) ? $a['pet'] : null, is_string($a['exceto_dia'] ?? null) ? $a['exceto_dia'] : null, $this->setoresDaAgenda(), (int) $a['tenant_id'],
                            is_string($a['tutor'] ?? null) ? $a['tutor'] : null, is_string($a['profissional'] ?? null) ? $a['profissional'] : null);
                    } catch (\App\Exceptions\RegraDeNegocioException $e) {
                        // Data inválida ou período grande demais: a frase do servidor chega ao usuário (nada de erro genérico).
                        throw new \App\Exceptions\ToolRecusadaException($e->getMessage());
                    }

                    if ($linhas === []) {
                        throw new \App\Exceptions\ToolRecusadaException('Não encontrei nenhum agendamento em aberto com esses filtros, então não há nada para cancelar. Quer tentar outro período, cliente, animal ou profissional?');
                    }
                    if (count($linhas) > AgendaService::LIMITE_CANCELAR_EM_LOTE) {
                        throw new \App\Exceptions\ToolRecusadaException('Achei mais de '.AgendaService::LIMITE_CANCELAR_EM_LOTE
                            .' agendamentos em aberto — são muitos para eu listar de uma vez. Pode restringir por dia, cliente, animal ou profissional?');
                    }

                    return $linhas;
                },
                mensagemErro: 'Não consegui montar a lista agora. Tente novamente.',
                colunas: ['id', 'horario', 'pet', 'tutor', 'servico', 'profissional', 'status', 'observacao'],
            ),
            // Remarcar vários agendamentos (26/11, decisão do Lucas): MESMO desenho do cancelar em lote. A tool só MONTA o plano (o servidor
            // propõe o novo horário de cada um, simulando a grade de verdade, sem gravar); o usuário revisa, marca quais remarcar e segura
            // 2 s. Quem grava é /agente/agenda/remarcar-lote, com os horários CONGELADOS aqui, tudo ou nada entre os marcados.
            $this->definirTool(
                nome: 'remarcar_agendamentos_em_lote',
                descricao: 'Para remarcar VÁRIOS agendamentos de uma vez ("dia 27 estaremos fechados, remarque todos", "passe os de amanhã para o próximo horário livre"): o servidor propõe o novo horário de cada um e mostra a lista com caixas de seleção; o usuário revisa, marca e confirma. Nada é remarcado por esta tool. Os agendamentos de origem: dia (um dia só) ou data_inicio/data_fim (AAAA-MM-DD; padrão hoje até 6 dias depois), com filtros opcionais de setor, tutor, pet e profissional. modo: "mesmo_horario" (padrão: mesmo horário e profissional, o primeiro dia em que todos cabem) ou "proximo_livre" (cada um vai, por ordem de horário, para o primeiro horário livre do mesmo profissional; use quando o usuário disser que os clientes não aceitam o outro dia, ou pedir "o próximo horário disponível"). para_o_dia: só se o usuário disser o dia de destino. a_partir_de: só se o usuário disser "a partir de segunda". NUNCA invente datas: use o que o usuário disse, e deixe o servidor achar o dia. Para remarcar um só, use remarcar_agendamento. Ação de leitura: a confirmação é do usuário, marcando e segurando o botão.',
                tipo: 'leitura',
                risco: 'leitura',
                acao: 'consultar',
                entidade: 'Agendamento',
                papeis: self::PAPEIS_DA_AGENDA,
                propriedades: [
                    'dia' => ['type' => 'string', 'format' => 'date', 'description' => 'O dia dos agendamentos a remarcar (AAAA-MM-DD): "dia 27 estaremos fechados". Vale no lugar de data_inicio/data_fim.'],
                    'data_inicio' => ['type' => 'string', 'format' => 'date'],
                    'data_fim' => ['type' => 'string', 'format' => 'date'],
                    'setor' => ['type' => 'string', 'enum' => CatalogoItem::SETORES],
                    'tutor' => ['type' => 'string', 'description' => 'Nome do CLIENTE (tutor), se o usuário restringiu a um cliente.'],
                    'pet' => ['type' => 'string', 'description' => 'Nome do pet/animal, se o usuário restringiu a um pet.'],
                    'profissional' => ['type' => 'string', 'description' => 'Nome do veterinário ou atendente, se o usuário restringiu a um profissional.'],
                    'modo' => ['type' => 'string', 'enum' => ['mesmo_horario', 'proximo_livre']],
                    'para_o_dia' => ['type' => 'string', 'format' => 'date', 'description' => 'Só se o usuário disse o dia de destino (AAAA-MM-DD). Só vale no modo mesmo_horario e quando os agendamentos são de um único dia.'],
                    'a_partir_de' => ['type' => 'string', 'format' => 'date', 'description' => 'Só se o usuário disse "a partir de" um dia (AAAA-MM-DD): a busca de vaga começa nele.'],
                ],
                obrigatorios: [],
                executar: function (array $a) use ($agenda) {
                    try {
                        $linhas = $agenda->planoParaRemarcarEmLote($a['dia'] ?? $a['data_inicio'] ?? null, $a['dia'] ?? $a['data_fim'] ?? null, $this->setorDaAgendaPedido($a['setor'] ?? null),
                            is_string($a['pet'] ?? null) ? $a['pet'] : null, is_string($a['tutor'] ?? null) ? $a['tutor'] : null, is_string($a['profissional'] ?? null) ? $a['profissional'] : null,
                            is_string($a['modo'] ?? null) && $a['modo'] !== '' ? $a['modo'] : 'mesmo_horario', is_string($a['para_o_dia'] ?? null) ? $a['para_o_dia'] : null,
                            is_string($a['a_partir_de'] ?? null) ? $a['a_partir_de'] : null, $this->setoresDaAgenda(), (int) $a['tenant_id']);
                    } catch (\App\Exceptions\RegraDeNegocioException $e) {
                        throw new \App\Exceptions\ToolRecusadaException($e->getMessage());
                    }

                    if ($linhas === []) {
                        throw new \App\Exceptions\ToolRecusadaException('Não encontrei nenhum agendamento em aberto com esses filtros, então não há nada para remarcar. Quer tentar outro período, cliente, animal ou profissional?');
                    }

                    return $linhas;
                },
                mensagemErro: 'Não consegui montar o plano de remarcação agora. Tente novamente.',
                colunas: ['id', 'horario', 'pet', 'tutor', 'servico', 'profissional', 'novo_horario', 'observacao'],
            ),
            $this->definirAcao(
                nome: 'alterar_status_agendamento',
                descricao: 'Muda o status de um agendamento em aberto (agendado ou confirmado) para confirmado, realizado, cancelado ou faltou. Achado pelo id ou pelo nome do pet.',
                acao: 'atualizar',
                entidade: 'Agendamento',
                papeis: self::PAPEIS_DA_AGENDA,
                propriedades: ['novo_status' => ['type' => 'string', 'enum' => ['confirmado', 'realizado', 'cancelado', 'faltou']]],
                obrigatorios: ['novo_status'],
                formRequest: AlterarStatusAgendamentoRequest::class,
                executar: function (array $d, int $tenantId, User $ator) use ($agenda) {
                    // 25/11: o setor do papel também é conferido na EXECUÇÃO (card parado ou token antigo: o papel pode ter mudado).
                    $this->recusarSeForaDoSetor(Agendamento::query()->where('tenant_id', $tenantId)->find((int) $d['id'])?->setor);

                    return $agenda->alterarStatus($d['id'], $d['novo_status'], $tenantId, $d['versao'], $this->autorizacaoDaAgenda($ator));
                },
                mensagemErro: 'Não consegui mudar o status do agendamento. Tente novamente.',
                titulo: 'Mudar o status do agendamento',
                sucesso: 'Status do agendamento atualizado',
                conferir: function (array $a) use ($agenda): ?array {
                    $motivo = $this->motivoForaDoSetorDaAgenda(Agendamento::query()->where('tenant_id', (int) $a['tenant_id'])->find((int) ($a['id'] ?? 0))?->setor)
                        ?? $agenda->motivoParaNaoAlterarStatus((int) ($a['id'] ?? 0), (string) ($a['novo_status'] ?? ''), (int) $a['tenant_id'], $this->versaoDaFicha($a),
                            $this->autorizacaoDaAgenda(null));

                    return $motivo === null ? null : ['campo' => 'novo_status', 'motivo' => $motivo];
                },
                localizar: fn (?int $id, ?string $busca, int $tenantId) => $agenda->localizarEmAberto($id, $busca, $tenantId, $this->setoresDaAgenda()),
                rotulo: 'o agendamento',
                colunaRotulo: 'pet',
                rotuloAcao: 'Escolher',
                campos: ['novo_status'],
                resumir: fn (array $v, int $tenantId) => $this->resumoDoAgendamento((int) ($v['id'] ?? 0), $tenantId),
                colunasLista: self::COLUNAS_DA_AGENDA,
                comVersao: true,
                // Adendo final (15/11): cancelar cirurgia pede CRMV + senha; faltou e realizado seguem a regra atual.
                exigeCrmv: fn (array $v, int $tenantId) => $agenda->exigeCrmvNoStatus((int) ($v['id'] ?? 0), $v['novo_status'] ?? null, $tenantId),
            ),
            // Remarcar (15/11): muda o início (e, se escolhido no calendário, o profissional do mesmo setor) de um agendamento
            // que já existe. Nunca cria agendamento novo; o fim recalcula. "Abrir a ficha do agendamento" também abre esta.
            $this->definirAcao(
                nome: 'remarcar_agendamento',
                descricao: 'Remarca um agendamento que já existe (em aberto): muda o início (e, se o usuário pedir, o profissional do mesmo setor); o servidor recalcula o fim; pet, tutor e serviço continuam. Também é a tool para "ver", "abrir" ou "mostrar a ficha" de um agendamento. Achado pelo número, pela linha da última tabela (posicao) ou pelo que o usuário disse (nome do pet, do tutor, do serviço ou do profissional). Para "o último agendamento" ou "o mais recente" (o último CRIADO na clínica, por qualquer pessoa, sem tabela mostrada antes), mande busca = "último" (ou "último" mais o nome do pet, se o usuário disse); nunca posicao nesse caso. Nunca cria um agendamento novo. Novo início no formato AAAA-MM-DD HH:MM (horário de São Paulo); sem horário dito, deixe em branco: a ficha abre com o calendário.',
                acao: 'atualizar',
                entidade: 'Agendamento',
                papeis: self::PAPEIS_DA_AGENDA,
                propriedades: [
                    'inicio' => ['type' => 'string', 'description' => 'Novo início COMPLETO (data e hora): AAAA-MM-DD HH:MM. Só quando o usuário disser dia E hora; senão use dia/dia_do_mes/hora.'],
                    'dia' => ['type' => 'string', 'description' => 'Só o NOVO DIA, AAAA-MM-DD ("pra amanhã", "pro dia 5 de dezembro"). O servidor mantém o horário do agendamento. Nunca invente o horário.'],
                    'dia_do_mes' => ['type' => 'integer', 'description' => 'Só o número do dia ("pro dia 21"), 1 a 31: vale no mês do próprio agendamento. O servidor mantém o horário.'],
                    'somar_dias' => ['type' => 'integer', 'description' => 'Deslocamento RELATIVO em dias a partir do dia do próprio agendamento: "no dia seguinte" = 1, "daqui a 3 dias" = 3. O servidor mantém o horário e calcula a data; você nunca sabe a data atual do agendamento, então use isto em vez de chutar "dia". 1 a 366.'],
                    'somar_semanas' => ['type' => 'integer', 'description' => 'Deslocamento RELATIVO em semanas ("semana que vem" = 1): mesmo dia da semana e mesmo horário. 1 a 52.'],
                    'somar_meses' => ['type' => 'integer', 'description' => 'Deslocamento RELATIVO em meses ("mês que vem" = 1): mesmo dia do mês e mesmo horário. 1 a 12.'],
                    'hora' => ['type' => 'string', 'description' => 'Só a HORA nova, HH:MM, quando o usuário disser ("às 10h"). Sem ela, o horário não muda.'],
                    'profissional_id' => ['type' => 'integer', 'description' => 'Só se o usuário pedir outro profissional (do mesmo setor).'],
                ],
                obrigatorios: [],
                formRequest: RemarcarRequest::class,
                executar: function (array $d, int $tenantId, User $ator) use ($agenda) {
                    $this->recusarSeForaDoSetor(Agendamento::query()->where('tenant_id', $tenantId)->find((int) $d['id'])?->setor);
                    $agendamento = $agenda->remarcar($d['id'], $d['inicio'], $tenantId, $d['profissional_id'], $d['versao'], $this->autorizacaoDaAgenda($ator));
                    // O "antes" na auditoria: de que horário e de que profissional saiu (o update() guarda em getPrevious()).
                    $campos = ['profissional_id', 'inicio', 'fim'];
                    $this->detalhesAuditoria->anotar('valores_anteriores', array_intersect_key([...$agendamento->only($campos), ...$agendamento->getPrevious()], array_flip($campos)));

                    return $agendamento;
                },
                mensagemErro: 'Não consegui remarcar o agendamento. Nada foi gravado.',
                titulo: 'Remarcar o agendamento',
                sucesso: 'Agendamento remarcado',
                conferir: function (array $a) use ($agenda): ?array {
                    $motivo = $this->motivoForaDoSetorDaAgenda(Agendamento::query()->where('tenant_id', (int) $a['tenant_id'])->find((int) ($a['id'] ?? 0))?->setor)
                        ?? $agenda->motivoParaNaoRemarcar((int) ($a['id'] ?? 0), $a['inicio'] ?? null, (int) $a['tenant_id'], $a['profissional_id'] ?? null, $this->versaoDaFicha($a),
                            $this->autorizacaoDaAgenda(null));

                    return $motivo === null ? null : ['campo' => 'inicio', 'motivo' => $motivo];
                },
                localizar: fn (?int $id, ?string $busca, int $tenantId) => $agenda->localizarParaRemarcar($id, $busca, $tenantId, $this->setoresDaAgenda()),
                rotulo: 'o agendamento',
                buscaPor: 'pet, serviço ou profissional',
                colunaRotulo: 'resumo',
                rotuloAcao: 'Escolher',
                campos: ['profissional_id', 'inicio'],
                preparar: fn (array $argumentos, array $propostos, int $tenantId) => $this->prepararDataRelativa($argumentos, $propostos, $tenantId),
                resumir: fn (array $v, int $tenantId) => $this->fichaDoAgendamento('remarcar', $v, $tenantId),
                colunasLista: self::COLUNAS_DA_AGENDA,
                comVersao: true,
                exigeCrmv: fn (array $v, int $tenantId) => $agenda->exigeCrmv((int) ($v['id'] ?? 0), null, $tenantId),
            ),
            // Trocar o serviço (15/11): "não era banho, era consulta". O MESMO agendamento é alterado, nunca duplicado. No
            // mesmo setor a ficha mantém o profissional e o horário (o fim recalcula); em outro setor, o profissional fica
            // em branco e a ficha abre com a lista do setor novo e o calendário.
            $this->definirAcao(
                nome: 'alterar_servico_agendamento',
                descricao: 'Troca o serviço de um agendamento que já existe (em aberto), quando foi marcado errado ("não era banho, era consulta", "troca o banho do Thor por tosa"). Altera o mesmo agendamento, nunca cria outro. Achado pelo número, pela linha da última tabela (posicao) ou pelo que o usuário disse (nome do pet, do tutor, do serviço ou do profissional) no campo busca. Para "o último agendamento" ou "o mais recente" (o último CRIADO na clínica, por qualquer pessoa, sem tabela mostrada antes), mande busca = "último"; nunca posicao nesse caso. Informeem catalogo_item_id o número do serviço novo, escolhido na lista de serviços; sem certeza, deixe em branco: a ficha mostra a lista.',
                acao: 'atualizar',
                entidade: 'Agendamento',
                papeis: self::PAPEIS_DA_AGENDA,
                propriedades: [
                    'catalogo_item_id' => ['type' => 'integer', 'description' => 'Número do serviço NOVO.'.$this->numerosDosServicos()],
                    'servico' => ['type' => 'string', 'description' => 'Nome do serviço NOVO como o usuário disse ("tosa completa"), quando você não souber o número: o servidor procura.'],
                ],
                obrigatorios: [],
                formRequest: AlterarServicoAgendamentoRequest::class,
                executar: function (array $d, int $tenantId, User $ator) use ($agenda) {
                    $this->recusarSeForaDoSetor(Agendamento::query()->where('tenant_id', $tenantId)->find((int) $d['id'])?->setor);
                    $this->recusarSeForaDoSetor(CatalogoItem::query()->where('tenant_id', $tenantId)->find((int) $d['catalogo_item_id'])?->setor);
                    $agendamento = $agenda->trocarServico($d['id'], $d['catalogo_item_id'], $d['profissional_id'], $d['inicio'], $tenantId, $d['versao'],
                        $this->autorizacaoDaAgenda($ator));
                    // O "antes" na auditoria: serviço, setor, profissional, início e fim de antes da troca.
                    $campos = ['catalogo_item_id', 'setor', 'profissional_id', 'inicio', 'fim'];
                    $this->detalhesAuditoria->anotar('valores_anteriores', array_intersect_key([...$agendamento->only($campos), ...$agendamento->getPrevious()], array_flip($campos)));

                    return $agendamento;
                },
                mensagemErro: 'Não consegui trocar o serviço do agendamento. Nada foi gravado.',
                titulo: 'Trocar o serviço do agendamento',
                sucesso: 'Serviço do agendamento trocado',
                conferir: function (array $a) use ($agenda): ?array {
                    $tenantId = (int) $a['tenant_id'];
                    $motivo = $this->motivoForaDoSetorDaAgenda(Agendamento::query()->where('tenant_id', $tenantId)->find((int) ($a['id'] ?? 0))?->setor)
                        ?? $this->motivoForaDoSetorDaAgenda(CatalogoItem::query()->where('tenant_id', $tenantId)->find((int) ($a['catalogo_item_id'] ?? 0))?->setor)
                        ?? $agenda->motivoParaNaoTrocarServico((int) ($a['id'] ?? 0), $a['catalogo_item_id'] ?? null, $a['profissional_id'] ?? null, $a['inicio'] ?? null,
                            $tenantId, $this->versaoDaFicha($a), $this->autorizacaoDaAgenda(null));

                    return $motivo === null ? null : ['campo' => 'catalogo_item_id', 'motivo' => $motivo];
                },
                localizar: fn (?int $id, ?string $busca, int $tenantId) => $agenda->localizarParaRemarcar($id, $busca, $tenantId, $this->setoresDaAgenda()),
                rotulo: 'o agendamento',
                buscaPor: 'pet, serviço ou profissional',
                colunaRotulo: 'resumo',
                rotuloAcao: 'Escolher',
                campos: ['catalogo_item_id', 'profissional_id', 'inicio'],
                resumir: fn (array $v, int $tenantId) => $this->fichaDoAgendamento('trocar_servico', $v, $tenantId),
                colunasLista: self::COLUNAS_DA_AGENDA,
                preparar: fn (array $argumentos, array $propostos, int $tenantId) => $this->prepararTrocaDeServico($argumentos, $propostos, $tenantId),
                comVersao: true,
                exigeCrmv: fn (array $v, int $tenantId) => $agenda->exigeCrmv((int) ($v['id'] ?? 0), $v['catalogo_item_id'] ?? null, $tenantId),
            ),
            // F (P-C4): agendar e encaixe de emergência. A origem é fixada pelo servidor em cada tool (a IA não escolhe):
            // a regra (pet, serviço e profissional do tenant, setor, passado, vaga, conflito, duração) é toda do AgendaService.
            ...array_map(fn (array $t) => $this->definirAcao(
                nome: $t['nome'],
                descricao: $t['descricao'].' Pet, serviço do catálogo e profissional pelo id; início no formato AAAA-MM-DD HH:MM (horário de São Paulo).',
                acao: 'agendar',
                entidade: 'Agendamento',
                papeis: $t['papeis'],
                propriedades: [
                    'pet_id' => ['type' => 'integer'],
                    'pet' => ['type' => 'string', 'description' => 'Nome do pet, quando o número não é conhecido (o servidor procura).'],
                    'catalogo_item_id' => ['type' => 'integer', 'description' => 'Id do serviço do catálogo (não produto).'.$this->numerosDosServicos()],
                    'profissional_id' => ['type' => 'integer', 'description' => 'Id do veterinário (clínica) ou do atendente (banho e tosa). Em branco se o usuário não disse quem ("qualquer veterinário").'],
                    'inicio' => ['type' => 'string', 'description' => 'AAAA-MM-DD HH:MM'],
                ],
                // profissional_id é opcional (25/11): em branco = "qualquer", e o servidor escolhe o de menos atendimentos no dia.
                obrigatorios: ['pet_id', 'catalogo_item_id', 'inicio'],
                formRequest: AgendarRequest::class,
                // O fim não é pedido: o servidor calcula pelo modelo do setor (AgendaService / GradeDaAgenda).
                executar: function (array $d, int $tenantId, User $ator) use ($agenda, $t) {
                    $this->recusarSeForaDoSetor(CatalogoItem::query()->where('tenant_id', $tenantId)->find((int) ($d['catalogo_item_id'] ?? 0))?->setor);

                    return $agenda->agendar([
                        ...array_intersect_key($d, array_flip(['pet_id', 'catalogo_item_id', 'profissional_id', 'inicio'])),
                        'origem' => $t['origem'],
                    ], $tenantId, $this->autorizacaoDaAgenda($ator));
                },
                mensagemErro: 'Não consegui agendar agora. Nada foi gravado.',
                titulo: $t['titulo'],
                sucesso: $t['sucesso'],
                conferir: function (array $a) use ($agenda, $t): ?array {
                    $motivo = $this->motivoForaDoSetorDaAgenda(CatalogoItem::query()->where('tenant_id', (int) $a['tenant_id'])->find((int) ($a['catalogo_item_id'] ?? 0))?->setor)
                        ?? $agenda->motivoParaNaoAgendar([...$a, 'origem' => $t['origem']], (int) $a['tenant_id'], $this->autorizacaoDaAgenda(null));

                    return $motivo === null ? null : ['campo' => 'inicio', 'motivo' => $motivo];
                },
                // Só o agendar normal usa a ficha com o calendário; o encaixe (já aconteceu) segue o card comum.
                resumir: $t['origem'] === 'normal' ? fn (array $v, int $tenantId) => $this->fichaDoAgendamento('agendar', $v, $tenantId) : null,
                preparar: fn (array $argumentos, array $propostos, int $tenantId) => $this->prepararPetPeloNome($argumentos, $tenantId),
                exigeCrmv: $t['origem'] === 'normal' ? fn (array $v, int $tenantId) => $agenda->exigeCrmv(null, $v['catalogo_item_id'] ?? null, $tenantId) : null,
            ), [
                ['nome' => 'agendar_atendimento', 'origem' => 'normal', 'papeis' => self::PAPEIS_DA_AGENDA,
                    'titulo' => 'Agendar', 'sucesso' => 'Atendimento agendado',
                    'descricao' => 'Agenda um NOVO atendimento da clínica ou do banho e tosa, de agora em diante. Informe só o início: o servidor calcula o fim. Para mudar o horário de um agendamento que já existe, use remarcar_agendamento; para trocar o serviço dele, alterar_servico_agendamento. Na clínica o horário é por bloco de 1 h (08:00 … 17:00): consultas dividem o bloco (até o limite de vagas por bloco configurado pelo dono, por veterinário), os demais serviços ocupam o bloco inteiro e a cirurgia 2 blocos; cirurgia só o próprio veterinário com CRMV agenda, para si. No banho e tosa, 1 por vez.'],
                ['nome' => 'registrar_encaixe_emergencia', 'origem' => 'encaixe_emergencia', 'papeis' => ['recepcao', 'veterinario', 'dono'],
                    'titulo' => 'Registrar encaixe de emergência', 'sucesso' => 'Encaixe de emergência registrado',
                    'descricao' => 'Registra na agenda da clínica um atendimento de emergência que já começou (início agora ou antes); não confere vaga nem horário. Só da clínica.'],
            ]),

            // ---------------------------------------------------------------- cobrança (P-B4)
            $this->definirTool(
                nome: 'consultar_cobrancas',
                descricao: 'Lista as cobranças dos atendimentos (aberta, fechada = a receber, paga, cancelada), com pet, serviço, total e forma de pagamento. Ação de leitura — não exige confirmação.',
                tipo: 'leitura',
                risco: 'leitura',
                acao: 'consultar',
                entidade: 'Cobranca',
                // Decisão do Lucas (15/11), tabela da FASE oito: o veterinário VÊ as cobranças (não cobra, não recebe, não cancela).
                papeis: [...$quemCobra, 'veterinario'],
                propriedades: [
                    'status' => ['type' => 'string', 'enum' => Cobranca::STATUS],
                    'setor' => ['type' => 'string', 'enum' => CatalogoItem::SETORES],
                    'id' => ['type' => 'integer', 'description' => 'Número de um registro específico.'],
                ],
                obrigatorios: [],
                executar: fn (array $a) => $cobrancas->buscarPorFiltro($a['status'] ?? null, $a['setor'] ?? null, $a['tenant_id'], isset($a['id']) ? (int) $a['id'] : null)
                    ->map(fn (Cobranca $c) => [
                        'id' => (int) $c->id,
                        'pet' => $c->getAttribute('pet'),
                        'servico' => $c->getAttribute('servico'),
                        'atendimento' => \Carbon\CarbonImmutable::parse($c->getAttribute('atendimento_em'), (string) config('app.timezone'))->format('d/m/Y H:i'),
                        'total' => $this->reais((int) $c->total_centavos),
                        // T2: estornado (soma dos estornos por itens; estorno antigo, de antes da T2: a coluna estorno_valor) e líquido.
                        'estornado' => $this->reais($estornado = $this->estornadoDaCobranca($c)),
                        // Líquido só onde houve pagamento (paga, ou cancelada por estorno); nas outras, "—".
                        'liquido' => $c->status === 'paga' || $estornado > 0 ? $this->reais((int) $c->total_centavos - $estornado) : '—',
                        'status' => $c->status === 'fechada' ? 'a receber' : $c->status,
                        'forma_pagamento' => $c->forma_pagamento,
                        ...($c->status === 'fechada' && $this->podeEditar('receber_cobranca') ? ['acao' => ['tool' => 'receber_cobranca', 'rotulo' => 'Receber']] : []),
                    ])->all(),
                mensagemErro: 'Não consegui consultar as cobranças agora. Tente novamente.',
                colunas: ['id', 'pet', 'servico', 'atendimento', 'total', 'estornado', 'liquido', 'status', 'forma_pagamento'],
            ),
            $this->definirAcao(
                nome: 'cobrar_atendimento',
                descricao: 'Cobra um atendimento já realizado: abre a cobrança com o serviço do agendamento, inclui o que o veterinário registrou no prontuário assinado e deixa a cobrança a receber. Achado pelo id do agendamento ou pelo nome do pet. Os preços vêm do catálogo: nunca informe valor.',
                acao: 'cobrar',
                entidade: 'Cobranca',
                papeis: $quemCobra,
                propriedades: [],
                obrigatorios: [],
                formRequest: RegistroIdRequest::class,
                executar: fn (array $d, int $tenantId) => $cobrancas->cobrarAtendimento($d['id'], $tenantId),
                mensagemErro: 'Não consegui cobrar o atendimento. Nada foi gravado.',
                titulo: 'Cobrar o atendimento',
                sucesso: 'Atendimento cobrado: a cobrança está a receber',
                conferir: fn (array $a) => $this->recusaDe(fn () => $cobrancas->previaDaCobranca((int) ($a['id'] ?? 0), (int) $a['tenant_id']), 'o atendimento'),
                localizar: $cobrancas->atendimentosParaCobrar(...),
                rotulo: 'o atendimento',
                colunaRotulo: 'pet',
                rotuloAcao: 'Cobrar',
                campos: [],
                resumir: function (array $v, int $tenantId) use ($cobrancas): array {
                    $previa = $cobrancas->previaDaCobranca((int) ($v['id'] ?? 0), $tenantId);

                    return ['resumo' => [...$this->resumoDoAgendamento((int) ($v['id'] ?? 0), $tenantId), ...$this->linhasDoResumo($previa['linhas'], $previa['total_centavos'])],
                        'avisos' => $previa['avisos']];
                },
            ),
            $this->definirAcao(
                nome: 'receber_cobranca',
                descricao: 'Registra o pagamento de uma cobrança a receber (fechada): dinheiro, pix ou cartao. Achada pelo id da cobrança ou pelo nome do pet.',
                acao: 'receber',
                entidade: 'Cobranca',
                papeis: $quemCobra,
                propriedades: ['forma_pagamento' => ['type' => 'string', 'enum' => Cobranca::FORMAS_PAGAMENTO]],
                obrigatorios: ['forma_pagamento'],
                formRequest: ReceberCobrancaRequest::class,
                executar: fn (array $d, int $tenantId) => $cobrancas->registrarPagamento($d['id'], $d['forma_pagamento'], $tenantId),
                mensagemErro: 'Não consegui registrar o pagamento. Nada foi gravado.',
                titulo: 'Receber a cobrança',
                sucesso: 'Pagamento registrado',
                conferir: fn (array $a) => $cobrancas->localizar((int) ($a['id'] ?? 0), null, (int) $a['tenant_id'], ['fechada'])->isEmpty()
                    ? ['campo' => 'id', 'motivo' => 'Não encontrei a cobrança a receber #'.(int) ($a['id'] ?? 0).'.'] : null,
                localizar: fn (?int $id, ?string $busca, int $tenantId) => $cobrancas->localizar($id, $busca, $tenantId, ['fechada']),
                rotulo: 'a cobrança a receber',
                colunaRotulo: 'pet',
                rotuloAcao: 'Receber',
                campos: ['forma_pagamento'],
                resumir: fn (array $v, int $tenantId) => $this->resumoDaCobranca((int) ($v['id'] ?? 0), $tenantId),
            ),
            $this->definirAcao(
                nome: 'cancelar_cobranca',
                descricao: 'Cancela uma cobrança que ainda não foi paga (aberta ou a receber); o estoque dos itens avulsos volta. Cobrança paga não se cancela: é estornada. Achada pelo id da cobrança ou pelo nome do pet.',
                acao: 'cancelar',
                entidade: 'Cobranca',
                papeis: $quemCobra,
                propriedades: [],
                obrigatorios: [],
                formRequest: RegistroIdRequest::class,
                executar: fn (array $d, int $tenantId) => $cobrancas->cancelar($d['id'], $tenantId),
                mensagemErro: 'Não consegui cancelar a cobrança. Nada foi gravado.',
                titulo: 'Cancelar a cobrança',
                sucesso: 'Cobrança cancelada',
                conferir: fn (array $a) => $cobrancas->localizar((int) ($a['id'] ?? 0), null, (int) $a['tenant_id'], ['aberta', 'fechada'])->isEmpty()
                    ? ['campo' => 'id', 'motivo' => 'Não encontrei a cobrança em aberto #'.(int) ($a['id'] ?? 0).'.'] : null,
                risco: 'exclusao',
                localizar: fn (?int $id, ?string $busca, int $tenantId) => $cobrancas->localizar($id, $busca, $tenantId, ['aberta', 'fechada']),
                rotulo: 'a cobrança em aberto',
                colunaRotulo: 'pet',
                rotuloAcao: 'Cancelar',
                campos: [],
                resumir: fn (array $v, int $tenantId) => $this->resumoDaCobranca((int) ($v['id'] ?? 0), $tenantId),
            ),
            // H, T2 (14/11): estorno por itens de cobrança PAGA no card. Pede: a recepção (clínica), o atendente (petshop) ou o
            // dono; quem é do setor certo o CobrancaService confere. Autoriza: o supervisor do setor ou o dono, com o PIN
            // (T1), digitado no card (reautenticar 'estorno'); a conferência fica no /agente/confirmar e nunca vai para a IA.
            // A IA escolhe o modo e, em "itens", as linhas e as quantidades; o VALOR é sempre do servidor.
            $this->definirAcao(
                nome: 'estornar_cobranca',
                descricao: 'Estorna, no todo ou em parte, uma cobrança PAGA. modo: "tudo" (tudo o que ainda não foi estornado), "tudo_menos_prontuario" (sem o que veio do prontuário) ou "itens" (só as linhas e quantidades em linhas; item_id é o número da linha da cobrança). Nunca informe valor: o servidor calcula pelo preço da época. Parcial: a cobrança continua paga. O estoque dos itens avulsos volta; o do prontuário nunca. Precisa de motivo e da autorização de um supervisor do setor ou do dono (PIN), digitada só no card de confirmação: nunca peça nem aceite PIN ou senha no chat. Achada pelo id da cobrança ou pelo nome do pet.',
                acao: 'estornar',
                entidade: 'Cobranca',
                papeis: ['recepcao', 'atendente', 'dono'],
                propriedades: [
                    'motivo' => ['type' => 'string', 'description' => 'Por que a cobrança paga está sendo estornada.'],
                    ...$this->propriedadesDoEstorno(EstornarCobrancaRequest::MODOS),
                ],
                obrigatorios: [],
                formRequest: EstornarCobrancaRequest::class,
                // Quem pede é o usuário logado; quem autorizou é quem o card conferiu (autorizadoPor), nunca a IA.
                executar: fn (array $d, int $tenantId, User $ator) => $cobrancas->estornar($d['id'], (string) $d['motivo'], $ator->id,
                    $this->autorizadoPor() ?? 0, $tenantId, $this->autorizadoPor() !== null, (string) $d['modo'], $this->itensDoEstorno($d['linhas'] ?? [])),
                mensagemErro: 'Não consegui estornar a cobrança. Nada foi gravado.',
                titulo: 'Estornar a cobrança',
                sucesso: 'Estorno registrado',
                conferir: fn (array $a) => $cobrancas->localizar((int) ($a['id'] ?? 0), null, (int) $a['tenant_id'], ['paga'])->isEmpty()
                    ? ['campo' => 'id', 'motivo' => 'Não encontrei a cobrança paga #'.(int) ($a['id'] ?? 0).'.']
                    : $this->recusaDe(fn () => $cobrancas->planoDoEstorno((int) $a['id'], (string) ($a['modo'] ?? ''), $this->itensDoEstorno($a['linhas'] ?? []), (int) $a['tenant_id']), 'a cobrança paga', 'itens'),
                risco: 'exclusao',
                localizar: fn (?int $id, ?string $busca, int $tenantId) => $cobrancas->localizar($id, $busca, $tenantId, ['paga']),
                rotulo: 'a cobrança paga',
                colunaRotulo: 'pet',
                rotuloAcao: 'Estornar',
                colunasLista: ['id', 'data', 'pet', 'total'], // 24/11: duas cobranças do mesmo pet se distinguem pela data e pelo total
                campos: ['modo', 'motivo', 'linhas'],
                resumir: fn (array $v, int $tenantId) => $this->resumoDoEstorno(
                    $this->resumoDaCobranca((int) ($v['id'] ?? 0), $tenantId, comLinhas: false),
                    fn () => $cobrancas->linhasParaEstornar((int) ($v['id'] ?? 0), $tenantId),
                    fn () => $cobrancas->planoDoEstorno((int) ($v['id'] ?? 0), (string) ($v['modo'] ?? ''), $this->itensDoEstorno($v['linhas'] ?? []), $tenantId),
                    $v,
                ),
                reautenticar: 'estorno',
            ),

            // ---------------------------------------------------------------- venda avulsa (decisão de 09/11)
            $this->definirAcao(
                nome: 'registrar_venda',
                descricao: 'Registra uma venda avulsa de itens do catálogo (produto ou serviço, todos do mesmo setor), opcionalmente ligada a um tutor, a um pet e a um atendimento. Os preços vêm do catálogo: nunca informe valor. Sem forma de pagamento, a venda fica em aberto. Item que exige receita não é vendido.',
                acao: 'vender',
                entidade: 'Venda',
                papeis: self::PAPEIS_EQUIPE,
                propriedades: [
                    'itens' => ['type' => 'array', 'description' => 'Cada item: o id do item do catálogo e a quantidade inteira.'.$this->numerosDoCatalogo(), 'items' => [
                        'type' => 'object',
                        'properties' => ['catalogo_item_id' => ['type' => 'integer'], 'quantidade' => ['type' => 'integer']],
                        'required' => ['catalogo_item_id', 'quantidade'],
                        'additionalProperties' => false,
                    ]],
                    'forma_pagamento' => ['type' => 'string', 'enum' => Cobranca::FORMAS_PAGAMENTO],
                    'tutor_id' => ['type' => 'integer'],
                    'pet_id' => ['type' => 'integer'],
                    'agendamento_id' => ['type' => 'integer', 'description' => 'Id do atendimento, se a venda é dele.'],
                ],
                obrigatorios: ['itens'],
                formRequest: RegistrarVendaRequest::class,
                // O vendedor é sempre quem está logado (o $ator), nunca alguém informado pela IA ou pelo navegador.
                executar: fn (array $d, int $tenantId, User $ator) => $vendas->registrar($this->itensDaVenda($d['itens']), $ator->id, $d['tutor_id'] ?? null,
                    $d['forma_pagamento'] ?? null, $tenantId, $d['pet_id'] ?? null, $d['agendamento_id'] ?? null),
                mensagemErro: 'Não consegui registrar a venda. Nada foi gravado.',
                titulo: 'Registrar a venda',
                sucesso: 'Venda registrada',
                conferir: fn (array $a) => $this->recusaDe(fn () => $this->previaDaVenda($vendas, $a, (int) $a['tenant_id']), 'a venda', 'itens'),
                campos: ['forma_pagamento', 'tutor_id', 'pet_id'],
                resumir: function (array $v, int $tenantId) use ($vendas): array {
                    $previa = $this->previaDaVenda($vendas, $v, $tenantId);

                    return ['resumo' => [
                        ['rotulo' => 'Setor', 'valor' => $previa['setor'] === 'clinica' ? 'Clínica' : 'Petshop'],
                        ...(isset($v['agendamento_id']) ? $this->resumoDoAgendamento((int) $v['agendamento_id'], $tenantId, 'Atendimento') : []),
                        ...$this->linhasDoResumo($previa['linhas'], $previa['total_centavos']),
                    ], 'avisos' => $previa['avisos']];
                },
            ),

            // T2 (14/11): consulta das vendas (total, estornado e líquido) e estorno por itens da venda PAGA (resposta E).
            $this->definirTool(
                nome: 'consultar_vendas',
                descricao: 'Lista as vendas avulsas (aberta, paga, cancelada), com setor, tutor, pet, total, estornado e líquido. Ação de leitura — não exige confirmação.',
                tipo: 'leitura',
                risco: 'leitura',
                acao: 'consultar',
                entidade: 'Venda',
                papeis: self::PAPEIS_EQUIPE,
                propriedades: [
                    'status' => ['type' => 'string', 'enum' => Venda::STATUS],
                    'setor' => ['type' => 'string', 'enum' => CatalogoItem::SETORES],
                    'id' => ['type' => 'integer', 'description' => 'Número de um registro específico.'],
                ],
                obrigatorios: [],
                executar: fn (array $a) => $vendas->buscarPorFiltro($a['status'] ?? null, $a['setor'] ?? null, $a['tenant_id'], isset($a['id']) ? (int) $a['id'] : null)
                    ->map(fn (Venda $venda) => [
                        'id' => (int) $venda->id,
                        'data' => ($venda->pago_em ?? $venda->created_at)?->format('d/m/Y H:i'),
                        'setor' => $venda->setor === 'clinica' ? 'Clínica' : 'Petshop',
                        'tutor' => $venda->getAttribute('tutor'),
                        'pet' => $venda->getAttribute('pet'),
                        'total' => $this->reais((int) $venda->total_centavos),
                        'estornado' => $this->reais((int) $venda->getAttribute('estornado_centavos')),
                        'liquido' => $venda->status === 'paga' || (int) $venda->getAttribute('estornado_centavos') > 0
                            ? $this->reais((int) $venda->total_centavos - (int) $venda->getAttribute('estornado_centavos')) : '—',
                        'status' => $venda->status,
                        'forma_pagamento' => $venda->forma_pagamento,
                    ])->all(),
                mensagemErro: 'Não consegui consultar as vendas agora. Tente novamente.',
                colunas: ['id', 'data', 'setor', 'tutor', 'pet', 'total', 'estornado', 'liquido', 'status', 'forma_pagamento'],
            ),
            $this->definirAcao(
                nome: 'estornar_venda',
                descricao: 'Estorna, no todo ou em parte, uma venda avulsa PAGA, com a mesma regra da cobrança. modo: "tudo" (tudo o que ainda não foi estornado) ou "itens" (só as linhas e quantidades em linhas; item_id é o número da linha da venda). Nunca informe valor: o servidor calcula pelo preço da época. Parcial: a venda continua paga; o estoque volta. Precisa de motivo e da autorização de um supervisor do setor ou do dono (PIN), digitada só no card de confirmação: nunca peça nem aceite PIN ou senha no chat. Achada pelo id da venda ou pelo nome do tutor ou do pet.',
                acao: 'estornar',
                entidade: 'Venda',
                papeis: ['recepcao', 'atendente', 'dono'],
                propriedades: [
                    'motivo' => ['type' => 'string', 'description' => 'Por que a venda paga está sendo estornada.'],
                    ...$this->propriedadesDoEstorno(EstornarVendaRequest::MODOS),
                ],
                obrigatorios: [],
                formRequest: EstornarVendaRequest::class,
                executar: fn (array $d, int $tenantId, User $ator) => $vendas->estornar($d['id'], (string) $d['motivo'], $ator->id,
                    $this->autorizadoPor() ?? 0, $tenantId, $this->autorizadoPor() !== null, (string) $d['modo'], $this->itensDoEstorno($d['linhas'] ?? [])),
                mensagemErro: 'Não consegui estornar a venda. Nada foi gravado.',
                titulo: 'Estornar a venda',
                sucesso: 'Estorno registrado',
                conferir: fn (array $a) => $vendas->localizar((int) ($a['id'] ?? 0), null, (int) $a['tenant_id'], ['paga'])->isEmpty()
                    ? ['campo' => 'id', 'motivo' => 'Não encontrei a venda paga #'.(int) ($a['id'] ?? 0).'.']
                    : $this->recusaDe(fn () => $vendas->planoDoEstorno((int) $a['id'], (string) ($a['modo'] ?? ''), $this->itensDoEstorno($a['linhas'] ?? []), (int) $a['tenant_id']), 'a venda paga', 'itens'),
                risco: 'exclusao',
                localizar: fn (?int $id, ?string $busca, int $tenantId) => $vendas->localizar($id, $busca, $tenantId, ['paga']),
                rotulo: 'a venda paga',
                buscaPor: 'nome do tutor ou do pet',
                colunaRotulo: 'tutor',
                rotuloAcao: 'Estornar',
                colunasLista: ['id', 'data', 'tutor', 'pet', 'total'], // 24/11: idem
                campos: ['modo', 'motivo', 'linhas'],
                resumir: fn (array $v, int $tenantId) => $this->resumoDoEstorno(
                    $this->resumoDaVenda((int) ($v['id'] ?? 0), $tenantId),
                    fn () => $vendas->linhasParaEstornar((int) ($v['id'] ?? 0), $tenantId),
                    fn () => $vendas->planoDoEstorno((int) ($v['id'] ?? 0), (string) ($v['modo'] ?? ''), $this->itensDoEstorno($v['linhas'] ?? []), $tenantId),
                    $v,
                ),
                reautenticar: 'estorno',
            ),

            // ---------------------------------------------------------------- caixa de entrada (P-B5)
            $this->definirTool(
                nome: 'consultar_mensagens',
                descricao: 'Lista as mensagens da caixa de entrada (simulada) dos tutores, novas ou tratadas. Ação de leitura — não exige confirmação.',
                tipo: 'leitura',
                risco: 'leitura',
                acao: 'consultar',
                entidade: 'Mensagem',
                papeis: self::PAPEIS_EQUIPE,
                propriedades: ['status' => ['type' => 'string', 'enum' => Mensagem::STATUS]],
                obrigatorios: [],
                executar: function (array $a) use ($mensagens): array {
                    $lista = $mensagens->buscarPorFiltro($a['status'] ?? null, $a['tenant_id']);
                    $pets = $this->petsDosTutores($lista, (int) $a['tenant_id']);

                    return $lista->map(fn (Mensagem $m) => [
                        'id' => (int) $m->id,
                        // E-mail (origem_id): o "De" é livre (sem SPF/DKIM), então mostra SEMPRE o remetente de verdade, marcado como não
                        // verificado, e nunca só o nome do tutor vinculado (um remetente forjado pareceria o próprio tutor).
                        'de' => $m->origem_id !== null ? ($m->remetente ?? '—').' (remetente não verificado)' : ($m->getAttribute('tutor') ?? $m->remetente ?? '—'),
                        'mensagem' => $m->texto,
                        'recebida_em' => $m->created_at?->format('d/m/Y H:i'),
                        'status' => $m->status,
                        // P-B5: o botão "Pedir ao agente" manda este texto (com o tutor e os pets) como um pedido normal (com Revisar e segurar).
                        ...($m->status === 'nova' ? ['pedido' => $this->pedidoDaMensagem($m, $pets)] : []),
                        ...($m->status === 'nova' && $this->podeEditar('marcar_mensagem_tratada') ? ['acao' => ['tool' => 'marcar_mensagem_tratada', 'rotulo' => 'Marcar como tratada']] : []),
                    ])->all();
                },
                mensagemErro: 'Não consegui consultar as mensagens agora. Tente novamente.',
                colunas: ['id', 'de', 'mensagem', 'recebida_em', 'status'],
            ),
            $this->definirAcao(
                nome: 'marcar_mensagem_tratada',
                descricao: 'Marca uma mensagem nova da caixa de entrada como tratada, pelo número (id) dela.',
                acao: 'atualizar',
                entidade: 'Mensagem',
                papeis: self::PAPEIS_EQUIPE,
                propriedades: [],
                obrigatorios: [],
                formRequest: RegistroIdRequest::class,
                executar: fn (array $d, int $tenantId) => $mensagens->marcarTratada($d['id'], $tenantId),
                mensagemErro: 'Não consegui marcar a mensagem. Tente novamente.',
                titulo: 'Marcar a mensagem como tratada',
                sucesso: 'Mensagem marcada como tratada',
                localizar: $mensagens->localizar(...),
                rotulo: 'a mensagem nova',
                buscaPor: 'número da mensagem',
                colunaRotulo: 'remetente',
                campos: [],
                resumir: function (array $v, int $tenantId): array {
                    $mensagem = Mensagem::query()->where('tenant_id', $tenantId)->with('tutor')->find((int) ($v['id'] ?? 0));

                    return $mensagem === null ? [] : [
                        // Mesma regra da listagem: e-mail (origem_id) tem "De" livre, então mostra o remetente real, não verificado.
                        ['rotulo' => 'De', 'valor' => $mensagem->origem_id !== null
                            ? ($mensagem->remetente ?? '—').' (remetente não verificado)'
                            : ($mensagem->tutor?->nome ?? $mensagem->remetente)],
                        ['rotulo' => 'Mensagem', 'valor' => $mensagem->texto],
                        ['rotulo' => 'Recebida em', 'valor' => $mensagem->created_at?->format('d/m/Y H:i')],
                    ];
                },
            ),
        ];
    }

    /** Até PETS_NO_PEDIDO pets (id e nome) de cada tutor das mensagens novas, só do tenant, numa consulta só. */
    private function petsDosTutores(\Illuminate\Support\Collection $mensagens, int $tenantId): array
    {
        $tutores = $mensagens->where('status', 'nova')->pluck('tutor_id')->filter()->unique()->values()->all();

        return $tutores === [] ? [] : Pet::query()->where('tenant_id', $tenantId)->whereIn('tutor_id', $tutores)
            ->orderBy('nome')->orderBy('id')->get(['id', 'nome', 'tutor_id'])->groupBy('tutor_id')
            ->map(fn ($pets) => $pets->take(self::PETS_NO_PEDIDO)->map(fn (Pet $p) => ['id' => (int) $p->id, 'nome' => (string) $p->nome])->all())->all();
    }

    /**
     * Caixa de entrada → pedido ao agente: o texto da mensagem com o contexto que a IA não tem (o número do tutor e os
     * pets dele, com o número de cada um), para o card de agendamento já vir com o pet. O nome e o telefone de quem
     * escreveu não vão: os números bastam, e o card mostra os nomes. Acima do limite do chat, vai só o texto.
     */
    private function pedidoDaMensagem(Mensagem $mensagem, array $petsPorTutor): string
    {
        $tutorId = $mensagem->tutor_id === null ? null : (int) $mensagem->tutor_id;
        $pets = $tutorId === null ? [] : ($petsPorTutor[$tutorId] ?? []);
        $contexto = match (true) {
            $tutorId === null => 'remetente sem cadastro',
            $pets === [] => 'tutor #'.$tutorId.', sem pet cadastrado',
            default => 'tutor #'.$tutorId.'; pets: '.implode(', ', array_map(fn (array $p) => $p['nome'].' = pet #'.$p['id'], $pets)),
        };
        // O texto vai como string JSON (aspas e quebras escapadas): quem escreveu não fecha a aspa para forjar o contexto.
        $prefixo = 'Mensagem da caixa de entrada ('.$contexto.'): ';
        $codificar = fn (string $t) => $prefixo.json_encode($t, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $texto = (string) $mensagem->texto;
        $pedido = $codificar($texto);

        // E-mail (texto de fora): NUNCA cai no texto cru (que viraria o próprio comando, sem o enquadramento). Se não cabe,
        // corta o TEXTO até caber, sempre com o prefixo e as aspas. Mensagem do seed mantém o comportamento antigo.
        if ($mensagem->origem_id !== null) {
            while (mb_strlen($pedido) > self::LIMITE_DO_PEDIDO && $texto !== '') {
                $texto = mb_substr($texto, 0, max(0, mb_strlen($texto) - max(10, intdiv(mb_strlen($pedido) - self::LIMITE_DO_PEDIDO, 2) + 1)));
                $pedido = $codificar($texto);
            }

            return $pedido;
        }

        return mb_strlen($pedido) <= self::LIMITE_DO_PEDIDO ? $pedido : $texto;
    }

    /**
     * A ficha de agendamento (15/11; agendar, remarcar e trocar o serviço): o cabeçalho (pet e tutor com link, e o
     * serviço, o profissional e o horário atuais), só leitura, e o que o calendário precisa (serviço, setor, dia e o
     * agendamento a ignorar na contagem). Os atalhos abrem a ficha de outra ação do mesmo agendamento, sem IA, só as
     * que o papel pode usar. Tudo do tenant; o agendamento já veio do localizar, que filtra o setor do papel.
     */
    private function fichaDoAgendamento(string $acao, array $v, int $tenantId): array
    {
        $ag = $acao === 'agendar' ? null
            : Agendamento::query()->where('tenant_id', $tenantId)->whereIn('setor', $this->setoresDaAgenda())->with(['pet.tutor', 'servico', 'profissional'])->find((int) ($v['id'] ?? 0));
        $petId = (int) ($v['pet_id'] ?? 0);
        $pet = $ag?->pet ?? ($petId >= 1 ? Pet::query()->where('tenant_id', $tenantId)->with('tutor')->find($petId) : null);
        $servicoId = $acao === 'remarcar' ? (int) $ag?->catalogo_item_id : (int) ($v['catalogo_item_id'] ?? 0);
        $servico = $servicoId >= 1 ? CatalogoItem::query()->where('tenant_id', $tenantId)->where('tipo', 'servico')->find($servicoId) : null;

        $cabecalho = [];
        if ($pet !== null) {
            $cabecalho[] = ['rotulo' => 'Pet', 'valor' => $pet->nome, 'href' => '/pets/'.$pet->id];
            $tutor = $pet->tutor !== null && (int) $pet->tutor->tenant_id === $tenantId ? $pet->tutor : null;
            if ($tutor !== null) {
                $cabecalho[] = ['rotulo' => 'Tutor', 'valor' => $tutor->nome.($tutor->telefone ? ' · '.\App\Support\Mascara::telefone($tutor->telefone) : ''), 'href' => '/tutores/'.$tutor->id];
            }
        }
        if ($ag !== null) {
            $cabecalho[] = ['rotulo' => $acao === 'trocar_servico' ? 'Serviço atual' : 'Serviço', 'valor' => $ag->servico?->nome_comercial];
            $cabecalho[] = ['rotulo' => 'Profissional atual', 'valor' => $ag->profissional?->name];
            $cabecalho[] = ['rotulo' => 'Horário atual', 'valor' => $ag->inicio->format('d/m/Y H:i').'–'.$ag->fim->format('H:i')];
            $cabecalho[] = ['rotulo' => 'Situação', 'valor' => $ag->status];
        }

        $atalhos = [];
        if ($ag !== null) {
            $dela = $acao === 'remarcar' ? 'remarcar_agendamento' : 'alterar_servico_agendamento';
            foreach (['remarcar_agendamento' => 'Remarcar', 'alterar_servico_agendamento' => 'Trocar serviço', 'alterar_status_agendamento' => 'Mudar status'] as $tool => $rotulo) {
                if ($tool !== $dela && $this->podeExecutar($tool)) {
                    $atalhos[] = ['tool' => $tool, 'rotulo' => $rotulo];
                }
            }
        }

        $inicio = is_string($v['inicio'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v['inicio']) === 1 ? substr($v['inicio'], 0, 10) : null;
        $avisos = [];
        // 15/11: cirurgia e internação só por quem tem CRMV no perfil; os demais papéis veem logo que só consultam.
        if ($servico !== null && blank($this->contexto->usuario()->crmv) && app(AgendaService::class)->exigeCrmv($ag?->id, $servico->id, $tenantId)) {
            $avisos[] = AgendaService::RECUSA_SEM_CRMV;
        }
        // Troca no mesmo setor: o horário atual pode não servir para o serviço novo (ex.: cirurgia de 2 blocos).
        if ($acao === 'trocar_servico' && $ag !== null && $servico !== null && ! blank($v['profissional_id'] ?? null)) {
            $motivo = app(AgendaService::class)->motivoParaNaoTrocarServico((int) $ag->id, $servico->id, $v['profissional_id'], $v['inicio'] ?? null, $tenantId,
                null, $this->autorizacaoDaAgenda(null));
            if ($motivo !== null) {
                $avisos[] = $motivo.' Escolha outro horário no calendário.';
            }
        }

        return [
            'resumo' => $cabecalho,
            'avisos' => $avisos,
            'ficha' => [
                'acao' => $acao,
                'agendamento_id' => $ag?->id,
                'servico_id' => $servico?->id,
                'setor' => $servico?->setor,
                'dia' => $inicio ?? ($ag !== null ? $ag->inicio->format('Y-m-d') : now()->format('Y-m-d')),
                'atalhos' => $atalhos,
            ],
        ];
    }

    /**
     * Remarcar com data RELATIVA (25/11): a IA não vê o horário do agendamento, então "pro dia 21, mesmo horário" chega como
     * dia (AAAA-MM-DD) ou dia_do_mes (só o número, no mês do próprio agendamento) e, se o usuário disser, hora (HH:MM). O
     * servidor monta o início a partir do agendamento atual (mesma hora por padrão). Com dia/dia_do_mes, o que a IA tiver
     * chutado em "inicio" não vale (ela não sabe a hora); sem eles, o "inicio" completo da IA segue como está.
     */
    private function prepararDataRelativa(array $argumentos, array $propostos, int $tenantId): array
    {
        $dia = is_string($propostos['dia'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($propostos['dia'])) === 1 ? trim($propostos['dia']) : null;
        $diaDoMes = filter_var($propostos['dia_do_mes'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 31]]);
        $hora = is_string($propostos['hora'] ?? null) && preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', trim($propostos['hora'])) === 1 ? trim($propostos['hora']) : null;
        // 26/11: deslocamento relativo ("no dia seguinte", "semana que vem", "mês que vem"): o servidor soma ao horário atual.
        $somarDias = filter_var($propostos['somar_dias'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 366]]);
        $somarSemanas = filter_var($propostos['somar_semanas'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 52]]);
        $somarMeses = filter_var($propostos['somar_meses'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
        $relativo = $somarDias !== false || $somarSemanas !== false || $somarMeses !== false;

        if ($dia === null && $diaDoMes === false && $hora === null && ! $relativo) {
            return $argumentos;
        }

        $atual = Agendamento::query()->where('tenant_id', $tenantId)->find((int) ($argumentos['id'] ?? 0))?->inicio;
        if ($atual === null) {
            return $argumentos;
        }
        $atual = \Carbon\CarbonImmutable::instance($atual)->setTimezone((string) config('app.timezone'));

        if ($dia !== null) {
            $data = \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $dia, $atual->getTimezone());
            $data = $data !== false && $data->format('Y-m-d') === $dia ? $data : null;
        } elseif ($diaDoMes !== false) {
            // Dia 31 num mês de 30 dias não existe: fica em branco (a ficha pede o dia), em vez de "pular" para o mês seguinte.
            $data = $diaDoMes <= $atual->daysInMonth ? $atual->setDate($atual->year, $atual->month, $diaDoMes) : null;
        } elseif ($relativo) {
            // Meses sem estouro (31/01 + 1 mês = 28/02); depois semanas e dias. A data nova pode ficar no passado só se a conta der isso: a ficha confere.
            $data = $atual->addMonthsNoOverflow($somarMeses === false ? 0 : $somarMeses)->addWeeks($somarSemanas === false ? 0 : $somarSemanas)->addDays($somarDias === false ? 0 : $somarDias);
        } else {
            $data = $atual;
        }

        if ($data === null) {
            return [...$argumentos, 'inicio' => null];
        }

        return [...$argumentos, 'inicio' => $data->format('Y-m-d').' '.($hora !== null ? str_pad($hora, 5, '0', STR_PAD_LEFT) : $atual->format('H:i'))];
    }

    /**
     * Trocar o serviço: o serviço novo é o que a IA indicou (do tenant, serviço; senão fica em branco e a ficha marca o
     * campo). Setor diferente do atual: o profissional fica em branco (a ficha mostra os do setor novo e o calendário).
     * 25/11: também vale o NOME do serviço dito pelo usuário ("é tosa completa"), quando só um serviço do tenant bate.
     */
    private function prepararTrocaDeServico(array $argumentos, array $propostos, int $tenantId): array
    {
        $ag = Agendamento::query()->where('tenant_id', $tenantId)->find((int) ($argumentos['id'] ?? 0));
        $novoId = filter_var($propostos['catalogo_item_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $nome = is_string($propostos['servico'] ?? null) ? trim($propostos['servico']) : '';
        if ($novoId === false && $nome !== '') {
            $achados = CatalogoItem::query()->where('tenant_id', $tenantId)->where('tipo', 'servico')->get(['id', 'nome_comercial'])
                ->filter(function (CatalogoItem $c) use ($nome) {
                    $alvo = \Illuminate\Support\Str::ascii(mb_strtolower((string) $c->nome_comercial));
                    foreach (preg_split('/[^\p{L}\p{N}]+/u', \Illuminate\Support\Str::ascii(mb_strtolower($nome)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $palavra) {
                        if (mb_strlen($palavra) >= 3 && ! str_contains($alvo, $palavra)) {
                            return false;
                        }
                    }

                    return true;
                });
            $novoId = $achados->count() === 1 ? (int) $achados->first()->id : false;
        }
        $novo = $novoId === false ? null : CatalogoItem::query()->where('tenant_id', $tenantId)->where('tipo', 'servico')->find($novoId);

        if ($ag === null || $novo === null || (int) $novo->id === (int) $ag->catalogo_item_id) {
            return [...$argumentos, 'catalogo_item_id' => null];
        }

        return [...$argumentos, 'catalogo_item_id' => (int) $novo->id, ...($novo->setor !== $ag->setor ? ['profissional_id' => null] : [])];
    }

    /** Agendar: o nome do pet dito pelo usuário vira o pet_id quando só um pet do tenant bate (nome igual primeiro). */
    private function prepararPetPeloNome(array $argumentos, int $tenantId): array
    {
        $nome = is_string($argumentos['pet'] ?? null) ? trim($argumentos['pet']) : '';
        unset($argumentos['pet']);

        // pet_id 0 (ou outro número inválido) que a IA manda no lugar de "não sei" conta como em branco.
        if ($nome !== '' && filter_var($argumentos['pet_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $pets = app(PetService::class)->buscarPorFiltro($nome, null, $tenantId);
            $iguais = $pets->filter(fn (Pet $p) => mb_strtolower((string) $p->nome) === mb_strtolower($nome));
            $um = $iguais->count() === 1 ? $iguais->first() : ($pets->count() === 1 ? $pets->first() : null);
            if ($um !== null) {
                $argumentos['pet_id'] = (int) $um->id;
            }
        }

        return $argumentos;
    }

    /** Card de alterar tutor (15/11): os pets dele, só leitura, cada um com link para a ficha (nome e espécie). */
    private function petsDoTutorNoCard(int $tutorId, int $tenantId): array
    {
        if ($tutorId < 1 || ! $this->podeExecutar('consultar_pets')) {
            return [];
        }
        $pets = Pet::query()->where('tenant_id', $tenantId)->where('tutor_id', $tutorId)->orderBy('nome')->orderBy('id')->get(['id', 'nome', 'especie']);

        return $pets->isEmpty() ? [['rotulo' => 'Pets', 'valor' => 'Nenhum pet cadastrado']]
            : $pets->map(fn (Pet $p) => ['rotulo' => 'Pet', 'valor' => $p->nome.' ('.$p->especie.')', 'href' => '/pets/'.$p->id])->all();
    }

    /** Card de alterar pet (15/11): o tutor dele, só leitura (nome e telefone), com link para a ficha do tutor. */
    private function tutorDoPetNoCard(int $petId, int $tenantId): array
    {
        $pet = $petId >= 1 && $this->podeExecutar('consultar_tutores') ? Pet::query()->where('tenant_id', $tenantId)->find($petId) : null;
        $tutor = $pet === null ? null : Tutor::query()->where('tenant_id', $tenantId)->find($pet->tutor_id);

        return $tutor === null ? [] : [['rotulo' => 'Tutor', 'valor' => $tutor->nome.($tutor->telefone ? ' · '.\App\Support\Mascara::telefone($tutor->telefone) : ''), 'href' => '/tutores/'.$tutor->id]];
    }

    /**
     * Quem grava na agenda, para a regra de cirurgia e internação (AgendaService::conferirRestrito): o usuário logado (do
     * servidor) e se ele confirmou com CRMV + senha NESTA requisição (o /agente/confirmar põe o autorizado_por depois de
     * conferir). Sem $ator é o Revisar: reautenticado = null (a senha só vem no Confirmar).
     *
     * @return array{ator: User, reautenticado: ?bool}
     */
    private function autorizacaoDaAgenda(?User $ator): array
    {
        $usuario = $ator ?? $this->contexto->usuario();

        return ['ator' => $usuario, 'reautenticado' => $ator === null ? null : $this->autorizadoPor() === (int) $usuario->id];
    }

    /** A versão da ficha que veio do rascunho (o servidor a pôs ao localizar). Sem ela, '' nunca bate: recusa. */
    private function versaoDaFicha(array $a): string
    {
        return is_string($a['versao'] ?? null) ? $a['versao'] : '';
    }

    /** Como numerosDoCatalogo(), só os SERVIÇOS dos setores da agenda do papel (agendar e trocar o serviço). */
    private function numerosDosServicos(): string
    {
        $setores = $this->setoresDaAgenda();
        $itens = app(CatalogoService::class)->buscarPorFiltro(null, null, null, $this->contexto->tenantId())
            ->filter(fn (CatalogoItem $i) => $i->tipo === 'servico' && in_array($i->setor, $setores, true));
        if ($itens->isEmpty() || $itens->count() > self::LIMITE_CATALOGO_NA_DESCRICAO) {
            return '';
        }

        return ' Serviços (número = serviço): '.$itens->sortBy('id')->map(fn (CatalogoItem $i) => $i->id.' = '.$this->nomeNaDescricao((string) $i->nome_comercial))->implode('; ').'.';
    }

    /**
     * Setores da agenda do papel logado, a fonte única do chat e das telas (tabela da FASE oito, decisão do Lucas de
     * 15/11; Bloco A de 15/11): o atendente só o banho e tosa; recepção, veterinário e dono os dois; quem não está em
     * PAPEIS_DA_AGENDA (o vendedor), nenhum.
     *
     * @return string[]
     */
    public function setoresDaAgenda(): array
    {
        return match ($this->contexto->papel()) {
            'atendente' => ['petshop'],
            'recepcao', 'veterinario', 'dono' => ['clinica', 'petshop'],
            default => [],
        };
    }

    /** Quem só vê um setor fica preso nele, qualquer que seja o setor pedido (como na tela /agenda). */
    private function setorDaAgendaPedido(mixed $setor): ?string
    {
        $setores = $this->setoresDaAgenda();

        return count($setores) === 1 ? $setores[0] : (is_string($setor) ? $setor : null);
    }

    /** Agendar ou mudar o status fora do setor do papel (o atendente na agenda da clínica) é recusado no Revisar. */
    private function motivoForaDoSetorDaAgenda(?string $setor): ?string
    {
        return $setor === null || in_array($setor, $this->setoresDaAgenda(), true)
            ? null
            : 'Seu papel só mexe na agenda do banho e tosa. Este atendimento é da clínica: peça à recepção.';
    }

    /** O mesmo teste do Revisar, na hora de gravar: setor fora do papel recusa (nada é gravado). */
    private function recusarSeForaDoSetor(?string $setor): void
    {
        $motivo = $this->motivoForaDoSetorDaAgenda($setor);
        if ($motivo !== null) {
            throw new \App\Exceptions\ToolRecusadaException($motivo);
        }
    }

    /** O agendamento no card: pet, serviço, profissional, horário e situação (só leitura, do tenant). */
    private function resumoDoAgendamento(int $id, int $tenantId, string $prefixo = ''): array
    {
        $ag = Agendamento::query()->where('tenant_id', $tenantId)->with(['pet.tutor', 'servico', 'profissional'])->find($id);

        if ($ag === null) {
            return [];
        }

        $linhas = [
            ['rotulo' => 'Pet', 'valor' => $ag->pet?->nome.($ag->pet?->tutor ? ' (tutor: '.$ag->pet->tutor->nome.')' : '')],
            ['rotulo' => 'Serviço', 'valor' => $ag->servico?->nome_comercial],
            ['rotulo' => 'Profissional', 'valor' => $ag->profissional?->name],
            ['rotulo' => 'Horário', 'valor' => $ag->inicio->format('d/m/Y H:i').'–'.$ag->fim->format('H:i')],
            ['rotulo' => 'Situação', 'valor' => $ag->status],
        ];

        return $prefixo === '' ? $linhas : array_map(fn (array $l) => [...$l, 'rotulo' => $prefixo.': '.mb_strtolower($l['rotulo'])], $linhas);
    }

    /** A cobrança no card: pet, serviço, cada linha (cópia do preço da época), total e situação. Sem $comLinhas (estorno): só o cabeçalho. */
    private function resumoDaCobranca(int $id, int $tenantId, bool $comLinhas = true): array
    {
        $cobranca = Cobranca::query()->where('tenant_id', $tenantId)->with(['agendamento.pet', 'agendamento.servico', 'itens'])->find($id);

        if ($cobranca === null) {
            return [];
        }

        if (! $comLinhas) {
            return [
                ['rotulo' => 'Pet', 'valor' => $cobranca->agendamento?->pet?->nome],
                ['rotulo' => 'Atendimento', 'valor' => $cobranca->agendamento?->servico?->nome_comercial.' em '.$cobranca->agendamento?->inicio?->format('d/m/Y H:i')],
                ['rotulo' => 'Pago em', 'valor' => $cobranca->pago_em?->format('d/m/Y H:i').' ('.$cobranca->forma_pagamento.')'],
            ];
        }

        return [
            ['rotulo' => 'Pet', 'valor' => $cobranca->agendamento?->pet?->nome],
            ['rotulo' => 'Atendimento', 'valor' => $cobranca->agendamento?->servico?->nome_comercial.' em '.$cobranca->agendamento?->inicio?->format('d/m/Y H:i')],
            ...$this->linhasDoResumo($cobranca->itens->sortBy('id')->map(fn ($l) => ['descricao' => $l->descricao, 'quantidade' => $l->quantidade, 'subtotal_centavos' => $l->subtotal_centavos])->values()->all(), (int) $cobranca->total_centavos),
            ['rotulo' => 'Situação', 'valor' => $cobranca->status === 'fechada' ? 'a receber' : $cobranca->status],
        ];
    }

    /** A venda no card do estorno: setor, tutor, pet e quando foi paga (só leitura, do tenant). */
    private function resumoDaVenda(int $id, int $tenantId): array
    {
        $venda = Venda::query()->where('tenant_id', $tenantId)->with(['tutor', 'pet'])->find($id);

        return $venda === null ? [] : [
            ['rotulo' => 'Setor', 'valor' => $venda->setor === 'clinica' ? 'Clínica' : 'Petshop'],
            ['rotulo' => 'Tutor', 'valor' => $venda->tutor?->nome],
            ['rotulo' => 'Pet', 'valor' => $venda->pet?->nome],
            ['rotulo' => 'Pago em', 'valor' => $venda->pago_em?->format('d/m/Y H:i').' ('.$venda->forma_pagamento.')'],
        ];
    }

    /** T2: estornado de uma linha da consulta de cobranças (soma dos estornos; estorno de antes da T2: estorno_valor_centavos). */
    private function estornadoDaCobranca(Cobranca $c): int
    {
        $porItens = (int) $c->getAttribute('estornado_centavos');

        return $porItens > 0 ? $porItens : (int) ($c->estorno_valor_centavos ?? 0);
    }

    /**
     * T2, 1ª passada do reviewer (imp. 2): as linhas do estorno só com item_id e quantidade, venham da IA ou do navegador
     * (/agente/revisar). Assim nenhuma chave a mais (um "valor_centavos") vai para a sessão nem para a auditoria.
     */
    public function limparLinhasDoEstorno(string $tool, array $valores): array
    {
        if (in_array($tool, ['estornar_cobranca', 'estornar_venda'], true) && array_key_exists('linhas', $valores)) {
            $valores['linhas'] = $this->itensDoEstorno($valores['linhas']);
        }
        // 24/11 (reviewer): idem na venda — só catalogo_item_id e quantidade de cada item (chave a mais, como um "pin", não vai para a sessão nem para a auditoria).
        if ($tool === 'registrar_venda' && is_array($valores['itens'] ?? null)) {
            // Só filtra as chaves (sem converter nada: "02" continua sendo recusado pela validação).
            $valores['itens'] = array_map(fn ($linha) => is_array($linha) ? array_intersect_key($linha, array_flip(['catalogo_item_id', 'quantidade'])) : $linha, $valores['itens']);
        }

        return $valores;
    }

    /**
     * T2, 1ª passada do reviewer (imp. 1): no Revisar, o plano que o card mostrou é congelado em linhas explícitas (modo
     * "itens", com as quantidades daquele instante). No Confirmar, "tudo" não é recalculado: se outro estorno levou parte
     * das linhas nesse meio-tempo, o service recusa ("restam N"), em vez de gravar um valor que quem autorizou não viu.
     */
    public function congelarEstorno(string $tool, array $revisados): array
    {
        $servico = match ($tool) {
            'estornar_cobranca' => app(CobrancaService::class),
            'estornar_venda' => app(VendaService::class),
            default => null,
        };
        if ($servico === null) {
            return $revisados;
        }

        $plano = $servico->planoDoEstorno((int) ($revisados['id'] ?? 0), (string) ($revisados['modo'] ?? ''),
            $this->itensDoEstorno($revisados['linhas'] ?? []), $this->contexto->tenantId());

        return [...$revisados, 'modo' => 'itens', 'linhas' => array_values(array_map(
            fn (array $l) => ['item_id' => $l['item_id'], 'quantidade' => $l['quantidade']],
            array_filter($plano['linhas'], fn (array $l) => $l['quantidade'] >= 1),
        ))];
    }

    /**
     * 24/11 (R1): referência do plano do estorno mostrado ao humano. Hash das linhas que serão estornadas (item_id,
     * quantidade e valor de cada uma, em ordem) — qualquer linha a mais, a menos ou com outra quantidade/valor muda a
     * referência, e o total decorre das linhas. Só leitura (mesmo cálculo do congelarEstorno). null: tool sem plano, ou
     * plano recusado (a recusa já tem a própria frase). Não é segredo: o servidor sempre recalcula; serve para comparar.
     */
    public function referenciaDoPlano(string $tool, array $valores): ?string
    {
        $servico = match ($tool) {
            'estornar_cobranca' => app(CobrancaService::class),
            'estornar_venda' => app(VendaService::class),
            default => null,
        };
        if ($servico === null) {
            return null;
        }

        try {
            $plano = $servico->planoDoEstorno((int) ($valores['id'] ?? 0), (string) ($valores['modo'] ?? ''),
                $this->itensDoEstorno($valores['linhas'] ?? []), $this->contexto->tenantId());
        } catch (\App\Exceptions\RegraDeNegocioException) {
            return null;
        }

        $linhas = array_values(array_map(fn (array $l) => [(int) $l['item_id'], (int) $l['quantidade'], (int) $l['valor_centavos']],
            array_filter($plano['linhas'], fn (array $l) => $l['quantidade'] >= 1)));
        usort($linhas, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return substr(hash('sha256', json_encode([$tool, (int) ($valores['id'] ?? 0), $linhas])), 0, 32);
    }

    /**
     * Bateria da clínica (15/11): o card da venda não escolhe itens, e o pedido tem uma resposta só (a consulta do catálogo
     * não volta para a IA). A descrição dos itens leva o número e o nome de cada item ativo do catálogo DO TENANT logado:
     * nada de preço, estoque ou dado de pessoa. Acima de LIMITE_CATALOGO_NA_DESCRICAO itens, a lista sai (protótipo).
     */
    private function numerosDoCatalogo(): string
    {
        $itens = app(CatalogoService::class)->buscarPorFiltro(null, null, null, $this->contexto->tenantId());
        if ($itens->isEmpty() || $itens->count() > self::LIMITE_CATALOGO_NA_DESCRICAO) {
            return '';
        }

        return ' Números do catálogo (número = item): '.$itens->sortBy('id')->map(fn (CatalogoItem $i) => $i->id.' = '.$this->nomeNaDescricao((string) $i->nome_comercial))->implode('; ').'.';
    }

    /** Revisão de 15/11 (C4): nome do item na descrição, em uma linha, sem os separadores da lista e com até 60 caracteres. */
    private function nomeNaDescricao(string $nome): string
    {
        return \Illuminate\Support\Str::limit(trim((string) preg_replace('/[;=\s]+/u', ' ', $nome)), 60, '');
    }

    /** As propriedades do estorno por itens no esquema da tool (a IA nunca informa valor). */
    private function propriedadesDoEstorno(array $modos): array
    {
        return [
            'modo' => ['type' => 'string', 'enum' => $modos],
            'linhas' => ['type' => 'array', 'description' => 'Só no modo "itens": cada linha com item_id (o número da linha da venda ou da cobrança, obrigatório) e a quantidade inteira a estornar. Se você não souber o número da linha, não mande linhas: o card mostra os itens para a pessoa escolher.', 'items' => [
                'type' => 'object',
                'properties' => ['item_id' => ['type' => 'integer'], 'quantidade' => ['type' => 'integer']],
                'required' => ['item_id', 'quantidade'],
                'additionalProperties' => false,
            ]],
        ];
    }

    /** Itens do estorno: só item_id e quantidade de cada linha (qualquer outra chave, como um "valor", é descartada). */
    private function itensDoEstorno(mixed $itens): array
    {
        if (! is_array($itens)) {
            return [];
        }

        // Pontos fracos da bateria (15/11): linha sem item_id inteiro positivo não é linha (a IA não sabe o número e manda só a
        // quantidade). Ela sai aqui, e o card pede o item na lista; o service continua recusando item que não é da venda/cobrança.
        $comItem = array_filter($itens, fn ($linha) => is_array($linha) && $this->itemDaLinha($linha['item_id'] ?? null));

        return array_values(array_map(fn (array $linha) => array_intersect_key($linha, array_flip(['item_id', 'quantidade'])), $comItem));
    }

    /** O item_id de uma linha do estorno é um inteiro positivo (ou texto só de dígitos, como o formulário manda). */
    private function itemDaLinha(mixed $id): bool
    {
        return (is_int($id) && $id >= 1) || (is_string($id) && preg_match('/^[1-9]\d{0,8}$/', trim($id)) === 1);
    }

    /**
     * O card do estorno (T2): o cabeçalho do registro, o que este estorno leva (linha a linha, com o valor da época), o
     * valor do estorno, o já estornado e o líquido que fica; e o editor de itens (itens_estorno: cada linha com o que
     * resta e a quantidade escolhida). Recusa do plano (ex.: modo sem itens) vira aviso: o Revisar recusa de novo.
     */
    private function resumoDoEstorno(array $cabecalho, \Closure $linhas, \Closure $plano, array $valores): array
    {
        try {
            $todas = $linhas();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ['resumo' => $cabecalho];
        }

        $avisos = [];
        try {
            $previsto = $plano();
        } catch (\App\Exceptions\RegraDeNegocioException $e) {
            $previsto = null;
            $avisos[] = $e->getMessage();
        }

        $escolhidas = $previsto === null ? array_column($this->itensDoEstorno($valores['linhas'] ?? []), 'quantidade', 'item_id')
            : array_column($previsto['linhas'], 'quantidade', 'item_id');
        $resumo = $cabecalho;
        if ($previsto !== null) {
            foreach ($previsto['linhas'] as $l) {
                if ($l['quantidade'] >= 1) {
                    $resumo[] = ['rotulo' => 'Estornar '.$l['quantidade'].' × '.$l['descricao'].($l['prontuario'] ? ' (prontuário: o estoque não volta)' : ''), 'valor' => $this->reais($l['valor_centavos'])];
                }
            }
            $resumo[] = ['rotulo' => 'Valor do estorno', 'valor' => $this->reais($previsto['valor_centavos'])];
            $resumo[] = ['rotulo' => 'Total pago', 'valor' => $this->reais($previsto['total_centavos'])];
            $resumo[] = ['rotulo' => 'Já estornado antes', 'valor' => $this->reais($previsto['estornado_antes_centavos'])];
            $resumo[] = ['rotulo' => 'Líquido depois do estorno', 'valor' => $this->reais($previsto['liquido_centavos'])];
        }

        return [
            'resumo' => $resumo,
            'avisos' => $avisos,
            'itens_estorno' => array_map(fn (array $l) => [
                'item_id' => $l['item_id'],
                'descricao' => $l['descricao'],
                'origem' => $l['prontuario'] ? 'Prontuário' : 'Avulso',
                'lancada' => $l['lancada'],
                'estornada' => $l['estornada'],
                'restante' => $l['restante'],
                'preco' => $this->reais($l['preco_unitario_centavos']),
                'quantidade' => (int) ($escolhidas[$l['item_id']] ?? 0),
            ], $todas),
        ];
    }

    /** @param  array<int, array{descricao: string, quantidade: int, subtotal_centavos: int}>  $linhas */
    private function linhasDoResumo(array $linhas, int $totalCentavos): array
    {
        return [
            ...array_map(fn (array $l) => ['rotulo' => $l['quantidade'].' × '.$l['descricao'], 'valor' => $this->reais((int) $l['subtotal_centavos'])], $linhas),
            ['rotulo' => 'Total', 'valor' => $this->reais($totalCentavos)],
        ];
    }

    /** A venda que a proposta faria agora (o vendedor é quem está logado). */
    private function previaDaVenda(VendaService $vendas, array $a, int $tenantId): array
    {
        return $vendas->previa($this->itensDaVenda($a['itens'] ?? []), $this->contexto->usuario()->id,
            $this->idInformado($a['tutor_id'] ?? null, 'o tutor'), $tenantId, $this->idInformado($a['pet_id'] ?? null, 'o pet'),
            $this->idInformado($a['agendamento_id'] ?? null, 'o atendimento'));
    }

    /** Itens da venda com id e quantidade inteiros quando vierem como texto de número; o resto o service recusa. */
    private function itensDaVenda(mixed $itens): array
    {
        if (! is_array($itens)) {
            return [];
        }

        return array_values(array_map(fn ($linha) => is_array($linha) ? array_map(
            fn ($v) => is_string($v) && preg_match('/^\d+$/', trim($v)) === 1 ? (int) trim($v) : $v,
            array_intersect_key($linha, array_flip(['catalogo_item_id', 'quantidade'])),
        ) : [], $itens));
    }

    /**
     * Id opcional da prévia: ausente é null; informado precisa ser inteiro positivo (número ou texto só de dígitos).
     * Qualquer outra coisa ("abc", -3, 0, 1.5) é recusa com frase, nunca "sem vínculo" nem o id truncado (1.5 → 1).
     */
    private function idInformado(mixed $valor, string $rotulo): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if ((is_int($valor) && $valor >= 1) || (is_string($valor) && preg_match('/^[1-9]\d*$/', $valor) === 1)) {
            return (int) $valor;
        }

        throw new \App\Exceptions\RegraDeNegocioException('Informe '.$rotulo.' pelo número (um inteiro maior que zero).');
    }

    /**
     * Regra de negócio no Revisar (antes do segurar): roda a prévia; recusa vira a frase do topo do card.
     *
     * @return ?array{campo: string, motivo: string}
     */
    private function recusaDe(callable $previa, string $rotulo, string $campo = 'id'): ?array
    {
        try {
            $previa();

            return null;
        } catch (\App\Exceptions\RegraDeNegocioException $e) {
            return ['campo' => $campo, 'motivo' => $e->getMessage()];
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ['campo' => $campo, 'motivo' => 'Não encontrei '.$rotulo.'.'];
        }
    }

    /**
     * A lixeira da clínica: o vínculo tool ↔ entidade ↔ service, no mesmo formato de lixeiras() do domínio antigo.
     * "papeis" é quem restaura (a senha do dono é pedida no card); excluir e excluir de vez usam as listas do
     * definirLixeira (dono, e 'admin' só no domínio antigo: o Administrador da clínica não tem tool nenhuma, T1).
     * Item C (12/11): só o dono restaura, em toda entidade, pelo chat e pela tela: é o mesmo papel que abre a Lixeira
     * (consultar_lixeira). Antes, a recepção (e o resto da equipe) recebia 403 na tela, mas restaurava tutor e pet pelo chat.
     *
     * @return array<string, array<string, mixed>>
     */
    private function lixeirasDaClinica(): array
    {
        return [
            'tutor' => ['entidade' => 'Tutor', 'modelo' => Tutor::class, 'rotulo' => 'o tutor', 'servico' => app(TutorService::class),
                'busca_por' => 'nome', 'coluna_rotulo' => 'nome', 'colunas' => ['nome', 'documento', 'email', 'telefone'], 'papeis' => self::PAPEIS_DO_DONO],
            'pet' => ['entidade' => 'Pet', 'modelo' => Pet::class, 'rotulo' => 'o pet', 'servico' => app(PetService::class),
                'busca_por' => 'nome', 'coluna_rotulo' => 'nome', 'colunas' => ['nome', 'especie', 'raca', 'microchip'], 'papeis' => self::PAPEIS_DO_DONO],
            'item_catalogo' => ['entidade' => 'CatalogoItem', 'modelo' => CatalogoItem::class, 'rotulo' => 'o item do catálogo', 'servico' => app(CatalogoService::class),
                'busca_por' => 'nome', 'coluna_rotulo' => 'nome_comercial', 'colunas' => ['nome_comercial', 'setor', 'categoria', 'preco_centavos'], 'papeis' => self::PAPEIS_DO_DONO],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function propriedadesDoTutor(string $descricaoDoNome = ''): array
    {
        return [
            'nome' => ['type' => 'string', ...($descricaoDoNome === '' ? [] : ['description' => $descricaoDoNome])],
            'documento' => ['type' => 'string', 'description' => 'CPF ou CNPJ.'],
            'email' => ['type' => 'string'],
            'telefone' => ['type' => 'string'],
            'endereco' => ['type' => 'string'],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function propriedadesDoPet(string $descricaoDoNome = ''): array
    {
        return [
            'tutor_id' => ['type' => 'integer', 'description' => 'Id do tutor (dono) do pet.'],
            'nome' => ['type' => 'string', ...($descricaoDoNome === '' ? [] : ['description' => $descricaoDoNome])],
            'especie' => ['type' => 'string', 'enum' => Pet::ESPECIES],
            'raca' => ['type' => 'string'],
            'sexo' => ['type' => 'string', 'enum' => Pet::SEXOS],
            'data_nascimento' => ['type' => 'string', 'format' => 'date'],
            'peso_gramas' => ['type' => 'integer', 'description' => 'Peso em GRAMAS inteiros (6200 = 6,2 kg).'],
            'microchip' => ['type' => 'string'],
            'observacoes' => ['type' => 'string', 'description' => 'Observações do cadastro do animal (não clínicas).'],
            'alertas' => ['type' => 'string', 'description' => 'Aviso NÃO clínico para a equipe (ex.: "morde", "medo de secador"). Nunca escreva texto clínico aqui.'],
        ];
    }
}
