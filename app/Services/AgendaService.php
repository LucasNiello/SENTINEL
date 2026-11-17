<?php

namespace App\Services;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Agendamento;
use App\Models\CatalogoItem;
use App\Models\Cobranca;
use App\Models\Pet;
use App\Models\User;
use App\Models\Prontuario;
use App\Services\Concerns\ConfereTenant;
use App\Services\Concerns\FiltroPorNome;
use App\Services\Concerns\InterpretaMomento;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Clínica vet: agenda da clínica e do banho e tosa.
 * - Modelo de horário (decisão do Lucas, 15/11, que substitui o "pico de sobreposição" e a duração padrão da clínica):
 *   as regras ficam no GradeDaAgenda (PHP puro, config('sentinel.agenda')). Clínica: blocos de 1 h com 4 vagas por
 *   veterinário, fim = fim do bloco, cirurgia ocupa todas as vagas dos 2 blocos que cobre, internação 1 vaga no bloco
 *   de entrada, bloco em andamento aceita enquanto não terminou. Petshop: duração do serviço, um por vez, grade de
 *   15 min, só de agora em diante, inteiro no expediente. Cancelado e faltou liberam o horário.
 * - A conferência de vaga/conflito é feita na GRAVAÇÃO, na mesma transação, com o profissional e a leitura travados.
 *   O calendário da ficha (disponibilidade) só mostra; nunca é a trava.
 * - O fim nunca é informado: o servidor calcula (o "fim" que chegar nos dados é ignorado).
 * - encaixe_emergencia registra um atendimento que JÁ aconteceu: não confere vaga nem grade, mas só aceita
 *   início até agora (encaixe no futuro é recusado) e só na clínica.
 * - Remarcar, trocar o serviço e mudar o status conferem a "versao" (o updated_at de quando a ficha abriu; precisão
 *   de segundo): se o agendamento mudou depois, recusa.
 * - Profissional: clínica = veterinário ou dono; petshop = atendente.
 * Datas no fuso da aplicação (America/Sao_Paulo).
 */
class AgendaService
{
    use ConfereTenant, FiltroPorNome, InterpretaMomento;

    private const PROFISSIONAIS = ['clinica' => ['veterinario', 'dono'], 'petshop' => ['atendente']];

    private const RECUSA_PROFISSIONAL = [
        'clinica' => 'Na clínica, o profissional precisa ser um veterinário.',
        'petshop' => 'No banho e tosa, o profissional precisa ser um atendente.',
    ];

    /** A ficha abriu com uma versão do agendamento e ele mudou depois (outra pessoa, outra aba). */
    public const RECUSA_VERSAO = 'Este agendamento mudou. Reabra a ficha.';

    /** Cirurgia e internação (config restritas_crmv; decisão do Lucas, 15/11): só quem tem CRMV no perfil. */
    public const RECUSA_SEM_CRMV = 'Cirurgia só é agendada, assumida, remarcada ou cancelada por um veterinário com CRMV no perfil. Sem CRMV, você só consulta as cirurgias.';

    public const RECUSA_SEM_REAUTENTICACAO = 'Confirme com o seu CRMV e a sua senha para gravar.';

    public const RECUSA_SUBSTITUICAO = 'Na cirurgia, o profissional depois da mudança só pode ser você: remarque a sua ou assuma a do colega.';

    public const RECUSA_CIRURGIA_PARA_OUTRO = 'Cirurgia nova só é agendada pelo próprio veterinário, para si mesmo.';

    public const RECUSA_TROCA_CIRURGIA = 'Trocar de ou para cirurgia não é feito por aqui: cancele e agende de novo.';

    /**
     * Decisão do Lucas (16/11): nestas categorias "faltou" só vale a partir do início do agendamento (a cirurgia já
     * deveria ter começado; a internação, dado entrada). Antes disso, "faltou" seria um cancelamento disfarçado (libera
     * os blocos sem CRMV): é recusado, e quem precisa desfazer usa cancelar.
     */
    private const FALTOU_SO_DEPOIS_DO_INICIO = ['cirurgia', 'internacao'];

    private const TRANSICOES = [
        'agendado' => ['confirmado', 'realizado', 'cancelado', 'faltou'],
        'confirmado' => ['realizado', 'cancelado', 'faltou'],
    ];

    /**
     * @param  array{pet_id: mixed, profissional_id: mixed, catalogo_item_id: mixed, inicio: mixed, origem?: string}  $dados
     * @param  ?array{ator: User, reautenticado: ?bool}  $autorizacao  quem grava e se confirmou com CRMV + senha (cirurgia e
     *         internação). Null = sem ator (seeder, código): cirurgia e internação são recusadas (falha fechada).
     */
    public function agendar(array $dados, int $tenantId, ?array $autorizacao = null): Agendamento
    {
        return DB::transaction(function () use ($dados, $tenantId, $autorizacao) {
            // Serializa os agendamentos do mesmo profissional até o commit: dois pedidos simultâneos
            // não passam os dois pela conferência de vaga. (O SQLite dos testes ignora FOR UPDATE.)
            User::query()->whereKey($this->idValido($dados['profissional_id'] ?? null) ?? 0)->lockForUpdate()->first();

            ['servico' => $servico, 'inicio' => $inicio, 'fim' => $fim, 'origem' => $origem] = $this->conferir($dados, $tenantId, $autorizacao);

            return Agendamento::create([
                'pet_id' => (int) $dados['pet_id'],
                'profissional_id' => (int) $dados['profissional_id'],
                'setor' => $servico->setor,
                'catalogo_item_id' => $servico->id,
                'inicio' => $inicio,
                'fim' => $fim,
                'status' => 'agendado',
                'origem' => $origem,
                'tenant_id' => $tenantId,
            ]);
        });
    }

