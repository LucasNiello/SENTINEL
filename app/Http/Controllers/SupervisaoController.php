<?php

namespace App\Http\Controllers;

use App\Exceptions\RegraDeNegocioException;
use App\Models\User;
use App\Services\AuditoriaService;
use App\Services\ContextoUsuario;
use App\Services\DetalhesAuditoria;
use App\Services\SupervisaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * Item I (clínica vet), em JSON; a tela /supervisao (TelasController::supervisao) chama estas rotas. O dono lista, marca e desmarca os supervisores
 * de setor e zera o PIN. O supervisor define o próprio PIN, confirmando a senha de login. Papel, tenant e "quem"
 * vêm sempre do ContextoUsuario, nunca do corpo. O PIN e a senha nunca vão para a resposta, a sessão ou a
 * auditoria. A escrita e a auditoria do sucesso ficam na mesma transação; a negação e a recusa também são
 * auditadas (a listagem só audita a negação, como a consulta da auditoria). O SupervisaoService reconfere o
 * dono e o tenant.
 */
class SupervisaoController extends Controller
{
    /** Tentativas de conferir a senha de login ao definir o PIN, por usuário, a cada JANELA_PIN_S. */
    private const LIMITE_PIN = 5;

    private const JANELA_PIN_S = 600;

    public function __construct(
        private ContextoUsuario $contexto,
        private SupervisaoService $supervisao,
        private AuditoriaService $auditoria,
    ) {
    }

    /** GET /supervisao/supervisores: usuários do tenant com o setor de supervisão e se têm PIN (nunca o hash). */
    public function listar(): JsonResponse
    {
        if ($resposta = $this->barrar('listar_supervisores', 'consultar', null, true)) {
            return $resposta;
        }

        $usuarios = User::query()->where('tenant_id', $this->contexto->tenantId())->orderBy('name')->get()
            ->map(fn (User $u) => ['id' => $u->id, 'nome' => $u->name, 'papel' => $u->papel,
                'supervisor_setor' => $u->supervisor_setor, 'tem_pin' => $u->pin_autorizacao !== null])
            ->values()->all();

        return response()->json(['tipo' => 'lista', 'usuarios' => $usuarios]);
    }

    /** POST /supervisao/supervisores/{id} {setor: clinica|petshop|null}: null desmarca (e zera o PIN, no service). */
    public function marcar(Request $request, int $id): JsonResponse
    {
        if ($resposta = $this->barrar('marcar_supervisor', 'atualizar', $id, true)) {
            return $resposta;
        }

        $validador = Validator::make($request->all(), ['setor' => 'present|nullable|string|in:clinica,petshop'],
            ['setor.*' => 'Informe o setor "clinica" ou "petshop", ou vazio (null) para desmarcar.']);
        if ($validador->fails()) {
            return $this->erro(422, 'AI_VALIDATION_ERROR', 'validation_error', $validador->errors()->first());
        }
        $setor = $validador->validated()['setor'];

        // nº 6-4: a auditoria diz de qual setor a pessoa saiu (desmarcar gravava só "setor: null"), e remarcar no
        // mesmo setor diz que nada mudou. Leitura só do tenant: usuário de fora continua "não encontrado" no service.
        $anterior = User::query()->where('tenant_id', $this->contexto->tenantId())->whereKey($id)->value('supervisor_setor');
        $semMudanca = $anterior === $setor;

        return $this->executar('marcar_supervisor', $id, ['setor' => $setor, 'setor_anterior' => $anterior],
            $semMudanca ? 'Sem mudança: o usuário já estava nesse setor.' : 'Supervisor atualizado.',
            fn () => $this->supervisao->marcarSupervisor($id, $setor, $this->contexto->usuario()->id, $this->contexto->tenantId()));
    }

    /** POST /supervisao/supervisores/{id}/zerar-pin: o dono só zera; nunca vê nem define o PIN. */
    public function zerarPin(int $id): JsonResponse
    {
        if ($resposta = $this->barrar('zerar_pin', 'atualizar', $id, true)) {
            return $resposta;
        }

        return $this->executar('zerar_pin', $id, [], 'PIN zerado.',
            fn () => $this->supervisao->resetarPin($id, $this->contexto->usuario()->id, $this->contexto->tenantId()));
    }

