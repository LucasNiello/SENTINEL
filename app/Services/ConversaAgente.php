<?php

namespace App\Services;

use Illuminate\Contracts\Session\Session;

/** Contexto textual limitado; propostas nunca são registradas como ações executadas. */
class ConversaAgente
{
    private const CHAVE = 'agente_conversa';
    private const MAX_MENSAGENS = 16; // contexto limitado, sem marcadores internos enviados à IA
    private const MAX_CARACTERES = 8000;
    private const AVISO_PROPOSTA = 'Proposta pendente de confirmação humana. Nenhuma escrita foi executada.';
    private const CHAVE_LISTA = 'agente_ultima_lista'; // P-C1
    private const PRAZO_LISTA_S = 1800;
    // 26/11 (UX): a conversa na tela (tabelas e cards junto das mensagens) vale 2 h desde a última atividade; depois a próxima
    // requisição começa uma conversa nova. Logout e "Nova conversa" também apagam. O limite de mensagens é o mesmo de sempre.
    private const PRAZO_CONVERSA_S = 7200;
    private const MAX_LINHAS_TELA = 200;
    private const MAX_BYTES_TELA = 600000;

    public function __construct(private ContextoUsuario $contexto) {}

    private function identidade(): string
    {
        return $this->contexto->usuario()->getKey().':'.$this->contexto->tenantId();
    }

    /** Todas as falas guardadas, inclusive as internas (que só a tela usa). Conversa de outro usuário ou passada de 2 h: começa do zero. */
    private function lerTudo(Session $sessao): array
    {
        $dados = $sessao->get(self::CHAVE, []);
        if (($dados['identidade'] ?? null) !== $this->identidade()) {
            $sessao->forget(self::CHAVE);
            return [];
        }
        if (now()->getTimestamp() - (int) ($dados['em'] ?? now()->getTimestamp()) > self::PRAZO_CONVERSA_S) {
            $this->esquecer($sessao);
            return [];
        }

        return array_values((array) ($dados['mensagens'] ?? []));
    }

    /** Falas com o que a tela mostra (tabela, card); a IA nunca recebe isto, só o texto (ler). */
    public function lerParaTela(Session $sessao): array
    {
        return $this->lerTudo($sessao);
    }

    /** "Nova conversa" e fim do prazo: some o que a tela guarda e a lista de posições. As propostas abertas são descartadas por quem chama. */
    public function esquecer(Session $sessao): void
    {
        $sessao->forget(self::CHAVE);
        $sessao->forget(self::CHAVE_LISTA);
    }

    private function gravar(Session $sessao, array $mensagens): void
    {
        $sessao->put(self::CHAVE, ['identidade' => $this->identidade(), 'mensagens' => array_values($mensagens), 'em' => now()->getTimestamp()]);
    }

    public function ler(Session $sessao): array
    {
        $todas = $this->lerTudo($sessao);
        // É um marcador da interface, não uma resposta da IA. Reenviá-lo como
        // fala do assistente induz o modelo a copiá-lo no próximo cadastro,
        // sem chamar uma tool e, portanto, sem produzir um formulário.
        // Preservar o pedido do usuário mantém o contexto de complementação.
        $internosLegados = [
            self::AVISO_PROPOSTA,
            'A consulta foi concluída. Os dados não são guardados no histórico.',
            'Não há lançamentos pendentes nesse período. Nada a conciliar.',
        ];
        // A IA recebe só o texto: o que a tela guardou (tabela, card) nunca vai junto.
        $todas = array_map(fn ($m) => array_diff_key($m, ['tela' => 1]), $todas);

        return array_values(array_filter($todas, fn ($m) =>
            ! ($m['interno'] ?? false)
            && ! (($m['role'] ?? null) === 'assistant' && in_array($m['content'] ?? null, $internosLegados, true))));
    }

