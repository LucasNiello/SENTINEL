<?php

namespace Tests\Feature\Clinica;

use App\Services\AiAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

/**
 * 23/11: prova de que NENHUMA tool do agente cria ou altera prontuário. O catálogo (AiAgentService::tools) é a fonte
 * única do que o modelo pode chamar e do que executarTool aceita; aqui o catálogo da clínica não tem escrita de
 * prontuário, receita, evolução, diagnóstico ou assinatura, e um nome desses é recusado como "tool desconhecida".
 */
class AgenteSemProntuarioTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function papeisClinicos(): array
    {
        return ['dono' => ['dono'], 'veterinario' => ['veterinario'], 'recepcao' => ['recepcao'], 'atendente' => ['atendente'], 'vendedor' => ['vendedor']];
    }

    #[DataProvider('papeisClinicos')]
    public function test_o_catalogo_nao_tem_tool_de_prontuario_receita_ou_assinatura(string $papel): void
    {
        $this->logarComo($papel, self::T);
        $proibidos = '/prontuario|receita|prescri|evolucao|diagnostico|anamnese|assinar|adendo/i';

        $nomes = [];
        foreach (app(AiAgentService::class)->tools() as $tool) {
            $nome = $tool['function']['name'];
            $nomes[] = $nome;
            $this->assertDoesNotMatchRegularExpression($proibidos, $nome, "A tool {$nome} parece mexer em prontuário.");
            // exige_receita é só um sim/não do item do catálogo (o produto pede receita), não conteúdo clínico.
            foreach (array_diff(array_keys($tool['function']['parameters']['properties'] ?? []), ['exige_receita']) as $argumento) {
                $this->assertDoesNotMatchRegularExpression($proibidos, (string) $argumento, "{$nome}: o argumento {$argumento} parece conteúdo clínico.");
            }
        }

        // Sanidade: o catálogo da clínica foi mesmo carregado (senão o teste passaria no vazio).
        $this->assertContains('agendar_atendimento', $nomes);
        $this->assertContains('consultar_cobrancas', $nomes);
    }

    #[DataProvider('papeisClinicos')]
    public function test_executar_tool_de_prontuario_e_recusada_como_desconhecida(string $papel): void
    {
        $this->logarComo($papel, self::T);

        foreach (['registrar_prontuario', 'atualizar_prontuario', 'assinar_prontuario', 'criar_receita', 'adendo_prontuario'] as $tool) {
            try {
                app(AiAgentService::class)->executarTool($tool, ['agendamento_id' => 1, 'texto' => 'x']);
                $this->fail("{$tool} foi aceita pelo agente.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Tool desconhecida', $e->getMessage());
            }
        }
    }
}
