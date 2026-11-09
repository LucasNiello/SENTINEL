<?php

namespace Tests\Feature\Clinica;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Prontuario;
use App\Models\ProntuarioAdendo;
use App\Models\ProntuarioItem;
use App\Services\ProntuarioService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

class ProntuarioServiceTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    private ProntuarioService $prontuarios;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
        $this->prontuarios = app(ProntuarioService::class);
    }

    private function recusa(callable $acao, string $frase): void
    {
        try {
            $acao();
            $this->fail("Esperava a recusa: {$frase}");
        } catch (RegraDeNegocioException $e) {
            $this->assertStringContainsString($frase, $e->getMessage());
        }
    }

    private function assinado(): array
    {
        $vet = $this->usuarioClinica('veterinario');
        $pet = $this->pet();
        $prontuario = $this->prontuarios->abrir(['pet_id' => $pet->id, 'tipo' => 'consulta', 'diagnostico' => 'Otite externa.'], $vet->id, self::T);
        $this->prontuarios->assinar($prontuario->id, $vet->id, self::T);

        return [$prontuario->fresh(), $vet, $pet];
    }

    public function test_assinatura_exige_crmv(): void
    {
        $semCrmv = $this->usuarioClinica('veterinario', comCrmv: false);
        $prontuario = $this->prontuarios->abrir(['pet_id' => $this->pet()->id, 'tipo' => 'consulta'], $semCrmv->id, self::T);

        $this->recusa(fn () => $this->prontuarios->assinar($prontuario->id, $semCrmv->id, self::T), 'precisa ter o CRMV cadastrado');
        $this->assertNull($prontuario->fresh()->assinado_em);

        $semCrmv->update(['crmv' => 'CRMV-SP 777']);
        $this->assertNotNull($this->prontuarios->assinar($prontuario->id, $semCrmv->id, self::T)->assinado_em);
    }

    public function test_so_o_autor_edita_e_assina_e_so_veterinario_abre(): void
    {
        $autor = $this->usuarioClinica('veterinario');
        $colega = $this->usuarioClinica('veterinario');
        $pet = $this->pet();
        $prontuario = $this->prontuarios->abrir(['pet_id' => $pet->id, 'tipo' => 'consulta'], $autor->id, self::T);

        $this->recusa(fn () => $this->prontuarios->atualizar($prontuario->id, ['diagnostico' => 'X'], $colega->id, self::T), 'Só o veterinário responsável');
        $this->recusa(fn () => $this->prontuarios->assinar($prontuario->id, $colega->id, self::T), 'Só o veterinário responsável');
        $this->recusa(fn () => $this->prontuarios->abrir(['pet_id' => $pet->id, 'tipo' => 'consulta'], $this->usuarioClinica('recepcao')->id, self::T),
            'Só um veterinário pode registrar o prontuário.');

        $this->prontuarios->atualizar($prontuario->id, ['diagnostico' => 'Dermatite.'], $autor->id, self::T);
        $this->assertSame('Dermatite.', $prontuario->fresh()->diagnostico);
    }

    public function test_assinado_nao_muda_por_nenhum_caminho(): void
    {
        [$prontuario, $vet] = $this->assinado();
        $consulta = $this->item();

        $this->recusa(fn () => $this->prontuarios->atualizar($prontuario->id, ['diagnostico' => 'Outro'], $vet->id, self::T), 'registre um adendo');
        $this->recusa(fn () => $this->prontuarios->adicionarItem($prontuario->id, $consulta->id, 1, $vet->id, self::T), 'registre um adendo');
        // Direto pelo model também: a guarda é do Prontuario, não só do service.
        $this->recusa(fn () => $prontuario->update(['diagnostico' => 'Outro']), 'Prontuário assinado não pode ser alterado.');
        $this->recusa(fn () => $prontuario->update(['assinado_em' => null]), 'Prontuário assinado não pode ser alterado.');
        $this->recusa(fn () => $prontuario->delete(), 'não pode ser excluído');
        $this->recusa(fn () => ProntuarioItem::create(['prontuario_id' => $prontuario->id, 'catalogo_item_id' => $consulta->id, 'quantidade' => 1, 'tenant_id' => self::T]),
            'Prontuário assinado não pode ser alterado.');

        $this->assertSame('Otite externa.', $prontuario->fresh()->diagnostico);
        $this->assertNotNull($prontuario->fresh()->assinado_em);
        $this->assertSame(0, ProntuarioItem::count());
    }

    public function test_item_do_prontuario_assinado_nao_sai_e_item_do_petshop_nao_entra(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $prontuario = $this->prontuarios->abrir(['pet_id' => $this->pet()->id, 'tipo' => 'vacinacao'], $vet->id, self::T);
        $vacina = $this->item(['categoria' => 'vacina', 'nome_comercial' => 'Vacina V10']);
        $item = $this->prontuarios->adicionarItem($prontuario->id, $vacina->id, 1, $vet->id, self::T);
        $racao = $this->produto();

        $this->recusa(fn () => $this->prontuarios->adicionarItem($prontuario->id, $racao->id, 1, $vet->id, self::T), 'é do petshop');

        $this->prontuarios->assinar($prontuario->id, $vet->id, self::T);
        $this->recusa(fn () => $this->prontuarios->removerItem($item->id, $vet->id, self::T), 'registre um adendo');
        $this->recusa(fn () => $item->fresh()->delete(), 'Prontuário assinado não pode ser alterado.');
        $this->assertSame(1, ProntuarioItem::count());
    }

    public function test_item_nao_sai_do_prontuario_assinado_trocando_de_prontuario(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $pet = $this->pet();
        $assinado = $this->prontuarios->abrir(['pet_id' => $pet->id, 'tipo' => 'vacinacao'], $vet->id, self::T);
        $item = $this->prontuarios->adicionarItem($assinado->id, $this->item(['categoria' => 'vacina', 'nome_comercial' => 'Vacina V10'])->id, 1, $vet->id, self::T);
        $this->prontuarios->assinar($assinado->id, $vet->id, self::T);
        $rascunho = $this->prontuarios->abrir(['pet_id' => $pet->id, 'tipo' => 'consulta'], $vet->id, self::T);

        $this->recusa(fn () => $item->fresh()->update(['prontuario_id' => $rascunho->id]), 'não muda de prontuário');
        $this->assertSame($assinado->id, $item->fresh()->prontuario_id);
    }

    public function test_internacao_so_e_assinada_com_entrada_e_alta(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $prontuario = $this->prontuarios->abrir(['pet_id' => $this->pet()->id, 'tipo' => 'internacao', 'entrada_em' => '2026-11-07 18:00'], $vet->id, self::T);

        $this->recusa(fn () => $this->prontuarios->assinar($prontuario->id, $vet->id, self::T), 'com a entrada e a alta informadas');
        $this->recusa(fn () => $this->prontuarios->atualizar($prontuario->id, ['alta_em' => '2026-11-06 10:00'], $vet->id, self::T), 'A alta não pode ser antes da entrada.');

        $this->prontuarios->atualizar($prontuario->id, ['alta_em' => '2026-11-09 09:00'], $vet->id, self::T);
        $this->assertSame(2, $this->prontuarios->assinar($prontuario->id, $vet->id, self::T)->fresh()->diarias());
    }

    public function test_diarias_sao_dias_de_calendario_com_minimo_de_um(): void
    {
        $prontuario = new Prontuario(['entrada_em' => '2026-11-10 08:00', 'alta_em' => '2026-11-10 20:00']);
        $this->assertSame(1, $prontuario->diarias());

        $prontuario = new Prontuario(['entrada_em' => '2026-11-10 22:00', 'alta_em' => '2026-11-12 08:00']);
        $this->assertSame(2, $prontuario->diarias());

        $this->assertNull((new Prontuario(['entrada_em' => '2026-11-10 22:00']))->diarias());
    }

    public function test_adendo_so_em_prontuario_assinado_por_qualquer_veterinario_ou_dono_com_crmv(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $rascunho = $this->prontuarios->abrir(['pet_id' => $this->pet()->id, 'tipo' => 'consulta'], $vet->id, self::T);
        $this->recusa(fn () => $this->prontuarios->adendar($rascunho->id, 'X', $vet->id, self::T), 'ainda não foi assinado');

        [$prontuario] = $this->assinado();
        $this->prontuarios->adendar($prontuario->id, 'Correção: otite bilateral.', $this->usuarioClinica('veterinario')->id, self::T);
        $this->prontuarios->adendar($prontuario->id, 'Retorno em 7 dias.', $this->usuarioClinica('dono')->id, self::T);

        $this->recusa(fn () => $this->prontuarios->adendar($prontuario->id, 'X', $this->usuarioClinica('veterinario', comCrmv: false)->id, self::T), 'precisa ter o CRMV');
        $this->recusa(fn () => $this->prontuarios->adendar($prontuario->id, 'X', $this->usuarioClinica('recepcao')->id, self::T), 'Só um veterinário');
        $this->recusa(fn () => $this->prontuarios->adendar($prontuario->id, '   ', $vet->id, self::T), 'Escreva o texto do adendo.');

        $this->assertSame(2, ProntuarioAdendo::where('prontuario_id', $prontuario->id)->whereNotNull('assinado_em')->count());
        $this->assertSame('Otite externa.', $prontuario->fresh()->diagnostico);
    }

    public function test_adendo_nunca_muda_nem_e_excluido(): void
    {
        [$prontuario, $vet] = $this->assinado();
        $adendo = $this->prontuarios->adendar($prontuario->id, 'Correção.', $vet->id, self::T);

        $this->recusa(fn () => $adendo->update(['texto' => 'Outro']), 'Adendo assinado não pode ser alterado.');
        $this->recusa(fn () => $adendo->delete(), 'Adendo não pode ser excluído');
        $this->assertSame('Correção.', $adendo->fresh()->texto);
    }

    public function test_emergencia_sem_agendamento_e_vinculada_depois_uma_unica_vez_com_quem_e_quando(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $pet = $this->pet();
        $emergencia = $this->prontuarios->abrir(['pet_id' => $pet->id, 'tipo' => 'emergencia', 'anamnese' => 'Atropelamento.'], $vet->id, self::T);
        $this->prontuarios->assinar($emergencia->id, $vet->id, self::T);
        $encaixe = $this->agendamentoDireto($pet, $vet, $this->item(), ['origem' => 'encaixe_emergencia']);
        $recepcao = $this->usuarioClinica('recepcao');

        $this->travel(5)->minutes();
        $vinculado = $this->prontuarios->vincular($emergencia->id, $encaixe->id, $recepcao->id, self::T);

        // A recepção recebe só os metadados: nenhum campo clínico vem na resposta.
        foreach (Prontuario::CAMPOS_CLINICOS as $campo) {
            $this->assertArrayNotHasKey($campo, $vinculado->getAttributes(), $campo);
        }
        $salvo = $emergencia->fresh();
        $this->assertSame([$encaixe->id, $recepcao->id, '2026-11-10 10:05:00'], [$salvo->agendamento_id, $salvo->vinculado_por, $salvo->vinculado_em->format('Y-m-d H:i:s')]);
        $this->assertSame('Atropelamento.', $salvo->anamnese);

        $outroEncaixe = $this->agendamentoDireto($pet, $vet, $this->item(), ['origem' => 'encaixe_emergencia']);
        $this->recusa(fn () => $this->prontuarios->vincular($emergencia->id, $outroEncaixe->id, $recepcao->id, self::T), 'uma única vez');
        $this->recusa(fn () => $salvo->update(['agendamento_id' => $outroEncaixe->id]), 'Prontuário assinado não pode ser alterado.');
        $this->assertSame($encaixe->id, $emergencia->fresh()->agendamento_id);
    }

    public function test_vinculo_so_com_encaixe_do_mesmo_pet_e_do_mesmo_veterinario(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $pet = $this->pet();
        $emergencia = $this->prontuarioDireto($pet, $vet, ['tipo' => 'emergencia']);
        $consulta = $this->item();
        $recepcao = $this->usuarioClinica('recepcao');

        $normal = $this->agendamentoDireto($pet, $vet, $consulta);
        $deOutroPet = $this->agendamentoDireto($this->pet(), $vet, $consulta, ['origem' => 'encaixe_emergencia']);
        $deOutroVet = $this->agendamentoDireto($pet, $this->usuarioClinica('veterinario'), $consulta, ['origem' => 'encaixe_emergencia']);

        $this->recusa(fn () => $this->prontuarios->vincular($emergencia->id, $normal->id, $recepcao->id, self::T), 'agendamento de encaixe de emergência');
        $this->recusa(fn () => $this->prontuarios->vincular($emergencia->id, $deOutroPet->id, $recepcao->id, self::T), 'é de outro pet');
        $this->recusa(fn () => $this->prontuarios->vincular($emergencia->id, $deOutroVet->id, $recepcao->id, self::T), 'é de outro veterinário');
        $this->recusa(fn () => $this->prontuarios->vincular($emergencia->id, $normal->id, $this->usuarioClinica('vendedor')->id, self::T), 'Só a recepção ou um veterinário');
        $this->assertNull($emergencia->fresh()->agendamento_id);
    }

    public function test_assinado_nao_aceita_conteudo_junto_com_o_vinculo(): void
    {
        [$prontuario, $vet, $pet] = $this->assinado();
        $encaixe = $this->agendamentoDireto($pet, $vet, $this->item(), ['origem' => 'encaixe_emergencia']);

        $this->recusa(fn () => $prontuario->update(['agendamento_id' => $encaixe->id, 'diagnostico' => 'Trocado']), 'Prontuário assinado não pode ser alterado.');
        $this->recusa(fn () => $prontuario->fresh()->update(['vinculado_por' => $vet->id]), 'Prontuário assinado não pode ser alterado.');
        $this->assertNull($prontuario->fresh()->agendamento_id);
    }

    public function test_metadados_nao_trazem_campos_clinicos(): void
    {
        [$prontuario, , $pet] = $this->assinado();

        $linhas = $this->prontuarios->metadados(self::T, $pet->id);

        $this->assertCount(1, $linhas);
        $this->assertSame(Prontuario::CAMPOS_METADADOS, array_keys($linhas->first()->getAttributes()));
        $this->assertStringNotContainsString('Otite', json_encode($linhas->toArray()));
        $this->assertCount(0, $this->prontuarios->metadados(self::OUTRO_TENANT));
    }

    public function test_outro_tenant_e_inexistente(): void
    {
        [$prontuario, $vet] = $this->assinado();

        $this->recusa(fn () => $this->prontuarios->abrir(['pet_id' => $this->pet(null, self::OUTRO_TENANT)->id, 'tipo' => 'consulta'], $vet->id, self::T), 'Não encontrei o pet');
        $this->recusa(fn () => $this->prontuarios->abrir(['pet_id' => $this->pet()->id, 'tipo' => 'consulta'], $this->usuarioClinica('veterinario', self::OUTRO_TENANT)->id, self::T),
            'Não encontrei o veterinário');

        $this->expectException(ModelNotFoundException::class);
        $this->prontuarios->adendar($prontuario->id, 'X', $vet->id, self::OUTRO_TENANT);
    }
}
