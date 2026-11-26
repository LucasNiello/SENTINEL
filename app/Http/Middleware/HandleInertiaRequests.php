<?php

namespace App\Http\Middleware;

use App\Services\AiAgentService;
use App\Services\ContextoUsuario;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Dados compartilhados com as páginas React (usuário, papel, menu, flash).
 * Só é aplicado às rotas que renderizam Inertia; as telas Blade não passam por aqui.
 * O menu replica as regras de resources/views/layouts/app.blade.php (que o servidor confere de novo em cada rota).
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'inertia';

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'appNome' => config('app.name', 'Sentinel'),
            'usuario' => fn () => $this->usuario($request),
            'menu' => fn () => $this->menu($request),
            'flash' => fn () => [
                'status' => $request->hasSession() ? $request->session()->get('status') : null,
                'ok' => $request->hasSession() ? $request->session()->get('ok') : null,
            ],
        ]);
    }

    /** @return array{id:int,nome:string,iniciais:string,papel:string,rotuloPapel:string}|null */
    private function usuario(Request $request): ?array
    {
        $usuario = $request->user();
        if (! $usuario) {
            return null;
        }
        $papel = rescue(fn () => app(ContextoUsuario::class)->papel(), null, false);
        $rotulos = (array) config('sentinel.rotulos_papeis', []);
        $iniciais = collect(preg_split('/[^\p{L}\p{N}]+/u', trim($usuario->name)))
            ->filter()->take(2)->map(fn ($nome) => mb_substr($nome, 0, 1))->implode('');

        return [
            'id' => (int) $usuario->id,
            'nome' => $usuario->name,
            'iniciais' => mb_strtoupper($iniciais),
            'papel' => (string) $papel,
            'rotuloPapel' => (string) ($rotulos[$papel] ?? $papel),
        ];
    }

    /** @return list<array{caminho:string,rotulo:string,icone:string,ativo:bool}> */
    private function menu(Request $request): array
    {
        if (! $request->user()) {
            return [];
        }
        $ctx = app(ContextoUsuario::class);
        $papel = rescue(fn () => $ctx->papel(), null, false);
        if ($papel === null) {
            return [];
        }
        $adminDaClinica = rescue(fn () => $ctx->ehAdminDaClinica(), false, false);
        $itens = $adminDaClinica ? [] : [
            ['agente', 'Agente', 'M4 5h16v11H8l-4 4V5m4 4h8m-8 3h5'],
            ['cadastros', 'Cadastros', 'M4 4h6v6H4zm10 0h6v6h-6zM4 14h6v6H4zm10 0h6v6h-6z'],
        ];
        $naClinica = ! $adminDaClinica && rescue(fn () => $ctx->dominio() === 'clinica', false, false);
        if ($naClinica) {
            $podeTool = fn (string $tool) => rescue(fn () => app(AiAgentService::class)->podeExecutar($tool), false, false);
            $daClinica = [];
            if ($podeTool('consultar_tutores') && $podeTool('consultar_pets')) array_splice($itens, 1, 0, [['clientes', 'Clientes', 'M16 11a3 3 0 1 0-6 0 3 3 0 0 0 6 0M5 20c0-3.3 3.1-5 7-5s7 1.7 7 5']]);
            if ($podeTool('consultar_agenda') && $papel !== 'vendedor') $daClinica[] = ['agenda', 'Agenda', 'M5 5h14v15H5zM5 10h14M9 3v4m6-4v4'];
            if ($podeTool('consultar_cobrancas')) $daClinica[] = ['cobrancas', 'Cobranças', 'M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6m-6 4h6'];
            if ($podeTool('consultar_vendas')) $daClinica[] = ['vendas', 'Vendas', 'M4 6h2l2 10h10l2-7H8m1 11h.01M17 20h.01'];
            if (rescue(fn () => $ctx->ehDono(), false, false)) $daClinica[] = ['resultados', 'Resultados', 'M5 20V10m7 10V4m7 16v-7'];
            array_splice($itens, 1, 0, array_slice($daClinica, 0, 1));
            array_push($itens, ...array_slice($daClinica, 1));
        }
        $pode = fn (callable $regra) => rescue($regra, false, false);
        if ($pode(fn () => app(AiAgentService::class)->podeExecutar('consultar_lixeira'))) $itens[] = ['lixeira', 'Lixeira', 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13m-8 4v5m4-5v5'];
        if ($pode(fn () => $ctx->ehAdministrativo())) $itens[] = ['auditoria', 'Auditoria', 'M6 3h9l3 3v15H6V3m4 5h4m-4 4h4m-4 4h4'];
        if ($pode(fn () => $ctx->dominio() === 'clinica' && ($ctx->ehDono() || $ctx->ehAdminDaClinica()))) $itens[] = ['supervisao', 'Supervisão', 'M12 3l7 3v5c0 4.5-3 8.5-7 10-4-1.5-7-5.5-7-10V6l7-3m-3 9 2 2 4-4'];

        return array_map(fn (array $i) => [
            'caminho' => $i[0],
            'rotulo' => $i[1],
            'icone' => $i[2],
            'ativo' => $request->is($i[0]) || ($i[0] === 'cadastros' && $request->is('pets/*')),
        ], $itens);
    }
}
