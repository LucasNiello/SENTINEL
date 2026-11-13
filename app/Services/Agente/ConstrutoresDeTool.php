<?php

namespace App\Services\Agente;

use App\Models\User;
use Closure;
use Illuminate\Support\Str;

/**
 * Construtores de tools do AiAgentService para os domínios novos. Cada um devolve entradas prontas do catálogo,
 * já com o que não pode ser esquecido por construção: lista de papéis, tenant e AUTOR (o usuário logado) vindos
 * do servidor, validação com as regras do Form Request, formulário do card, Revisar → segurar e auditoria
 * (o motor do AiAgentService faz o resto). Usa definirTool(), definirEscrita() e definirAtualizacao(), que
 * continuam sendo o único lugar que monta uma entrada do catálogo.
 *
 * - definirCadastro: consultar + criar + atualizar de uma entidade (a lixeira vem de lixeiras*()).
 * - definirAcao: uma operação de negócio que não é CRUD (agendar, cobrar, vender, receber, cancelar...).
 *
 * O que NUNCA vem da IA nem do navegador: tenant_id e o id do usuário que age.
 */
trait ConstrutoresDeTool
{
    /**
     * @param  string  $singular  "tutor": criar_tutor, atualizar_tutor (e a lixeira, excluir_tutor...)
     * @param  string  $plural  "tutores": consultar_tutores
     * @param  string  $rotulo  "o tutor" (frases de "Não encontrei…")
     * @param  string  $rotuloPlural  "tutores" (frases de "Não consegui consultar…")
     * @param  array<string, array<string, mixed>>  $filtros  o esquema dos filtros da consulta (todos opcionais)
     * @param  Closure(array): \Illuminate\Support\Collection  $buscar  recebe os filtros + tenant_id
     * @param  Closure(array, int): \Illuminate\Database\Eloquent\Model  $gravar  recebe os dados validados e o tenant
     * @param  array<string, array<string, mixed>>  $propriedadesCriar
     * @param  array<string, array<string, mixed>>  $propriedadesAtualizar  só os campos que mudam (nome novo, etc.)
     * @param  object  $servico  precisa de localizar() e atualizar(int, array, int)
     * @param  class-string  $requestAtualizar  as regras do formulário de alterar; sem ele, as mesmas de criar
     * @return array<int, array<string, mixed>>
     */
    private function definirCadastro(
        string $entidade,
        string $singular,
        string $plural,
        string $rotulo,
        string $rotuloPlural,
        object $servico,
        string $requestCriar,
        array $propriedadesCriar,
        array $obrigatorios,
        array $propriedadesAtualizar,
        array $filtros,
        Closure $buscar,
        Closure $gravar,
        array $colunas,
        string $colunaRotulo,
        array $papeisLeitura,
        array $papeisEscrita,
        string $tituloCriar,
        string $sucessoCriar,
        string $tituloAtualizar,
        string $sucessoAtualizar,
        string $buscaPor = 'nome',
        ?string $requestAtualizar = null,
        array $mensagens = [],
    ): array {
        $regrasCriar = (new $requestCriar)->rules();
        $regrasAtualizar = (new ($requestAtualizar ?? $requestCriar))->rules();
        $artigo = Str::startsWith($rotulo, 'a ') ? 'a' : 'o';

        return [
            $this->definirTool(
                nome: "consultar_{$plural}",
                descricao: "Consulta {$rotuloPlural} cadastrad{$artigo}s, opcionalmente filtrando. Ação de leitura — não exige confirmação.",
                tipo: 'leitura',
                risco: 'leitura',
                acao: 'consultar',
                entidade: $entidade,
                papeis: $papeisLeitura,
                propriedades: $filtros,
                obrigatorios: [],
                executar: $buscar,
                mensagemErro: "Não consegui consultar {$rotuloPlural} agora. Tente novamente.",
                colunas: $colunas,
                editavelPor: "atualizar_{$singular}",
            ),
            $this->definirEscrita(
                nome: "criar_{$singular}",
                descricao: 'Cadastra '.$rotulo.' novo. Ação de escrita — exige confirmação humana antes de ser executada.',
                entidade: $entidade,
                papeis: $papeisEscrita,
                propriedades: $propriedadesCriar,
                obrigatorios: $obrigatorios,
                formRequest: $requestCriar,
                gravar: fn (array $dados) => $gravar($this->inteiros($dados, $regrasCriar), (int) $dados['tenant_id']),
                mensagemErro: "Não consegui cadastrar {$rotulo}. Tente novamente.",
                titulo: $tituloCriar,
                sucesso: $sucessoCriar,
                mensagens: $mensagens,
            ),
            $this->definirAtualizacao(
                nome: "atualizar_{$singular}",
                descricao: 'Altera '.$rotulo.' existente, achad'.$artigo.' pelo id ou por parte do '.$buscaPor.'. Mande só os campos que mudam. Ação de escrita — exige confirmação humana antes de ser executada.',
                entidade: $entidade,
                rotulo: $rotulo,
                papeis: $papeisEscrita,
                propriedades: $propriedadesAtualizar,
                formRequest: $requestAtualizar ?? $requestCriar,
                localizar: $servico->localizar(...),
                atualizar: fn (int $id, array $dados, int $tenantId) => $servico->atualizar($id, $this->inteiros($dados, $regrasAtualizar), $tenantId),
                buscaPor: $buscaPor,
                colunaRotulo: $colunaRotulo,
                mensagemErro: "Não consegui alterar {$rotulo}. Tente novamente.",
                titulo: $tituloAtualizar,
                sucesso: $sucessoAtualizar,
                mensagens: $mensagens,
            ),
        ];
    }