    /** Só leitura (para o Revisar, antes do Confirmar): a frase do motivo de recusa, ou null. */
    public function motivoParaNaoAgendar(array $dados, int $tenantId, ?array $autorizacao = null): ?string
    {
        try {
            $this->conferir($dados, $tenantId, $autorizacao);

            return null;
        } catch (RegraDeNegocioException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Registro de outro tenant: ModelNotFoundException (inexistente). $versao: a da ficha (null = quem chama não tem ficha).
     * Cancelar cirurgia (adendo final, 15/11): qualquer usuário com CRMV, confirmando com CRMV + senha ($autorizacao).
     */
    public function alterarStatus(int $id, string $novoStatus, int $tenantId, ?string $versao = null, ?array $autorizacao = null): Agendamento
    {
        return DB::transaction(function () use ($id, $novoStatus, $tenantId, $versao, $autorizacao) {
            $agendamento = Agendamento::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);
            $this->conferirVersao($agendamento, $versao);
            $this->conferirCancelamento($agendamento, $novoStatus, $autorizacao, $tenantId);
            $this->conferirFaltou($agendamento, $novoStatus, $tenantId);
            $this->conferirStatus($agendamento, $novoStatus);

            $agendamento->update(['status' => $novoStatus]);

            return $agendamento;
        });
    }

    /** Só leitura (para o Revisar): a frase do motivo de recusa da mudança de status, ou null. */
    public function motivoParaNaoAlterarStatus(int $id, string $novoStatus, int $tenantId, ?string $versao = null, ?array $autorizacao = null): ?string
    {
        $agendamento = Agendamento::where('tenant_id', $tenantId)->find($id);

        if ($agendamento === null) {
            return 'Não encontrei o agendamento #'.$id.'.';
        }

        try {
            $this->conferirVersao($agendamento, $versao);
            $this->conferirCancelamento($agendamento, $novoStatus, $autorizacao, $tenantId);
            $this->conferirFaltou($agendamento, $novoStatus, $tenantId);
            $this->conferirStatus($agendamento, $novoStatus);

            return null;
        } catch (RegraDeNegocioException $e) {
            return $e->getMessage();
        }
    }

    /**
     * A agenda do tenant num período (padrão: de hoje até 6 dias depois), com o pet, o tutor, o profissional e o
     * serviço de cada horário. Datas AAAA-MM-DD no fuso da aplicação; até 200, do mais cedo para o mais tarde.
     */
    public function buscarPorFiltro(?string $dataInicio, ?string $dataFim, ?string $setor, ?int $profissionalId, ?int $petId, ?string $status, int $tenantId): Collection
    {
        $fuso = (string) config('app.timezone');
        foreach (['de início' => $dataInicio, 'de fim' => $dataFim] as $qual => $informada) {
            if ($informada !== null && $informada !== '' && $this->dia($informada) === null) {
                throw new RegraDeNegocioException("A data {$qual} da agenda deve estar no formato AAAA-MM-DD (ex.: 2026-12-15).");
            }
        }
        $inicio = $this->dia($dataInicio) ?? CarbonImmutable::now($fuso)->startOfDay();
        $fim = ($this->dia($dataFim) ?? $inicio->addDays(6))->endOfDay();

        return Agendamento::query()
            ->join('pets', 'pets.id', '=', 'agendamentos.pet_id')
            ->join('tutores', 'tutores.id', '=', 'pets.tutor_id')
            ->join('users', 'users.id', '=', 'agendamentos.profissional_id')
            ->join('catalogo_itens', 'catalogo_itens.id', '=', 'agendamentos.catalogo_item_id')
            ->where('agendamentos.tenant_id', $tenantId)
            ->whereBetween('agendamentos.inicio', [$inicio, $fim])
            ->when($setor, fn ($query) => $query->where('agendamentos.setor', $setor))
            ->when($profissionalId, fn ($query) => $query->where('agendamentos.profissional_id', $profissionalId))
            ->when($petId, fn ($query) => $query->where('agendamentos.pet_id', $petId))
            ->when($status, fn ($query) => $query->where('agendamentos.status', $status))
            ->orderBy('agendamentos.inicio')
            ->limit(200)
            ->get(['agendamentos.*', 'pets.nome as pet', 'tutores.nome as tutor', 'users.name as profissional', 'catalogo_itens.nome_comercial as servico']);
    }

    /**
     * Para mudar o status: agendamento ainda em aberto (agendado ou confirmado), por id ou pelo nome do pet. Até 6.
     * $setores (15/11): só os setores que o papel vê na agenda; fora deles o agendamento é tratado como inexistente.
     * Cada um vem com as colunas da tabela da agenda (lista de escolha igual à da agenda).
     */
    public function localizarEmAberto(?int $id, ?string $busca, int $tenantId, ?array $setores = null): Collection
    {
        return $this->comColunasDaAgenda(Agendamento::query())
            ->where('agendamentos.tenant_id', $tenantId)
            ->when($setores !== null, fn ($query) => $query->whereIn('agendamentos.setor', $setores))
            ->whereIn('agendamentos.status', array_keys(self::TRANSICOES))
            ->when($id, fn ($query) => $query->where('agendamentos.id', $id), fn ($query) => $this->nomeContem($query, (string) $busca, 'pets.nome'))
            ->orderBy('agendamentos.inicio')
            ->limit(6)
            ->get($this->selecaoDaAgenda())
            ->each(fn (Agendamento $a) => $this->horarioNaLinha($a));
    }

    /** Cancelar um agendamento de categoria restrita (cirurgia) passa pela mesma regra do CRMV + senha. */
    private function conferirCancelamento(Agendamento $agendamento, string $novoStatus, ?array $autorizacao, int $tenantId): void
    {
        if ($novoStatus === 'cancelado') {
            $this->conferirRestrito($this->servicoDoTenant($agendamento->catalogo_item_id, $tenantId), $agendamento, (int) $agendamento->profissional_id, $autorizacao, $tenantId, cancelamento: true);
        }
    }

    /** Cirurgia e internação: "faltou" só a partir do início do agendamento (FALTOU_SO_DEPOIS_DO_INICIO). */
    private function conferirFaltou(Agendamento $agendamento, string $novoStatus, int $tenantId): void
    {
        if ($novoStatus !== 'faltou') {
            return;
        }
        $categoria = CatalogoItem::query()->where('tenant_id', $tenantId)->whereKey($agendamento->catalogo_item_id)->value('categoria');
        if (! in_array($categoria, self::FALTOU_SO_DEPOIS_DO_INICIO, true)) {
            return;
        }
        $inicio = CarbonImmutable::instance($agendamento->inicio)->setTimezone((string) config('app.timezone'));
        if ($inicio->gt(CarbonImmutable::now($inicio->getTimezone()))) {
            $comoDesfazer = in_array($categoria, $this->categoriasRestritas(), true) ? 'use cancelar (com CRMV e senha)' : 'use cancelar';
            throw new RegraDeNegocioException('"Faltou" só vale a partir do início do agendamento ('.$inicio->format('d/m H:i').'). Antes disso, para desfazer, '.$comoDesfazer.'.');
        }
    }

    /** O Confirmar desta mudança de status pede CRMV + senha? Só cancelar cirurgia. */
    public function exigeCrmvNoStatus(int $id, mixed $novoStatus, int $tenantId): bool
    {
        return $novoStatus === 'cancelado' && $this->exigeCrmv($id, null, $tenantId);
    }

    /** As regras da mudança de status (transição, cobrança viva, prontuário), com leitura travada. */
    private function conferirStatus(Agendamento $agendamento, string $novoStatus): void
    {
        if (! in_array($novoStatus, self::TRANSICOES[$agendamento->status] ?? [], true)) {
            throw new RegraDeNegocioException("Um agendamento {$agendamento->status} não pode passar para {$novoStatus}.");
        }
        if (in_array($novoStatus, Agendamento::STATUS_LIVRES, true)) {
            // Defesa: com cobrança viva (não cancelada), o agendamento não é desfeito. Paga sai por estorno.
            if ($this->existeTravado(Cobranca::where('agendamento_id', $agendamento->id)->where('status', '!=', 'cancelada'))) {
                throw new RegraDeNegocioException("Este agendamento tem cobrança: cancele ou estorne a cobrança antes de marcá-lo como {$novoStatus}.");
            }
            // O atendimento já foi registrado: cancelado ou faltou seria um estado final que impede a cobrança para sempre.
            if ($this->existeTravado(Prontuario::where('agendamento_id', $agendamento->id))) {
                throw new RegraDeNegocioException("Este agendamento já tem prontuário: o atendimento aconteceu e não pode ser marcado como {$novoStatus}.");
            }
        }
    }

    /** A ficha abriu com $versao (o updated_at de então); se o agendamento mudou depois, recusa. Null = sem ficha (seeder, código). */
    private function conferirVersao(Agendamento $agendamento, ?string $versao): void
    {
        if ($versao !== null && (string) $agendamento->getRawOriginal('updated_at') !== $versao) {
            throw new RegraDeNegocioException(self::RECUSA_VERSAO);
        }
    }

    /** AAAA-MM-DD estrito, no fuso da aplicação, ou null. */
    private function dia(?string $valor): ?CarbonImmutable
    {
        if ($valor === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) !== 1) {
            return null;
        }
        $dia = CarbonImmutable::createFromFormat('!Y-m-d', $valor, (string) config('app.timezone'));

        return $dia !== false && $dia->format('Y-m-d') === $valor ? $dia : null;
    }