    /** POST /supervisao/meu-pin {pin, pin_confirmacao, senha_atual}: só para o próprio usuário logado. */
    public function definirMeuPin(Request $request): JsonResponse
    {
        $usuario = $this->contexto->usuario();
        if ($resposta = $this->barrar('definir_pin', 'atualizar', $usuario->id, false)) {
            return $resposta;
        }

        $validador = Validator::make($request->all(), [
            'pin' => 'required|string|max:255|same:pin_confirmacao',
            'pin_confirmacao' => 'required|string|max:255',
            'senha_atual' => 'required|string|max:255',
        ], [
            'pin.same' => 'O PIN e a confirmação não conferem.',
            '*.required' => 'Informe o PIN, a confirmação e a sua senha de login.',
        ]);
        if ($validador->fails()) {
            return $this->erro(422, 'AI_VALIDATION_ERROR', 'validation_error', $validador->errors()->first());
        }
        $dados = $validador->validated();

        // Quem pegou a sessão aberta não troca o PIN sem a senha. A tentativa conta ANTES de conferir (incremento
        // atômico, como no ReautenticacaoAdmin): rajadas em paralelo não passam do teto. Barrada ou acertada, devolve.
        $chave = 'pin:'.$usuario->id;
        if (RateLimiter::hit($chave, self::JANELA_PIN_S) > self::LIMITE_PIN) {
            $this->devolverTentativa($chave);

            return $this->erro(429, 'AI_RATE_LIMIT', 'rate_limit', 'Muitas tentativas. Aguarde alguns minutos.');
        }

        if (! Hash::check($dados['senha_atual'], (string) $usuario->password)) {
            $this->auditar('definir_pin', $usuario->id, [], 'negado', 'Senha de login não confere.', false);

            return $this->erro(403, 'AI_REAUTH_FAILED', 'authorization_error', 'A senha de login não confere.');
        }
        $this->devolverTentativa($chave);

        return $this->executar('definir_pin', $usuario->id, [], 'PIN definido.',
            fn () => $this->supervisao->definirProprioPin($usuario->id, $dados['pin'], $this->contexto->tenantId()));
    }

    private function devolverTentativa(string $chave): void
    {
        if (RateLimiter::attempts($chave) > 0) {
            RateLimiter::decrement($chave, self::JANELA_PIN_S);
        }
    }

    /** Só na clínica (fora dela, 404) e, nas rotas do dono, só o dono (403 auditado). */
    private function barrar(string $tool, string $acao, ?int $id, bool $soDono): ?JsonResponse
    {
        if ($this->contexto->dominio() !== 'clinica') {
            return $this->erro(404, 'AI_NOT_FOUND', 'not_found', 'Recurso não encontrado.');
        }

        if ($soDono && ! $this->contexto->ehDono()) {
            $this->auditar($tool, $id, [], 'negado', 'Só o dono gerencia supervisores e PIN.', false, $acao);

            return $this->erro(403, 'AI_FORBIDDEN', 'authorization_error', 'Só o dono gerencia supervisores e PIN.');
        }

        return null;
    }

    private function executar(string $tool, int $id, array $parametros, string $sucesso, callable $acao): JsonResponse
    {
        try {
            DB::transaction(function () use ($tool, $id, $parametros, $sucesso, $acao) {
                $acao();
                $this->auditar($tool, $id, $parametros, 'sucesso', $sucesso, true);
            });
        } catch (RegraDeNegocioException $e) {
            $this->auditar($tool, $id, $parametros, 'recusado', $e->getMessage(), true);

            return $this->erro(422, 'AI_BUSINESS_RULE', 'business_rule', $e->getMessage());
        }

        return response()->json(['tipo' => 'sucesso', 'mensagem' => $sucesso]);
    }

    private function auditar(string $tool, ?int $id, array $parametros, string $resultado, string $mensagem, bool $permitido, string $acao = 'atualizar'): void
    {
        // Ação de tela, fora do agente: origem "manual", como na auditoria (ConsultaAuditoria).
        app(DetalhesAuditoria::class)->com(['origem' => 'manual'], fn () => $this->auditoria->registrar(
            $tool, $acao, 'User', $id, $parametros, $resultado, $mensagem, $this->contexto->papel(), $permitido,
        ));
    }

    private function erro(int $status, string $codigo, string $categoria, string $mensagem): JsonResponse
    {
        return response()->json(['tipo' => $status === 403 ? 'negado' : 'erro', 'categoria' => $categoria, 'codigo' => $codigo,
            'http_status' => $status, 'mensagem' => $mensagem], $status);
    }
}