    public function guardar(Session $sessao, string $mensagem, array $resposta): void
    {
        // P-C1: a tabela vira uma frase do servidor (quantas linhas e como se referir a uma), sem dados; ela entra no
        // histórico (não é interna) para a IA entender "o segundo". A tabela em si fica só na sessão (guardarLista).
        $frase = is_string($resposta['lista']['frase'] ?? null) && ($resposta['tipo'] ?? null) === 'resultado_leitura' ? $resposta['lista']['frase'] : null;
        $texto = match ($resposta['tipo'] ?? null) {
            'texto' => $resposta['mensagem'] ?? '',
            // 23/11: a proposta fecha o turno com uma fala do SERVIDOR (nunca da IA), para o histórico seguir alternando
            // usuário/assistente e a IA poder corrigir a proposta pelo chat. O estado dela muda depois (marcarProposta).
            'confirmacao_pendente' => $this->fraseDaProposta($resposta, 'ainda não confirmada'),
            'resultado_leitura' => $frase ?? 'A consulta foi concluída. Os dados não são guardados no histórico.',
            default => null,
        };
        if ($texto === null) return;
        $tipo = $resposta['tipo'] ?? null;
        $interno = $tipo !== 'confirmacao_pendente' && $frase === null && (isset($resposta['tool']) || $tipo === 'resultado_leitura');
        // 26/11 (UX): o título e a hora do card, e o tamanho da tabela, ficam no histórico só para a tela refazer a "tira" depois de
        // recarregar (o card finalizado não some sem deixar rastro). Nenhum dado da consulta: só o título do card e a contagem.
        $extra = match (true) {
            $tipo === 'confirmacao_pendente' => ['resumo' => $this->resumoDaProposta($resposta),
                'titulo' => mb_substr(is_string($resposta['formulario']['titulo'] ?? null) ? $resposta['formulario']['titulo'] : 'Ação proposta', 0, 120),
                'hora' => now()->format('H:i')],
            $frase !== null => ['tabela' => count((array) ($resposta['resultado'] ?? []))],
            default => [],
        };
        $this->acrescentar($sessao, $mensagem, $texto, $interno, $extra);
    }

    /**
     * P-C1: congela na sessão a última tabela mostrada (entidade e ids em ordem), com a identidade (usuário:tenant) e
     * a hora. Uma nova consulta substitui a anterior, inclusive uma vazia.
     *
     * @param  array{entidade: ?string, linhas: array<int, ?int>}  $lista
     */
    public function guardarLista(Session $sessao, array $lista): void
    {
        $sessao->put(self::CHAVE_LISTA, [
            'identidade' => $this->identidade(),
            'em' => now()->getTimestamp(),
            'entidade' => $lista['entidade'] ?? null,
            'linhas' => array_values($lista['linhas'] ?? []),
            // 25/11: tabela com checkboxes (cancelar agendamentos em lote): só dela saem os ids que o endpoint aceita.
            'selecionavel' => $lista['selecionavel'] ?? null,
            // A "versão" (updated_at) de cada linha NO MOMENTO em que o usuário a viu: a gravação recusa o que mudou depois.
            'versoes' => $lista['versoes'] ?? [],
        ]);
    }

    /**
     * P-C1: a última tabela, se for deste usuário neste tenant e de até PRAZO_LISTA_S atrás; senão, some.
     * 26/11 (Lucas, opção b): a lista com CHECKBOX não vence PARA O ENVIO do lote ($paraEnvioDoLote = true, só o
     * AgendaLoteController). Vale enquanto for a última tabela mostrada e só para um envio (esquecerLista). O que protege a
     * escrita sem rascunho nem token: só ids desta lista, a versão de cada linha guardada aqui (o que mudou depois é recusado),
     * tudo ou nada no servidor e o segurar 2 s na tela. Para as referências por posição ("o segundo") ela continua valendo só
     * PRAZO_LISTA_S, como qualquer tabela (padrão).
     */
    public function lerLista(Session $sessao, bool $paraEnvioDoLote = false): ?array
    {
        $lista = $sessao->get(self::CHAVE_LISTA);
        $selecionavel = is_array($lista) ? ($lista['selecionavel'] ?? null) : null;
        if (! is_array($lista) || ($lista['identidade'] ?? null) !== $this->identidade()) {
            $sessao->forget(self::CHAVE_LISTA);

            return null;
        }

        $antiga = now()->getTimestamp() - (int) ($lista['em'] ?? 0) > self::PRAZO_LISTA_S;
        if ($antiga && $selecionavel !== null && ! $paraEnvioDoLote) {
            // Lista do lote com mais de 30 min: não serve para posição ("o segundo"), mas NÃO é apagada, senão qualquer mensagem
            // no chat desfaria a decisão de 26/11 (a lista continua marcável na tela até ser substituída ou enviada).
            return null;
        }
        if ($antiga && $selecionavel === null) {
            $sessao->forget(self::CHAVE_LISTA);

            return null;
        }

        return ['entidade' => $lista['entidade'] ?? null, 'linhas' => $lista['linhas'] ?? [], 'selecionavel' => $selecionavel, 'versoes' => $lista['versoes'] ?? []];
    }

