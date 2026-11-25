<?php

namespace App\Http\Controllers;

use App\Models\Agendamento;
use App\Models\AuditLog;
use App\Models\Cobranca;
use App\Models\Mensagem;
use App\Models\Pet;
use App\Models\Tutor;
use App\Services\AiAgentService;
use App\Services\CaixaDeEntrada;
use App\Services\ContextoUsuario;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Painel lateral do /agente (24/11): só leitura. "Pendentes agora" conta o que está em aberto no negócio (cobranças, mensagens
 * novas, agenda de hoje) e "Atividade" resume as ações do PRÓPRIO usuário em audit_logs. Tudo sai do papel, do tenant e
 * do usuário do ContextoUsuario, nunca da requisição. Cada contagem só aparece se o papel pode usar a tool de leitura da
 * tela correspondente (a mesma regra de /cobrancas e /agenda); sem permissão, o item simplesmente não existe. Nada de
 * payload, parâmetros, mensagem de auditoria nem dado de tutor: só rótulo da ação, resultado e horário.
 * A conversa continua só na sessão por desenho (stateless, Documentação 1.2): este painel não a guarda nem lista.
 */
class AgentePainelController extends Controller
{
    private const JANELA = 50;

    private const COM_EFEITO = 5;

    private const SEM_EFEITO = 8;

    private const RESULTADOS = ['sucesso' => 'Concluída', 'negado' => 'Negada', 'revisao_com_erro' => 'Revisão com erro', 'erro' => 'Com erro',
        'expirado' => 'Expirado', 'cancelado' => 'Cancelado', 'recusado' => 'Recusado'];

    public function __construct(private AiAgentService $agente, private ContextoUsuario $contexto)
    {
    }

    public function mostrar(): JsonResponse
    {
        // O Administrador da clínica só gerencia PINs: a bancada do agente o leva à /supervisao.
        abort_if($this->contexto->ehAdminDaClinica(), 403);
        $tenant = $this->contexto->tenantId();

        return response()->json(['pendentes' => $this->pendentes($tenant), 'atividade' => $this->atividade($tenant)]);
    }

    /**
     * POST (tem efeito colateral: grava mensagens), chamado pelo JS do painel. Só importa para quem está no tenant do contato
     * e pode ler mensagens; no máximo uma importação a cada `intervalo_s` por tenant (Cache::add é atômico), mesmo que o
     * Mailpit esteja fora do ar. Nunca falha por causa do Mailpit: o painel segue.
     */
    public function importar(CaixaDeEntrada $caixa): JsonResponse
    {
        abort_if($this->contexto->ehAdminDaClinica(), 403);
        $tenant = $this->contexto->tenantId();

        if ($this->contexto->dominio() !== 'clinica' || $tenant !== (int) config('sentinel.contato.tenant')
            || ! $this->agente->podeExecutar('consultar_mensagens')
            || ! Cache::add("caixa:importar:{$tenant}", true, now()->addSeconds((int) config('sentinel.contato.intervalo_s', 10)))) {
            return response()->json(['importadas' => 0, 'pulado' => true]);
        }

        return response()->json(['importadas' => $caixa->importar(), 'pulado' => false]);
    }

    /** @return list<array{chave:string,rotulo:string,total:int,href?:string,pedido?:string,origem?:string}> */
    private function pendentes(int $tenant): array
    {
        if ($this->contexto->dominio() !== 'clinica') {
            return [];
        }
        $itens = [];

        if ($this->agente->podeExecutar('consultar_cobrancas')) {
            $itens[] = ['chave' => 'cobrancas', 'rotulo' => 'Cobranças pendentes', 'href' => '/cobrancas',
                'total' => Cobranca::query()->where('tenant_id', $tenant)->whereIn('status', ['aberta', 'fechada'])->count()];
        }
        if ($this->agente->podeExecutar('consultar_mensagens')) {
            // "origem": de onde elas vêm (o e-mail de contato, lido no Mailpit na demo); o painel a mostra sob o rótulo.
            $itens[] = ['chave' => 'mensagens', 'rotulo' => 'Mensagens novas', 'origem' => (string) config('sentinel.contato.email'),
                'pedido' => 'mostre as mensagens novas da caixa de entrada',
                'total' => Mensagem::query()->where('tenant_id', $tenant)->where('status', 'nova')->count()];
        }
        $setores = $this->agente->podeExecutar('consultar_agenda') ? $this->agente->setoresDaAgenda() : [];
        if ($setores !== []) {
            $hoje = CarbonImmutable::now(config('app.timezone'));
            $itens[] = ['chave' => 'agenda', 'rotulo' => 'Agenda de hoje', 'href' => '/agenda',
                'total' => Agendamento::query()->where('tenant_id', $tenant)->whereIn('setor', $setores)->whereIn('status', ['agendado', 'confirmado'])
                    ->whereBetween('inicio', [$hoje->startOfDay(), $hoje->endOfDay()])->count()];
        }

        return $itens;
    }

