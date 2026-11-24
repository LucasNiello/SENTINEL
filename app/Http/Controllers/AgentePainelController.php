<?php

namespace App\Http\Controllers;

use App\Models\Agendamento;
use App\Models\AuditLog;
use App\Models\Cobranca;
use App\Models\Mensagem;
use App\Services\AiAgentService;
use App\Services\ContextoUsuario;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Painel lateral do /agente (24/11): só leitura. "Pendentes agora" conta o que está em aberto no negócio (cobranças, mensagens
 * novas, agenda de hoje) e "Recentes" lista as últimas ações do PRÓPRIO usuário em audit_logs. Tudo sai do papel, do tenant e
 * do usuário do ContextoUsuario, nunca da requisição. Cada contagem só aparece se o papel pode usar a tool de leitura da
 * tela correspondente (a mesma regra de /cobrancas e /agenda); sem permissão, o item simplesmente não existe. Nada de
 * payload, parâmetros, mensagem de auditoria nem dado de tutor: só rótulo da ação, resultado e horário.
 * A conversa continua só na sessão por desenho (stateless, Documentação 1.2): este painel não a guarda nem lista.
 */
class AgentePainelController extends Controller
{
    private const RECENTES = 10;

    private const RESULTADOS = ['sucesso' => 'Concluída', 'negado' => 'Negada', 'revisao_com_erro' => 'Revisão com erro', 'erro' => 'Com erro'];

    public function __construct(private AiAgentService $agente, private ContextoUsuario $contexto)
    {
    }

    public function mostrar(): JsonResponse
    {
        // O Administrador da clínica só gerencia PINs: a bancada do agente o leva à /supervisao.
        abort_if($this->contexto->ehAdminDaClinica(), 403);
        $tenant = $this->contexto->tenantId();

        return response()->json(['pendentes' => $this->pendentes($tenant), 'recentes' => $this->recentes($tenant)]);
    }

    /** @return list<array{chave:string,rotulo:string,total:int,href?:string,pedido?:string}> */
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
            $itens[] = ['chave' => 'mensagens', 'rotulo' => 'Mensagens novas', 'pedido' => 'mostre as mensagens novas da caixa de entrada',
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

    /** @return list<array{acao:string,resultado:string,quando:string}> */
    private function recentes(int $tenant): array
    {
        return AuditLog::query()
            ->where('tenant_id', $tenant)
            ->where('user_id', $this->contexto->usuario()->id)
            ->where('acao', '!=', 'consultar')
            ->latest('id')
            ->limit(self::RECENTES)
            ->get(['tool', 'resultado', 'created_at'])
            ->map(fn (AuditLog $l) => [
                'acao' => $this->agente->tituloDaTool((string) $l->tool) ?? 'Ação registrada',
                'resultado' => self::RESULTADOS[$l->resultado] ?? ucfirst(str_replace('_', ' ', (string) $l->resultado)),
                'quando' => $l->created_at?->toIso8601String() ?? '',
            ])->all();
    }
}