    /** Uma tabela com checkboxes só vale para UM envio: depois de gravar, some (reenviar o mesmo pedido não cancela de novo). */
    public function esquecerLista(Session $sessao): void
    {
        $sessao->forget(self::CHAVE_LISTA);
    }

    /**
     * Escrita confirmada no card: o agente passa a saber que ela aconteceu. Entra como
     * a confirmação do humano e a frase fixa do servidor, sem valores do registro.
     *
     * Bateria da clínica (15/11): na clínica, a frase leva também o NÚMERO do registro gravado (só o id interno, nenhum
     * valor), para "esse mesmo" depois de gravar ter a que se referir. Na contabilidade, nada muda.
     */
    public function registrarResultado(Session $sessao, string $frase, ?int $idGravado = null, ?string $tituloDaTira = null, bool $ocultaNaTela = false, ?array $registro = null): void
    {
        if ($idGravado !== null && $idGravado > 0 && $this->contexto->dominio() === 'clinica') {
            $frase = rtrim($frase).' Número do registro gravado: '.$idGravado.'.';
        }
        // UX (26/11): ações sem card de proposta (o lote) deixam a mesma "tira" que um card finalizado deixa depois de recarregar.
        $extra = $tituloDaTira === null ? [] : ['titulo' => mb_substr($tituloDaTira, 0, 120), 'desfecho' => 'confirmada', 'hora' => now()->format('H:i')];
        // O registro gravado, como a tabela o mostrou (já mascarado): volta junto da mensagem ao recarregar, dentro do prazo da conversa.
        if ($registro !== null) {
            $registro['resultado'] = array_slice(array_values((array) ($registro['resultado'] ?? [])), 0, self::MAX_LINHAS_TELA);
            $extra['tela'] = ['tipo' => 'consulta', 'dados' => $registro];
        }
        // Card aberto fora do chat (Cadastros, Agenda): a IA continua sabendo, mas a tela não mostra uma fala solta, sem pedido nem tira antes dela.
        if ($ocultaNaTela) {
            $extra['oculta'] = true;
        }
        $this->acrescentar($sessao, 'Confirmei a proposta no card.', $frase, false, $extra);
    }

    /** Como cada desfecho de uma proposta aparece no histórico (frase do servidor, sem valores sensíveis). */
    private const DESFECHOS = [
        'confirmada' => 'confirmada e executada',
        'cancelada' => 'cancelada pelo usuário. Nada foi gravado',
        'substituida' => 'substituída por novo pedido. Nada foi gravado',
        'expirada' => 'expirou sem confirmação. Nada foi gravado',
        'falhou' => 'não foi executada. Nada foi gravado',
    ];

    /**
     * Liga a última fala de proposta ao id dela (o UUID só existe depois do guardarRascunho), para o desfecho
     * (confirmada, cancelada, substituída, expirada) atualizar a mesma fala.
     */
    public function vincularProposta(Session $sessao, string $proposta): void
    {
        // lerTudo confere identidade e o prazo de 2 h: marcar uma proposta de uma conversa vencida não a renova.
        $mensagens = $this->lerTudo($sessao);
        $ultima = array_key_last($mensagens);
        if ($ultima === null || ! isset($mensagens[$ultima]['resumo'])) {
            return;
        }
        $mensagens[$ultima]['proposta'] = $proposta;
        $this->gravar($sessao, $mensagens);
    }