    /**
     * Atividade do PRÓPRIO usuário (janela das últimas JANELA linhas de audit_logs, sem leituras). Repetições viram uma
     * linha com quantidade; só o que teve efeito (resultado "sucesso") fica à vista, no máximo COM_EFEITO linhas; o resto
     * é contado em "sem_efeito" (total de tentativas) e listado, agrupado, para o painel mostrar recolhido.
     *
     * @return array{com_efeito:list<array<string,mixed>>,sem_efeito:array{total:int,itens:list<array<string,mixed>>}}
     */
    private function atividade(int $tenant): array
    {
        $linhas = AuditLog::query()
            ->where('tenant_id', $tenant)
            ->where('user_id', $this->contexto->usuario()->id)
            ->whereNotIn('acao', ['consultar', 'exportar'])
            ->latest('id')
            ->limit(self::JANELA)
            ->get(['tool', 'acao', 'entidade_tipo', 'entidade_id', 'resultado', 'created_at']);

        $abrem = $this->registrosQueAbrem($tenant, $linhas);
        $grupos = ['efeito' => [], 'sem' => []];
        $totalSemEfeito = 0;

        foreach ($linhas as $l) {
            $efeito = $l->resultado === 'sucesso';
            $href = $efeito ? ($abrem[$l->entidade_tipo.':'.$l->entidade_id] ?? null) : null;
            // Mesma tool, mesmo resultado e (no sucesso) o mesmo registro: uma linha só, com a hora da mais recente.
            $chave = $l->tool.'|'.$l->resultado.'|'.($efeito ? $l->entidade_id : '');
            $g = $efeito ? 'efeito' : 'sem';
            $totalSemEfeito += $efeito ? 0 : 1;

            if (isset($grupos[$g][$chave])) {
                $grupos[$g][$chave]['quantidade']++;
                continue;
            }
            $grupos[$g][$chave] = [
                'acao' => $this->agente->tituloDaTool((string) $l->tool) ?? 'Ação registrada',
                'resultado' => self::RESULTADOS[$l->resultado] ?? 'Registrada',
                'quantidade' => 1,
                'quando' => $l->created_at?->toIso8601String() ?? '',
                ...($href !== null ? ['href' => $href] : []),
            ];
        }

        return [
            'com_efeito' => array_slice(array_values($grupos['efeito']), 0, self::COM_EFEITO),
            'sem_efeito' => ['total' => $totalSemEfeito, 'itens' => array_slice(array_values($grupos['sem']), 0, self::SEM_EFEITO)],
        ];
    }

    /**
     * Fichas que o item pode abrir: só tutor e pet (as únicas telas por registro), em criar/atualizar com sucesso, para quem
     * pode consultá-los, e só se o registro AINDA existe neste tenant (excluído, de outro tenant ou inexistente: sem link).
     *
     * @param  \Illuminate\Support\Collection<int, AuditLog>  $linhas
     * @return array<string, string> "Entidade:id" => href
     */
    private function registrosQueAbrem(int $tenant, $linhas): array
    {
        $fichas = ['Tutor' => ['/tutores/', Tutor::class, 'consultar_tutores'], 'Pet' => ['/pets/', Pet::class, 'consultar_pets']];
        $abrem = [];

        foreach ($fichas as $tipo => [$prefixo, $modelo, $tool]) {
            $ids = $linhas->filter(fn (AuditLog $l) => $l->resultado === 'sucesso' && in_array($l->acao, ['criar', 'atualizar'], true)
                && $l->entidade_tipo === $tipo && $l->entidade_id !== null)->pluck('entidade_id')->unique()->all();
            if ($ids === [] || ! $this->agente->podeExecutar($tool)) {
                continue;
            }
            foreach ($modelo::query()->where('tenant_id', $tenant)->whereIn('id', $ids)->pluck('id') as $id) {
                $abrem[$tipo.':'.$id] = $prefixo.$id;
            }
        }

        return $abrem;
    }
}
