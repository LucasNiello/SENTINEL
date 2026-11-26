<?php

namespace App\Http\Controllers;

use App\Models\Agendamento;
use App\Support\Front;
use App\Models\Cobranca;
use App\Models\Pet;
use App\Models\Tutor;
use App\Models\Venda;
use App\Exceptions\RegraDeNegocioException;
use App\Services\AgendaService;
use App\Services\AiAgentService;
use App\Services\AuditoriaService;
use App\Services\DetalhesAuditoria;
use App\Services\ContextoUsuario;
use App\Services\ResultadosClinica;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Front de 14/11 (F2): telas de LEITURA da clínica + petshop — agenda, cobranças, vendas, ficha do pet e Resultados.
 * Nada é gravado aqui. Toda ação de escrita das telas abre o card de sempre (/agente/formulario → Revisar → Confirmar).
 *
 * RBAC: o da tool de leitura de cada tela (AiAgentService::podeExecutar), mais a tabela da FASE oito (decisão do Lucas,
 * 15/11) na agenda: o vendedor não abre a agenda e o atendente só vê a de banho e tosa (setoresDaAgenda).
 * Agenda, cobranças e vendas leem por consultarManual (RBAC, tenant, colunas e auditoria da própria tool). A ficha do
 * pet e os Resultados leem os models direto, sempre filtrados pelo tenant do ContextoUsuario, e gravam a própria
 * linha de auditoria de leitura (origem "manual"), como a ConsultaAuditoria faz.
 * Fora da clínica as telas não existem (404). O Administrador da clínica só gerencia PINs: vai para a /supervisao.
 * Nenhuma tela mostra o prontuário (nem os metadados).
 */
class ClinicaTelasController extends Controller
{
    private const SETORES = ['clinica' => 'Clínica', 'petshop' => 'Banho e tosa / loja'];

    /** Nomes das leituras próprias na auditoria (não são tools do agente); a tela de auditoria filtra por eles também. */
    public const TOOL_FICHA = 'ver_ficha_pet';

    public const TOOL_RESULTADOS = 'ver_resultados';

    public const TOOL_FICHA_TUTOR = 'ver_ficha_tutor';

    public function __construct(private AiAgentService $agente, private ContextoUsuario $contexto, private AuditoriaService $auditoria, private DetalhesAuditoria $detalhes)
    {
    }

    public function agenda(Request $request)
    {
        if ($desvio = $this->entrada('consultar_agenda')) {
            return $desvio;
        }
        $setores = $this->setoresDaAgenda();
        abort_if($setores === [], 403);

        $filtros = $this->filtros($request, [
            'visao' => 'nullable|in:dia,semana',
            'dia' => 'nullable|date_format:Y-m-d',
            'setor' => 'nullable|in:clinica,petshop',
        ]);
        $visao = $filtros['visao'] ?? 'dia';
        // Quem só vê um setor fica preso nele, qualquer que seja o filtro pedido.
        $setor = count($setores) === 1 ? $setores[0] : ($filtros['setor'] ?? null);
        $dia = $this->data($filtros['dia'] ?? null);
        [$inicio, $fim] = $visao === 'semana' ? [$dia->startOfWeek(CarbonImmutable::MONDAY), $dia->endOfWeek(CarbonImmutable::SUNDAY)] : [$dia, $dia];

        $resposta = $this->consultar('consultar_agenda', array_filter([
            'data_inicio' => $inicio->toDateString(), 'data_fim' => $fim->toDateString(), 'setor' => $setor,
        ]));

        // Um bloco por dia, na ordem; o horário vem pronto da tool ("15/11/2026 09:00–09:30").
        $dias = [];
        for ($d = $inicio; $d->lte($fim); $d = $d->addDay()) {
            $dias[$d->format('d/m/Y')] = ['data' => $d, 'linhas' => []];
        }
        foreach ($resposta['linhas'] as $linha) {
            $chave = substr((string) $linha['horario'], 0, 10);
            if (isset($dias[$chave])) {
                $dias[$chave]['linhas'][] = [...$linha, 'hora' => trim(substr((string) $linha['horario'], 10))];
            }
        }

        $passo = $visao === 'semana' ? 7 : 1;

        $dados = [
            'erro' => $resposta['erro'],
            'dias' => $dias,
            'visao' => $visao,
            'dia' => $dia,
            'setor' => $setor ?? '',
            'setores' => self::SETORES,
            'soUmSetor' => count($setores) === 1,
            'anterior' => $dia->subDays($passo)->toDateString(),
            'proximo' => $dia->addDays($passo)->toDateString(),
            'hoje' => $this->data(null)->toDateString(),
            'total' => count($resposta['linhas']),
            // A tool devolve no máximo 200 horários: no teto, os últimos dias podem estar incompletos e a tela avisa.
            'noTeto' => count($resposta['linhas']) >= 200,
            'podeMudarStatus' => $this->agente->podeExecutar('alterar_status_agendamento'),
            'podeAgendar' => $this->agente->podeExecutar('agendar_atendimento'),
        ];

        if (! Front::react()) {
            return view('clinica.agenda', $dados);
        }

        // React: só os campos que a tela mostra (nada de modelo inteiro na página). Mesmos dados do Blade.
        $diasSemana = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];

