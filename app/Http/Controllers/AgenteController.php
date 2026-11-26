<?php

namespace App\Http\Controllers;

use App\Services\AiAgentService;
use App\Services\AuditoriaService;
use App\Services\ConfirmacoesPendentes;
use App\Services\ConsultasExportaveis;
use App\Services\ConversaAgente;
use App\Support\Front;
use App\Services\PlanilhaCsv;
use App\Services\ReautenticacaoAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AgenteController extends Controller
{
    /** Chaves que nunca valem como valor de formulário: senha, PIN e CRMV só vão nos campos próprios do Confirmar. */
    private const CHAVES_SECRETAS = ['email_admin', 'senha_admin', 'senha', 'password', 'pin', 'crmv'];

    public function __construct(
        private AiAgentService $aiAgentService,
        private ConversaAgente $conversa,
        private ConfirmacoesPendentes $pendentes,
        private ReautenticacaoAdmin $reautenticacao,
        private ConsultasExportaveis $exportaveis,
    ) {
    }

    public function index(Request $request)
    {
        if (app(\App\Services\ContextoUsuario::class)->ehAdminDaClinica()) {
            return redirect('/supervisao');
        }

        return Front::tela('agente', 'Agente', Front::react()
            ? ['historico' => $this->historicoParaTela($this->conversa->lerParaTela($request->session()), $request->session())]
            : []);
    }

    /**
     * O histórico como a tela precisa ao recarregar (26/11, UX): texto como antes; card e tabela viram uma "tira" (título, desfecho,
     * hora; nunca dados), para o que esteve na tela não sumir como se tivesse dado erro. Só campos explícitos saem daqui: o resumo da
     * proposta e o id dela ficam no servidor. "Confirmei a proposta no card." é uma fala sintética do servidor: não aparece.
     *
     * @param  list<array<string, mixed>>  $mensagens
     * @return list<array<string, mixed>>
     */
    private function historicoParaTela(array $mensagens, \Illuminate\Contracts\Session\Session $sessao): array
    {
        $saida = [];
        foreach ($mensagens as $m) {
            if ((($m['role'] ?? null) === 'user' && ($m['content'] ?? null) === 'Confirmei a proposta no card.') || ($m['oculta'] ?? false)) {
                continue;
            }
            // 26/11: o que a tela mostrou (tabela já mascarada, card) volta como estava, dentro do prazo da conversa. O card só volta
            // com botões se a proposta ainda existe na sessão e não teve desfecho; senão fica a tira.
            $tela = $m['tela'] ?? null;
            if (is_array($tela) && is_array($tela['dados'] ?? null)) {
                if (($tela['tipo'] ?? null) === 'consulta') {
                    // O resultado de uma escrita (ex.: "Agendamento remarcado.") leva a tabela do registro: o texto vem antes dela.
                    if (! isset($m['tabela']) && is_string($m['content'] ?? null) && $m['content'] !== '') {
                        $saida[] = ['role' => 'assistant', 'content' => $m['content']];
                    }
                    $saida[] = ['role' => 'assistant', 'consulta' => $tela['dados']];

                    continue;
                }
                if (($tela['tipo'] ?? null) === 'proposta' && is_string($m['proposta'] ?? null) && ! isset($m['desfecho'])
                    && $this->pendentes->rascunho($sessao, $m['proposta']) !== null) {
                    $saida[] = ['role' => 'assistant', 'proposta' => $tela['dados']];

                    continue;
                }
            }
            $conteudo = is_string($m['content'] ?? null) ? $m['content'] : '';
            // Mensagens gravadas antes de 26/11 não têm os campos novos: reconhece pelo texto fixo que o servidor escrevia.
            if (! isset($m['titulo']) && ! isset($m['tabela']) && ($m['role'] ?? null) === 'assistant') {
                if (preg_match('/^Mostrei ao usuário uma tabela com (\d+) linhas? \(/u', $conteudo, $t) === 1) {
                    $saida[] = ['role' => 'assistant', 'tira' => ['tipo' => 'consulta', 'linhas' => (int) $t[1]]];

                    continue;
                }
                if (preg_match('/^\[Proposta: (.+?)(?: \(.*\))? — (.*)\]$/su', $conteudo, $p) === 1) {
                    $fim = mb_strtolower($p[2]);
                    $desfecho = match (true) {
                        str_starts_with($fim, 'confirmada') => 'confirmada',
                        str_starts_with($fim, 'cancelada') => 'cancelada',
                        str_starts_with($fim, 'substituída') => 'substituida',
                        str_starts_with($fim, 'expirou') => 'expirada',
                        str_starts_with($fim, 'não foi executada') => 'falhou',
                        default => 'pendente',
                    };
                    $saida[] = ['role' => 'assistant', 'tira' => ['tipo' => 'proposta', 'titulo' => mb_substr($p[1], 0, 120), 'desfecho' => $desfecho, 'hora' => null]];

                    continue;
                }
            }
            if (isset($m['titulo']) && is_string($m['titulo'])) {
                $saida[] = ['role' => 'assistant', 'tira' => ['tipo' => 'proposta', 'titulo' => $m['titulo'],
                    'desfecho' => is_string($m['desfecho'] ?? null) ? $m['desfecho'] : 'pendente', 'hora' => is_string($m['hora'] ?? null) ? $m['hora'] : null]];
            } elseif (isset($m['tabela']) && is_int($m['tabela'])) {
                $saida[] = ['role' => 'assistant', 'tira' => ['tipo' => 'consulta', 'linhas' => $m['tabela']]];
            } else {
                $saida[] = ['role' => $m['role'] ?? null, 'content' => $m['content'] ?? null];
            }
        }

        return $saida;
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
            $resultado = $this->aiAgentService->exportar($consulta['tool'], $consulta['argumentos'], ($consulta['origem'] ?? null) === 'manual' ? 'manual' : 'agente');
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

        // T1 (resposta F): o Administrador da clínica só gerencia PINs; nenhum pedido dele vai à IA (nem gasta chamada).
        if (app(\App\Services\ContextoUsuario::class)->ehAdminDaClinica()) {
            return response()->json(['tipo' => 'negado', 'categoria' => 'authorization_error', 'codigo' => 'AI_FORBIDDEN', 'http_status' => 403,
                'mensagem' => 'O Administrador só gera e revoga os PINs, na tela Supervisão.'], 403);
        }

        // Ciclo de vida (23/11): uma pendência nunca sobrevive a um novo pedido. A anterior é descartada, auditada como
        // cancelada e marcada no histórico (fala do servidor) ANTES de a IA ver a conversa.
        $this->descartarPendencias($request);

        try {
            $resposta = $this->aiAgentService->processar($mensagem, $this->conversa->ler($request->session()), $this->conversa->lerLista($request->session()));
        } catch (\Illuminate\Auth\AuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $resposta = $this->aiAgentService->erroTecnicoTool($e);
        }
        $this->conversa->guardar($request->session(), $mensagem, $resposta);

        // P-C1: a tabela mostrada fica congelada na sessão (ids em ordem); o front não recebe esta chave.
        if (isset($resposta['lista'])) {
            $this->conversa->guardarLista($request->session(), $resposta['lista']);
            unset($resposta['lista']);
        }

        // A3: a consulta (tool + argumentos) fica na sessão; o front só recebe o id para o Exportar.
        if (isset($resposta['consulta'])) {
            $resposta['exportar'] = $this->exportaveis->guardar($request->session(), $resposta['tool'], $resposta['consulta']);
            unset($resposta['consulta']);
        }

        if (($resposta['tipo'] ?? null) === 'confirmacao_pendente') {
            // A sessão guarda a proposta crua; o front só vê CPF/salário/documento mascarados (LGPD).
            $resposta['proposta'] = $this->pendentes->guardarRascunho($request->session(), $resposta['tool'], $resposta['argumentos'], $resposta['propostos'] ?? null,
                tempoIaMs: $resposta['tempo_ia_ms'] ?? null, loteIds: $resposta['lote_ids'] ?? null);
            $this->conversa->vincularProposta($request->session(), $resposta['proposta']);
            // 25/11: quanto tempo a proposta vale (s); o front só cria o contador se vier número (contrato para o React também).
            $resposta['prazo_s'] = ConfirmacoesPendentes::prazoS('agente');
            $resposta['argumentos'] = AuditoriaService::mascararCamposSensiveis($resposta['argumentos']);
            unset($resposta['propostos'], $resposta['tempo_ia_ms'], $resposta['lote_ids']);
        }

        // UX (26/11): o que a tela vai mostrar (tabela mascarada ou card) fica guardado na conversa, por 2 h, para recarregar não apagar o trabalho.
        $this->conversa->guardarTela($request->session(), $resposta);

        return response()->json($resposta, $resposta['http_status'] ?? match ($resposta['tipo'] ?? null) {
            'negado' => 403,
            'erro' => 422,
            default => 200,
        });
    }

    /**
     * "Nova conversa" (26/11): descarta as propostas abertas (auditadas como canceladas, como num novo pedido) e apaga o que a conversa
     * guardou (falas, tabelas, cards, lista de posições). Não grava nada de negócio.
     */
    public function nova(Request $request)
    {
        $this->descartarPendencias($request);
        $this->conversa->esquecer($request->session());

        return response()->json(['tipo' => 'ok']);
    }

    /**
     * Descarta as propostas ainda abertas (rascunho ou token) quando chega um novo pedido: auditadas como "cancelado"
     * (motivo: substituída por novo pedido) e marcadas no histórico da conversa. O card antigo que ainda estiver na tela
     * recebe "Confirmação inválida ou já utilizada" se alguém clicar. A que expirou já sai como "expirado".
     */
    private function descartarPendencias(Request $request): void
    {
        foreach ($this->pendentes->retirarTodas($request->session()) as $pendente) {
            $expirou = $this->pendentes->expirou($pendente);
            $this->aiAgentService->auditarDesfecho($pendente['tool'], ConfirmacoesPendentes::propostos($pendente), $expirou ? 'expirado' : 'cancelado',
                $expirou ? 'A confirmação expirou sem resposta. Nada foi gravado.' : 'Substituída por novo pedido. Nada foi gravado.',
                ConfirmacoesPendentes::origem($pendente), entidadeId: ConfirmacoesPendentes::entidadeId($pendente), tempoIaMs: ConfirmacoesPendentes::tempoIa($pendente));
            if (isset($pendente['proposta_id'])) {
                $this->conversa->marcarProposta($request->session(), $pendente['proposta_id'], $expirou ? 'expirada' : 'substituida');
            }
        }
    }

    /**
     * Bloco 2b: o humano revisa o formulário e o servidor valida sem gravar (RBAC,
     * tenant e as regras da tool). Com erro, o formulário volta com a frase de cada
     * campo, sem token, e a proposta segue em rascunho. Sem erro, os valores
     * revisados ficam na sessão e sai o token de confirmação (uso único, sem
     * prazo desde 25/11), e o /confirmar com {token} só usa estes valores (no passo único, o Confirmar revalida os do card). "segurar" diz à
     * tela se o Confirmar pede o gesto de 2 s (ações destrutivas; decisão B, 13/11).
     *
     * Contrato: { "proposta": "<uuid do /agente/comando>", "valores": { campo: valor } }.
     */
    public function revisar(Request $request)
    {
        $lido = $this->lerRascunho($request);

        if ($lido instanceof JsonResponse) {
            return $lido;
        }

        ['proposta' => $proposta, 'rascunho' => $rascunho, 'valores' => $valores] = $lido;
        $tool = $rascunho['tool'];

        // Prévia (23/11): avisos e plano para o card, sem token, sem auditoria, sem gravar. Ramo à parte, que nem chama o
        // service->revisar (é lá que mora a auditoria); ver previa().
        if ($request->boolean('previa')) {
            return $this->previa($proposta, $rascunho, $valores);
        }

        $preparo = $this->prepararRevisao($tool, $rascunho, $valores);

        if ($preparo instanceof JsonResponse) {
            return $preparo;
        }

        if ($resposta = $this->respostaDaRecusa($request, $proposta, $rascunho, $preparo)) {
            return $resposta;
        }

        $revisados = $preparo['revisados'];

        // T2: no estorno por itens, o card mostra de novo o que vai ser estornado, com os valores REVISADOS (o humano pode
        // ter mudado o modo ou as quantidades), antes do segurar.
        // Só nas tools do estorno: nas outras, o resumo pode rodar uma prévia (transação desfeita) sem necessidade.
        $refeito = $this->aiAgentService->tipoReautenticacao($tool) === 'estorno' || $this->aiAgentService->avisaNoRevisar($tool)
            ? $this->aiAgentService->formulario($tool, $revisados, [], ConfirmacoesPendentes::loteIds($rascunho)) : null;

        return response()->json([
            'tipo' => 'revisada',
            'proposta' => $proposta,
            'token' => $this->pendentes->emitirToken($request->session(), $proposta, $rascunho, $revisados),
            'risco' => $this->aiAgentService->risco($tool),
            'segurar' => $this->aiAgentService->exigeSegurar($tool, $revisados),
            'segurar_quando' => $this->aiAgentService->condicaoSegurar($tool),
            // Agendar e remarcar: o aviso "já há N agendamentos neste horário" vale para o horário REVISADO, antes do confirmar.
            ...($refeito !== null && (isset($refeito['itens_estorno']) || isset($refeito['avisos']) || $this->aiAgentService->avisaNoRevisar($tool)) ? ['formulario' => $refeito] : []),
        ]);
    }

    /**
     * Corpo comum do Revisar e do Confirmar em um passo: confere a proposta e acha o rascunho vivo (no prazo).
     *
     * @return JsonResponse|array{proposta: string, rascunho: array, valores: array}
     */
    private function lerRascunho(Request $request): JsonResponse|array
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

        // R2 (24/11): segredo nunca entra em "valores" (não vai a 422, rascunho nem auditoria); a mesma lista vale para Revisar, prévia e Confirmar.
        return ['proposta' => $proposta, 'rascunho' => $rascunho, 'valores' => Arr::except($valores, self::CHAVES_SECRETAS)];
    }

    /**
     * Miolo comum do Revisar e do Confirmar em um passo: aplica os valores do card, confere (RBAC, tenant, Form Request,
     * auditando revisao_com_erro / negado) e, sem recusa, congela o plano do estorno com ESTES valores. Não mexe na sessão.
     *
     * @return JsonResponse|array{revisados: array, recusa: ?array, formulario: ?array}
     */
    private function prepararRevisao(string $tool, array $rascunho, array $valores): JsonResponse|array
    {
        // T2: as linhas do estorno só com item_id e quantidade (nada a mais do navegador vai para a sessão ou a auditoria).
        $revisados = $this->aiAgentService->limparLinhasDoEstorno($tool, $this->aiAgentService->aplicarValores($tool, $rascunho['argumentos'], $valores));

        try {
            $recusa = $this->aiAgentService->revisar($tool, $revisados, ConfirmacoesPendentes::propostos($rascunho), ConfirmacoesPendentes::origem($rascunho),
                ConfirmacoesPendentes::tempoIa($rascunho), ConfirmacoesPendentes::loteIds($rascunho));
            $formulario = $recusa === null ? null : $this->aiAgentService->formulario($tool, $revisados, $recusa['erros'] ?? [], ConfirmacoesPendentes::loteIds($rascunho));
            // T2: o plano do estorno que o card vai mostrar fica congelado em linhas explícitas (o Confirmar não recalcula "tudo").
            if ($recusa === null) {
                try {
                    $revisados = $this->aiAgentService->congelarEstorno($tool, $revisados);
                } catch (\App\Exceptions\RegraDeNegocioException $e) {
                    // Corrida entre o conferir e o congelar (ex.: outro estorno esgotou o registro): a frase de negócio, não um 500.
                    $recusa = ['tipo' => 'erro', 'categoria' => 'business_rule', 'codigo' => 'AI_BUSINESS_RULE', 'http_status' => 422,
                        'mensagem' => $e->getMessage(), 'erros' => ['_acao' => $e->getMessage()]];
                    $formulario = $this->aiAgentService->formulario($tool, $revisados, $recusa['erros'], ConfirmacoesPendentes::loteIds($rascunho));
                }
            }
        } catch (\Illuminate\Auth\AuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Mesmo tratamento do /confirmar: log só com metadados (AI-003), resposta no contrato, nunca 500 cru.
            $erro = $this->aiAgentService->erroTecnicoTool($e);

            return response()->json($erro, $erro['http_status']);
        }

        return ['revisados' => $revisados, 'recusa' => $recusa, 'formulario' => $formulario];
    }

    /**
     * A resposta de uma revisão recusada (ou null se passou). Negado: a proposta acaba. Recusa de validação: o rascunho
     * guarda o que o humano editou, a proposta segue aberta e o formulário volta com a frase de cada campo.
     *
     * @param  array{revisados: array, recusa: ?array, formulario: ?array}  $preparo
     */
    private function respostaDaRecusa(Request $request, string $proposta, array $rascunho, array $preparo): ?JsonResponse
    {
        $recusa = $preparo['recusa'];

        if (($recusa['tipo'] ?? null) === 'negado') {
            // Sem permissão a proposta não tem mais futuro: a negação (já auditada) é o desfecho dela.
            $this->pendentes->concluir($proposta);
            $this->pendentes->encerrar($request->session(), $proposta);
            $this->conversa->marcarProposta($request->session(), $proposta, 'falhou');

            return response()->json($recusa, 403);
        }

        if ($recusa !== null) {
            // O rascunho guarda o que já foi editado: na próxima revisão, sensível em branco mantém o editado.
            $this->pendentes->atualizarRascunho($request->session(), $proposta, $rascunho, $preparo['revisados']);

            return response()->json([...$recusa, 'proposta' => $proposta, 'risco' => $this->aiAgentService->risco($rascunho['tool']),
                'segurar' => $this->aiAgentService->exigeSegurar($rascunho['tool'], $preparo['revisados']),
                'segurar_quando' => $this->aiAgentService->condicaoSegurar($rascunho['tool']),
                'formulario' => $preparo['formulario']], $recusa['http_status'] ?? 422);
        }

        return null;
    }

    /**
     * Prévia do Revisar (POST /agente/revisar?previa=1): o card refeito com os valores digitados (plano do estorno,
     * agenda ocupada, estoque negativo, campos da cirurgia), para o front mostrar antes do Confirmar. Sem emitir token,
     * sem auditar, sem tocar na sessão. Nunca lê senha, PIN nem CRMV: o corpo deles é ignorado e, por segurança,
     * até as chaves com esses nomes saem de "valores". Não chama o service->revisar (onde estão as auditorias): "sem
     * auditoria" vale por construção. O formulário() só lê; a prévia de cobrança/venda roda numa transação desfeita
     * num finally (previaDaCobranca/previa).
     */
    private function previa(string $proposta, array $rascunho, array $valores): JsonResponse
    {
        $tool = $rascunho['tool'];

        if (! $this->aiAgentService->podeExecutar($tool)) {
            // Sem permissão: só a frase, sem auditar (a negação auditada é a do Revisar/Confirmar de verdade).
            return response()->json(['tipo' => 'negado', 'categoria' => 'authorization_error', 'codigo' => 'AI_FORBIDDEN', 'http_status' => 403,
                'mensagem' => 'Seu perfil não permite esta ação.'], 403);
        }

        try {
            $revisados = $this->aiAgentService->limparLinhasDoEstorno($tool, $this->aiAgentService->aplicarValores($tool, $rascunho['argumentos'], $valores));
            $formulario = $this->aiAgentService->formulario($tool, $revisados, [], ConfirmacoesPendentes::loteIds($rascunho));
        } catch (\Illuminate\Auth\AuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $erro = $this->aiAgentService->erroTecnicoTool($e);

            return response()->json($erro, $erro['http_status']);
        }

        return response()->json([
            'tipo' => 'previa',
            'proposta' => $proposta,
            // R1 (24/11): referência do plano de estorno mostrado; o front a devolve no Confirmar (opcional) para provar que o plano não mudou.
            ...(($ref = $this->aiAgentService->referenciaDoPlano($tool, $revisados)) === null ? [] : ['plano_ref' => $ref]),
            'risco' => $this->aiAgentService->risco($tool),
            'segurar' => $this->aiAgentService->exigeSegurar($tool, $revisados),
            'segurar_quando' => $this->aiAgentService->condicaoSegurar($tool),
            'formulario' => $formulario,
        ]);
    }

    /**
     * Confirmar em um passo (23/11, decisão do Lucas, opção B): faz o Revisar e o Confirmar na mesma requisição.
     * Aplica os valores, confere, congela o estorno COM ESTES valores, confere senha/CRMV/PIN e executa. Recusa de
     * validação: 422 com o formulário (valores preservados), auditada como revisao_com_erro, e a proposta segue em
     * rascunho para tentar de novo. A proposta só é "concluída" depois da validação (o duplo clique perde aí: 409); a senha/PIN/CRMV vêm depois, e uma recusa deles encerra a proposta (como no contrato antigo).
     *
     * Contrato: { "proposta": "<uuid do /agente/comando>", "valores"?: {...}, "email_admin"?, "senha_admin"? }.
     */
    private function confirmarEmUmPasso(Request $request)
    {
        $lido = $this->lerRascunho($request);

        if ($lido instanceof JsonResponse) {
            return $lido;
        }

        ['proposta' => $proposta, 'rascunho' => $rascunho, 'valores' => $valores] = $lido;
        $preparo = $this->prepararRevisao($rascunho['tool'], $rascunho, $valores);

        if ($preparo instanceof JsonResponse) {
            return $preparo;
        }

        if ($resposta = $this->respostaDaRecusa($request, $proposta, $rascunho, $preparo)) {
            return $resposta;
        }

        // R1: com "plano_ref", o plano recalculado (com os valores recebidos) tem de ser o que a prévia mostrou. Sem ele, como antes.
        $mudou = $this->planoMudou($request, $proposta, $rascunho, $preparo['revisados']);

        return $mudou ?? $this->executarPendente($request, $proposta, ConfirmacoesPendentes::montarRevisada($proposta, $rascunho, $preparo['revisados']));
    }

    /**
     * R1: 422 AI_PLAN_CHANGED se o Confirmar trouxe "plano_ref" e o plano de estorno recalculado é outro (ex.: alguém estornou
     * uma linha depois da prévia). Auditado como revisao_com_erro; nada é gravado e a proposta continua em rascunho (o
     * rascunho não é tocado). A resposta traz o formulário com o plano NOVO e o plano_ref novo, para a tela mostrar de novo.
     * Sem plano_ref (ou tool sem plano) devolve null: nada a conferir.
     */
    private function planoMudou(Request $request, string $proposta, array $rascunho, array $revisados): ?JsonResponse
    {
        $enviada = $request->input('plano_ref');
        $tool = $rascunho['tool'];

        if ($enviada === null || $enviada === '') {
            return null;
        }

        $atual = $this->aiAgentService->referenciaDoPlano($tool, $revisados);

        if ($atual === null || (is_string($enviada) && hash_equals($atual, $enviada))) {
            return null;
        }

        $mensagem = 'O plano mudou desde a prévia. Revise de novo.';
        $this->aiAgentService->auditarDesfecho($tool, ConfirmacoesPendentes::propostos($rascunho), AuditoriaService::REVISAO_COM_ERRO, $mensagem,
            ConfirmacoesPendentes::origem($rascunho), entidadeId: ConfirmacoesPendentes::entidadeId($rascunho), tempoIaMs: ConfirmacoesPendentes::tempoIa($rascunho));

        return response()->json(['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_PLAN_CHANGED', 'http_status' => 422,
            'mensagem' => $mensagem, 'proposta' => $proposta, 'risco' => $this->aiAgentService->risco($tool),
            'segurar' => $this->aiAgentService->exigeSegurar($tool, $revisados), 'segurar_quando' => $this->aiAgentService->condicaoSegurar($tool),
            'plano_ref' => $atual,
            'formulario' => $this->aiAgentService->formulario($tool, $revisados, [], ConfirmacoesPendentes::loteIds($rascunho))], 422);
    }

    /**
     * Executa uma tool após confirmação humana no front.
     *
     * Contrato novo (23/11): { "proposta", "valores"?, "email_admin"?, "senha_admin"? } em um passo (ver confirmarEmUmPasso).
     * Contrato antigo (dívida técnica, sai depois da entrega): { "token": "<uuid recebido no /agente/revisar>" }. Nele,
     * tool e valores vêm da proposta revisada na sessão, nunca do corpo (nem "valores" reenviados pelo navegador).
     */
    public function confirmar(Request $request)
    {
        if (! $request->has('token') && $request->has('proposta')) {
            return $this->confirmarEmUmPasso($request);
        }

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

        return $this->executarPendente($request, ConfirmacoesPendentes::proposta($token, $pendente), $pendente);
    }

    /**
     * A segunda metade do Confirmar, comum aos dois contratos: dá o desfecho único da proposta (o duplo clique perde: 409),
     * confere prazo, senha de admin / PIN / CRMV e executa com os valores da pendência: no passo único, os do card já revalidados nesta requisição; no {token}, os revisados guardados na sessão.
     */
    private function executarPendente(Request $request, string $proposta, array $pendente)
    {
        if (! $this->pendentes->concluir($proposta)) {
            return response()->json(['tipo' => 'erro', 'mensagem' => 'Esta confirmação já foi usada.'], 409);
        }

        // Desfecho da proposta: o rascunho dela também sai da sessão (R3).
        $this->pendentes->encerrar($request->session(), $proposta);

        // Decisão B (13/11): não há espera mínima nem validade de 12 s; só os 5 min absolutos da proposta. O segurar
        // de 2 s é gesto visual (ações destrutivas); o que o servidor garante é consumo único, rascunho do dono e valores revalidados aqui (nunca confiados).
        if ($this->pendentes->expirou($pendente)) {
            $this->aiAgentService->auditarDesfecho($pendente['tool'], ConfirmacoesPendentes::propostos($pendente), 'expirado',
                'Confirmação recebida fora do prazo. Nada foi gravado.', ConfirmacoesPendentes::origem($pendente),
                entidadeId: ConfirmacoesPendentes::entidadeId($pendente), tempoIaMs: ConfirmacoesPendentes::tempoIa($pendente));

            $this->conversa->marcarProposta($request->session(), $proposta, 'expirada');

            return response()->json(['tipo' => 'erro', 'mensagem' => 'Confirmação fora do prazo. Refaça o comando.'], 422);
        }

        // RF10 (Bloco 4): restaurar e excluir de vez pedem a senha de um admin, digitada no card. Ela só
        // existe nesta requisição: vai direto para a conferência, nunca para a sessão, o log ou a auditoria.
        // Ordem: primeiro o que não envolve segredo (proposta revisada e no prazo, acima; papel e tenant,
        // aqui). Sem permissão, a senha nem é conferida nem gasta tentativa: a execução abaixo nega pelo
        // papel, com a mesma resposta para senha certa ou errada (o card não serve de verificador de senha).
        $autorizadoPor = null;
        $conferencia = null;

        if ($this->aiAgentService->exigeReautenticacao($pendente['tool']) && $this->aiAgentService->podeExecutar($pendente['tool'])) {
            // H, T1: o estorno é autorizado pelo PIN do supervisor ou do dono (mesmos campos do card e mesmos limites).
            $estorno = $this->aiAgentService->tipoReautenticacao($pendente['tool']) === 'estorno';
            $autorizacao = $this->reautenticar($request, $estorno ? 'estorno' : 'admin');

            if (! isset($autorizacao['admin'])) {
                $this->conversa->marcarProposta($request->session(), $proposta, 'falhou'); // a proposta já foi consumida: o card guardado sai da sessão

                return $this->reautenticacaoRecusada($pendente, $autorizacao['falha'], $estorno ? 'estorno' : 'admin');
            }

            $autorizadoPor = $autorizacao['admin'];
            $conferencia = $estorno ? 'pin' : 'senha de admin';
        } elseif ($this->aiAgentService->exigeCrmv($pendente['tool'], $pendente['argumentos']) && $this->aiAgentService->podeExecutar($pendente['tool'])) {
            // 15/11: cirurgia e internação. O próprio usuário confirma com o CRMV do perfil e a senha (mesmos campos do
            // card: "email_admin" leva o CRMV e "senha_admin" a senha). A regra do CRMV é conferida de novo no service.
            $autorizacao = $this->reautenticar($request, 'crmv');

            if (! isset($autorizacao['admin'])) {
                $this->conversa->marcarProposta($request->session(), $proposta, 'falhou');

                return $this->reautenticacaoRecusada($pendente, $autorizacao['falha'], 'crmv');
            }

            $autorizadoPor = $autorizacao['admin'];
            $conferencia = 'crmv+senha';
        }

        try {
            $resultado = $this->aiAgentService->executarConfirmada($pendente['tool'], $pendente['argumentos'], ConfirmacoesPendentes::propostos($pendente),
                ConfirmacoesPendentes::origem($pendente), $autorizadoPor, ConfirmacoesPendentes::tempoIa($pendente), ConfirmacoesPendentes::loteIds($pendente), $conferencia);
        } catch (InvalidArgumentException $e) {
            $this->conversa->marcarProposta($request->session(), $proposta, 'falhou');

            return response()->json(['tipo' => 'erro', 'categoria' => 'unsupported_operation', 'codigo' => 'AI_UNSUPPORTED_OPERATION', 'mensagem' => 'Esta operação não é suportada pelo agente.'], 422);
        } catch (\Illuminate\Auth\AuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $resultado = $this->aiAgentService->erroTecnicoTool($e);
        }

        // O agente passa a saber que a escrita aconteceu: só a frase fixa do servidor, sem valores.
        $ligada = $this->conversa->marcarProposta($request->session(), $proposta, ($resultado['tipo'] ?? null) === 'resultado_escrita' ? 'confirmada' : 'falhou');
        if (($resultado['tipo'] ?? null) === 'resultado_escrita') {
            // 26/11 (UX): depois de agendar/remarcar/trocar serviço/mudar status pelo chat, mostra o registro como ficou, igual à consulta
            // inicial. Mesma leitura (papel, tenant, máscara) de "me mostre o agendamento"; sem isso, o usuário não vê o resultado.
            $registro = $ligada ? $this->registroAtualizado($request, $pendente['tool'], $this->idGravado($resultado)) : null;
            if ($registro !== null) {
                $resultado['registro'] = $registro;
            }
            $this->conversa->registrarResultado($request->session(), $resultado['mensagem'], $this->idGravado($resultado), null, ! $ligada, $registro);
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
        // R5: só o dono enxerga a pendência (nem o "expirou" vaza a existência do token).
        $pendente = $token === null ? null : $this->pendentes->doUsuario($request->session(), $token);

        // Token já vencido: a proposta expirou. Fica para a varredura (depois desta ação) dar o "expirado";
        // o Editar não pode apagar esse desfecho nem devolver a proposta ao formulário.
        if (is_array($pendente) && $this->pendentes->expirou($pendente)) {
            return response()->json(['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_PROPOSAL_EXPIRED',
                'http_status' => 422, 'mensagem' => 'Esse pedido ficou parado por muito tempo e expirou, então não alterei nada. É só me pedir de novo.'], 422);
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
     * o front manda, com o mesmo limite das outras rotas: até 5 min depois da proposta
     * (rascunho ou token dela; decisão B, 13/11), encerrar é cancelamento;
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
        $this->conversa->marcarProposta($request->session(), $proposta, $expirou ? 'expirada' : 'cancelada');
        $this->aiAgentService->auditarDesfecho($pendente['tool'], ConfirmacoesPendentes::propostos($pendente), $desfecho, $motivo, ConfirmacoesPendentes::origem($pendente),
            entidadeId: ConfirmacoesPendentes::entidadeId($pendente), tempoIaMs: ConfirmacoesPendentes::tempoIa($pendente));

        return response()->json($expirou
            ? ['tipo' => 'expirado', 'mensagem' => 'Esse pedido ficou parado por muito tempo e expirou, então não alterei nada. É só me pedir de novo.']
            : ['tipo' => 'cancelado', 'mensagem' => 'Tudo bem, cancelei o pedido. Não alterei nada.']);
    }

    /**
     * B2 (RF05/RNF05): modo manual — lista e busca SEM chamar a IA. O servidor confere a tool
     * (só leitura do catálogo) e os filtros; RBAC, tenant e máscara são os da tool.
     *
     * Contrato: { "tool": "consultar_clientes", "filtros"?: { "nome": "Silva" } }.
     */
    public function consultar(Request $request)
    {
        $validador = Validator::make($request->all(), ['tool' => 'required|string|max:64', 'filtros' => 'nullable|array|max:5']);

        if ($validador->fails()) {
            return response()->json(['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_VALIDATION_ERROR',
                'http_status' => 422, 'mensagem' => 'Não consegui fazer essa consulta. Tente de novo.'], 422);
        }

        $dados = $validador->validated();

        try {
            $resposta = $this->aiAgentService->consultarManual($dados['tool'], $dados['filtros'] ?? []);
        } catch (\Illuminate\Auth\AuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $resposta = $this->aiAgentService->erroTecnicoTool($e);
        }

        if (isset($resposta['consulta'])) {
            $resposta['exportar'] = $this->exportaveis->guardar($request->session(), $resposta['tool'], $resposta['consulta'], 'manual');
            unset($resposta['consulta']);
        }

        return response()->json($resposta, $resposta['http_status'] ?? match ($resposta['tipo'] ?? null) {
            'negado' => 403,
            'erro' => 422,
            default => 200,
        });
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
        $validador = Validator::make($request->all(), ['tool' => 'required|string|max:64', 'id' => 'nullable|integer|min:1', 'tutor_id' => 'nullable|integer|min:1']);

        if ($validador->fails()) {
            return response()->json(['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_VALIDATION_ERROR',
                'http_status' => 422, 'mensagem' => 'Não consegui abrir esse formulário. Tente de novo.'], 422);
        }

        $dados = $validador->validated();

        try {
            $resposta = $this->aiAgentService->abrirFormulario($dados['tool'], isset($dados['id']) ? (int) $dados['id'] : null, isset($dados['tutor_id']) ? (int) $dados['tutor_id'] : null);
        } catch (\Illuminate\Auth\AuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $resposta = $this->aiAgentService->erroTecnicoTool($e);
        }

        if (($resposta['tipo'] ?? null) === 'confirmacao_pendente') {
            $resposta['proposta'] = $this->pendentes->guardarRascunho($request->session(), $resposta['tool'], $resposta['argumentos'],
                $resposta['propostos'] ?? [], 'formulario');
            // 25/11: card aberto por tela não vence (null): o front não cria contador (ver ConfirmacoesPendentes::prazoS).
            $resposta['prazo_s'] = ConfirmacoesPendentes::prazoS('formulario');
            // Sem prazo, há um teto de cards de tela por usuário: o mais antigo que passar dele sai, auditado como cancelado.
            foreach ($this->pendentes->retirarExcedentesDeTela($request->session()) as $excedente) {
                $this->aiAgentService->auditarDesfecho($excedente['tool'], ConfirmacoesPendentes::propostos($excedente), 'cancelado',
                    'Card de tela substituído: limite de cards abertos. Nada foi gravado.', ConfirmacoesPendentes::origem($excedente),
                    entidadeId: ConfirmacoesPendentes::entidadeId($excedente), tempoIaMs: ConfirmacoesPendentes::tempoIa($excedente));
            }
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
    private function reautenticar(Request $request, string $tipo = 'admin'): array
    {
        try {
            // H, T1: no estorno, "senha_admin" leva o PIN do supervisor ou do dono (o card é o mesmo).
            // 15/11: no CRMV, "email_admin" leva o CRMV do próprio usuário e "senha_admin" a senha dele.
            return match ($tipo) {
                'estorno' => $this->reautenticacao->autorizarEstorno($request->input('email_admin'), $request->input('senha_admin')),
                'crmv' => $this->reautenticacao->autorizarComCrmv($request->input('email_admin'), $request->input('senha_admin')),
                default => $this->reautenticacao->autorizar($request->input('email_admin'), $request->input('senha_admin')),
            };
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
    private function reautenticacaoRecusada(array $pendente, string $falha, string $tipo = 'admin')
    {
        [$codigo, $status, $motivo, $mensagem] = $tipo === 'crmv' ? match ($falha) {
            'limite' => ['AI_REAUTH_RATE_LIMIT', 429, 'Limite de tentativas de CRMV e senha atingido.',
                'Muitas tentativas com o CRMV ou a senha errados. Aguarde um minuto. Nada foi gravado.'],
            'faltando' => ['AI_REAUTH_REQUIRED', 403, 'Sem CRMV e senha para cirurgia.',
                'Informe o seu CRMV e a sua senha. Nada foi gravado.'],
            default => ['AI_REAUTH_FAILED', 403, 'CRMV e senha não conferiram.',
                'CRMV ou senha inválidos. Nada foi gravado.'],
        } : ($tipo === 'estorno' ? match ($falha) {
            'limite' => ['AI_REAUTH_RATE_LIMIT', 429, 'Limite de tentativas de autorização do estorno atingido.',
                'Muitas tentativas com o PIN errado. Aguarde alguns minutos. Nada foi gravado.'],
            'faltando' => ['AI_REAUTH_REQUIRED', 403, 'Sem e-mail e PIN de quem autoriza o estorno.',
                'Informe o e-mail e o PIN do supervisor do setor ou do dono. Nada foi gravado.'],
            default => ['AI_REAUTH_FAILED', 403, 'Autorização do estorno não conferiu.',
                'E-mail ou PIN de quem autoriza inválidos. Nada foi gravado.'],
        } : match ($falha) {
            'limite' => ['AI_REAUTH_RATE_LIMIT', 429, 'Limite de tentativas de senha de administrador atingido.',
                'Muitas tentativas com a senha errada. Aguarde alguns minutos. Nada foi gravado.'],
            'faltando' => ['AI_REAUTH_REQUIRED', 403, 'Sem e-mail e senha de administrador.',
                'Informe o e-mail e a senha de um administrador. Nada foi gravado.'],
            default => ['AI_REAUTH_FAILED', 403, 'Senha de administrador não conferiu.',
                'E-mail ou senha de administrador inválidos. Nada foi gravado.'],
        });

        $this->aiAgentService->auditarDesfecho($pendente['tool'], ConfirmacoesPendentes::propostos($pendente), 'negado', $motivo,
            ConfirmacoesPendentes::origem($pendente), permitido: false,
            // Qual registro era o alvo (só o id; o card aberto sem IA não tem "proposto").
            entidadeId: ConfirmacoesPendentes::entidadeId($pendente), tempoIaMs: ConfirmacoesPendentes::tempoIa($pendente));

        return response()->json(['tipo' => 'erro', 'categoria' => $status === 429 ? 'rate_limit' : 'authorization_error',
            'codigo' => $codigo, 'http_status' => $status, 'mensagem' => $mensagem], $status);
    }

    private function propostaIndisponivel()
    {
        return response()->json(['tipo' => 'erro', 'categoria' => 'validation_error', 'codigo' => 'AI_PROPOSAL_UNAVAILABLE',
            'http_status' => 422, 'mensagem' => 'Esta proposta não está mais disponível. Nada foi gravado. Peça de novo.'], 422);
    }

    /**
     * Qual consulta mostra o registro que cada escrita acabou de gravar (excluir não tem: o registro foi para a lixeira).
     * Regra: o papel de cada escrita da lista precisa estar contido no papel da consulta correspondente; senão a leitura seria
     * negada e deixaria uma linha "negado" na auditoria sem o usuário ter pedido nada.
     */
    private const CONSULTA_DO_REGISTRO = [
        'agendar_atendimento' => 'consultar_agenda', 'remarcar_agendamento' => 'consultar_agenda', 'alterar_servico_agendamento' => 'consultar_agenda',
        'alterar_status_agendamento' => 'consultar_agenda', 'registrar_encaixe_emergencia' => 'consultar_agenda',
        'criar_tutor' => 'consultar_tutores', 'atualizar_tutor' => 'consultar_tutores', 'cadastrar_cliente_com_pets' => 'consultar_tutores',
        'criar_pet' => 'consultar_pets', 'atualizar_pet' => 'consultar_pets', 'arquivar_pet' => 'consultar_pets', 'reativar_pet' => 'consultar_pets',
        'criar_item_catalogo' => 'consultar_catalogo', 'atualizar_item_catalogo' => 'consultar_catalogo',
        'cobrar_atendimento' => 'consultar_cobrancas', 'receber_cobranca' => 'consultar_cobrancas', 'cancelar_cobranca' => 'consultar_cobrancas', 'estornar_cobranca' => 'consultar_cobrancas',
        'registrar_venda' => 'consultar_vendas', 'estornar_venda' => 'consultar_vendas',
    ];

    /** O registro que acabou de ser gravado, como a tabela de consulta o mostra (com Exportar). Falha ou ação sem consulta: nada. */
    private function registroAtualizado(Request $request, string $tool, ?int $id): ?array
    {
        $consulta = self::CONSULTA_DO_REGISTRO[$tool] ?? null;
        if ($id === null || $consulta === null) {
            return null;
        }
        try {
            // Origem 'agente': a leitura vem de uma ação do chat, não de uma consulta manual do usuário (RF06/RF07).
            $resposta = $this->aiAgentService->consultarManual($consulta, ['id' => $id], 'agente');
            if (($resposta['tipo'] ?? null) !== 'resultado_leitura' || (array) ($resposta['resultado'] ?? []) === []) {
                return null;
            }
            if (isset($resposta['consulta'])) {
                $resposta['exportar'] = $this->exportaveis->guardar($request->session(), $resposta['tool'], $resposta['consulta'], 'agente');
                unset($resposta['consulta']);
            }
        } catch (\Illuminate\Auth\AuthenticationException $e) {
            throw $e;
        } catch (\Throwable) {
            return null; // a escrita já aconteceu: ler de volta nunca derruba o Confirmar
        }

        return $resposta;
    }

    /** O id do registro que a escrita confirmada gravou (para o histórico da clínica; ver ConversaAgente::registrarResultado). */
    private function idGravado(array $resultado): ?int
    {
        $id = $resultado['resultado']['id'] ?? null;

        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;
    }
}
