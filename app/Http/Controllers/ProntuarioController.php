<?php

namespace App\Http\Controllers;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Agendamento;
use App\Models\Pet;
use App\Models\Prontuario;
use App\Services\AuditoriaService;
use App\Services\ContextoUsuario;
use App\Services\DetalhesAuditoria;
use App\Services\ProntuarioService;
use App\Services\ReautenticacaoAdmin;
use App\Support\Front;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Atendimento do veterinário (26/11): abrir, preencher, assinar e adendar o prontuário, pela interface. Rotas próprias, FORA do
 * motor do agente: não há tool de prontuário no catálogo e a IA nunca toca nisto (o agente continua recusando prontuário).
 *
 * Regras (decisão do Lucas, 26/11): só veterinário; o dono pode porque é veterinário, e só com CRMV cadastrado. O servidor decide
 * tudo: o autor é sempre o usuário logado, o tenant é o do contexto, e a tela só recebe booleanos (pode_editar, pode_assinar,
 * pode_adendar), nunca o id do veterinário. Assinar e adendar pedem CRMV e senha (o mesmo caminho da cirurgia), com limite de tentativas.
 * O texto clínico NÃO vai para audit_logs: a auditoria leva só ids, tipo e os NOMES dos campos alterados.
 */
class ProntuarioController extends Controller
{
    public const TOOL_ABRIR = 'abrir_prontuario';

    public const TOOL_ATUALIZAR = 'atualizar_prontuario';

    public const TOOL_ASSINAR = 'assinar_prontuario';

    public const TOOL_ADENDAR = 'adendar_prontuario';

    public const TOOL_VER = 'ver_prontuario';

    private const CAMPOS_CLINICOS = ['anamnese', 'exame_fisico', 'diagnostico', 'conduta'];

    public function __construct(
        private ContextoUsuario $contexto,
        private ProntuarioService $prontuarios,
        private AuditoriaService $auditoria,
        private DetalhesAuditoria $detalhes,
        private ReautenticacaoAdmin $reautenticacao,
    ) {
    }

    // ------------------------------------------------------------------ telas

