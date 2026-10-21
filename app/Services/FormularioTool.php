<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * RF04: transforma as regras do Form Request de uma tool de escrita no
 * formulário do card de confirmação. Só lê as regras (tipo, obrigatório,
 * opções, vínculos e condições), então trocar de domínio é trocar os Form
 * Requests do catálogo, não este motor.
 */
class FormularioTool
{
    /** Até quantos registros uma lista de vínculo (regra exists:) oferece. */
    private const LIMITE_REFERENCIAS = 50;

    /** Coluna que nomeia um registro numa lista de vínculo, na ordem de preferência. */
    private const COLUNAS_ROTULO = ['nome', 'descricao', 'numero'];

    /** Opções cujo rótulo não é só a palavra com inicial maiúscula. */
    private const ROTULOS_OPCOES = ['saida' => 'Saída'];

    /**
     * @param  array<string, mixed>  $regras  rules() do Form Request da tool
     * @param  array<string, mixed>  $valores  valores atuais (proposta da IA ou o que o humano editou)
     * @param  array<string, string>  $erros  primeira frase de erro por campo
     * @param  array<string, string>  $rotulos  como cada campo aparece nas frases ("o e-mail")
     * @param  string[]  $revelados  campos sensíveis de dinheiro que este usuário pode ver, formatados em reais
     * @return array{titulo: string, campos: array<int, array<string, mixed>>}
     */
    public function montar(string $titulo, array $regras, array $valores, array $erros, array $rotulos, int $tenantId, array $revelados = []): array
    {
        $campos = [];

        foreach (self::campos($regras) as $nome => $partes) {
            $valor = $valores[$nome] ?? null;
            $sensivel = in_array($nome, AuditoriaService::camposSensiveis(), true);
            $mascara = match (true) {
                ! $sensivel => null,
                // Dinheiro que este usuário pode conferir: em reais, só nesta resposta ao front.
                in_array($nome, $revelados, true) && is_numeric($valor) => 'R$ '.number_format((float) $valor, 2, ',', '.'),
                default => AuditoriaService::mascararCamposSensiveis([$nome => $valor])[$nome],
            };
            $condicoes = self::condicoes($partes);
            $obrigatorio = in_array('required', $partes, true);
            $max = self::parametros($partes, 'max')[0] ?? null;
            $minimo = self::parametros($partes, 'min')[0] ?? self::parametros($partes, 'gt')[0] ?? null;
            $opcoes = self::parametros($partes, 'in');
            $vinculo = self::parametros($partes, 'exists');

            $campo = [
                'nome' => $nome,
                'rotulo' => Str::ucfirst(self::semArtigo($rotulos[$nome] ?? str_replace('_', ' ', $nome))),
                'tipo' => match (true) {
                    $opcoes !== [] => 'opcoes',
                    $vinculo !== [] => 'referencia',
                    in_array('email', $partes, true) => 'email',
                    self::parametros($partes, 'date_format') !== [] || in_array('date', $partes, true) => 'data',
                    in_array('numeric', $partes, true) || in_array('integer', $partes, true) => 'numero',
                    default => 'texto',
                },
                'obrigatorio' => $obrigatorio,
                // Obrigatório e vazio, inclusive o obrigatório só por condição (ex.: cliente numa nota de saída).
                'faltando' => blank($valor) && ($obrigatorio || self::condicaoAtiva($condicoes, 'obrigatorio', $valores)),
                // LGPD: campo sensível mostra só a máscara para conferência (nada, quando ela esconde o valor
                // inteiro, como no salário); deixado vazio, vale o valor da proposta.
                'sensivel' => $sensivel,
                'valor' => $sensivel ? ($mascara === AuditoriaService::REDIGIDO ? null : $mascara) : $valor,
            ];

            if ($sensivel) {
                $campo['informado'] = ! blank($valor);
            }

            if ($max !== null) {
                $campo['max'] = (float) $max;
            }
            if ($minimo !== null) {
                $campo['min'] = (float) $minimo;
            }
            if (in_array('integer', $partes, true)) {
                $campo['inteiro'] = true;
            }
            if ($opcoes !== []) {
                $campo['opcoes'] = array_map(fn (string $opcao) => ['valor' => $opcao, 'rotulo' => self::ROTULOS_OPCOES[$opcao] ?? Str::ucfirst($opcao)], $opcoes);
            }
            if ($vinculo !== []) {
                $campo['opcoes'] = $this->referencias($vinculo[0], $vinculo[1] ?? 'id', $tenantId, $valor);

                // O vínculo proposto não existe neste tenant (ou está na lixeira): o campo avisa, em vez de sumir calado.
                if (is_scalar($valor) && ! blank($valor) && ! in_array((string) $valor, array_map(fn (array $opcao) => (string) $opcao['valor'], $campo['opcoes']), true)) {
                    $campo['erro'] ??= 'Não encontrei '.($rotulos[$nome] ?? $nome).' informado.';
                }
            }
            if ($condicoes !== []) {
                $campo['condicoes'] = $condicoes;
            }
            if (isset($erros[$nome])) {
                $campo['erro'] = $erros[$nome];
            }

            $campos[] = $campo;
        }

        return ['titulo' => $titulo, 'campos' => $campos];
    }

