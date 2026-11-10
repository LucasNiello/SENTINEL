<?php

namespace Tests\Feature\Clinica;

use App\Exceptions\ToolRecusadaException;
use App\Http\Requests\PetRequest;
use App\Models\Tutor;
use App\Models\User;
use App\Services\AiAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * P-B3: o construtor definirAcao (operação de negócio que não é CRUD). A primeira tool real que o usa é a venda
 * (item 3 da 1ª leva); aqui se prova o que ele garante por construção, chamando o executor que ele monta.
 */
class ConstrutoresDeToolTest extends TestCase
{
    use RefreshDatabase;

    private ?array $recebido = null;

    private function acao(array $papeis = ['recepcao', 'dono'], string $risco = 'escrita', ?\Closure $conferir = null): array
    {
        $agente = app(AiAgentService::class);
        $metodo = new ReflectionMethod(AiAgentService::class, 'definirAcao');

        return $metodo->invoke($agente,
            nome: 'acao_de_teste',
            descricao: 'Faz uma coisa.',
            acao: 'criar',
            entidade: 'Pet',
            papeis: $papeis,
            propriedades: ['nome' => ['type' => 'string']],
            obrigatorios: ['nome'],
            formRequest: PetRequest::class,
            executar: function (array $dados, int $tenantId, User $ator) {
                $this->recebido = compact('dados', 'tenantId', 'ator');

                return $ator;
            },
            mensagemErro: 'Não consegui.',
            titulo: 'Fazer a coisa',
            sucesso: 'Coisa feita',
            conferir: $conferir,
            risco: $risco,
        );
    }

    public function test_o_executor_recebe_so_os_dados_validados_o_tenant_e_o_autor_do_servidor(): void
    {
        $logado = User::factory()->papel('recepcao')->doTenant(3)->create();
        $this->actingAs($logado);
        $tutor = Tutor::create(['nome' => 'Ana', 'tenant_id' => 3]);
        $outro = User::factory()->papel('dono')->doTenant(3)->create(); // um usuário real que os dados tentam pôr como autor
        $definicao = $this->acao();

        // O que viria de fora tenta escolher autor e campo que não existe: nada disso chega ao executor.
        ($definicao['executar'])(['tutor_id' => (string) $tutor->id, 'nome' => 'Thor', 'especie' => 'cao', 'peso_gramas' => '6200',
            'usuario_id' => $outro->id, 'autor_id' => $outro->id, 'user_id' => $outro->id, 'campo_estranho' => 'x', 'tenant_id' => 3]);

        $this->assertSame($logado->id, $this->recebido['ator']->id, 'o autor é o usuário logado, nunca o que veio nos dados');
        $this->assertSame(3, $this->recebido['tenantId']);
        $this->assertSame(6200, $this->recebido['dados']['peso_gramas'], 'número do formulário vira inteiro de verdade');
        $this->assertSame($tutor->id, $this->recebido['dados']['tutor_id']);
        $this->assertEmpty(array_intersect(['usuario_id', 'autor_id', 'user_id', 'campo_estranho'], array_keys($this->recebido['dados'])));
    }

    public function test_dado_invalido_nao_chega_ao_executor(): void
    {
        $this->actingAs(User::factory()->papel('dono')->doTenant(3)->create());
        $definicao = $this->acao();

        try {
            ($definicao['executar'])(['nome' => 'Thor', 'especie' => 'dragao', 'tenant_id' => 3]);
            $this->fail('Esperava a recusa da validação.');
        } catch (ToolRecusadaException $e) {
            $this->assertStringContainsString('tutor', $e->getMessage());
        }

        $this->assertNull($this->recebido);
    }

    public function test_e_sempre_escrita_com_confirmacao_e_o_risco_e_os_papeis_sao_os_declarados(): void
    {
        $this->actingAs(User::factory()->papel('dono')->doTenant(3)->create());
        $conferir = fn (array $a) => null;

        $escrita = $this->acao(['dono'], 'escrita', $conferir);
        $exclusao = $this->acao(['dono'], 'exclusao');

        $this->assertSame(['escrita', 'escrita', ['dono'], 'criar', 'Pet'], [$escrita['tipo'], $escrita['risco'], $escrita['papeis'], $escrita['acao'], $escrita['entidade']]);
        $this->assertSame(['escrita', 'exclusao'], [$exclusao['tipo'], $exclusao['risco']]);
        $this->assertSame($conferir, $escrita['conferir'], 'a regra de negócio aparece no Revisar, antes do segurar');
        $this->assertStringContainsString('exige confirmação humana', $escrita['definicao']['function']['description']);
        $this->assertNotEmpty($escrita['regras'], 'as regras do Form Request desenham o formulário do card');
        $this->assertArrayNotHasKey('tenant_id', $escrita['definicao']['function']['parameters']['properties']);
    }

    public function test_lista_de_papeis_vazia_ou_com_papel_desconhecido_quebra_na_definicao(): void
    {
        $this->actingAs(User::factory()->papel('dono')->doTenant(3)->create());

        foreach ([[], ['recepção'], ['dono', 'gerente']] as $papeis) {
            try {
                $this->acao($papeis);
                $this->fail('Lista de papéis inválida deveria quebrar: '.json_encode($papeis));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('lista de papéis vazia ou com papel desconhecido', $e->getMessage());
            }
        }
    }
}