    /**
     * Operação de negócio que não é CRUD. O $executar recebe os dados JÁ validados, o tenant e o AUTOR (o usuário
     * logado, lido do servidor: a IA e o navegador não escolhem quem age) e devolve o registro gravado (ou uma
     * lista). Recusa de regra de negócio (RegraDeNegocioException) vira frase amigável e fica na auditoria como
     * "recusado". $conferir é a regra de negócio que já aparece no Revisar, antes do segurar, sem gravar nada.
     *
     * @param  class-string  $formRequest  as regras desenham o formulário do card e validam a execução
     * @param  Closure(array, int, User): mixed  $executar
     * @param  ?Closure(array): ?array{campo: string, motivo: string}  $conferir  recebe os argumentos + tenant_id
     * @param  string  $risco  'escrita' (padrão) ou 'exclusao' (cancelar, estornar: borda de risco máximo)
     * @param  ?Closure(?int, ?string, int): \Illuminate\Support\Collection  $localizar  ação sobre um registro que já
     *         existe: o servidor acha o registro por id ou busca (no tenant), com a lista de escolha se houver mais de um.
     *         O id fica na proposta guardada na sessão, FORA do formulário: o humano não troca de registro no card.
     * @param  ?string[]  $campos  os campos que o card mostra e deixa editar (null = todos os do Form Request);
     *         os outros só valem como vieram (da IA ou do localizar) e são validados na execução
     * @param  ?Closure(array, int): array<int, array{rotulo: string, valor: mixed}>  $resumir  o que vai acontecer, só
     *         leitura no card (linhas, total, pet...); recusa de regra de negócio vira o aviso do topo do card
     * @return array<string, mixed>
     */
    private function definirAcao(
        string $nome,
        string $descricao,
        string $acao,
        string $entidade,
        array $papeis,
        array $propriedades,
        array $obrigatorios,
        string $formRequest,
        Closure $executar,
        string $mensagemErro,
        string $titulo,
        string $sucesso,
        array $mensagens = [],
        ?Closure $conferir = null,
        string $risco = 'escrita',
        ?Closure $localizar = null,
        string $rotulo = '',
        string $buscaPor = 'nome do pet',
        string $colunaRotulo = '',
        string $rotuloAcao = '',
        ?array $campos = null,
        ?Closure $resumir = null,
        bool|string $reautenticar = false, // H: 'estorno' = o card confere o PIN do supervisor ou a senha do dono
    ): array {
        $regras = (new $formRequest)->rules();
        $regrasDoFormulario = $campos === null ? $regras : array_intersect_key($regras, array_flip($campos));

        if ($localizar !== null) {
            $propriedades = [
                'id' => ['type' => 'integer', 'description' => 'Número (id) do registro, se o usuário informou.'],
                'busca' => ['type' => 'string', 'description' => Str::ucfirst($buscaPor).', quando o número não foi informado.'],
                ...\App\Services\AiAgentService::PROPRIEDADE_POSICAO, // P-C1
                ...$propriedades,
            ];
        }

        return $this->definirTool(
            nome: $nome,
            descricao: $descricao.' Ação de '.($risco === 'exclusao' ? 'exclusão' : 'escrita').' — exige confirmação humana antes de ser executada.',
            tipo: 'escrita',
            risco: $risco,
            acao: $acao,
            entidade: $entidade,
            papeis: $papeis,
            propriedades: $propriedades,
            obrigatorios: $obrigatorios,
            executar: function (array $a) use ($regras, $mensagens, $executar) {
                $dados = $this->inteiros($this->validar($a, $regras, $mensagens), $regras);

                return $executar($dados, (int) $a['tenant_id'], $this->contexto->usuario());
            },
            mensagemErro: $mensagemErro,
            regras: $regrasDoFormulario,
            titulo: $titulo,
            sucesso: $sucesso,
            mensagens: $mensagens,
            localizar: $localizar,
            rotulo: $rotulo,
            buscaPor: $buscaPor,
            colunaRotulo: $colunaRotulo,
            conferir: $conferir,
            feito: $acao === 'cobrar' ? 'cobrado' : 'alterado',
            rotuloAcao: $rotuloAcao,
            resumir: $resumir,
            regrasRevisar: $regras,
            reautenticar: $reautenticar,
        );
    }

    /** Centavos (inteiro) em reais para o card: "R$ 1.234,50". Sem float em nenhum passo. */
    private function reais(int $centavos): string
    {
        return 'R$ '.number_format(intdiv($centavos, 100), 0, '', '.').','.str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * O formulário do card manda número como texto ("6200"); os services da clínica exigem inteiro de verdade
     * (nunca float, nunca texto). Só os campos com a regra "integer" são convertidos, e só se forem inteiros em texto.
     *
     * @param  array<string, mixed>  $dados
     * @param  array<string, mixed>  $regras
     * @return array<string, mixed>
     */
    private function inteiros(array $dados, array $regras): array
    {
        foreach ($dados as $campo => $valor) {
            $regra = $regras[$campo] ?? '';
            $partes = is_string($regra) ? explode('|', $regra) : array_filter((array) $regra, 'is_string');

            if (in_array('integer', $partes, true) && is_string($valor) && preg_match('/^-?\d+$/', trim($valor)) === 1) {
                $dados[$campo] = (int) trim($valor);
            }
        }

        return $dados;
    }
}
