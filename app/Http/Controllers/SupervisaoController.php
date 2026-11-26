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
 * Item I (clínica vet), em JSON; a tela /supervisao (TelasController::supervisao) chama estas rotas. T1 (14/11): o dono
 * lista, marca e desmarca os supervisores de setor; o Administrador (papel 'admin' do tenant da clínica) lista o dono e
 * os supervisores. O Administrador e o dono (Bloco B de 15/11) geram o PIN (confirmando a própria senha de login) e o
 * revogam. Papel, tenant e "quem" vêm sempre do
 * ContextoUsuario, nunca do corpo. O código gerado sai UMA vez, na resposta do "gerar" (sem cache), e nunca vai para
 * a sessão, o log, a auditoria nem outra resposta; a senha também não. A escrita e a auditoria do sucesso ficam na
 * mesma transação; a negação e a recusa também são auditadas (a listagem só audita a negação). O SupervisaoService
 * reconfere o dono, o Administrador e o tenant.
 */
class SupervisaoController extends Controller
{
    /** Tentativas de conferir a senha de login de quem gera um PIN (Administrador ou dono), por pessoa, a cada JANELA_PIN_S. */
    private const LIMITE_PIN = 5;

    private const JANELA_PIN_S = 600;

    private const SO_QUEM_GERE_PIN = 'Só o dono e o Administrador geram e revogam PIN.';

    public function __construct(
        private ContextoUsuario $contexto,
        private SupervisaoService $supervisao,
        private AuditoriaService $auditoria,
    ) {
    }

    /**
     * GET /supervisao/supervisores: para o dono, os usuários do tenant (para marcar o setor); para o Administrador, só
     * quem pode ter PIN (o dono e os supervisores). Nunca o hash nem o e-mail; só se tem PIN.
     */
    public function listar(): JsonResponse
    {
        if ($resposta = $this->barrar('listar_supervisores', 'consultar', null, ['dono', 'admin'])) {
            return $resposta;
        }

        // 26/11: o Administrador/RH agora vê a equipe toda (para ligar e desligar contas); o PIN continua só de quem pode
        // ter ("pode_pin"). "ativo" e "pode_ativar" dizem o estado da conta e se este usuário pode ser ligado/desligado.
        $eu = (int) $this->contexto->usuario()->id;
        $usuarios = User::query()->where('tenant_id', $this->contexto->tenantId())->orderBy('name')->get()
            ->map(fn (User $u) => ['id' => $u->id, 'nome' => $u->name, 'papel' => $u->papel,
                'supervisor_setor' => $u->supervisor_setor, 'tem_pin' => $u->pin_autorizacao !== null,
                'pode_pin' => SupervisaoService::podeTerPin($u), 'ativo' => $u->ativo !== false,
                'pode_ativar' => $u->papel !== 'dono' && (int) $u->id !== $eu])
            ->values()->all();

        return response()->json(['tipo' => 'lista', 'usuarios' => $usuarios]);
    }

