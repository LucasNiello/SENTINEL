<?php

namespace App\Http\Controllers;

use App\Models\Pet;
use App\Models\Tutor;
use App\Services\AiAgentService;
use App\Services\AuditoriaService;
use App\Services\ContextoUsuario;
use App\Services\DetalhesAuditoria;
use App\Support\Front;
use App\Support\Mascara;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 27/11: página "Clientes" (React): o tutor com os pets dele empilhados, a busca por tutor OU por pet e o arquivo de pets.
 * Só LEITURA. Toda escrita abre o card de sempre (/agente/formulario → Revisar → Confirmar). RBAC: o das tools de leitura
 * (consultar_tutores e consultar_pets); tenant sempre o do ContextoUsuario; cada leitura grava uma linha de auditoria "manual".
 * Com o front Blade, a rota leva ao /cadastros (o plano B).
 */
class ClientesController extends Controller
{
    private const LIMITE = 30;

    public function __construct(private AiAgentService $agente, private ContextoUsuario $contexto, private AuditoriaService $auditoria, private DetalhesAuditoria $detalhes)
    {
    }

    public function index(Request $request)
    {
        abort_unless($this->contexto->dominio() === 'clinica', 404);
        if ($this->contexto->ehAdminDaClinica()) {
            return redirect('/supervisao');
        }
        abort_unless($this->agente->podeExecutar('consultar_tutores') && $this->agente->podeExecutar('consultar_pets'), 403);
        if (! Front::react()) {
            return redirect('/cadastros');
        }

        $cliente = $request->query('cliente');
        $pet = $request->query('pet');

        return Front::tela('telas.consulta', 'Clientes', [
            'clienteInicial' => is_string($cliente) && ctype_digit($cliente) && strlen($cliente) < 18 ? (int) $cliente : null,
            'petInicial' => is_string($pet) && ctype_digit($pet) && strlen($pet) < 18 ? (int) $pet : null,
            'pode' => collect(['cadastrar_cliente_com_pets', 'atualizar_tutor', 'criar_pet', 'atualizar_pet', 'arquivar_pet', 'reativar_pet'])
                ->mapWithKeys(fn (string $tool) => [$tool => $this->agente->podeExecutar($tool)])->all(),
        ]);
    }

    /** GET /clientes/dados?busca=  (lista)  ou  ?cliente=ID (detalhe com todos os pets, ativos e arquivados). */
    public function dados(Request $request): JsonResponse
    {
        abort_unless($this->contexto->dominio() === 'clinica', 404);
        abort_unless($this->agente->podeExecutar('consultar_tutores') && $this->agente->podeExecutar('consultar_pets'), 403);
        $tenantId = $this->contexto->tenantId();

        $cliente = $request->query('cliente');
        if (is_string($cliente) && ctype_digit($cliente) && strlen($cliente) < 18) {
            return $this->detalhe((int) $cliente, $tenantId);
        }

        $busca = is_string($request->query('busca')) ? mb_substr(trim($request->query('busca')), 0, 60) : '';
        $termo = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $busca).'%';

        $consulta = Tutor::query()->where('tenant_id', $tenantId)->orderBy('nome')->orderBy('id')->limit(self::LIMITE);
        if ($busca !== '') {
            $consulta->where(fn ($w) => $w->whereRaw("nome like ? escape '!'", [$termo])
                ->orWhereHas('pets', fn ($p) => $p->where('tenant_id', $tenantId)->where(fn ($q) => $q->whereRaw("nome like ? escape '!'", [$termo])
                    ->orWhereRaw("microchip like ? escape '!'", [$termo]))));
        }
        $tutores = $consulta->get(['id', 'nome', 'telefone']);
        $pets = Pet::query()->where('tenant_id', $tenantId)->whereIn('tutor_id', $tutores->pluck('id'))->orderBy('nome')
            ->get(['id', 'tutor_id', 'nome', 'especie', 'inativado_em'])->groupBy('tutor_id');

        $this->auditarLeitura(null, ['busca' => $busca], 'Lista de clientes consultada.');

        return response()->json(['tipo' => 'clientes', 'busca' => $busca, 'clientes' => $tutores->map(fn (Tutor $t) => [
            'id' => $t->id,
            'nome' => $t->nome,
            'telefone' => $t->telefone ? Mascara::telefone($t->telefone) : null,
            'pets' => ($pets[$t->id] ?? collect())->map(fn (Pet $p) => [
                'id' => $p->id, 'nome' => $p->nome, 'especie' => $p->especie, 'arquivado' => $p->inativado_em !== null,
                // Quando se busca por pet, a lista mostra qual deles bateu.
                'achado' => $busca !== '' && mb_stripos($p->nome, $busca) !== false,
            ])->values()->all(),
        ])->values()->all()]);
    }

    private function detalhe(int $id, int $tenantId): JsonResponse
    {
        // Outro tenant, lixeira ou inexistente: 404, sem dizer qual dos três.
        $tutor = Tutor::query()->where('tenant_id', $tenantId)->find($id);
        if ($tutor === null) {
            return response()->json(['tipo' => 'erro', 'mensagem' => 'Cliente não encontrado.'], 404);
        }
        $pets = Pet::query()->where('tenant_id', $tenantId)->where('tutor_id', $tutor->id)->orderBy('nome')->orderBy('id')->get();
        $this->auditarLeitura($tutor->id, [], 'Cadastro do cliente aberto.');

        return response()->json(['tipo' => 'cliente', 'cliente' => [
            'id' => $tutor->id,
            'nome' => $tutor->nome,
            'documento' => AuditoriaService::mascararCamposSensiveis(['documento' => $tutor->documento])['documento'],
            'email' => $tutor->email,
            'telefone' => $tutor->telefone ? Mascara::telefone($tutor->telefone) : null,
            'endereco' => $tutor->endereco,
            'pets' => $pets->map(fn (Pet $p) => [
                'id' => $p->id, 'nome' => $p->nome, 'especie' => $p->especie, 'raca' => $p->raca, 'sexo' => $p->sexo,
                'data_nascimento' => $p->data_nascimento?->format('Y-m-d'), 'peso_gramas' => $p->peso_gramas, 'microchip' => $p->microchip,
                'alertas' => $p->alertas, 'observacoes' => $p->observacoes, 'arquivado' => $p->inativado_em !== null,
                'arquivado_em' => $p->inativado_em?->format('Y-m-d'),
            ])->values()->all(),
        ]]);
    }

    private function auditarLeitura(?int $tutorId, array $parametros, string $mensagem): void
    {
        $this->detalhes->com(['origem' => 'manual'], fn () => $this->auditoria->registrar(
            tool: 'consultar_tutores', acao: 'consultar', entidadeTipo: 'Tutor', entidadeId: $tutorId, parametros: $parametros,
            resultado: 'sucesso', mensagem: $mensagem, papel: $this->contexto->papel(), permitido: true,
        ));
    }
}