    /** Novo prontuário de um pet (e, se vier, de um agendamento). Se o agendamento já tem prontuário, abre esse. */
    public function novo(Request $request)
    {
        $this->exigirAtendimento();
        $tenant = $this->contexto->tenantId();

        $petId = filter_var($request->query('pet'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $agId = filter_var($request->query('agendamento'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($petId === false, 404);

        $pet = Pet::query()->where('tenant_id', $tenant)->with(['tutor' => fn ($q) => $q->where('tenant_id', $tenant)])->findOrFail($petId);

        $agendamento = null;
        if ($agId !== false) {
            $agendamento = Agendamento::query()->where('tenant_id', $tenant)->where('pet_id', $pet->id)->with('servico')->findOrFail($agId);
            $existente = Prontuario::query()->where('tenant_id', $tenant)->where('agendamento_id', $agendamento->id)->value('id');
            if ($existente !== null) {
                return redirect('/prontuarios/'.$existente);
            }
        }

        return Front::tela('clinica.prontuario', 'Prontuario', [
            'prontuario' => null,
            'pet' => $this->petParaTela($pet),
            'agendamento' => $agendamento === null ? null : $this->agendamentoParaTela($agendamento),
            'tipos' => Prontuario::TIPOS,
            'permissoes' => ['editar' => true, 'assinar' => false, 'adendar' => false],
        ]);
    }

    public function mostrar(int $id)
    {
        abort_unless($this->podeLer(), 403);
        $tenant = $this->contexto->tenantId();
        $prontuario = $this->visivel($id, $tenant);

        $pet = Pet::query()->where('tenant_id', $tenant)->with(['tutor' => fn ($q) => $q->where('tenant_id', $tenant)])->findOrFail($prontuario->pet_id);
        $agendamento = $prontuario->agendamento_id === null ? null
            : Agendamento::query()->where('tenant_id', $tenant)->with('servico')->find($prontuario->agendamento_id);

        $this->auditar(self::TOOL_VER, 'consultar', $prontuario->id, [], 'sucesso', 'Prontuário aberto.');

        return Front::tela('clinica.prontuario', 'Prontuario', [
            'prontuario' => $this->paraTela($prontuario),
            'pet' => $this->petParaTela($pet),
            'agendamento' => $agendamento === null ? null : $this->agendamentoParaTela($agendamento),
            'tipos' => Prontuario::TIPOS,
            'permissoes' => $this->permissoes($prontuario),
        ]);
    }

    // ------------------------------------------------------------------ gravações (JSON)

    public function criar(Request $request)
    {
        $this->exigirAtendimento();
        $dados = $this->validar($request, true);
        if ($dados instanceof \Illuminate\Http\JsonResponse) {
            return $dados;
        }

        try {
            $prontuario = $this->prontuarios->abrir($dados, $this->usuarioId(), $this->contexto->tenantId());
        } catch (RegraDeNegocioException $e) {
            $this->auditar(self::TOOL_ABRIR, 'criar', null, $this->parametros($dados), 'recusado', $e->getMessage());

            return response()->json(['tipo' => 'erro', 'mensagem' => $e->getMessage()], 422);
        } catch (ModelNotFoundException) {
            return response()->json(['tipo' => 'erro', 'mensagem' => 'Pet ou agendamento não encontrado.'], 404);
        } catch (UniqueConstraintViolationException) {
            // Dois "Atender" ao mesmo tempo: a unicidade do banco segura o segundo; mensagem fixa, sem o SQL (que traria o texto clínico).
            $this->auditar(self::TOOL_ABRIR, 'criar', null, $this->parametros($dados), 'recusado', 'Este agendamento já tem um prontuário.');

            return response()->json(['tipo' => 'erro', 'mensagem' => 'Este agendamento já tem um prontuário.'], 422);
        } catch (QueryException $e) {
            return $this->erroDeBanco($e);
        }

        $this->auditar(self::TOOL_ABRIR, 'criar', $prontuario->id, $this->parametros($dados), 'sucesso', 'Prontuário aberto como rascunho.');

        return response()->json(['tipo' => 'ok', 'id' => $prontuario->id, 'url' => '/prontuarios/'.$prontuario->id, 'mensagem' => 'Rascunho salvo.']);
    }

    public function atualizar(Request $request, int $id)
    {
        $this->exigirAtendimento(self::TOOL_ATUALIZAR, 'atualizar');
        $this->visivel($id, $this->contexto->tenantId()); // rascunho alheio e outro tenant: 404, sem confirmar que o id existe
        $dados = $this->validar($request, false);
        if ($dados instanceof \Illuminate\Http\JsonResponse) {
            return $dados;
        }

        try {
            $prontuario = $this->prontuarios->atualizar($id, $dados, $this->usuarioId(), $this->contexto->tenantId());
        } catch (RegraDeNegocioException $e) {
            $this->auditar(self::TOOL_ATUALIZAR, 'atualizar', $id, $this->parametros($dados), 'recusado', $e->getMessage());

            return response()->json(['tipo' => 'erro', 'mensagem' => $e->getMessage()], 422);
        } catch (ModelNotFoundException) {
            return response()->json(['tipo' => 'erro', 'mensagem' => 'Prontuário não encontrado.'], 404);
        } catch (QueryException $e) {
            return $this->erroDeBanco($e);
        }

        $this->auditar(self::TOOL_ATUALIZAR, 'atualizar', $prontuario->id, $this->parametros($dados), 'sucesso', 'Rascunho do prontuário salvo.');

        return response()->json(['tipo' => 'ok', 'id' => $prontuario->id, 'mensagem' => 'Rascunho salvo.']);
    }

    /** Assinar: irreversível. Pede CRMV e senha do próprio veterinário (a tela ainda exige segurar 2 s). */
    public function assinar(Request $request, int $id)
    {
        $this->exigirAtendimento(self::TOOL_ASSINAR, 'assinar');
        $this->visivel($id, $this->contexto->tenantId());
        if (($negado = $this->reautenticar($request, self::TOOL_ASSINAR, $id)) !== null) {
            return $negado;
        }

        try {
            $prontuario = $this->prontuarios->assinar($id, $this->usuarioId(), $this->contexto->tenantId());
        } catch (RegraDeNegocioException $e) {
            $this->auditar(self::TOOL_ASSINAR, 'assinar', $id, [], 'recusado', $e->getMessage());

            return response()->json(['tipo' => 'erro', 'mensagem' => $e->getMessage()], 422);
        } catch (ModelNotFoundException) {
            return response()->json(['tipo' => 'erro', 'mensagem' => 'Prontuário não encontrado.'], 404);
        }

        $this->auditar(self::TOOL_ASSINAR, 'assinar', $prontuario->id, [], 'sucesso', 'Prontuário assinado.');

        return response()->json(['tipo' => 'ok', 'id' => $prontuario->id, 'mensagem' => 'Prontuário assinado. Para corrigir, registre um adendo.']);
    }

    /** Adendo: correção de prontuário assinado. Nasce assinado e não muda. Mesma conferência (CRMV e senha). */
    public function adendar(Request $request, int $id)
    {
        $this->exigirAtendimento(self::TOOL_ADENDAR, 'adendar');
        $this->visivel($id, $this->contexto->tenantId());
        $validador = Validator::make($request->all(), ['texto' => 'required|string|max:5000']);
        if ($validador->fails()) {
            return response()->json(['tipo' => 'erro', 'mensagem' => 'Escreva o texto do adendo (até 5000 caracteres).'], 422);
        }
        if (($negado = $this->reautenticar($request, self::TOOL_ADENDAR, $id)) !== null) {
            return $negado;
        }

        try {
            $adendo = $this->prontuarios->adendar($id, $validador->validated()['texto'], $this->usuarioId(), $this->contexto->tenantId());
        } catch (RegraDeNegocioException $e) {
            $this->auditar(self::TOOL_ADENDAR, 'adendar', $id, [], 'recusado', $e->getMessage());

            return response()->json(['tipo' => 'erro', 'mensagem' => $e->getMessage()], 422);
        } catch (ModelNotFoundException) {
            return response()->json(['tipo' => 'erro', 'mensagem' => 'Prontuário não encontrado.'], 404);
        } catch (QueryException $e) {
            return $this->erroDeBanco($e);
        }

        $this->auditar(self::TOOL_ADENDAR, 'adendar', $id, ['adendo_id' => $adendo->id], 'sucesso', 'Adendo registrado.');

        return response()->json(['tipo' => 'ok', 'id' => $id, 'mensagem' => 'Adendo registrado.']);
    }

    // ------------------------------------------------------------------ regras de acesso

    /** Quem ESCREVE: veterinário, ou o dono quando é veterinário (CRMV cadastrado). Domínio da clínica. */
    public function podeAtender(): bool
    {
        return $this->contexto->dominio() === 'clinica'
            && in_array($this->contexto->papel(), ['veterinario', 'dono'], true)
            && filled($this->contexto->usuario()->crmv);
    }

    /** Quem LÊ o prontuário: veterinário e dono (os papéis clínicos). */
    private function podeLer(): bool
    {
        return $this->contexto->dominio() === 'clinica' && in_array($this->contexto->papel(), ['veterinario', 'dono'], true);
    }

    private function exigirAtendimento(string $tool = self::TOOL_ABRIR, string $acao = 'criar'): void
    {
        abort_unless($this->contexto->dominio() === 'clinica', 404);
        if (! $this->podeAtender()) {
            $this->auditar($tool, $acao, null, [], 'negado', 'Só veterinário (com CRMV) atende e preenche o prontuário.', false);
            abort(403, 'Só veterinário, com CRMV cadastrado, atende e preenche o prontuário.');
        }
    }

    /** Rascunho de outro veterinário só o dono vê; o veterinário vê os assinados e os próprios. Fora disso (ou outro tenant): 404. */
    private function visivel(int $id, int $tenant): Prontuario
    {
        // Mesma resposta (404 com a mesma mensagem) para inexistente, de outro tenant ou rascunho alheio: não revela qual.
        $p = Prontuario::query()->where('tenant_id', $tenant)->with(['veterinario:id,name', 'adendos' => fn ($q) => $q->orderBy('assinado_em')->orderBy('id')])->find($id);
        if ($p === null || ($this->contexto->papel() !== 'dono' && ! $p->estaAssinado() && $p->veterinario_id !== $this->usuarioId())) {
            abort(404, 'Prontuário não encontrado.');
        }

        return $p;
    }

    /** @return array{editar: bool, assinar: bool, adendar: bool} */
    private function permissoes(Prontuario $p): array
    {
        $atende = $this->podeAtender();
        $rascunhoMeu = $atende && ! $p->estaAssinado() && $p->veterinario_id === $this->usuarioId();

        return ['editar' => $rascunhoMeu, 'assinar' => $rascunhoMeu, 'adendar' => $atende && $p->estaAssinado()];
    }

    /** Falha de banco: só a classe e o código no log (a mensagem traz o SQL com o texto clínico); resposta fixa. */
    private function erroDeBanco(QueryException $e)
    {
        Log::warning('ProntuarioController: falha de banco', ['excecao' => $e::class, 'codigo' => $e->getCode()]);

        return response()->json(['tipo' => 'erro', 'mensagem' => 'Não consegui gravar agora. Nada foi alterado. Tente de novo.'], 500);
    }

    private function usuarioId(): int
    {
        return (int) $this->contexto->usuario()->getKey();
    }

    // ------------------------------------------------------------------ entrada e saída

    /** @return array<string, mixed>|\Illuminate\Http\JsonResponse */
    private function validar(Request $request, bool $novo)
    {
        $regras = [
            'tipo' => 'required|string|in:'.implode(',', Prontuario::TIPOS),
            'anamnese' => 'nullable|string|max:10000', 'exame_fisico' => 'nullable|string|max:10000',
            'diagnostico' => 'nullable|string|max:10000', 'conduta' => 'nullable|string|max:10000',
            'entrada_em' => 'nullable|string|max:25', 'alta_em' => 'nullable|string|max:25',
        ];
        if ($novo) {
            $regras += ['pet_id' => 'required|integer|min:1', 'agendamento_id' => 'nullable|integer|min:1'];
        }
        $validador = Validator::make($request->all(), $regras);
        if ($validador->fails()) {
            return response()->json(['tipo' => 'erro', 'mensagem' => $validador->errors()->first()], 422);
        }
        $dados = $validador->validated();
        // "2026-11-26T14:30" (campo datetime-local) vira "2026-11-26 14:30"; vazio vira nulo.
        foreach (['entrada_em', 'alta_em'] as $campo) {
            if (array_key_exists($campo, $dados)) {
                $dados[$campo] = ($dados[$campo] === null || trim((string) $dados[$campo]) === '') ? null : str_replace('T', ' ', trim((string) $dados[$campo]));
            }
        }

        return $dados;
    }

    /** CRMV e senha do próprio usuário. Devolve a resposta de recusa (e audita) ou null se conferiu. */
    private function reautenticar(Request $request, string $tool, int $id)
    {
        $resultado = $this->reautenticacao->autorizarComCrmv($request->input('crmv'), $request->input('senha'));
        if (isset($resultado['admin'])) {
            return null;
        }

        [$status, $mensagem] = match ($resultado['falha'] ?? 'invalido') {
            'limite' => [429, 'Muitas tentativas com o CRMV ou a senha errados. Aguarde um minuto. Nada foi gravado.'],
            'faltando' => [403, 'Informe o seu CRMV e a sua senha. Nada foi gravado.'],
            default => [403, 'CRMV ou senha inválidos. Nada foi gravado.'],
        };
        $this->auditar($tool, $tool === self::TOOL_ASSINAR ? 'assinar' : 'adendar', $id, [], 'negado', 'CRMV e senha não conferiram.', false);

        return response()->json(['tipo' => 'erro', 'mensagem' => $mensagem], $status);
    }

    /** A auditoria leva ids, o tipo e os NOMES dos campos clínicos mexidos; nunca o texto. */
    private function parametros(array $dados): array
    {
        return array_filter([
            'pet_id' => $dados['pet_id'] ?? null, 'agendamento_id' => $dados['agendamento_id'] ?? null, 'tipo' => $dados['tipo'] ?? null,
            'campos_preenchidos' => array_values(array_filter(self::CAMPOS_CLINICOS, fn ($c) => filled($dados[$c] ?? null))),
        ], fn ($v) => $v !== null && $v !== []);
    }

    private function auditar(string $tool, string $acao, ?int $id, array $parametros, string $resultado, string $mensagem, bool $permitido = true): void
    {
        $this->detalhes->com(['origem' => 'manual'], fn () => $this->auditoria->registrar(
            tool: $tool, acao: $acao, entidadeTipo: 'Prontuario', entidadeId: $id, parametros: $parametros,
            resultado: $resultado, mensagem: $mensagem, papel: $this->contexto->papel(), permitido: $permitido,
        ));
    }

    /** @return array<string, mixed> */
    private function paraTela(Prontuario $p): array
    {
        $dt = fn ($v) => $v?->format('Y-m-d\TH:i');

        return [
            'id' => $p->id, 'tipo' => $p->tipo, 'data' => $p->created_at?->format('d/m/Y H:i'), 'veterinario' => $p->veterinario?->name,
            'assinado' => $p->estaAssinado(), 'assinado_em' => $p->assinado_em?->format('d/m/Y H:i'),
            'assinado_por' => $p->assinado_por_nome ? $p->assinado_por_nome.($p->assinado_por_crmv ? ' (CRMV '.$p->assinado_por_crmv.')' : '') : null,
            'entrada_em' => $dt($p->entrada_em), 'alta_em' => $dt($p->alta_em),
            'anamnese' => $p->anamnese, 'exame_fisico' => $p->exame_fisico, 'diagnostico' => $p->diagnostico, 'conduta' => $p->conduta,
            'adendos' => $p->adendos->map(fn ($a) => [
                'autor' => $a->autor_nome ? $a->autor_nome.($a->autor_crmv ? ' (CRMV '.$a->autor_crmv.')' : '') : null,
                'texto' => $a->texto, 'em' => $a->assinado_em?->format('d/m/Y H:i'),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function petParaTela(Pet $pet): array
    {
        return ['id' => $pet->id, 'nome' => $pet->nome, 'especie' => $pet->especie, 'tutor' => $pet->tutor?->nome, 'arquivado' => $pet->inativado_em !== null];
    }

    /** @return array<string, mixed> */
    private function agendamentoParaTela(Agendamento $a): array
    {
        return ['id' => $a->id, 'horario' => $a->inicio->format('d/m/Y H:i'), 'servico' => $a->servico?->nome_comercial, 'status' => $a->status];
    }
}