    private function grade(): GradeDaAgenda
    {
        return GradeDaAgenda::daConfiguracao();
    }

    /** @return array{servico: CatalogoItem, inicio: CarbonImmutable, fim: CarbonImmutable, origem: string} */
    private function conferir(array $dados, int $tenantId, ?array $autorizacao = null): array
    {
        $origem = $dados['origem'] ?? 'normal';
        if (! in_array($origem, Agendamento::ORIGENS, true)) {
            throw new RegraDeNegocioException('Origem do agendamento inválida.');
        }

        $this->doTenant(Pet::class, $dados['pet_id'] ?? null, $tenantId, 'o pet');
        $servico = $this->servicoDoTenant($dados['catalogo_item_id'] ?? null, $tenantId);
        $this->profissionalDoSetor($dados['profissional_id'] ?? null, $servico->setor, $tenantId);

        $inicio = $this->momento($dados['inicio'] ?? null);
        if ($inicio === null) {
            throw new RegraDeNegocioException('Informe o início com data e hora.');
        }
        // O fim não é pedido na tela nem à IA: o servidor calcula pelo modelo do setor (o "fim" dos dados é ignorado).
        $fim = $this->grade()->fim($servico->setor, $servico->categoria, $inicio);
        $this->conferirDuracaoMaxima($inicio, $fim, 'agendamento');

        if ($origem === 'encaixe_emergencia') {
            if ($servico->setor !== 'clinica') {
                throw new RegraDeNegocioException('Encaixe de emergência é só da clínica.');
            }
            if ($inicio->gt(now())) {
                throw new RegraDeNegocioException('Encaixe de emergência registra um atendimento que já começou: o início não pode estar no futuro.');
            }
        } else {
            $this->conferirRestrito($servico, null, (int) $dados['profissional_id'], $autorizacao, $tenantId);
            $this->conferirNaoPassou($servico->setor, $inicio, 'agendar');
            $this->conferirHorario($servico, (int) $dados['profissional_id'], $inicio, $tenantId);
        }

        return ['servico' => $servico, 'inicio' => $inicio, 'fim' => $fim, 'origem' => $origem];
    }

    private function servicoDoTenant(mixed $id, int $tenantId): CatalogoItem
    {
        $servico = $this->doTenant(CatalogoItem::class, $id, $tenantId, 'o serviço');
        if ($servico->tipo !== 'servico') {
            throw new RegraDeNegocioException("{$servico->nome_comercial} é um produto: agende um serviço.");
        }

        return $servico;
    }

