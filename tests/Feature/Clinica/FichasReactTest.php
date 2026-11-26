<?php

namespace Tests\Feature\Clinica;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

/** 26/11: fichas de Pet e Tutor em React: só os campos da tela, mesmo bloqueio de tenant e papel do Blade. */
class FichasReactTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    public function test_ficha_do_pet_e_do_tutor_em_react_sem_campos_de_sistema(): void
    {
        $this->logarComo('dono', self::T);
        $tutor = $this->tutor(self::T, 'Maria Souza');
        $pet = $this->pet($tutor, self::T, ['nome' => 'Bolt', 'alertas' => 'Morde']);

        config(['sentinel.front' => 'react']);
        $this->get('/pets/'.$pet->id)->assertOk()->assertInertia(fn ($p) => $p->component('Pet', false)
            ->where('pet.nome', 'Bolt')->where('pet.alertas', 'Morde')->where('tutor.nome', 'Maria Souza')
            ->missing('pet.tenant_id')->missing('pet.tutor_id')->missing('tutor.tenant_id')->where('podeEditar', true));
        $this->get('/tutores/'.$tutor->id)->assertOk()->assertInertia(fn ($p) => $p->component('Tutor', false)
            ->where('tutor.nome', 'Maria Souza')->missing('tutor.tenant_id')->has('pets.0', fn ($x) => $x->where('nome', 'Bolt')->etc()));
    }

    public function test_prontuario_na_ficha_so_para_veterinario_e_dono_com_auditoria(): void
    {
        config(['sentinel.front' => 'react']);
        $vet = $this->logarComo('veterinario', self::T);
        $pet = $this->pet($this->tutor(self::T, 'Maria Souza'), self::T, ['nome' => 'Bolt']);
        $this->prontuarioDireto($pet, $vet, ['diagnostico' => 'Gastroenterite leve.']);
        // Prontuário de OUTRO tenant apontando para o MESMO pet: só o filtro por tenant_id o barra (o por pet_id não).
        $this->prontuarioDireto($pet, $vet, ['tenant_id' => self::OUTRO_TENANT, 'diagnostico' => 'SEGREDO DE OUTRO TENANT']);

        $r = $this->get('/pets/'.$pet->id)->assertOk();
        $r->assertInertia(fn ($p) => $p->component('Pet', false)->count('prontuarios', 1)
            ->where('prontuarios.0.diagnostico', 'Gastroenterite leve.')->where('prontuarios.0.tipo', 'consulta')->where('prontuarios.0.assinado', false)
            ->missing('prontuarios.0.tenant_id')->missing('prontuarios.0.veterinario_id'));
        $this->assertStringNotContainsString('SEGREDO DE OUTRO TENANT', $r->getContent());
        $this->assertSame(1, \App\Models\AuditLog::where('tool', 'ver_prontuario_pet')->where('entidade_id', $pet->id)->where('resultado', 'sucesso')->count());

        // O dono também lê.
        $this->logarComo('dono', self::T);
        $this->get('/pets/'.$pet->id)->assertInertia(fn ($p) => $p->count('prontuarios', 1));
    }

    public function test_recepcao_e_demais_papeis_nao_recebem_nenhum_texto_clinico(): void
    {
        config(['sentinel.front' => 'react']);
        $vet = $this->logarComo('veterinario', self::T);
        $pet = $this->pet($this->tutor(self::T, 'Maria Souza'), self::T, ['nome' => 'Bolt']);
        $this->prontuarioDireto($pet, $vet, ['diagnostico' => 'Texto clínico reservado.', 'anamnese' => 'Anamnese reservada.']);

        // Cada papel tem um desfecho esperado: 200 sem texto clínico, ou a barreira de sempre (403/redirect do admin da clínica).
        foreach (['recepcao' => 200, 'atendente' => 200, 'vendedor' => 200, 'admin' => 302] as $papel => $esperado) {
            $this->logarComo($papel, self::T);
            $r = $this->get('/pets/'.$pet->id);
            $this->assertContains($r->status(), [$esperado, 403], $papel);
            if ($r->status() === 200) {
                $r->assertInertia(fn ($p) => $p->where('prontuarios', null));
            }
            $this->assertStringNotContainsString('Texto clínico reservado.', $r->getContent(), $papel);
            $this->assertStringNotContainsString('Anamnese reservada.', $r->getContent(), $papel);
        }
        $this->assertSame(0, \App\Models\AuditLog::where('tool', 'ver_prontuario_pet')->count(), 'só quem lê o prontuário gera a linha');
    }

    public function test_no_plano_b_blade_o_veterinario_nao_le_nem_audita_o_prontuario(): void
    {
        config(['sentinel.front' => 'blade']);
        $vet = $this->logarComo('veterinario', self::T);
        $pet = $this->pet($this->tutor(self::T, 'Maria Souza'), self::T, ['nome' => 'Bolt']);
        $this->prontuarioDireto($pet, $vet, ['diagnostico' => 'Texto clínico reservado.']);

        $r = $this->get('/pets/'.$pet->id)->assertOk();
        $this->assertStringNotContainsString('Texto clínico reservado.', $r->getContent());
        $this->assertSame(0, \App\Models\AuditLog::where('tool', 'ver_prontuario_pet')->count(), 'o Blade não mostra o prontuário: não há leitura a auditar');
    }

    public function test_ficha_de_outro_tenant_da_404_no_react(): void
    {
        $this->logarComo('dono', self::T);
        $alheio = $this->pet($this->tutor(self::OUTRO_TENANT), self::OUTRO_TENANT, ['nome' => 'Fantasma']);
        config(['sentinel.front' => 'react']);
        $this->get('/pets/'.$alheio->id)->assertNotFound();
        $this->get('/tutores/'.$alheio->tutor_id)->assertNotFound();
    }
}