    /**
     * Campos do formulário, com as regras de cada um: as regras do Form Request menos
     * tenant_id, que vem sempre do servidor (nunca do formulário nem da IA).
     *
     * @return array<string, string[]>
     */
    public static function campos(array $regras): array
    {
        $campos = [];

        foreach ($regras as $nome => $regra) {
            if ($nome === 'tenant_id') {
                continue;
            }

            // Regra em objeto (Rule::...) não descreve o campo para o formulário; só as em texto contam.
            $campos[$nome] = array_values(array_filter(is_string($regra) ? explode('|', $regra) : (array) $regra, 'is_string'));
        }

        return $campos;
    }

    /**
     * Campos opcionais nesta situação: não obrigatórios, nem obrigatórios por condição, e
     * não proibidos por condição (fornecedor numa nota de saída não é "opcional que faltou").
     *
     * @return string[]
     */
    public static function opcionais(array $regras, array $valores): array
    {
        $opcionais = [];

        foreach (self::campos($regras) as $nome => $partes) {
            $condicoes = self::condicoes($partes);

            if (! in_array('required', $partes, true)
                && ! self::condicaoAtiva($condicoes, 'obrigatorio', $valores)
                && ! self::condicaoAtiva($condicoes, 'proibido', $valores)) {
                $opcionais[] = $nome;
            }
        }

        return $opcionais;
    }

    /** "o e-mail" → "e-mail". */
    public static function semArtigo(string $rotulo): string
    {
        return (string) preg_replace('/^(o|a|os|as) /u', '', $rotulo);
    }

    /** @return string[] parâmetros da regra (ex.: "in:a,b" → ['a', 'b']); [] se a regra não está lá */
    private static function parametros(array $partes, string $regra): array
    {
        foreach ($partes as $parte) {
            if (str_starts_with($parte, "{$regra}:")) {
                return explode(',', substr($parte, strlen($regra) + 1));
            }
        }

        return [];
    }

    /** @return array<int, array{campo: string, valor: string, efeito: string}> required_if / prohibited_if */
    private static function condicoes(array $partes): array
    {
        $condicoes = [];

        foreach (['required_if' => 'obrigatorio', 'prohibited_if' => 'proibido'] as $regra => $efeito) {
            $parametros = self::parametros($partes, $regra);

            if (count($parametros) >= 2) {
                $condicoes[] = ['campo' => $parametros[0], 'valor' => $parametros[1], 'efeito' => $efeito];
            }
        }

        return $condicoes;
    }

    private static function condicaoAtiva(array $condicoes, string $efeito, array $valores): bool
    {
        foreach ($condicoes as $condicao) {
            $controle = $valores[$condicao['campo']] ?? '';

            // Valor que não é texto (array forjado) nunca ativa condição: sem "Array to string" (B4).
            if ($condicao['efeito'] === $efeito && is_scalar($controle) && (string) $controle === $condicao['valor']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Opções de um vínculo: só registros do tenant que não estão na lixeira (o
     * mesmo escopo que validar() aplica à regra exists:). Mostra id + nome. O
     * valor proposto entra sempre que existir nesse escopo, mesmo fora dos
     * LIMITE_REFERENCIAS mais recentes — senão a proposta da IA se perderia.
     *
     * @return array<int, array{valor: int, rotulo: string}>
     */
    private function referencias(string $tabela, string $chave, int $tenantId, mixed $atual): array
    {
        $coluna = collect(self::COLUNAS_ROTULO)->first(fn (string $coluna) => Schema::hasColumn($tabela, $coluna));
        $escopo = fn () => DB::table($tabela)->where('tenant_id', $tenantId)->whereNull('deleted_at');
        $colunas = array_filter([$chave, $coluna]);

        $linhas = $escopo()->orderByDesc($chave)->limit(self::LIMITE_REFERENCIAS)->get($colunas);

        if (is_scalar($atual) && ! blank($atual) && ! $linhas->contains(fn (object $linha) => (string) $linha->{$chave} === (string) $atual)) {
            $linhas = $escopo()->where($chave, $atual)->get($colunas)->concat($linhas);
        }

        return $linhas
            ->map(fn (object $linha) => [
                'valor' => $linha->{$chave},
                'rotulo' => '#'.$linha->{$chave}.($coluna ? ' — '.$linha->{$coluna} : ''),
            ])
            ->all();
    }
}