    private function profissionalDoSetor(mixed $id, string $setor, int $tenantId): User
    {
        return $this->usuarioDoTenant($id, $tenantId, self::PROFISSIONAIS[$setor] ?? [], 'o profissional', self::RECUSA_PROFISSIONAL[$setor] ?? 'Profissional inválido para este serviço.');
    }

    /** Sem um máximo válido na configuração, recusa (falha fechada). */
    private function conferirDuracaoMaxima(CarbonImmutable $inicio, CarbonImmutable $fim, string $oQue): void
    {
        $maximo = max(0, (int) config('sentinel.agenda_duracao_maxima_minutos'));
        if ($maximo < 1) {
            throw new RegraDeNegocioException("Não dá para gravar o {$oQue}: o limite de duração da agenda não está configurado.");
        }
        if ($inicio->diffInMinutes($fim) > $maximo) {
            throw new RegraDeNegocioException('Um agendamento dura no máximo '.$this->duracaoPorExtenso($maximo).'.');
        }
    }

    /** Clínica: bloco encerrado fica fechado (em andamento, ainda aceita). Petshop: início antes de agora é recusado. */
    private function conferirNaoPassou(string $setor, CarbonImmutable $inicio, string $verbo): void
    {
        if (! $this->grade()->jaPassou($setor, $inicio, CarbonImmutable::now($inicio->getTimezone()))) {
            return;
        }
        $para = $verbo === 'agendar' ? 'agendar no passado' : $verbo.' para um horário que já passou';

        throw new RegraDeNegocioException($setor === 'clinica'
            ? "Não dá para {$para}: esse bloco já terminou. Escolha um bloco de agora em diante."
            : "Não dá para {$para}: o início precisa ser agora ou depois.");
    }

    /**
     * Vaga (clínica) ou conflito (petshop) do profissional no horário, pela GradeDaAgenda, com a leitura dos
     * agendamentos dele TRAVADA: dentro da transação de quem grava, dois pedidos simultâneos não passam os dois.
     * $ignorarId: o próprio agendamento (remarcar, trocar o serviço).
     */
    private function conferirHorario(CatalogoItem $servico, int $profissionalId, CarbonImmutable $inicio, int $tenantId, ?int $ignorarId = null): void
    {
        if ($servico->setor === 'clinica' && $this->grade()->foraDaAgenda($servico->categoria)) {
            return; // internação: registrada com a data de entrada, sem ocupar a agenda do veterinário
        }
        $dia = $inicio->startOfDay();
        $ocupados = $this->ocupados([$profissionalId], $dia, $dia->addDay(), $tenantId, $ignorarId, travar: true)[$profissionalId] ?? [];
        $grade = $this->grade();
        $motivo = $grade->motivo($servico->setor, $servico->categoria, $inicio, $ocupados);

        if ($motivo !== null) {
            // 15/11 (substituição): quem está no caminho, pelo número e pelo horário (sem pet nem tutor), para escolher outro.
            $de = $servico->setor === 'clinica' ? ($grade->blocoQueContem($inicio) ?? $inicio) : $inicio;
            $ate = $grade->fim($servico->setor, $servico->categoria, $inicio);
            $noCaminho = array_filter($ocupados, fn (array $o) => $o['inicio']->lt($ate) && $o['fim']->gt($de));
            if ($servico->setor === 'clinica' && $noCaminho !== []) {
                $motivo .= ' No caminho: '.implode(', ', array_map(fn (array $o) => '#'.$o['id'].' ('.$o['inicio']->format('H:i').'–'.$o['fim']->format('H:i').')', $noCaminho)).'.';
            }

            throw new RegraDeNegocioException($motivo);
        }
    }

    /**
     * Cirurgia e internação (config sentinel.agenda.clinica.restritas_crmv; decisão do Lucas, 15/11): agendar, remarcar ou
     * trocar PARA elas, ou mexer num agendamento que já é delas, só por quem tem CRMV no perfil. Substituição (o vet
     * faltou): trocar o profissional de um desses só para o próprio usuário logado. A gravação exige que o usuário tenha
     * confirmado com CRMV + senha (reautenticado = true, posto pelo /agente/confirmar). reautenticado = null é o Revisar
     * (a senha só vem no Confirmar). Sem $autorizacao (seeder, código): recusa. O encaixe de emergência não passa aqui.
     *
     * @param  ?array{ator: User, reautenticado: ?bool}  $autorizacao
     */
    private function conferirRestrito(CatalogoItem $alvo, ?Agendamento $atual, int $profissionalNovo, ?array $autorizacao, int $tenantId, bool $cancelamento = false): void
    {
        $restritas = $this->categoriasRestritas();
        $atualRestrito = $atual !== null && in_array(CatalogoItem::query()->where('tenant_id', $tenantId)->whereKey($atual->catalogo_item_id)->value('categoria'), $restritas, true);
        if (! $atualRestrito && ! in_array($alvo->categoria, $restritas, true)) {
            return;
        }

        $ator = $autorizacao['ator'] ?? null;
        if (! $ator instanceof User || (int) $ator->tenant_id !== $tenantId || blank($ator->crmv)) {
            throw new RegraDeNegocioException(self::RECUSA_SEM_CRMV);
        }
        if ($atual === null && $profissionalNovo !== (int) $ator->id) {
            throw new RegraDeNegocioException(self::RECUSA_CIRURGIA_PARA_OUTRO);
        }
        // Remarcar (ou assumir) uma cirurgia que já existe: depois da mudança, o profissional é quem está logado. Mover a de um
        // colega mantendo o colega é recusado (revisão de 16/11). Cancelar vale para qualquer usuário com CRMV.
        if ($atual !== null && ! $cancelamento && $profissionalNovo !== (int) $ator->id) {
            throw new RegraDeNegocioException(self::RECUSA_SUBSTITUICAO);
        }
        $noRevisar = array_key_exists('reautenticado', $autorizacao) && $autorizacao['reautenticado'] === null;
        if (! $noRevisar && ($autorizacao['reautenticado'] ?? false) !== true) {
            throw new RegraDeNegocioException(self::RECUSA_SEM_REAUTENTICACAO);
        }
    }