    /** POST /supervisao/supervisores/{id} {setor: clinica|petshop|null}: null desmarca (e zera o PIN, no service). Só o dono. */
    public function marcar(Request $request, int $id): JsonResponse
    {
        if ($resposta = $this->barrar('marcar_supervisor', 'atualizar', $id, ['dono'])) {
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

    /**
     * POST /supervisao/pins/{id}/gerar {senha_atual}: o Administrador ou o dono, confirmando a própria senha de login. Devolve o
     * código UMA vez ("pin"), com Cache-Control: no-store; a auditoria diz para quem e por quem, nunca o código.
     */
    public function gerarPin(Request $request, int $id): JsonResponse
    {
        if ($resposta = $this->barrar('gerar_pin', 'atualizar', $id, ['dono', 'admin'], self::SO_QUEM_GERE_PIN)) {
            return $resposta;
        }
        $gestor = $this->contexto->usuario();

        $validador = Validator::make($request->all(), ['senha_atual' => 'required|string|max:255'],
            ['senha_atual.*' => 'Informe a sua senha de login para gerar o PIN.']);
        if ($validador->fails()) {
            return $this->erro(422, 'AI_VALIDATION_ERROR', 'validation_error', $validador->errors()->first());
        }

        // Quem pegou a sessão aberta não gera PIN sem a senha. A tentativa conta ANTES de conferir (incremento atômico,
        // como no ReautenticacaoAdmin): rajadas em paralelo não passam do teto. Barrada ou acertada, devolve.
        // A chave mantém o nome antigo ("pin-admin"), mas o contador é por pessoa: vale para o Administrador e para o dono.
        $chave = 'pin-admin:'.$gestor->id;
        if (RateLimiter::hit($chave, self::JANELA_PIN_S) > self::LIMITE_PIN) {
            $this->devolverTentativa($chave);

            return $this->erro(429, 'AI_RATE_LIMIT', 'rate_limit', 'Muitas tentativas. Aguarde alguns minutos.');
        }

        if (! Hash::check($validador->validated()['senha_atual'], (string) $gestor->password)) {
            $this->auditar('gerar_pin', $id, [], 'negado', 'Senha de login de quem gera o PIN não confere.', false);

            return $this->erro(403, 'AI_REAUTH_FAILED', 'authorization_error', 'A senha de login não confere.');
        }
        $this->devolverTentativa($chave);

        $codigo = null;
        $resposta = $this->executar('gerar_pin', $id, [], fn () => 'PIN gerado para '.$this->nome($id).' por '.$gestor->name.'.',
            function () use ($id, $gestor, &$codigo) {
                $codigo = $this->supervisao->gerarPin($id, $gestor->id, $this->contexto->tenantId());
            });

        // Só entrega o código se a gravação e a auditoria foram confirmadas (cosmético 2 da 1ª passada).
        if ($codigo === null || $resposta->getStatusCode() !== 200) {
            return $resposta;
        }

        return response()->json(['tipo' => 'sucesso', 'mensagem' => $resposta->getData(true)['mensagem'], 'pin' => $codigo])
            ->header('Cache-Control', 'no-store');
    }

    /** POST /supervisao/pins/{id}/revogar: o Administrador ou o dono; zera o PIN (a pessoa deixa de autorizar até ganhar outro). */
    public function revogarPin(int $id): JsonResponse
    {
        if ($resposta = $this->barrar('revogar_pin', 'atualizar', $id, ['dono', 'admin'], self::SO_QUEM_GERE_PIN)) {
            return $resposta;
        }
        $gestor = $this->contexto->usuario();

        return $this->executar('revogar_pin', $id, [], fn () => 'PIN revogado para '.$this->nome($id).' por '.$gestor->name.'.',
            fn () => $this->supervisao->revogarPin($id, $gestor->id, $this->contexto->tenantId()));
    }

    /**
     * POST /supervisao/usuarios/{id}/ativo {ativo: true|false}: liga ou desliga a conta de um funcionário. Função
     * administrativa (26/11): só o Administrador/RH; o dono dá a ordem. Auditado em todo desfecho.
     */
    public function definirAtivo(Request $request, int $id): JsonResponse
    {
        if ($resposta = $this->barrar('alterar_ativo_usuario', 'atualizar', $id, ['admin'], 'Só o Administrador liga ou desliga contas.')) {
            return $resposta;
        }

        $validador = Validator::make($request->all(), ['ativo' => 'required|boolean'],
            ['ativo.*' => 'Informe se a conta fica ativa (true) ou inativa (false).']);
        if ($validador->fails()) {
            return $this->erro(422, 'AI_VALIDATION_ERROR', 'validation_error', $validador->errors()->first());
        }
        $ativo = $request->boolean('ativo');
        $gestor = $this->contexto->usuario();

        // Como no marcar (nº 6-4): a auditoria guarda o estado de ANTES (inclusive o setor e o PIN que desligar zera) e diz
        // "Sem mudança" quando a conta já estava assim (ex.: desligada em outra aba). Leitura só do tenant.
        $antes = User::query()->where('tenant_id', $this->contexto->tenantId())->whereKey($id)->first(['ativo', 'supervisor_setor', 'pin_autorizacao']);
        $anterior = $antes === null ? null : $antes->ativo !== false;
        $parametros = ['ativo' => $ativo, 'ativo_anterior' => $anterior]
            + ($antes === null ? [] : ['setor_anterior' => $antes->supervisor_setor, 'tinha_pin' => $antes->pin_autorizacao !== null]);
        $semMudanca = $anterior === $ativo;

        return $this->executar('alterar_ativo_usuario', $id, $parametros,
            fn () => $semMudanca ? 'Sem mudança: '.$this->nome($id).' já estava '.($ativo ? 'ativa.' : 'inativa.')
                : $this->nome($id).($ativo ? ' foi ativada.' : ' foi desativada. A pessoa perde o acesso agora.'),
            fn () => $this->supervisao->definirAtivo($id, $ativo, $gestor->id, $this->contexto->tenantId()));
    }

    /** Nome de um usuário do tenant, para a frase da auditoria (fora do tenant: só o número). */
    private function nome(int $id): string
    {
        return (string) (User::query()->where('tenant_id', $this->contexto->tenantId())->whereKey($id)->value('name') ?? '#'.$id);
    }

    private function devolverTentativa(string $chave): void
    {
        if (RateLimiter::attempts($chave) > 0) {
            RateLimiter::decrement($chave, self::JANELA_PIN_S);
        }
    }

    /**
     * Só na clínica (fora dela, 404) e só para quem a rota admite: o dono ('dono') e/ou o Administrador ('admin');
     * qualquer outro papel recebe 403, auditado.
     *
     * @param  string[]  $quem
     */
    private function barrar(string $tool, string $acao, ?int $id, array $quem, ?string $recusa = null): ?JsonResponse
    {
        if ($this->contexto->dominio() !== 'clinica') {
            return $this->erro(404, 'AI_NOT_FOUND', 'not_found', 'Recurso não encontrado.');
        }

        $pode = (in_array('dono', $quem, true) && $this->contexto->ehDono())
            || (in_array('admin', $quem, true) && $this->contexto->ehAdminDaClinica());

        if (! $pode) {
            $mensagem = $recusa ?? match (true) {
                $quem === ['admin'] => 'Só o Administrador gera e revoga PIN.',
                $quem === ['dono'] => 'Só o dono marca supervisores.',
                default => 'Só o dono e o Administrador gerenciam a supervisão.',
            };
            $this->auditar($tool, $id, [], 'negado', $mensagem, false, $acao);

            return $this->erro(403, 'AI_FORBIDDEN', 'authorization_error', $mensagem);
        }

        return null;
    }

    /** @param  string|\Closure(): string  $sucesso  a frase do sucesso (calculada depois da ação, dentro da transação) */
    private function executar(string $tool, int $id, array $parametros, string|\Closure $sucesso, callable $acao): JsonResponse
    {
        try {
            $mensagem = DB::transaction(function () use ($tool, $id, $parametros, $sucesso, $acao) {
                $acao();
                $mensagem = is_string($sucesso) ? $sucesso : $sucesso();
                $this->auditar($tool, $id, $parametros, 'sucesso', $mensagem, true);

                return $mensagem;
            });
        } catch (RegraDeNegocioException $e) {
            $this->auditar($tool, $id, $parametros, 'recusado', $e->getMessage(), true);

            return $this->erro(422, 'AI_BUSINESS_RULE', 'business_rule', $e->getMessage());
        }

        return response()->json(['tipo' => 'sucesso', 'mensagem' => $mensagem]);
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