    /**
     * 23/11: o desfecho de uma proposta (sem IA, fala do servidor): a mesma fala passa de "ainda não confirmada" para
     * "cancelada", "substituída por novo pedido", etc. O pedido original continua no histórico, então "na verdade,
     * coloca R$ 90" ainda tem a que se referir. Fala que já saiu do histórico (limite de mensagens): nada a fazer.
     */
    public function marcarProposta(Session $sessao, string $proposta, string $desfecho): bool
    {
        if (! isset(self::DESFECHOS[$desfecho])) {
            return false;
        }
        $mensagens = $this->lerTudo($sessao);
        foreach ($mensagens as $i => $m) {
            if (($m['proposta'] ?? null) === $proposta) {
                $mensagens[$i]['content'] = $this->sanitizar('[Proposta: '.($m['resumo'] ?? 'sem resumo').' — '.self::DESFECHOS[$desfecho].']');
                $mensagens[$i]['desfecho'] = $desfecho;
                $mensagens[$i]['hora'] = now()->format('H:i');
                unset($mensagens[$i]['tela']); // finalizado: o card sai e fica a tira
                $this->gravar($sessao, $mensagens);

                return true;
            }
        }

        return false;
    }

    /** "[Proposta: Cadastrar item do catálogo (nome: Banho premium; …) — ainda não confirmada]". */
    private function fraseDaProposta(array $resposta, string $situacao): string
    {
        return $this->sanitizar('[Proposta: '.$this->resumoDaProposta($resposta).' — '.$situacao.']');
    }

    /**
     * O que foi proposto, para o histórico: o título do card e os campos NÃO sensíveis (cpf, documento, telefone, salário
     * e o texto clínico ficam de fora), cada valor curto. É o que permite corrigir pelo chat ("coloca R$ 90").
     */
    private function resumoDaProposta(array $resposta): string
    {
        $titulo = is_string($resposta['formulario']['titulo'] ?? null) ? $resposta['formulario']['titulo'] : (string) ($resposta['tool'] ?? 'ação');
        $pares = [];
        foreach ((array) ($resposta['argumentos'] ?? []) as $campo => $valor) {
            if (! is_string($campo) || $campo === 'tenant_id' || in_array($campo, AuditoriaService::camposSensiveis(), true) || ! is_scalar($valor) || $valor === '') {
                continue;
            }
            $pares[] = $campo.': '.mb_substr((string) $valor, 0, 60);
        }

        // Até 600 caracteres: o fechamento "— cancelada…]" nunca fica de fora do limite de 1000 do sanitizar().
        return mb_substr($titulo.($pares === [] ? '' : ' ('.implode('; ', $pares).')'), 0, 600);
    }

    /**
     * 26/11 (UX): guarda, na fala do assistente que acabou de entrar, o que a tela mostrou (tabela já mascarada pelo servidor, ou o card
     * da proposta), para a conversa voltar inteira se a página for recarregada. Só vale por PRAZO_CONVERSA_S, e sai com o logout.
     */
    public function guardarTela(Session $sessao, array $resposta): void
    {
        $tipo = $resposta['tipo'] ?? null;
        if (! in_array($tipo, ['resultado_leitura', 'confirmacao_pendente'], true)) {
            return;
        }
        $mensagens = $this->lerTudo($sessao);
        $ultima = array_key_last($mensagens);
        if ($ultima === null || ($mensagens[$ultima]['role'] ?? null) !== 'assistant') {
            return;
        }
        if ($tipo === 'resultado_leitura') {
            $resposta['resultado'] = array_slice(array_values((array) ($resposta['resultado'] ?? [])), 0, self::MAX_LINHAS_TELA);
            $mensagens[$ultima]['tela'] = ['tipo' => 'consulta', 'dados' => $resposta];
        } else {
            $mensagens[$ultima]['tela'] = ['tipo' => 'proposta', 'dados' => $resposta];
        }
        $this->gravar($sessao, $this->aparar($mensagens));
    }