        return Front::tela('clinica.agenda', 'Agenda', [
            ...\Illuminate\Support\Arr::except($dados, ['dias', 'dia', 'setores']),
            'dia' => $dia->toDateString(),
            'dias' => collect($dados['dias'])->map(fn (array $b, string $rotulo) => [
                'rotulo' => $rotulo,
                'semana' => $diasSemana[$b['data']->dayOfWeek],
                'linhas' => array_map(fn (array $l) => \Illuminate\Support\Arr::only($l, ['id', 'hora', 'pet', 'tutor', 'servico', 'profissional', 'setor', 'status']), $b['linhas']),
            ])->values()->all(),
        ]);
    }

    /** Cobranças e Vendas: Blade como antes; no React, só as colunas exibidas de cada linha (nada além do que a tabela mostra). */
    private function financeiro(array $dados)
    {
        if (! Front::react()) {
            return view('clinica.financeiro', $dados);
        }
        $chaves = [...array_keys($dados['colunas']), 'id', 'status'];
        $dados['linhas'] = array_map(fn (array $l) => \Illuminate\Support\Arr::only($l, $chaves), $dados['linhas']);

        return Front::tela('clinica.financeiro', 'Financeiro', $dados);
    }

    public function cobrancas(Request $request)
    {
        if ($desvio = $this->entrada('consultar_cobrancas')) {
            return $desvio;
        }

        $filtros = $this->filtros($request, ['status' => 'nullable|in:'.implode(',', Cobranca::STATUS), 'setor' => 'nullable|in:clinica,petshop']);
        $resposta = $this->consultar('consultar_cobrancas', $filtros);

        return $this->financeiro([
            'titulo' => 'Cobranças',
            'rota' => '/cobrancas',
            'intro' => 'Cobranças dos atendimentos, com o total, o que já foi estornado e o líquido. Receber, cancelar e estornar abrem o card de revisão.',
            'erro' => $resposta['erro'],
            'linhas' => $resposta['linhas'],
            'colunas' => ['id' => 'Nº', 'pet' => 'Pet', 'servico' => 'Serviço', 'atendimento' => 'Atendimento', 'total' => 'Total', 'estornado' => 'Estornado',
                'liquido' => 'Líquido', 'status' => 'Situação', 'forma_pagamento' => 'Pagamento'],
            'resumo' => $this->resumoFinanceiro($resposta['linhas']),
            'status' => $filtros['status'] ?? '',
            'setor' => $filtros['setor'] ?? '',
            'opcoesStatus' => ['aberta' => 'Aberta', 'fechada' => 'A receber', 'paga' => 'Paga', 'cancelada' => 'Cancelada'],
            // A ação de cada linha sai do status; o /agente/formulario confere papel e tenant de novo.
            'acoes' => array_filter([
                'a receber' => array_filter([
                    $this->agente->podeExecutar('receber_cobranca') ? ['tool' => 'receber_cobranca', 'rotulo' => 'Receber'] : null,
                    $this->agente->podeExecutar('cancelar_cobranca') ? ['tool' => 'cancelar_cobranca', 'rotulo' => 'Cancelar'] : null,
                ]),
                'aberta' => array_filter([$this->agente->podeExecutar('cancelar_cobranca') ? ['tool' => 'cancelar_cobranca', 'rotulo' => 'Cancelar'] : null]),
                'paga' => array_filter([$this->agente->podeExecutar('estornar_cobranca') ? ['tool' => 'estornar_cobranca', 'rotulo' => 'Estornar'] : null]),
            ]),
        ]);
    }

    public function vendas(Request $request)
    {
        if ($desvio = $this->entrada('consultar_vendas')) {
            return $desvio;
        }

        $filtros = $this->filtros($request, ['status' => 'nullable|in:'.implode(',', Venda::STATUS), 'setor' => 'nullable|in:clinica,petshop']);
        $resposta = $this->consultar('consultar_vendas', $filtros);

        return $this->financeiro([
            'titulo' => 'Vendas',
            'rota' => '/vendas',
            'intro' => 'Vendas avulsas da loja e da farmácia, com o total, o que já foi estornado e o líquido. O estorno abre o card de revisão.',
            'erro' => $resposta['erro'],
            'linhas' => $resposta['linhas'],
            'colunas' => ['id' => 'Nº', 'data' => 'Data', 'setor' => 'Setor', 'tutor' => 'Tutor', 'pet' => 'Pet', 'total' => 'Total', 'estornado' => 'Estornado',
                'liquido' => 'Líquido', 'status' => 'Situação', 'forma_pagamento' => 'Pagamento'],
            'resumo' => $this->resumoFinanceiro($resposta['linhas']),
            'status' => $filtros['status'] ?? '',
            'setor' => $filtros['setor'] ?? '',
            'opcoesStatus' => ['aberta' => 'Aberta', 'paga' => 'Paga', 'cancelada' => 'Cancelada'],
            'acoes' => array_filter([
                'paga' => array_filter([$this->agente->podeExecutar('estornar_venda') ? ['tool' => 'estornar_venda', 'rotulo' => 'Estornar'] : null]),
            ]),
        ]);
    }

    /**
     * Ficha do pet: cadastro, alertas NÃO clínicos (o campo "alertas" do cadastro), agendamentos, cobranças e vendas.
     * Nada do prontuário entra aqui. Cada bloco só aparece para quem já pode consultar aquilo pelo agente.
     */
    public function pet(int $id)
    {
        if ($desvio = $this->entrada('consultar_pets')) {
            return $desvio;
        }

        $tenantId = $this->contexto->tenantId();
        // Outro tenant, lixeira ou inexistente: 404, sem dizer qual dos três.
        $pet = Pet::query()->where('tenant_id', $tenantId)->with(['tutor' => fn ($q) => $q->where('tenant_id', $tenantId)])->findOrFail($id);

        $setores = $this->setoresDaAgenda();
        $agendamentos = $setores !== []
            ? Agendamento::query()->where('agendamentos.tenant_id', $tenantId)->where('pet_id', $pet->id)
                ->whereIn('agendamentos.setor', $setores)
                ->join('users', 'users.id', '=', 'agendamentos.profissional_id')
                ->join('catalogo_itens', 'catalogo_itens.id', '=', 'agendamentos.catalogo_item_id')
                ->orderByDesc('agendamentos.inicio')->limit(20)
                ->get(['agendamentos.*', 'users.name as profissional', 'catalogo_itens.nome_comercial as servico'])
            : null;

        $cobrancas = $this->agente->podeExecutar('consultar_cobrancas')
            ? Cobranca::query()->where('cobrancas.tenant_id', $tenantId)
                ->join('agendamentos', 'agendamentos.id', '=', 'cobrancas.agendamento_id')
                ->join('catalogo_itens', 'catalogo_itens.id', '=', 'agendamentos.catalogo_item_id')
                ->where('agendamentos.pet_id', $pet->id)->where('agendamentos.tenant_id', $tenantId)
                ->orderByDesc('cobrancas.id')->limit(20)
                ->select(['cobrancas.*', 'catalogo_itens.nome_comercial as servico', 'agendamentos.inicio as atendimento_em'])
                ->selectSub(fn ($q) => $q->from('estornos')->whereColumn('estornos.cobranca_id', 'cobrancas.id')->where('estornos.tenant_id', $tenantId)
                    ->selectRaw('coalesce(sum(valor_centavos), 0)'), 'estornado_centavos')
                ->get()
            : null;

        $vendas = $this->agente->podeExecutar('consultar_vendas')
            ? Venda::query()->where('vendas.tenant_id', $tenantId)->where('pet_id', $pet->id)->orderByDesc('vendas.id')->limit(20)
                ->select(['vendas.*'])
                ->selectSub(fn ($q) => $q->from('estornos')->whereColumn('estornos.venda_id', 'vendas.id')->where('estornos.tenant_id', $tenantId)
                    ->selectRaw('coalesce(sum(valor_centavos), 0)'), 'estornado_centavos')
                ->get()
            : null;

        $this->auditarLeitura(self::TOOL_FICHA, 'Pet', $pet->id, [], 'Ficha do pet aberta.');

        $dados = [
            'pet' => $pet,
            'agendamentos' => $agendamentos,
            'cobrancas' => $cobrancas,
            'vendas' => $vendas,
            'podeEditar' => $this->agente->podeExecutar('atualizar_pet'),
            // O bloco do tutor segue a consulta de tutores, como os outros blocos seguem a deles.
            'verTutor' => $this->agente->podeExecutar('consultar_tutores'),
        ];
        if (! Front::react()) {
            return view('clinica.pet', $dados);
        }

        return Front::tela('clinica.pet', 'Pet', $this->propsDoPet($dados));
    }

    /**
     * Ficha do tutor (15/11, só leitura): cadastro e os pets dele (nome, espécie, link para a ficha do pet). O cadastro
     * continua separado (Cadastros › Tutores). Outro tenant, lixeira ou inexistente: 404.
     */
    /** Ficha do pet para o React: só o que a tela mostra, com os mesmos textos e contas do Blade (reais, líquido, idade). */
    private function propsDoPet(array $d): array
    {
        $pet = $d['pet'];
        $reais = fn (int $c) => ResultadosClinica::reais($c);
        $idade = $pet->data_nascimento ? $pet->data_nascimento->diff(now()) : null;
        $textoIdade = $idade ? ($idade->y > 0 ? $idade->y.' ano(s)' : $idade->m.' mês(es)') : null;
        $liquido = fn (string $status, int $total, int $est) => $status === 'paga' || $est > 0 ? $reais($total - $est) : null;

        return [
            'pet' => [
                'id' => $pet->id, 'nome' => $pet->nome, 'especie' => $pet->especie, 'raca' => $pet->raca, 'sexo' => $pet->sexo,
                'nascimento' => $pet->data_nascimento ? $pet->data_nascimento->format('d/m/Y').' ('.$textoIdade.')' : null,
                'peso' => $pet->peso_gramas !== null ? number_format($pet->peso_gramas / 1000, $pet->peso_gramas % 1000 === 0 ? 0 : 1, ',', '.').' kg' : null,
                'microchip' => $pet->microchip, 'alertas' => $pet->alertas,
            ],
            'tutor' => ($d['verTutor'] && $pet->tutor) ? ['id' => $pet->tutor->id, 'nome' => $pet->tutor->nome, 'telefone' => $pet->tutor->telefone, 'email' => $pet->tutor->email] : null,
            'verTutor' => $d['verTutor'],
            'agendamentos' => $d['agendamentos']?->map(fn ($a) => [
                'id' => $a->id, 'horario' => $a->inicio->format('d/m/Y H:i').'–'.$a->fim->format('H:i'), 'servico' => $a->servico,
                'profissional' => $a->profissional, 'setor' => $a->setor, 'status' => $a->status,
            ])->values()->all(),
            'cobrancas' => $d['cobrancas']?->map(function ($c) use ($reais, $liquido) {
                $est = (int) $c->estornado_centavos > 0 ? (int) $c->estornado_centavos : (int) ($c->estorno_valor_centavos ?? 0);

                return ['id' => $c->id, 'servico' => $c->servico,
                    'atendimento' => CarbonImmutable::parse($c->atendimento_em, config('app.timezone'))->format('d/m/Y H:i'),
                    'total' => $reais((int) $c->total_centavos), 'estornado' => $reais($est), 'liquido' => $liquido($c->status, (int) $c->total_centavos, $est), 'status' => $c->status];
            })->values()->all(),
            'vendas' => $d['vendas']?->map(fn ($v) => [
                'id' => $v->id, 'data' => ($v->pago_em ?? $v->created_at)?->format('d/m/Y H:i'), 'setor' => $v->setor,
                'total' => $reais((int) $v->total_centavos), 'estornado' => $reais((int) $v->estornado_centavos),
                'liquido' => $liquido($v->status, (int) $v->total_centavos, (int) $v->estornado_centavos), 'status' => $v->status,
            ])->values()->all(),
            'podeEditar' => $d['podeEditar'],
        ];
    }

    public function tutor(int $id)
    {
        if ($desvio = $this->entrada('consultar_tutores')) {
            return $desvio;
        }

        $tenantId = $this->contexto->tenantId();
        $tutor = Tutor::query()->where('tenant_id', $tenantId)->findOrFail($id);
        $pets = $this->agente->podeExecutar('consultar_pets')
            ? Pet::query()->where('tenant_id', $tenantId)->where('tutor_id', $tutor->id)->orderBy('nome')->orderBy('id')->get(['id', 'nome', 'especie', 'raca'])
            : null;

        $this->auditarLeitura(self::TOOL_FICHA_TUTOR, 'Tutor', $tutor->id, [], 'Ficha do tutor aberta.');

        $podeEditar = $this->agente->podeExecutar('atualizar_tutor');
        if (! Front::react()) {
            return view('clinica.tutor', ['tutor' => $tutor, 'pets' => $pets, 'podeEditar' => $podeEditar]);
        }

        return Front::tela('clinica.tutor', 'Tutor', [
            'tutor' => ['id' => $tutor->id, 'nome' => $tutor->nome, 'telefone' => $tutor->telefone, 'email' => $tutor->email, 'endereco' => $tutor->endereco],
            'pets' => $pets?->map(fn ($p) => ['id' => $p->id, 'nome' => $p->nome, 'especie' => $p->especie, 'raca' => $p->raca])->values()->all(),
            'podeEditar' => $podeEditar,
        ]);
    }

    /**
     * Calendário da ficha de agendamento (15/11), em JSON: os horários de um dia (?dia=AAAA-MM-DD) ou os dias com vaga
     * de um mês (?mes=AAAA-MM) para um serviço (?servico=), opcionalmente de um profissional (?profissional=) e sem
     * contar o próprio agendamento (?ignorar=). Só mostra: a vaga é conferida de novo na gravação. Papel e tenant do
     * servidor (mesma regra da tela /agenda: o vendedor não consulta, o atendente só o banho e tosa). Devolve hora,
     * vagas e o nome dos profissionais do setor; nunca pet, tutor ou o que está agendado. Sem linha de auditoria por
     * chamada (decisão de 15/11: o calendário chama muitas vezes e não devolve dado pessoal); o teto é o da rota.
     */
    public function disponibilidade(Request $request, AgendaService $agenda)
    {
        abort_unless($this->contexto->dominio() === 'clinica', 404);
        $setores = $this->contexto->ehAdminDaClinica() ? [] : $this->setoresDaAgenda();
        if ($setores === []) {
            return response()->json(['tipo' => 'erro', 'mensagem' => 'Seu papel não consulta a agenda.'], 403);
        }

        $q = array_map(fn ($v) => is_string($v) ? $v : null, $request->only(['servico', 'dia', 'mes', 'profissional', 'ignorar']));

        try {
            $dados = filled($q['mes'] ?? null)
                ? $agenda->disponibilidadeDoMes($q['servico'] ?? null, $q['mes'], $q['profissional'] ?? null, $q['ignorar'] ?? null, $setores, $this->contexto->tenantId())
                : $agenda->disponibilidadeDoDia($q['servico'] ?? null, $q['dia'] ?? null, $q['profissional'] ?? null, $q['ignorar'] ?? null, $setores, $this->contexto->tenantId());
        } catch (RegraDeNegocioException $e) {
            return response()->json(['tipo' => 'erro', 'mensagem' => $e->getMessage()], 422);
        }

        return response()->json(['tipo' => 'disponibilidade', ...$dados]);
    }

    /** Resultados (BI): só o dono. O cálculo é do ResultadosClinica (só leitura). */
    public function resultados(Request $request, ResultadosClinica $resultados)
    {
        abort_unless($this->contexto->dominio() === 'clinica', 404);
        if ($this->contexto->ehAdminDaClinica()) {
            return redirect('/supervisao');
        }
        abort_unless($this->contexto->ehDono(), 403);

        $filtros = $this->filtros($request, ['periodo' => 'nullable|in:dia,semana,mes,ano', 'dia' => 'nullable|date_format:Y-m-d']);
        $periodo = $filtros['periodo'] ?? 'mes';
        $dia = $this->data($filtros['dia'] ?? null);
        [$inicio, $fim] = $resultados->intervalo($periodo, $dia);
        $passo = fn (int $sinal) => match ($periodo) {
            'semana' => $dia->addWeeks($sinal), 'mes' => $dia->startOfMonth()->addMonthsNoOverflow($sinal), 'ano' => $dia->startOfYear()->addYears($sinal), default => $dia->addDays($sinal),
        };

        $resumo = $resultados->resumo($this->contexto->tenantId(), $inicio, $fim);
        $this->auditarLeitura(self::TOOL_RESULTADOS, null, null, ['periodo' => $periodo, 'data_inicio' => $inicio->toDateString(), 'data_fim' => $fim->toDateString()], 'Resultados consultados.');

        $dados = [
            'r' => $resumo,
            'periodo' => $periodo,
            'periodos' => ResultadosClinica::PERIODOS,
            'dia' => $dia,
            'inicio' => $inicio,
            'fim' => $fim,
            'anterior' => $passo(-1)->toDateString(),
            'proximo' => $passo(1)->toDateString(),
            'hoje' => $this->data(null)->toDateString(),
        ];
        if (! Front::react()) {
            return view('clinica.resultados', $dados);
        }

        $meses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

        return Front::tela('clinica.resultados', 'Resultados', [
            // Listas (não objetos): o React faz .map nelas.
            'r' => [...$resumo, 'setores' => array_values($resumo['setores']), 'top_itens' => array_values($resumo['top_itens'])],
            'periodo' => $periodo,
            'periodos' => ResultadosClinica::PERIODOS,
            'dia' => $dia->toDateString(),
            'rotuloPeriodo' => match ($periodo) {
                'dia' => $dia->format('d/m/Y'),
                'semana' => $inicio->format('d/m').' a '.$fim->format('d/m/Y'),
                'ano' => (string) $dia->year,
                default => $meses[$dia->month - 1].' de '.$dia->year,
            },
            'nomes' => ['dia' => ['Dia anterior', 'Próximo dia'], 'semana' => ['Semana anterior', 'Próxima semana'], 'mes' => ['Mês anterior', 'Próximo mês'], 'ano' => ['Ano anterior', 'Próximo ano']][$periodo],
            'anterior' => $dados['anterior'], 'proximo' => $dados['proximo'], 'hoje' => $dados['hoje'],
        ]);
    }

    /** Domínio, Administrador da clínica e o papel da tool de leitura da tela. Null = pode abrir. */
    private function entrada(string $toolDeLeitura): ?\Illuminate\Http\RedirectResponse
    {
        abort_unless($this->contexto->dominio() === 'clinica', 404);
        if ($this->contexto->ehAdminDaClinica()) {
            return redirect('/supervisao');
        }
        abort_unless($this->agente->podeExecutar($toolDeLeitura), 403);

        return null;
    }

    /**
     * Setores da agenda que o papel vê nas telas (tabela da FASE oito, decisão do Lucas de 15/11): o vendedor nenhum, o
     * atendente só banho e tosa; recepção, veterinário e dono os dois. Sem a tool de leitura, nenhum. A regra mora no
     * catálogo (setoresDaAgenda), a mesma do chat (Bloco A de 15/11).
     *
     * @return string[]
     */
    private function setoresDaAgenda(): array
    {
        if (! $this->agente->podeExecutar('consultar_agenda')) {
            return [];
        }

        return $this->agente->setoresDaAgenda();
    }

    /** Leitura direta (sem tool) também fica na auditoria, com origem "manual", como as telas que leem pela tool. */
    private function auditarLeitura(string $tool, ?string $entidade, ?int $entidadeId, array $parametros, string $mensagem): void
    {
        $this->detalhes->com(['origem' => 'manual'], fn () => $this->auditoria->registrar(
            tool: $tool, acao: 'consultar', entidadeTipo: $entidade, entidadeId: $entidadeId, parametros: $parametros,
            resultado: 'sucesso', mensagem: $mensagem, papel: $this->contexto->papel(), permitido: true,
        ));
    }

    /** Filtros da query string: só as chaves esperadas; valor inválido é ignorado (a tela abre no padrão). */
    private function filtros(Request $request, array $regras): array
    {
        $entrada = array_filter($request->only(array_keys($regras)), fn ($v) => is_string($v) && $v !== '');
        $validador = Validator::make($entrada, $regras);

        return array_diff_key($entrada, $validador->errors()->toArray());
    }

    private function data(?string $dia): CarbonImmutable
    {
        $fuso = (string) config('app.timezone');

        return ($dia !== null ? CarbonImmutable::createFromFormat('!Y-m-d', $dia, $fuso) : CarbonImmutable::now($fuso))->startOfDay();
    }

    /** @return array{linhas: array<int, array<string, mixed>>, erro: ?string} a consulta da tool (RBAC, tenant e auditoria dela). */
    private function consultar(string $tool, array $filtros): array
    {
        $resposta = $this->agente->consultarManual($tool, $filtros);
        abort_if(($resposta['tipo'] ?? null) === 'negado', 403);

        return ($resposta['tipo'] ?? null) === 'resultado_leitura'
            ? ['linhas' => array_values((array) ($resposta['resultado'] ?? [])), 'erro' => null]
            : ['linhas' => [], 'erro' => (string) ($resposta['mensagem'] ?? 'Não consegui fazer a consulta agora. Tente de novo.')];
    }

    /** Soma do que a tabela mostra (as colunas já vêm em "R$ 1.234,56"; "—" não soma). */
    private function resumoFinanceiro(array $linhas): array
    {
        $centavos = fn (mixed $texto) => preg_match('/^R\$ ([\d.]+),(\d{2})$/', (string) $texto, $m) === 1 ? ((int) str_replace('.', '', $m[1])) * 100 + (int) $m[2] : 0;
        $soma = ['total' => 0, 'estornado' => 0, 'liquido' => 0];
        foreach ($linhas as $linha) {
            foreach ($soma as $coluna => $_) {
                $soma[$coluna] += $centavos($linha[$coluna] ?? null);
            }
        }

        return array_map(ResultadosClinica::reais(...), $soma) + ['registros' => count($linhas)];
    }
}