    /** @return string[] */
    private function categoriasRestritas(): array
    {
        return array_values(array_map('strval', (array) (config('sentinel.agenda.clinica.restritas_crmv') ?? ['cirurgia'])));
    }

    /**
     * O Confirmar desta ficha pede CRMV + senha? Sim quando o serviço pedido ou o do agendamento que já existe é de uma
     * categoria restrita (cirurgia, internação). Só leitura, no tenant.
     */
    public function exigeCrmv(?int $agendamentoId, mixed $catalogoItemId, int $tenantId): bool
    {
        $ids = array_values(array_filter([
            $this->idValido($catalogoItemId),
            $agendamentoId !== null && $agendamentoId >= 1 ? Agendamento::query()->where('tenant_id', $tenantId)->whereKey($agendamentoId)->value('catalogo_item_id') : null,
        ]));

        return $ids !== [] && CatalogoItem::query()->where('tenant_id', $tenantId)->whereIn('id', $ids)->whereIn('categoria', $this->categoriasRestritas())->exists();
    }

    /**
     * Agendamentos ATIVOS (não cancelados nem faltas) dos profissionais que cruzam [de, ate), por profissional, no
     * formato da GradeDaAgenda (exclusivo = a categoria ocupa o bloco inteiro). Travada, a leitura é a que vale dentro
     * da transação do executor: uma leitura comum veria a foto de antes (InnoDB, REPEATABLE READ). A categoria vem de
     * uma 2ª leitura, sem trava (o catálogo não muda a vaga de ninguém).
     *
     * @param  int[]  $profissionais
     * @return array<int, array<int, array{inicio: CarbonImmutable, fim: CarbonImmutable, exclusivo: bool}>>
     */
    private function ocupados(array $profissionais, CarbonInterface $de, CarbonInterface $ate, int $tenantId, ?int $ignorarId, bool $travar): array
    {
        $consulta = Agendamento::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('profissional_id', $profissionais)
            ->whereNotIn('status', Agendamento::STATUS_LIVRES)
            ->where('inicio', '<', $ate)
            ->where('fim', '>', $de)
            ->when($ignorarId, fn ($query) => $query->where('id', '!=', $ignorarId));
        $linhas = ($travar ? $consulta->lockForUpdate() : $consulta)->get(['id', 'profissional_id', 'catalogo_item_id', 'inicio', 'fim']);

        $categorias = CatalogoItem::query()->where('tenant_id', $tenantId)
            ->whereIn('id', $linhas->pluck('catalogo_item_id')->unique()->all())->pluck('categoria', 'id');
        $grade = $this->grade();
        $porProfissional = [];
        foreach ($linhas as $linha) {
            if ($grade->foraDaAgenda($categorias[$linha->catalogo_item_id] ?? null)) {
                continue; // internação não ocupa a agenda do veterinário
            }
            $porProfissional[(int) $linha->profissional_id][] = [
                'id' => (int) $linha->id,
                'inicio' => CarbonImmutable::instance($linha->inicio),
                'fim' => CarbonImmutable::instance($linha->fim),
                'exclusivo' => $grade->blocosExclusivos($categorias[$linha->catalogo_item_id] ?? null) > 0,
            ];
        }

        return $porProfissional;
    }

