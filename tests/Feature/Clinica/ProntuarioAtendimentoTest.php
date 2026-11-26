<?php

namespace Tests\Feature\Clinica;

use App\Models\AuditLog;
use App\Models\Prontuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

/** 26/11: atendimento do veterinário (prontuário: abrir, preencher, assinar, adendar), por rotas próprias fora do agente. */
class ProntuarioAtendimentoTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    private const TEXTO = 'Paciente com vômito há dois dias, sem febre.';

    private function corpo(array $extra = []): array
    {
        return ['tipo' => 'consulta', 'anamnese' => self::TEXTO, 'diagnostico' => 'Gastroenterite leve.', ...$extra];
    }

    private function abrir(int $petId, array $extra = []): int
    {
        return $this->postJson('/prontuarios', $this->corpo(['pet_id' => $petId, ...$extra]))->assertOk()->json('id');
    }

    public function test_so_veterinario_com_crmv_atende_o_dono_so_porque_e_veterinario(): void
    {
        $pet = $this->pet();

        foreach (['recepcao', 'atendente', 'vendedor'] as $papel) {
            $this->actingAs($this->usuarioClinica($papel));
            $this->postJson('/prontuarios', $this->corpo(['pet_id' => $pet->id]))->assertForbidden();
            $this->get('/prontuarios/novo?pet='.$pet->id)->assertForbidden();
        }
        // Veterinário e dono SEM CRMV: não atendem (o CRMV é o que os torna veterinários para este fim).
        foreach (['veterinario', 'dono'] as $papel) {
            $this->actingAs($this->usuarioClinica($papel, self::T, false));
            $this->postJson('/prontuarios', $this->corpo(['pet_id' => $pet->id]))->assertForbidden();
        }
        $this->assertSame(0, Prontuario::count());

        // O dono com CRMV atende.
        $this->actingAs($this->usuarioClinica('dono'));
        $this->abrir($pet->id);
        $this->assertSame(1, Prontuario::count());
    }

    public function test_fluxo_abrir_editar_assinar_e_adendar_com_auditoria_sem_texto_clinico(): void
    {
        config(['sentinel.front' => 'react']);
        $vet = $this->usuarioClinica('veterinario');
        $this->actingAs($vet);
        $pet = $this->pet();

        $id = $this->abrir($pet->id);
        $p = Prontuario::findOrFail($id);
        $this->assertSame([$vet->id, false], [$p->veterinario_id, $p->estaAssinado()], 'o autor é o usuário logado, não um campo do corpo');

        $this->get('/prontuarios/'.$id)->assertOk()->assertInertia(fn ($x) => $x->component('Prontuario', false)
            ->where('prontuario.anamnese', self::TEXTO)->where('permissoes.editar', true)->where('permissoes.assinar', true)->where('permissoes.adendar', false)
            ->missing('prontuario.veterinario_id')->missing('prontuario.tenant_id'));

        $this->postJson('/prontuarios/'.$id, $this->corpo(['conduta' => 'Dieta leve e hidratação.']))->assertOk();
        $this->assertSame('Dieta leve e hidratação.', $p->fresh()->conduta);

        // Assinar: sem CRMV e senha corretos, nada acontece (e fica negado na auditoria).
        $this->postJson('/prontuarios/'.$id.'/assinar', ['crmv' => 'CRMV-SP 99999', 'senha' => 'password'])->assertForbidden();
        $this->postJson('/prontuarios/'.$id.'/assinar', ['crmv' => $vet->crmv, 'senha' => 'errada'])->assertForbidden();
        $this->postJson('/prontuarios/'.$id.'/assinar', [])->assertForbidden();
        $this->assertFalse($p->fresh()->estaAssinado());
        $this->assertSame(3, AuditLog::where('tool', 'assinar_prontuario')->where('resultado', 'negado')->count());

        $this->postJson('/prontuarios/'.$id.'/assinar', ['crmv' => $vet->crmv, 'senha' => 'password'])->assertOk();
        $p = $p->fresh();
        $this->assertTrue($p->estaAssinado());
        $this->assertSame($vet->crmv, $p->assinado_por_crmv);

        // Depois de assinado nada muda: nem editar, nem assinar de novo; só adendo.
        $this->postJson('/prontuarios/'.$id, $this->corpo(['conduta' => 'Mudei depois de assinar']))->assertStatus(422);
        $this->assertSame('Dieta leve e hidratação.', $p->fresh()->conduta);
        $this->get('/prontuarios/'.$id)->assertInertia(fn ($x) => $x->where('permissoes.editar', false)->where('permissoes.adendar', true)->where('prontuario.assinado', true));

        $this->postJson('/prontuarios/'.$id.'/adendos', ['texto' => 'Retorno em 3 dias.', 'crmv' => $vet->crmv, 'senha' => 'errada'])->assertForbidden();
        $this->postJson('/prontuarios/'.$id.'/adendos', ['texto' => 'Retorno em 3 dias.', 'crmv' => $vet->crmv, 'senha' => 'password'])->assertOk();
        $this->get('/prontuarios/'.$id)->assertInertia(fn ($x) => $x->where('prontuario.adendos.0.texto', 'Retorno em 3 dias.')->where('prontuario.adendos.0.autor', $vet->name.' (CRMV '.$vet->crmv.')'));

        // Auditoria: tudo registrado, e NENHUM texto clínico em nenhuma coluna.
        foreach (['abrir_prontuario', 'atualizar_prontuario', 'assinar_prontuario', 'adendar_prontuario'] as $tool) {
            $this->assertGreaterThan(0, AuditLog::where('tool', $tool)->where('resultado', 'sucesso')->count(), $tool);
        }
        $tudo = json_encode(AuditLog::all()->toArray(), JSON_UNESCAPED_UNICODE);
        foreach ([self::TEXTO, 'Gastroenterite leve.', 'Dieta leve e hidratação.', 'Retorno em 3 dias.', 'Mudei depois de assinar'] as $texto) {
            $this->assertStringNotContainsString($texto, $tudo, 'texto clínico vazou para a auditoria');
        }
    }

    public function test_so_o_autor_edita_e_assina_rascunho_e_so_o_dono_ve_o_de_outro_veterinario(): void
    {
        config(['sentinel.front' => 'react']);
        $autor = $this->usuarioClinica('veterinario');
        $outro = $this->usuarioClinica('veterinario');
        $dono = $this->usuarioClinica('dono');
        $pet = $this->pet();

        $this->actingAs($autor);
        $id = $this->abrir($pet->id);

        $this->actingAs($outro);
        $this->get('/prontuarios/'.$id)->assertNotFound();
        $this->postJson('/prontuarios/'.$id, $this->corpo())->assertNotFound();
        $this->postJson('/prontuarios/'.$id.'/assinar', ['crmv' => $outro->crmv, 'senha' => 'password'])->assertNotFound();
        $this->get('/pets/'.$pet->id)->assertInertia(fn ($x) => $x->count('prontuarios', 0));

        // O dono lê o rascunho alheio, mas não o edita nem assina.
        $this->actingAs($dono);
        $this->get('/prontuarios/'.$id)->assertOk()->assertInertia(fn ($x) => $x->where('permissoes.editar', false)->where('permissoes.assinar', false));
        $this->get('/pets/'.$pet->id)->assertInertia(fn ($x) => $x->count('prontuarios', 1)->where('prontuarios.0.editavel', false));
        $this->assertFalse(Prontuario::find($id)->estaAssinado());
    }

    public function test_tenant_isolado_e_vinculo_com_o_agendamento(): void
    {
        config(['sentinel.front' => 'react']);
        $vet = $this->usuarioClinica('veterinario');
        $this->actingAs($vet);
        $pet = $this->pet();
        $alheio = $this->pet($this->tutor(self::OUTRO_TENANT), self::OUTRO_TENANT, ['nome' => 'Alheio']);

        // Pet de outro tenant: o serviço recusa sem dizer que existe (422) e nada é criado.
        $this->postJson('/prontuarios', $this->corpo(['pet_id' => $alheio->id]))->assertStatus(422);
        $this->assertSame(0, Prontuario::count());
        $this->get('/prontuarios/novo?pet='.$alheio->id)->assertNotFound();

        $agendamento = $this->agendamentoDireto($pet, $vet, $this->item());
        $id = $this->abrir($pet->id, ['agendamento_id' => $agendamento->id]);
        $this->assertSame($agendamento->id, Prontuario::find($id)->agendamento_id);

        // Já tem prontuário: "novo" abre o existente em vez de duplicar; o agendamento é único.
        $this->get('/prontuarios/novo?pet='.$pet->id.'&agendamento='.$agendamento->id)->assertRedirect('/prontuarios/'.$id);
        $this->postJson('/prontuarios', $this->corpo(['pet_id' => $pet->id, 'agendamento_id' => $agendamento->id]))->assertStatus(422);

        // Prontuário de outro tenant não abre.
        $de_fora = $this->prontuarioDireto($alheio, $this->usuarioClinica('veterinario', self::OUTRO_TENANT), ['tenant_id' => self::OUTRO_TENANT]);
        $this->get('/prontuarios/'.$de_fora->id)->assertNotFound();
    }

    public function test_agenda_mostra_atender_so_nas_linhas_do_proprio_veterinario_e_o_crmv_vai_para_o_avatar(): void
    {
        config(['sentinel.front' => 'react']);
        $vet = $this->usuarioClinica('veterinario');
        $outro = $this->usuarioClinica('veterinario');
        $pet = $this->pet();
        $this->actingAs($vet);
        $meu = $this->agendamentoDireto($pet, $vet, $this->item(), ['inicio' => now()->setTime(10, 0), 'fim' => now()->setTime(10, 30), 'status' => 'agendado']);
        $deOutro = $this->agendamentoDireto($pet, $outro, $this->item(), ['inicio' => now()->setTime(11, 0), 'fim' => now()->setTime(11, 30), 'status' => 'agendado']);

        $this->get('/agenda')->assertOk()->assertInertia(fn ($x) => $x->where('temAtender', true)->where('usuario.crmv', $vet->crmv));
        $this->get('/agenda')->assertInertia(fn ($x) => $x
            ->where('dias.0.linhas', fn ($ls) => collect($ls)->firstWhere('id', $meu->id)['atender']['pet_id'] === $pet->id
                && collect($ls)->firstWhere('id', $deOutro->id)['atender'] === null));
    }

    public function test_ficha_do_pet_oferece_novo_prontuario_so_a_quem_atende(): void
    {
        config(['sentinel.front' => 'react']);
        $pet = $this->pet();

        $this->actingAs($this->usuarioClinica('veterinario'));
        $this->get('/pets/'.$pet->id)->assertInertia(fn ($x) => $x->where('podeAtender', true));

        $this->actingAs($this->usuarioClinica('dono', self::T, false));
        $this->get('/pets/'.$pet->id)->assertInertia(fn ($x) => $x->where('podeAtender', false)->has('prontuarios'));

        $this->actingAs($this->usuarioClinica('recepcao'));
        $this->get('/pets/'.$pet->id)->assertInertia(fn ($x) => $x->where('podeAtender', false)->where('prontuarios', null));
    }
}
