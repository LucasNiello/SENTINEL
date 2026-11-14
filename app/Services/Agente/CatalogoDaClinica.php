<?php

namespace App\Services\Agente;

use App\Http\Requests\AgendarRequest;
use App\Http\Requests\AlterarStatusAgendamentoRequest;
use App\Http\Requests\CatalogoItemAtualizacaoRequest;
use App\Http\Requests\CatalogoItemRequest;
use App\Http\Requests\EstornarCobrancaRequest;
use App\Http\Requests\PetRequest;
use App\Http\Requests\ReceberCobrancaRequest;
use App\Http\Requests\RegistrarVendaRequest;
use App\Http\Requests\RegistroIdRequest;
use App\Http\Requests\TutorRequest;
use App\Models\Agendamento;
use App\Models\CatalogoItem;
use App\Models\Cobranca;
use App\Models\Mensagem;
use App\Models\Pet;
use App\Models\Tutor;
use App\Models\User;
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

    /** Caixa de entrada: quantos pets do tutor vão no pedido ao agente e o limite do campo do chat (AgenteController). */
    private const PETS_NO_PEDIDO = 5;

    private const LIMITE_DO_PEDIDO = 1000;

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
                filtros: ['nome' => ['type' => 'string', 'description' => 'Parte do nome do tutor.']],
                buscar: fn (array $a) => $tutores->buscarPorFiltro($a['nome'] ?? null, $a['tenant_id']),
                gravar: fn (array $dados, int $tenantId) => $tutores->criar($dados, $tenantId),
                colunas: ['id', 'nome', 'documento', 'email', 'telefone', 'endereco'],
                colunaRotulo: 'nome',
                papeisLeitura: self::PAPEIS_EQUIPE,
                papeisEscrita: self::PAPEIS_EQUIPE,
                tituloCriar: 'Cadastrar tutor',
                sucessoCriar: 'Tutor cadastrado',
                tituloAtualizar: 'Alterar tutor',
                sucessoAtualizar: 'Tutor atualizado',
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
                ],
                buscar: fn (array $a) => $pets->buscarPorFiltro($a['nome'] ?? null, isset($a['tutor_id']) ? (int) $a['tutor_id'] : null, $a['tenant_id']),
                gravar: fn (array $dados, int $tenantId) => $pets->criar($dados, $tenantId),
                colunas: ['id', 'nome', 'especie', 'raca', 'sexo', 'data_nascimento', 'peso_gramas', 'tutor_id', 'alertas'],
                colunaRotulo: 'nome',
                papeisLeitura: self::PAPEIS_EQUIPE,
                papeisEscrita: self::PAPEIS_EQUIPE,
                tituloCriar: 'Cadastrar pet',
                sucessoCriar: 'Pet cadastrado',
                tituloAtualizar: 'Alterar pet',
                sucessoAtualizar: 'Pet atualizado',
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
                    'controla_estoque' => ['type' => 'string', 'enum' => ['sim', 'nao'], 'description' => 'Obrigatório. Serviço: "nao".'],
                    'estoque' => ['type' => 'integer', 'description' => 'Estoque inicial, só produto que controla estoque.'],
                    'exige_receita' => ['type' => 'string', 'enum' => ['sim', 'nao'], 'description' => 'Obrigatório. Só item de farmácia da clínica pode ser "sim".'],
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
                ],
                buscar: fn (array $a) => $catalogo->buscarPorFiltro($a['setor'] ?? null, $a['categoria'] ?? null, $a['nome'] ?? null, $a['tenant_id'])
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
                descricao: 'Mostra a agenda da clínica e do banho e tosa num período (padrão: de hoje até 6 dias depois), com horário, pet, tutor, serviço, profissional e status. Datas no formato AAAA-MM-DD. Ação de leitura — não exige confirmação.',
                tipo: 'leitura',
                risco: 'leitura',
                acao: 'consultar',
                entidade: 'Agendamento',
                papeis: self::PAPEIS_EQUIPE,
                propriedades: [
                    'data_inicio' => ['type' => 'string', 'format' => 'date'],
                    'data_fim' => ['type' => 'string', 'format' => 'date'],
                    'setor' => ['type' => 'string', 'enum' => CatalogoItem::SETORES],
                    'profissional_id' => ['type' => 'integer', 'description' => 'Id do veterinário ou do atendente.'],
                    'pet_id' => ['type' => 'integer'],
                    'status' => ['type' => 'string', 'enum' => Agendamento::STATUS],
                ],
                obrigatorios: [],
                executar: fn (array $a) => $agenda->buscarPorFiltro($a['data_inicio'] ?? null, $a['data_fim'] ?? null, $a['setor'] ?? null,
                    isset($a['profissional_id']) ? (int) $a['profissional_id'] : null, isset($a['pet_id']) ? (int) $a['pet_id'] : null, $a['status'] ?? null, $a['tenant_id'])
                    ->map(fn (Agendamento $ag) => [
                        'id' => (int) $ag->id,
                        'horario' => $ag->inicio->format('d/m/Y H:i').'–'.$ag->fim->format('H:i'),
                        'pet' => $ag->getAttribute('pet'),
                        'tutor' => $ag->getAttribute('tutor'),
                        'servico' => $ag->getAttribute('servico'),
                        'profissional' => $ag->getAttribute('profissional'),
                        'setor' => $ag->setor,
                        'status' => $ag->status,
                    ])->all(),
                mensagemErro: 'Não consegui consultar a agenda agora. Tente novamente.',
                colunas: ['id', 'horario', 'pet', 'tutor', 'servico', 'profissional', 'setor', 'status'],
            ),
            $this->definirAcao(
                nome: 'alterar_status_agendamento',
                descricao: 'Muda o status de um agendamento em aberto (agendado ou confirmado) para confirmado, realizado, cancelado ou faltou. Achado pelo id ou pelo nome do pet.',
                acao: 'atualizar',
                entidade: 'Agendamento',
                papeis: ['atendente', 'recepcao', 'veterinario', 'dono'],
                propriedades: ['novo_status' => ['type' => 'string', 'enum' => ['confirmado', 'realizado', 'cancelado', 'faltou']]],
                obrigatorios: ['novo_status'],
                formRequest: AlterarStatusAgendamentoRequest::class,
                executar: fn (array $d, int $tenantId) => $agenda->alterarStatus($d['id'], $d['novo_status'], $tenantId),
                mensagemErro: 'Não consegui mudar o status do agendamento. Tente novamente.',
                titulo: 'Mudar o status do agendamento',
                sucesso: 'Status do agendamento atualizado',
                conferir: function (array $a) use ($agenda): ?array {
                    $motivo = $agenda->motivoParaNaoAlterarStatus((int) ($a['id'] ?? 0), (string) ($a['novo_status'] ?? ''), (int) $a['tenant_id']);

                    return $motivo === null ? null : ['campo' => 'novo_status', 'motivo' => $motivo];
                },
                localizar: $agenda->localizarEmAberto(...),
                rotulo: 'o agendamento',
                colunaRotulo: 'pet',
                campos: ['novo_status'],
                resumir: fn (array $v, int $tenantId) => $this->resumoDoAgendamento((int) ($v['id'] ?? 0), $tenantId),
            ),
            // F (P-C4): agendar e encaixe de emergência. A origem é fixada pelo servidor em cada tool (a IA não escolhe):
            // a regra (pet, serviço e profissional do tenant, setor, passado, conflito, duração) é toda do AgendaService.
            ...array_map(fn (array $t) => $this->definirAcao(
                nome: $t['nome'],
                descricao: $t['descricao'].' Pet, serviço do catálogo e profissional pelo id; início e fim no formato AAAA-MM-DD HH:MM (horário de São Paulo).',
                acao: 'agendar',
                entidade: 'Agendamento',
                papeis: $t['papeis'],
                propriedades: [
                    'pet_id' => ['type' => 'integer'],
                    'catalogo_item_id' => ['type' => 'integer', 'description' => 'Id do serviço do catálogo (não produto).'],
                    'profissional_id' => ['type' => 'integer', 'description' => 'Id do veterinário (clínica) ou do atendente (banho e tosa).'],
                    'inicio' => ['type' => 'string', 'description' => 'AAAA-MM-DD HH:MM'],
                    'fim' => ['type' => 'string', 'description' => 'AAAA-MM-DD HH:MM'],
                ],
                obrigatorios: ['pet_id', 'catalogo_item_id', 'profissional_id', 'inicio', 'fim'],
                formRequest: AgendarRequest::class,
                executar: fn (array $d, int $tenantId) => $agenda->agendar([
                    ...array_intersect_key($d, array_flip(['pet_id', 'catalogo_item_id', 'profissional_id', 'inicio', 'fim'])),
                    'origem' => $t['origem'],
                ], $tenantId),
                mensagemErro: 'Não consegui agendar agora. Nada foi gravado.',
                titulo: $t['titulo'],
                sucesso: $t['sucesso'],
                conferir: function (array $a) use ($agenda, $t): ?array {
                    $motivo = $agenda->motivoParaNaoAgendar([...$a, 'origem' => $t['origem']], (int) $a['tenant_id']);

                    return $motivo === null ? null : ['campo' => 'inicio', 'motivo' => $motivo];
                },
            ), [
                ['nome' => 'agendar_atendimento', 'origem' => 'normal', 'papeis' => ['atendente', 'recepcao', 'veterinario', 'dono'],
                    'titulo' => 'Agendar', 'sucesso' => 'Atendimento agendado',
                    'descricao' => 'Agenda um atendimento da clínica ou do banho e tosa, de agora em diante, sem conflito com outro horário do mesmo profissional.'],
                ['nome' => 'registrar_encaixe_emergencia', 'origem' => 'encaixe_emergencia', 'papeis' => ['recepcao', 'veterinario', 'dono'],
                    'titulo' => 'Registrar encaixe de emergência', 'sucesso' => 'Encaixe de emergência registrado',
                    'descricao' => 'Registra na agenda da clínica um atendimento de emergência que já começou (início agora ou antes); não confere conflito de horário. Só da clínica.'],
            ]),

            // ---------------------------------------------------------------- cobrança (P-B4)
            $this->definirTool(
                nome: 'consultar_cobrancas',
                descricao: 'Lista as cobranças dos atendimentos (aberta, fechada = a receber, paga, cancelada), com pet, serviço, total e forma de pagamento. Ação de leitura — não exige confirmação.',
                tipo: 'leitura',
                risco: 'leitura',
                acao: 'consultar',
                entidade: 'Cobranca',
                papeis: $quemCobra,
                propriedades: [
                    'status' => ['type' => 'string', 'enum' => Cobranca::STATUS],
                    'setor' => ['type' => 'string', 'enum' => CatalogoItem::SETORES],
                ],
                obrigatorios: [],
                executar: fn (array $a) => $cobrancas->buscarPorFiltro($a['status'] ?? null, $a['setor'] ?? null, $a['tenant_id'])
                    ->map(fn (Cobranca $c) => [
                        'id' => (int) $c->id,
                        'pet' => $c->getAttribute('pet'),
                        'servico' => $c->getAttribute('servico'),
                        'atendimento' => \Carbon\CarbonImmutable::parse($c->getAttribute('atendimento_em'), (string) config('app.timezone'))->format('d/m/Y H:i'),
                        'total' => $this->reais((int) $c->total_centavos),
                        'status' => $c->status === 'fechada' ? 'a receber' : $c->status,
                        'forma_pagamento' => $c->forma_pagamento,
                        ...($c->status === 'fechada' && $this->podeEditar('receber_cobranca') ? ['acao' => ['tool' => 'receber_cobranca', 'rotulo' => 'Receber']] : []),
                    ])->all(),
                mensagemErro: 'Não consegui consultar as cobranças agora. Tente novamente.',
                colunas: ['id', 'pet', 'servico', 'atendimento', 'total', 'status', 'forma_pagamento'],
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
            // H: estorno de cobrança PAGA no card. Pede: a recepção (clínica), o atendente (petshop) ou o dono; quem é do
            // setor certo o CobrancaService confere. Autoriza: o supervisor do setor com o PIN dele, ou o dono com a senha,
            // digitados no card (reautenticar 'estorno'); a conferência fica no /agente/confirmar e nunca vai para a IA.
            $this->definirAcao(
                nome: 'estornar_cobranca',
                descricao: 'Estorna uma cobrança PAGA (volta para cancelada; o estoque dos itens avulsos volta). Precisa de motivo e da autorização de um supervisor do setor (PIN) ou do dono (senha), digitada só no card de confirmação: nunca peça nem aceite PIN ou senha no chat. Achada pelo id da cobrança ou pelo nome do pet.',
                acao: 'estornar',
                entidade: 'Cobranca',
                papeis: ['recepcao', 'atendente', 'dono'],
                propriedades: ['motivo' => ['type' => 'string', 'description' => 'Por que a cobrança paga está sendo estornada.']],
                obrigatorios: [],
                formRequest: EstornarCobrancaRequest::class,
                // Quem pede é o usuário logado; quem autorizou é quem o card conferiu (autorizadoPor), nunca a IA.
                executar: fn (array $d, int $tenantId, User $ator) => $cobrancas->estornar($d['id'], (string) $d['motivo'], $ator->id,
                    $this->autorizadoPor() ?? 0, $tenantId, $this->autorizadoPor() !== null),
                mensagemErro: 'Não consegui estornar a cobrança. Nada foi gravado.',
                titulo: 'Estornar a cobrança',
                sucesso: 'Cobrança estornada',
                conferir: fn (array $a) => $cobrancas->localizar((int) ($a['id'] ?? 0), null, (int) $a['tenant_id'], ['paga'])->isEmpty()
                    ? ['campo' => 'id', 'motivo' => 'Não encontrei a cobrança paga #'.(int) ($a['id'] ?? 0).'.'] : null,
                risco: 'exclusao',
                localizar: fn (?int $id, ?string $busca, int $tenantId) => $cobrancas->localizar($id, $busca, $tenantId, ['paga']),
                rotulo: 'a cobrança paga',
                colunaRotulo: 'pet',
                rotuloAcao: 'Estornar',
                campos: ['motivo'],
                resumir: fn (array $v, int $tenantId) => $this->resumoDaCobranca((int) ($v['id'] ?? 0), $tenantId),
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
                    'itens' => ['type' => 'array', 'description' => 'Cada item: o id do item do catálogo e a quantidade inteira.', 'items' => [
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
                        'de' => $m->getAttribute('tutor') ?? $m->remetente ?? '—',
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
                        ['rotulo' => 'De', 'valor' => $mensagem->tutor?->nome ?? $mensagem->remetente],
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
        $pedido = 'Mensagem da caixa de entrada ('.$contexto.'): '.json_encode((string) $mensagem->texto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return mb_strlen($pedido) <= self::LIMITE_DO_PEDIDO ? $pedido : (string) $mensagem->texto;
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

    /** A cobrança no card: pet, serviço, cada linha (cópia do preço da época), total e situação. */
    private function resumoDaCobranca(int $id, int $tenantId): array
    {
        $cobranca = Cobranca::query()->where('tenant_id', $tenantId)->with(['agendamento.pet', 'agendamento.servico', 'itens'])->find($id);

        if ($cobranca === null) {
            return [];
        }

        return [
            ['rotulo' => 'Pet', 'valor' => $cobranca->agendamento?->pet?->nome],
            ['rotulo' => 'Atendimento', 'valor' => $cobranca->agendamento?->servico?->nome_comercial.' em '.$cobranca->agendamento?->inicio?->format('d/m/Y H:i')],
            ...$this->linhasDoResumo($cobranca->itens->sortBy('id')->map(fn ($l) => ['descricao' => $l->descricao, 'quantidade' => $l->quantidade, 'subtotal_centavos' => $l->subtotal_centavos])->values()->all(), (int) $cobranca->total_centavos),
            ['rotulo' => 'Situação', 'valor' => $cobranca->status === 'fechada' ? 'a receber' : $cobranca->status],
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
     * "papeis" é quem restaura (a senha do dono é pedida no card); excluir e excluir de vez são sempre do dono.
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
            'alertas' => ['type' => 'string', 'description' => 'Aviso NÃO clínico para a equipe (ex.: "morde", "medo de secador"). Nunca escreva texto clínico aqui.'],
        ];
    }
}