    /** Depois do envio do lote: a tabela deixa de ser selecionável e as linhas canceladas aparecem como tais ao recarregar. */
    public function encerrarSelecao(Session $sessao, array $ids): void
    {
        $mensagens = $this->lerTudo($sessao);
        foreach ($mensagens as $i => $m) {
            if (($m['tela']['tipo'] ?? null) !== 'consulta' || ! isset($m['tela']['dados']['selecao'])) {
                continue;
            }
            unset($mensagens[$i]['tela']['dados']['selecao']);
            foreach ((array) ($m['tela']['dados']['resultado'] ?? []) as $j => $linha) {
                if (is_array($linha) && in_array($linha['id'] ?? null, $ids, true)) {
                    $mensagens[$i]['tela']['dados']['resultado'][$j]['status'] = 'cancelado';
                }
            }
        }
        $this->gravar($sessao, $mensagens);
    }

    /** O que passa do teto de tamanho da tela cai do mais antigo para o mais novo; a fala de texto fica. */
    private function aparar(array $mensagens): array
    {
        $bytes = fn () => array_sum(array_map(fn ($m) => isset($m['tela']) ? strlen((string) json_encode($m['tela'])) : 0, $mensagens));
        foreach (array_keys($mensagens) as $i) {
            if ($bytes() <= self::MAX_BYTES_TELA) {
                break;
            }
            unset($mensagens[$i]['tela']);
        }

        return $mensagens;
    }

    private function acrescentar(Session $sessao, string $mensagem, string $texto, bool $interno = false, array $extra = []): void
    {
        // Guarda também as falas internas que têm o que a tela mostrar (ler() as esconde da IA).
        $historico = array_values(array_filter($this->lerTudo($sessao), fn ($m) => ! ($m['interno'] ?? false) || isset($m['tela'])));
        $historico[] = ['role' => 'user', 'content' => $this->sanitizar($mensagem)];
        $historico[] = ['role' => 'assistant', 'content' => $this->sanitizar($texto), ...$extra];
        if ($interno) $historico[array_key_last($historico)]['interno'] = true;
        $historico = array_slice($historico, -self::MAX_MENSAGENS);
        while (count($historico) > 2 && array_sum(array_map(fn ($m) => mb_strlen($m['content']), $historico)) > self::MAX_CARACTERES) {
            array_splice($historico, 0, 2);
        }
        $this->gravar($sessao, $this->aparar($historico));
    }

    private function sanitizar(string $texto): string
    {
        // Credenciais não são necessárias aos fluxos de cadastro disponíveis.
        if (preg_match('/senha|password|api.?key|app.?key|csrf|cookie|session.?id|bearer/i', $texto)) {
            return '[Mensagem com credenciais omitida do histórico]';
        }
        foreach ([config('services.anthropic.api_key'), config('app.key')] as $segredo) {
            if (is_string($segredo) && $segredo !== '') $texto = str_replace($segredo, '[credencial omitida]', $texto);
        }
        $texto = preg_replace('/\b\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}\b/', '[documento omitido]', $texto);
        $texto = preg_replace('/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/', '[documento omitido]', $texto);
        // Salário: o valor logo depois de "salário" ou "remuneração", até 30 caracteres adiante, com ou sem R$
        // (aprovado pelo Lucas em 20/10). Limites conhecidos: "ganha 4500" (sem a palavra) e o segundo número de
        // "entre 4000 e 5000" passam. "Vencimento" ficou de fora: num app financeiro é data de vencimento.
        $texto = preg_replace('/\b(sal[aá]rios?|remunera[cç](?:[aã]o|[oõ]es))\b(\D{0,30}?)(?:R\$\s*)?\d(?:[\d.,]*\d)?/iu', '$1$2[valor omitido]', $texto);
        return mb_substr($texto, 0, 1000);
    }
}