    /**
     * Remarcar: muda o início (e, se pedido, o profissional, do mesmo setor) de um agendamento em aberto; o fim
     * recalcula pelo modelo do setor. Pet, serviço, setor, origem e status continuam. Nunca cria um agendamento novo.
     * Mesmas regras do agendar para o novo horário, sem contar o próprio agendamento.
     * Registro de outro tenant: ModelNotFoundException (inexistente).
     */
    public function remarcar(int $id, mixed $inicio, int $tenantId, mixed $profissionalId = null, ?string $versao = null, ?array $autorizacao = null): Agendamento
    {
        return DB::transaction(function () use ($id, $inicio, $tenantId, $profissionalId, $versao, $autorizacao) {
            // Mesma ordem de trava do agendar (o profissional primeiro, depois os agendamentos): sem impasse entre os dois.
            // A 1ª leitura não trava: só diz de quem é o agendamento (outro tenant: ModelNotFoundException).
            $atual = Agendamento::query()->where('tenant_id', $tenantId)->findOrFail($id);
            $this->travarProfissionais((int) $atual->profissional_id, $this->idValido($profissionalId));
            $agendamento = Agendamento::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);
            $this->conferirVersao($agendamento, $versao);

            [$novoInicio, $novoFim, $novoProfissional] = $this->conferirRemarcacao($agendamento, $inicio, $profissionalId, $tenantId, $autorizacao);
            $agendamento->update(['inicio' => $novoInicio, 'fim' => $novoFim, 'profissional_id' => $novoProfissional]);

            return $agendamento;
        });
    }

    /**
     * Remarcar e trocar o serviço podem mudar o profissional: travam o atual e o de destino SEMPRE em ordem de id. Duas
     * trocas cruzadas (A→B e B→A ao mesmo tempo) esperam uma pela outra em vez de travar em impasse.
     */
    private function travarProfissionais(int $atual, ?int $destino): void
    {
        User::query()->whereKey(array_values(array_unique(array_filter([$atual, $destino]))))->orderBy('id')->lockForUpdate()->get(['id']);
    }

    /** Só leitura (para o Revisar): a frase do motivo de recusa da remarcação, ou null. */
    public function motivoParaNaoRemarcar(int $id, mixed $inicio, int $tenantId, mixed $profissionalId = null, ?string $versao = null, ?array $autorizacao = null): ?string
    {
        $agendamento = Agendamento::query()->where('tenant_id', $tenantId)->find($id);

        if ($agendamento === null) {
            return 'Não encontrei o agendamento #'.$id.'.';
        }

        try {
            $this->conferirVersao($agendamento, $versao);
            $this->conferirRemarcacao($agendamento, $inicio, $profissionalId, $tenantId, $autorizacao);

            return null;
        } catch (RegraDeNegocioException $e) {
            return $e->getMessage();
        }
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: int} o novo início, o novo fim e o profissional */
    private function conferirRemarcacao(Agendamento $agendamento, mixed $inicio, mixed $profissionalId, int $tenantId, ?array $autorizacao = null): array
    {
        if (! array_key_exists($agendamento->status, self::TRANSICOES)) {
            throw new RegraDeNegocioException("Um agendamento {$agendamento->status} não pode ser remarcado: só os que ainda estão em aberto.");
        }
        if ($agendamento->origem === 'encaixe_emergencia') {
            throw new RegraDeNegocioException('Um encaixe de emergência já aconteceu: não é remarcado.');
        }

        $setor = (string) $agendamento->setor;
        $profissional = blank($profissionalId) ? (int) $agendamento->profissional_id
            : (int) $this->profissionalDoSetor($profissionalId, $setor, $tenantId)->id;

        $novoInicio = $this->momento($inicio);
        if ($novoInicio === null) {
            throw new RegraDeNegocioException('Informe o novo início com data e hora (AAAA-MM-DD HH:MM).');
        }
        $this->conferirNaoPassou($setor, $novoInicio, 'remarcar');
        if ($novoInicio->equalTo($this->momento($agendamento->inicio)) && $profissional === (int) $agendamento->profissional_id) {
            throw new RegraDeNegocioException('O agendamento já está nesse horário.');
        }

        $servico = $this->servicoDoTenant($agendamento->catalogo_item_id, $tenantId);
        $this->conferirRestrito($servico, $agendamento, $profissional, $autorizacao, $tenantId);
        $novoFim = $this->grade()->fim($setor, $servico->categoria, $novoInicio);
        $this->conferirDuracaoMaxima($novoInicio, $novoFim, 'novo horário');
        $this->conferirHorario($servico, $profissional, $novoInicio, $tenantId, (int) $agendamento->id);

        return [$novoInicio, $novoFim, $profissional];
    }

    /**
     * Trocar o serviço de um agendamento em aberto ("não era banho, era consulta"). O MESMO agendamento é alterado,
     * nunca duplicado: serviço, setor, profissional e início novos (no mesmo setor a ficha mantém o profissional e o
     * horário; o fim recalcula). Recusa: realizado, cancelado ou faltou, encaixe de emergência, com cobrança válida
     * (não cancelada), serviço que é produto, profissional de outro setor, horário sem vaga ou em conflito.
     * Registro de outro tenant: ModelNotFoundException (inexistente).
     */
    public function trocarServico(int $id, mixed $catalogoItemId, mixed $profissionalId, mixed $inicio, int $tenantId, ?string $versao = null, ?array $autorizacao = null): Agendamento
    {
        return DB::transaction(function () use ($id, $catalogoItemId, $profissionalId, $inicio, $tenantId, $versao, $autorizacao) {
            $atual = Agendamento::query()->where('tenant_id', $tenantId)->findOrFail($id);
            $this->travarProfissionais((int) $atual->profissional_id, $this->idValido($profissionalId));
            $agendamento = Agendamento::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($id);
            $this->conferirVersao($agendamento, $versao);

            ['servico' => $servico, 'profissional' => $profissional, 'inicio' => $novoInicio, 'fim' => $novoFim]
                = $this->conferirTroca($agendamento, $catalogoItemId, $profissionalId, $inicio, $tenantId, $autorizacao);

            $agendamento->update([
                'catalogo_item_id' => $servico->id,
                'setor' => $servico->setor,
                'profissional_id' => $profissional,
                'inicio' => $novoInicio,
                'fim' => $novoFim,
            ]);

            return $agendamento;
        });
    }

    /** Só leitura (para o Revisar): a frase do motivo de recusa da troca de serviço, ou null. */
    public function motivoParaNaoTrocarServico(int $id, mixed $catalogoItemId, mixed $profissionalId, mixed $inicio, int $tenantId, ?string $versao = null, ?array $autorizacao = null): ?string
    {
        $agendamento = Agendamento::query()->where('tenant_id', $tenantId)->find($id);

        if ($agendamento === null) {
            return 'Não encontrei o agendamento #'.$id.'.';
        }

        try {
            $this->conferirVersao($agendamento, $versao);
            $this->conferirTroca($agendamento, $catalogoItemId, $profissionalId, $inicio, $tenantId, $autorizacao);

            return null;
        } catch (RegraDeNegocioException $e) {
            return $e->getMessage();
        }
    }

    /** @return array{servico: CatalogoItem, profissional: int, inicio: CarbonImmutable, fim: CarbonImmutable} */
    private function conferirTroca(Agendamento $agendamento, mixed $catalogoItemId, mixed $profissionalId, mixed $inicio, int $tenantId, ?array $autorizacao = null): array
    {
        if (! array_key_exists($agendamento->status, self::TRANSICOES)) {
            throw new RegraDeNegocioException("Um agendamento {$agendamento->status} não troca de serviço: só os que ainda estão em aberto.");
        }
        if ($agendamento->origem === 'encaixe_emergencia') {
            throw new RegraDeNegocioException('Um encaixe de emergência já aconteceu: o serviço dele não é trocado por aqui.');
        }
        // Com cobrança válida, o serviço cobrado ficaria diferente do agendado: cancele ou estorne antes.
        if ($this->existeTravado(Cobranca::where('agendamento_id', $agendamento->id)->where('status', '!=', 'cancelada'))) {
            throw new RegraDeNegocioException('Este agendamento já tem cobrança: cancele ou estorne a cobrança antes de trocar o serviço.');
        }

        $servico = $this->servicoDoTenant($catalogoItemId, $tenantId);
        if ((int) $servico->id === (int) $agendamento->catalogo_item_id) {
            throw new RegraDeNegocioException('O agendamento já é deste serviço: escolha o serviço novo.');
        }
        $restritas = $this->categoriasRestritas();
        $atual = CatalogoItem::query()->where('tenant_id', $tenantId)->whereKey($agendamento->catalogo_item_id)->value('categoria');
        if (in_array($servico->categoria, $restritas, true) || in_array($atual, $restritas, true)) {
            throw new RegraDeNegocioException(self::RECUSA_TROCA_CIRURGIA);
        }
        $profissional = (int) $this->profissionalDoSetor($profissionalId, $servico->setor, $tenantId)->id;
        $this->conferirRestrito($servico, $agendamento, $profissional, $autorizacao, $tenantId);

        $novoInicio = $this->momento($inicio);
        if ($novoInicio === null) {
            throw new RegraDeNegocioException('Informe o início com data e hora (AAAA-MM-DD HH:MM).');
        }
        $this->conferirNaoPassou($servico->setor, $novoInicio, 'trocar o serviço');
        $novoFim = $this->grade()->fim($servico->setor, $servico->categoria, $novoInicio);
        $this->conferirDuracaoMaxima($novoInicio, $novoFim, 'agendamento');
        $this->conferirHorario($servico, $profissional, $novoInicio, $tenantId, (int) $agendamento->id);

        return ['servico' => $servico, 'profissional' => $profissional, 'inicio' => $novoInicio, 'fim' => $novoFim];
    }

    /**
     * Para remarcar, trocar o serviço ou abrir a ficha: agendamento em aberto, por id ou pelo que o usuário disse
     * ("o retorno do dr. rafael", "o banho do Thor"): cada palavra da busca precisa aparecer no nome do pet, do tutor,
     * do serviço ou do profissional. Até 6. $setores: só os setores que o papel vê na agenda (o atendente não acha
     * horário da clínica). Cada candidato vem com as colunas da tabela da agenda (e o "resumo" numa frase).
     */
    public function localizarParaRemarcar(?int $id, ?string $busca, int $tenantId, ?array $setores = null): Collection
    {
        $palavras = $id ? [] : $this->palavrasDaBusca((string) $busca);

        if (! $id && $palavras === []) {
            return new Collection;
        }

        $consulta = $this->comColunasDaAgenda(Agendamento::query())
            ->where('agendamentos.tenant_id', $tenantId)
            ->when($setores !== null, fn ($query) => $query->whereIn('agendamentos.setor', $setores))
            ->whereIn('agendamentos.status', array_keys(self::TRANSICOES))
            ->when($id, fn ($query) => $query->where('agendamentos.id', $id));

        foreach ($palavras as $palavra) {
            $consulta->where(function ($grupo) use ($palavra) {
                foreach (['pets.nome', 'tutores.nome', 'catalogo_itens.nome_comercial', 'users.name'] as $coluna) {
                    $grupo->orWhere(fn ($um) => $this->nomeContem($um, $palavra, $coluna));
                }
            });
        }

        return $consulta->orderBy('agendamentos.inicio')->limit(6)
            ->get($this->selecaoDaAgenda())
            ->each(function (Agendamento $a) {
                $this->horarioNaLinha($a);
                $a->setAttribute('resumo', $a->getAttribute('pet').' · '.$a->getAttribute('servico').' · '.$a->getAttribute('profissional')
                    .' · '.$a->inicio->format('d/m H:i'));
            });
    }

    /** As colunas da tabela da agenda (pet, tutor, serviço, profissional) nas listas de escolha. */
    private function comColunasDaAgenda(Builder $consulta): Builder
    {
        return $consulta
            ->join('pets', 'pets.id', '=', 'agendamentos.pet_id')
            ->join('tutores', 'tutores.id', '=', 'pets.tutor_id')
            ->join('users', 'users.id', '=', 'agendamentos.profissional_id')
            ->join('catalogo_itens', 'catalogo_itens.id', '=', 'agendamentos.catalogo_item_id');
    }

    /** @return string[] */
    private function selecaoDaAgenda(): array
    {
        return ['agendamentos.*', 'pets.nome as pet', 'tutores.nome as tutor', 'catalogo_itens.nome_comercial as servico', 'users.name as profissional'];
    }

    /** "16/11/2026 09:00–10:00": o mesmo texto da coluna "horario" da consulta da agenda. */
    private function horarioNaLinha(Agendamento $a): void
    {
        $a->setAttribute('horario', $a->inicio->format('d/m/Y H:i').'–'.$a->fim->format('H:i'));
    }

    /**
     * Calendário da ficha (só mostra; a trava é a da gravação): os horários de UM dia para o serviço, por profissional
     * do setor. Devolve só hora, vagas e livre/lotado, e o nome dos profissionais do setor (nunca pet, tutor ou o que
     * está agendado). Clínica: os blocos ainda não encerrados, com as vagas (0 = lotado). Petshop: só os livres.
     * Sem $profissionalId, a união ("qualquer veterinário"): as vagas somam e cada horário lista quem tem vaga nele.
     * $ignorarId (remarcar, trocar o serviço): o próprio agendamento, só se for do tenant e de um setor do papel.
     *
     * @param  string[]  $setores  os setores que o papel vê na agenda
     */
    public function disponibilidadeDoDia(mixed $servicoId, ?string $dia, mixed $profissionalId, mixed $ignorarId, array $setores, int $tenantId): array
    {
        ['servico' => $servico, 'profissionais' => $profissionais, 'escolhidos' => $escolhidos, 'ignorar' => $ignorar]
            = $this->contextoDaDisponibilidade($servicoId, $profissionalId, $ignorarId, $setores, $tenantId);
        $data = $this->dia($dia);
        if ($data === null) {
            throw new RegraDeNegocioException('Informe o dia no formato AAAA-MM-DD.');
        }

        $grade = $this->grade();
        $agora = CarbonImmutable::now((string) config('app.timezone'));
        $ocupados = $this->ocupados($escolhidos, $data, $data->addDay(), $tenantId, $ignorar, travar: false);
        $porHora = [];
        foreach ($escolhidos as $id) {
            foreach ($grade->horariosDoDia($servico->setor, $servico->categoria, $data, $ocupados[$id] ?? [], $agora) as $h) {
                $chave = $h['inicio']->format('H:i');
                $porHora[$chave] ??= ['hora' => $chave, 'inicio' => $h['inicio']->format('Y-m-d H:i'), 'vagas' => 0, 'profissionais' => []];
                $porHora[$chave]['vagas'] += $h['vagas'];
                if ($h['vagas'] > 0) {
                    $porHora[$chave]['profissionais'][] = ['id' => $id, 'vagas' => $h['vagas']];
                }
            }
        }
        ksort($porHora);

        return [
            'setor' => $servico->setor,
            'modelo' => $servico->setor === 'clinica' ? 'blocos' : 'grade',
            // "09:00 · 2 vagas" só para consulta; os demais serviços, só livre/ocupado.
            'mostra_vagas' => $servico->setor === 'clinica' && $grade->mostraVagas($servico->categoria),
            'dia' => $data->format('Y-m-d'),
            'profissionais' => $profissionais,
            'horarios' => array_values($porHora),
        ];
    }

    /** O mini mês do calendário: para cada dia do mês (AAAA-MM), se há algum horário livre (no profissional ou em qualquer um). */
    public function disponibilidadeDoMes(mixed $servicoId, ?string $mes, mixed $profissionalId, mixed $ignorarId, array $setores, int $tenantId): array
    {
        ['servico' => $servico, 'profissionais' => $profissionais, 'escolhidos' => $escolhidos, 'ignorar' => $ignorar]
            = $this->contextoDaDisponibilidade($servicoId, $profissionalId, $ignorarId, $setores, $tenantId);
        $primeiro = is_string($mes) && preg_match('/^\d{4}-\d{2}$/', $mes) === 1 ? $this->dia($mes.'-01') : null;
        if ($primeiro === null) {
            throw new RegraDeNegocioException('Informe o mês no formato AAAA-MM.');
        }

        $grade = $this->grade();
        $agora = CarbonImmutable::now((string) config('app.timezone'));
        $depois = $primeiro->addMonth();
        $ocupados = $this->ocupados($escolhidos, $primeiro, $depois, $tenantId, $ignorar, travar: false);
        $dias = [];
        for ($dia = $primeiro; $dia->lt($depois); $dia = $dia->addDay()) {
            $livre = false;
            foreach ($escolhidos as $id) {
                $horarios = $grade->horariosDoDia($servico->setor, $servico->categoria, $dia, $ocupados[$id] ?? [], $agora);
                if (array_filter($horarios, fn (array $h) => $h['vagas'] > 0) !== []) {
                    $livre = true;
                    break;
                }
            }
            $dias[$dia->format('Y-m-d')] = $livre;
        }

        return ['setor' => $servico->setor, 'mes' => $primeiro->format('Y-m'), 'profissionais' => $profissionais, 'dias' => $dias];
    }

    /**
     * O que a disponibilidade pode ver: o serviço (do tenant, serviço e de um setor do papel; senão "não encontrei"),
     * os profissionais do setor (id e nome), o escolhido (precisa ser um deles) e o agendamento a ignorar.
     */
    private function contextoDaDisponibilidade(mixed $servicoId, mixed $profissionalId, mixed $ignorarId, array $setores, int $tenantId): array
    {
        $id = $this->idValido($servicoId);
        $servico = $id === null ? null : CatalogoItem::query()->where('tenant_id', $tenantId)->where('tipo', 'servico')->whereIn('setor', $setores)->find($id);
        if ($servico === null) {
            throw new RegraDeNegocioException('Não encontrei o serviço.');
        }

        $profissionais = User::query()->where('tenant_id', $tenantId)->whereIn('papel', self::PROFISSIONAIS[$servico->setor] ?? [])
            ->orderBy('name')->orderBy('id')->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => (int) $u->id, 'nome' => (string) $u->name])->all();
        $ids = array_column($profissionais, 'id');

        $escolhido = $this->idValido($profissionalId);
        if (! blank($profissionalId) && ($escolhido === null || ! in_array($escolhido, $ids, true))) {
            throw new RegraDeNegocioException('Não encontrei o profissional.');
        }

        $ignorar = $this->idValido($ignorarId);
        if ($ignorar !== null && ! Agendamento::query()->where('tenant_id', $tenantId)->whereIn('setor', $setores)->whereKey($ignorar)->exists()) {
            $ignorar = null; // de outro tenant ou de outro setor: não muda a contagem de ninguém
        }

        return ['servico' => $servico, 'profissionais' => $profissionais, 'escolhidos' => $escolhido === null ? $ids : [$escolhido], 'ignorar' => $ignorar];
    }

    /** @return string[] as palavras úteis de uma busca livre (sem tratamento, artigos nem as de até 2 letras) */
    private function palavrasDaBusca(string $busca): array
    {
        $ignoradas = ['dra', 'dos', 'das', 'com', 'para', 'por', 'uma', 'uns', 'pro', 'pra', 'que', 'dia', 'hoje'];
        $palavras = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($busca), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($palavras, fn (string $p) => mb_strlen($p) >= 3 && ! in_array($p, $ignoradas, true))));
    }

    /** "8 h" ou "45 min": a duração máxima como aparece na frase de recusa. */
    private function duracaoPorExtenso(int $minutos): string
    {
        return $minutos % 60 === 0 ? ($minutos / 60).' h' : $minutos.' min';
    }
}
