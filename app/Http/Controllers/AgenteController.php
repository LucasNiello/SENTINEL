<?php

namespace App\Http\Controllers;

use App\Services\AiAgentService;
use App\Services\AuditoriaService;
use App\Services\ConfirmacoesPendentes;
use App\Services\ConsultasExportaveis;
use App\Services\ConversaAgente;
use App\Services\PlanilhaCsv;
use App\Services\ReautenticacaoAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AgenteController extends Controller
{
    public function __construct(
        private AiAgentService $aiAgentService,
        private ConversaAgente $conversa,
        private ConfirmacoesPendentes $pendentes,
        private ReautenticacaoAdmin $reautenticacao,
        private ConsultasExportaveis $exportaveis,
    ) {
    }

    /**
     * A3 (RF02): baixa em CSV uma tabela de consulta do agente. O servidor reexecuta a consulta
     * guardada na sessão (RBAC, tenant e máscara de agora); do corpo só vem o id dela.
     *
     * Contrato: { "consulta": "<uuid recebido na resposta da consulta, campo exportar>" }.
     */
    public function exportar(Request $request)
    {
        $validador = Validator::make($request->all(), ['consulta' => 'required|string|uuid']);
        $consulta = $validador->fails() ? null : $this->exportaveis->ler($request->session(), $validador->validated()['consulta']);

        if ($consulta === null) {
            return response()->json(['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_EXPORT_UNAVAILABLE', 'http_status' => 422,
                'mensagem' => 'Esta tabela não está mais disponível para exportar. Faça a consulta de novo.'], 422);
        }

        try {
            $resultado = $this->aiAgentService->exportar($consulta['tool'], $consulta['argumentos']);
        } catch (\Illuminate\Auth\AuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $resultado = $this->aiAgentService->erroTecnicoTool($e);
        }

        if (($resultado['tipo'] ?? null) !== 'exportacao') {
            return response()->json($resultado, $resultado['http_status'] ?? (($resultado['tipo'] ?? null) === 'negado' ? 403 : 422));
        }

        $arquivo = Str::after($consulta['tool'], 'consultar_').'-'.now()->format('Y-m-d-His').'.csv';

        return response(PlanilhaCsv::gerar($resultado['colunas'], $resultado['linhas']), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$arquivo.'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Recebe o comando em linguagem natural do usuário e delega ao agente.
     *
     * Quando o agente propõe uma escrita, a proposta fica guardada na sessão como
     * rascunho (o formulário do card, prazo de 5 min). O token que autoriza o
     * /confirmar só sai no /revisar (RNF02, Bloco 2b).
     */
    public function processar(Request $request)
    {
        // Array ou tipo errado vira 422 com frase amigável, nunca 500 (B4); o teto limita o custo por chamada (RNF10).
        $validador = Validator::make($request->all(), ['mensagem' => 'required|string|max:1000'], [
            'mensagem.required' => 'Escreva o seu pedido.',
            'mensagem.string' => 'O pedido deve ser um texto.',
            'mensagem.max' => 'O pedido aceita no máximo 1000 caracteres.',
        ]);

        if ($validador->fails()) {
            return response()->json(['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_VALIDATION_ERROR', 'mensagem' => $validador->errors()->first()], 422);
        }

        $mensagem = $validador->validated()['mensagem'];
        try {
            $resposta = $this->aiAgentService->processar($mensagem, $this->conversa->ler($request->session()));
        } catch (\Illuminate\Auth\AuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $resposta = $this->aiAgentService->erroTecnicoTool($e);
        }
        $this->conversa->guardar($request->session(), $mensagem, $resposta);

        // A3: a consulta (tool + argumentos) fica na sessão; o front só recebe o id para o Exportar.
        if (isset($resposta['consulta'])) {
            $resposta['exportar'] = $this->exportaveis->guardar($request->session(), $resposta['tool'], $resposta['consulta']);
            unset($resposta['consulta']);
        }

        if (($resposta['tipo'] ?? null) === 'confirmacao_pendente') {
            // A sessão guarda a proposta crua; o front só vê CPF/salário/documento mascarados (LGPD).
            $resposta['proposta'] = $this->pendentes->guardarRascunho($request->session(), $resposta['tool'], $resposta['argumentos'], $resposta['propostos'] ?? null);
            $resposta['argumentos'] = AuditoriaService::mascararCamposSensiveis($resposta['argumentos']);
            unset($resposta['propostos']);
        }

        return response()->json($resposta, $resposta['http_status'] ?? match ($resposta['tipo'] ?? null) {
            'negado' => 403,
            'erro' => 422,
            default => 200,
        });
    }

    /**
     * Bloco 2b: o humano revisa o formulário e o servidor valida sem gravar (RBAC,
     * tenant e as regras da tool). Com erro, o formulário volta com a frase de cada
     * campo, sem token, e a proposta segue em rascunho. Sem erro, os valores
     * revisados ficam na sessão e sai o token de segurar — daí correm os prazos de
     * sempre (2 a 12 s), e o /confirmar só usa estes valores.
     *
     * Contrato: { "proposta": "<uuid do /agente/comando>", "valores": { campo: valor } }.
     */
    public function revisar(Request $request)
    {
        $validador = Validator::make($request->all(), ['proposta' => 'required|string|uuid']);
        $valores = $request->input('valores', []);

        if (! is_array($valores)) {
            return response()->json(['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_VALIDATION_ERROR',
                'http_status' => 422, 'mensagem' => 'Os dados do formulário vieram num formato inválido. Tente de novo.'], 422);
        }

        $proposta = $validador->fails() ? null : $validador->validated()['proposta'];
        $rascunho = $proposta === null ? null : $this->pendentes->rascunho($request->session(), $proposta);

        if (! $rascunho) {
            return $this->propostaIndisponivel();
        }

        // Rascunho vencido, ou token de segurar dele já vencido (a proposta expirou): a varredura,
        // que roda depois desta ação, dá o desfecho "expirado". Revisar não ressuscita a proposta.
        if ($this->pendentes->expirou($rascunho) || $this->pendentes->temTokenVencido($request->session(), $proposta)) {
            return response()->json(['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_PROPOSAL_EXPIRED',
                'http_status' => 422, 'mensagem' => 'O prazo para revisar terminou. Nada foi gravado. Peça de novo.'], 422);
        }

        $tool = $rascunho['tool'];
        $revisados = $this->aiAgentService->aplicarValores($tool, $rascunho['argumentos'], $valores);

        try {
            $recusa = $this->aiAgentService->revisar($tool, $revisados, ConfirmacoesPendentes::propostos($rascunho), ConfirmacoesPendentes::origem($rascunho));
            $formulario = $recusa === null ? null : $this->aiAgentService->formulario($tool, $revisados, $recusa['erros'] ?? []);
        } catch (\Illuminate\Auth\AuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Mesmo tratamento do /confirmar: log só com metadados (AI-003), resposta no contrato, nunca 500 cru.
            $erro = $this->aiAgentService->erroTecnicoTool($e);

            return response()->json($erro, $erro['http_status']);
        }

        if (($recusa['tipo'] ?? null) === 'negado') {
            // Sem permissão a proposta não tem mais futuro: a negação (já auditada) é o desfecho dela.
            $this->pendentes->concluir($proposta);
            $this->pendentes->encerrar($request->session(), $proposta);

            return response()->json($recusa, 403);
        }

        if ($recusa !== null) {
            // O rascunho guarda o que já foi editado: na próxima revisão, sensível em branco mantém o editado.
            $this->pendentes->atualizarRascunho($request->session(), $proposta, $rascunho, $revisados);

            return response()->json([...$recusa, 'proposta' => $proposta, 'risco' => $this->aiAgentService->risco($tool),
                'formulario' => $formulario], $recusa['http_status'] ?? 422);
        }

        return response()->json([
            'tipo' => 'revisada',
            'proposta' => $proposta,
            'token' => $this->pendentes->emitirToken($request->session(), $proposta, $rascunho, $revisados),
            'risco' => $this->aiAgentService->risco($tool),
        ]);
    }

    /**
     * Executa uma tool após confirmação humana no front.
     *
     * Contrato: { "token": "<uuid recebido no /agente/revisar>" }. Tool e valores vêm
     * da proposta revisada na sessão, nunca do corpo (nem "valores" reenviados pelo navegador).
     */
    public function confirmar(Request $request)
    {
        // Só UUID: um token com ponto navegaria dentro da sessão (N2); array não vira 500 (B4).
        $validador = Validator::make($request->all(), ['token' => 'required|string|uuid']);

        if ($validador->fails()) {
            return response()->json(['tipo' => 'erro', 'mensagem' => 'Confirmação inválida ou já utilizada.'], 422);
        }

        $token = $validador->validated()['token'];
        $pendente = $this->pendentes->retirarRevisada($request->session(), $token); // uso único

        if (! $pendente) {
            // Proposta ainda em rascunho: confirmar sem revisar é recusado, e o rascunho continua.
            if ($this->pendentes->rascunho($request->session(), $token)) {
                return response()->json(['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_CONFIRMATION_NOT_REVIEWED',
                    'http_status' => 422, 'mensagem' => 'Revise a proposta antes de confirmar.'], 422);
            }

            return response()->json(['tipo' => 'erro', 'mensagem' => 'Confirmação inválida ou já utilizada.'], 422);
        }

        $proposta = ConfirmacoesPendentes::proposta($token, $pendente);

        if (! $this->pendentes->concluir($proposta)) {
            return response()->json(['tipo' => 'erro', 'mensagem' => 'Esta confirmação já foi usada.'], 409);
        }

        // Desfecho da proposta: o rascunho dela também sai da sessão (R3).
        $this->pendentes->encerrar($request->session(), $proposta);
        $decorrido = $this->pendentes->decorrido($pendente);

        // Fora do prazo: antes da espera mínima, ou vencido (12 s do token ou os 5 min absolutos do rascunho).
        if ($decorrido < ConfirmacoesPendentes::ESPERA_MINIMA_S || $this->pendentes->expirou($pendente)) {
            [$desfecho, $motivo] = $this->pendentes->expirou($pendente)
                ? ['expirado', 'Confirmação recebida fora do prazo. Nada foi gravado.']
                : ['recusado', 'Confirmação antes da espera mínima. Nada foi gravado.'];
            $this->aiAgentService->auditarDesfecho($pendente['tool'], ConfirmacoesPendentes::propostos($pendente), $desfecho, $motivo, ConfirmacoesPendentes::origem($pendente),
                entidadeId: ConfirmacoesPendentes::entidadeId($pendente));

            return response()->json(['tipo' => 'erro', 'mensagem' => 'Confirmação fora do prazo. Refaça o comando.'], 422);
        }

        // RF10 (Bloco 4): restaurar e excluir de vez pedem a senha de um admin, digitada no card. Ela só
        // existe nesta requisição: vai direto para a conferência, nunca para a sessão, o log ou a auditoria.
        // Ordem: primeiro o que não envolve segredo (proposta revisada e no prazo, acima; papel e tenant,
        // aqui). Sem permissão, a senha nem é conferida nem gasta tentativa: a execução abaixo nega pelo
        // papel, com a mesma resposta para senha certa ou errada (o card não serve de verificador de senha).
        $autorizadoPor = null;

        if ($this->aiAgentService->exigeReautenticacao($pendente['tool']) && $this->aiAgentService->podeExecutar($pendente['tool'])) {
            $autorizacao = $this->reautenticar($request);

            if (! isset($autorizacao['admin'])) {
                return $this->reautenticacaoRecusada($pendente, $autorizacao['falha']);
            }

            $autorizadoPor = $autorizacao['admin'];
        }

        try {
            $resultado = $this->aiAgentService->executarConfirmada($pendente['tool'], $pendente['argumentos'], ConfirmacoesPendentes::propostos($pendente),
                ConfirmacoesPendentes::origem($pendente), $autorizadoPor);
        } catch (InvalidArgumentException $e) {
            return response()->json(['tipo' => 'erro', 'categoria' => 'unsupported_operation', 'codigo' => 'AI_UNSUPPORTED_OPERATION', 'mensagem' => 'Esta operação não é suportada pelo agente.'], 422);
        } catch (\Illuminate\Auth\AuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $resultado = $this->aiAgentService->erroTecnicoTool($e);
        }

        // O agente passa a saber que a escrita aconteceu: só a frase fixa do servidor, sem valores.
        if (($resultado['tipo'] ?? null) === 'resultado_escrita') {
            $this->conversa->registrarResultado($request->session(), $resultado['mensagem']);
        }

        $status = $resultado['http_status'] ?? match ($resultado['tipo'] ?? null) {
            'erro' => 422,
            'negado' => 403,
            default => 200,
        };

        return response()->json($resultado, $status);
    }

    /**
     * "Editar" depois do Revisar: o token de segurar morre sem auditoria e o card
     * volta ao formulário. O rascunho continua, com o prazo de 5 min contado desde a proposta.
     *
     * Contrato: { "token": "<uuid recebido no /agente/revisar>" }.
     */
    public function editar(Request $request)
    {
        $validador = Validator::make($request->all(), ['token' => 'required|string|uuid']);
        $token = $validador->fails() ? null : $validador->validated()['token'];
        $pendente = $token === null ? null : $request->session()->get("confirmacoes.{$token}");

        // Token já vencido: a proposta expirou. Fica para a varredura (depois desta ação) dar o "expirado";
        // o Editar não pode apagar esse desfecho nem devolver a proposta ao formulário.
        if (is_array($pendente) && $this->pendentes->expirou($pendente)) {
            return response()->json(['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_PROPOSAL_EXPIRED',
                'http_status' => 422, 'mensagem' => 'Confirmação expirada. Nada foi gravado. Peça de novo.'], 422);
        }

        $proposta = $token === null ? null : $this->pendentes->invalidarToken($request->session(), $token);

        if ($proposta === null) {
            return response()->json(['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_CONFIRMATION_INVALID',
                'http_status' => 422, 'mensagem' => 'Confirmação inválida ou já utilizada.'], 422);
        }

        if (! $this->pendentes->rascunho($request->session(), $proposta)) {
            return $this->propostaIndisponivel();
        }

        return response()->json(['tipo' => 'rascunho', 'proposta' => $proposta]);
    }

    /**
     * Encerra uma proposta sem gravar: Cancelar no card ou fim de um prazo.
     *
     * Contrato: { "token": "<uuid de segurar>" } ou { "proposta": "<uuid do rascunho>" }.
     * Se foi "cancelado" ou "expirado", decide o servidor pelo horário, nunca pelo que
     * o front manda, com o mesmo limite das outras rotas: até 12 s depois da revisão
     * (token) ou até 5 min depois da proposta (rascunho), encerrar é cancelamento;
     * depois, é expiração. O desfecho vai para a auditoria e a proposta sai da sessão.
     */
    public function cancelar(Request $request)
    {
        $validador = Validator::make($request->all(), [
            'token' => 'required_without:proposta|nullable|string|uuid',
            'proposta' => 'required_without:token|nullable|string|uuid',
        ]);
        $chave = $validador->fails() ? null : ($validador->validated()['token'] ?? $validador->validated()['proposta'] ?? null);
        $pendente = $chave === null ? null : $this->pendentes->retirar($request->session(), $chave);

        if (! $pendente) {
            return response()->json(['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_CONFIRMATION_INVALID',
                'http_status' => 422, 'mensagem' => 'Confirmação inválida ou já utilizada.'], 422);
        }

        $proposta = ConfirmacoesPendentes::proposta($chave, $pendente);

        // Mesma marca do /confirmar: se a proposta já foi confirmada por uma requisição concorrente, não há o que cancelar.
        if (! $this->pendentes->concluir($proposta)) {
            return response()->json(['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_CONFIRMATION_USED',
                'http_status' => 409, 'mensagem' => 'Esta confirmação já foi usada.'], 409);
        }

        $this->pendentes->encerrar($request->session(), $proposta);
        $expirou = $this->pendentes->expirou($pendente);
        [$desfecho, $motivo] = $expirou
            ? ['expirado', 'A confirmação expirou sem resposta. Nada foi gravado.']
            : ['cancelado', 'Cancelado pelo usuário. Nada foi gravado.'];
        $this->aiAgentService->auditarDesfecho($pendente['tool'], ConfirmacoesPendentes::propostos($pendente), $desfecho, $motivo, ConfirmacoesPendentes::origem($pendente),
            entidadeId: ConfirmacoesPendentes::entidadeId($pendente));

        return response()->json($expirou
            ? ['tipo' => 'expirado', 'mensagem' => 'Confirmação expirada. Nada foi gravado. Peça de novo.']
            : ['tipo' => 'cancelado', 'mensagem' => 'Cancelado. Nada foi gravado.']);
    }

    /**
     * Bloco 3: abre o formulário de uma tool de escrita SEM chamar a IA ("Editar" numa
     * linha de tabela, ou um cadastro em branco). Segue o mesmo caminho da proposta pelo
     * agente: rascunho → Revisar → segurar, com o mesmo RBAC e tenant.
     *
     * Contrato: { "tool": "atualizar_cliente", "id"?: 12 }.
     */
    public function formulario(Request $request)
    {
        $validador = Validator::make($request->all(), ['tool' => 'required|string|max:64', 'id' => 'nullable|integer|min:1']);

        if ($validador->fails()) {
            return response()->json(['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_VALIDATION_ERROR',
                'http_status' => 422, 'mensagem' => 'Não consegui abrir esse formulário. Tente de novo.'], 422);
        }

        $dados = $validador->validated();

        try {
            $resposta = $this->aiAgentService->abrirFormulario($dados['tool'], isset($dados['id']) ? (int) $dados['id'] : null);
        } catch (\Illuminate\Auth\AuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $resposta = $this->aiAgentService->erroTecnicoTool($e);
        }

        if (($resposta['tipo'] ?? null) === 'confirmacao_pendente') {
            $resposta['proposta'] = $this->pendentes->guardarRascunho($request->session(), $resposta['tool'], $resposta['argumentos'],
                $resposta['propostos'] ?? [], 'formulario');
            $resposta['argumentos'] = AuditoriaService::mascararCamposSensiveis($resposta['argumentos']);
            unset($resposta['propostos']);
        }

        return response()->json($resposta, $resposta['http_status'] ?? match ($resposta['tipo'] ?? null) {
            'negado' => 403,
            'erro' => 422,
            default => 200,
        });
    }

    /**
     * Confere o e-mail e a senha do admin que vieram no corpo do /agente/confirmar. Nenhum
     * caminho vira 500 (APP_DEBUG=true no laboratório mostraria o corpo): qualquer falha é "invalido".
     *
     * @return array{admin: int}|array{falha: string}
     */
    private function reautenticar(Request $request): array
    {
        try {
            return $this->reautenticacao->autorizar($request->input('email_admin'), $request->input('senha_admin'));
        } catch (\Illuminate\Auth\AuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // AI-003: só a classe e o local, nunca a mensagem da exceção (poderia trazer o que foi digitado).
            Log::warning('AgenteController: falha na reautenticação', [
                'correlation_id' => (string) Str::uuid(),
                'excecao' => $e::class,
                'local' => basename($e->getFile()).':'.$e->getLine(),
            ]);

            return ['falha' => 'invalido'];
        }
    }

    /**
     * Senha de admin recusada (ou em falta, ou tentativas demais): a proposta termina aqui, sem
     * gravar — o desfecho dela é "negado", com quem estava logado e sem o e-mail digitado (ele
     * poderia ser a senha digitada no campo errado). Para tentar de novo, abre-se outro card.
     */
    private function reautenticacaoRecusada(array $pendente, string $falha)
    {
        [$codigo, $status, $motivo, $mensagem] = match ($falha) {
            'limite' => ['AI_REAUTH_RATE_LIMIT', 429, 'Limite de tentativas de senha de administrador atingido.',
                'Muitas tentativas com a senha errada. Aguarde alguns minutos. Nada foi gravado.'],
            'faltando' => ['AI_REAUTH_REQUIRED', 403, 'Sem e-mail e senha de administrador.',
                'Informe o e-mail e a senha de um administrador. Nada foi gravado.'],
            default => ['AI_REAUTH_FAILED', 403, 'Senha de administrador não conferiu.',
                'E-mail ou senha de administrador inválidos. Nada foi gravado.'],
        };

        $this->aiAgentService->auditarDesfecho($pendente['tool'], ConfirmacoesPendentes::propostos($pendente), 'negado', $motivo,
            ConfirmacoesPendentes::origem($pendente), permitido: false,
            // Qual registro era o alvo (só o id; o card aberto sem IA não tem "proposto").
            entidadeId: ConfirmacoesPendentes::entidadeId($pendente));

        return response()->json(['tipo' => 'erro', 'categoria' => $status === 429 ? 'rate_limit' : 'authorization_error',
            'codigo' => $codigo, 'http_status' => $status, 'mensagem' => $mensagem], $status);
    }

    private function propostaIndisponivel()
    {
        return response()->json(['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_PROPOSAL_UNAVAILABLE',
            'http_status' => 422, 'mensagem' => 'Esta proposta não está mais disponível. Nada foi gravado. Peça de novo.'], 422);
    }
}
