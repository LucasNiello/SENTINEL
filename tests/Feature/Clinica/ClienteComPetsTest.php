<?php

namespace Tests\Feature\Clinica;

use App\Models\AuditLog;
use App\Models\Pet;
use App\Models\Tutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/** 27/11: cadastro unificado do cliente (tutor + vários pets), tudo ou nada, com auditoria por registro e o mesmo lote_id. */
class ClienteComPetsTest extends TestCase
{
    use CenarioClinica, RefreshDatabase, SimulaAgente;

    private function pets(): array
    {
        return [
            ['nome' => 'Thor', 'especie' => 'cao', 'peso_gramas' => '32000', 'microchip' => '111'],
            ['nome' => 'Mel', 'especie' => 'gato', 'observacoes' => 'Adotada'],
        ];
    }

    public function test_grava_tutor_e_pets_juntos_com_auditoria_por_registro_no_mesmo_lote(): void
    {
        $this->prepararAgente();
        $this->logarComo('recepcao', self::T);

        $token = $this->propor(['nome' => 'Maria', 'pets' => $this->pets()], 'cadastrar_cliente_com_pets');
        $this->confirmar($token)->assertOk()->assertJsonPath('tipo', 'resultado_escrita');

        $tutor = Tutor::where('nome', 'Maria')->sole();
        $this->assertSame(['Mel', 'Thor'], Pet::where('tutor_id', $tutor->id)->orderBy('nome')->pluck('nome')->all());
        $this->assertSame(self::T, (int) Pet::where('nome', 'Thor')->value('tenant_id'));
        $this->assertSame(32000, Pet::where('nome', 'Thor')->value('peso_gramas'));

        $lotes = AuditLog::query()->whereIn('tool', ['criar_tutor', 'criar_pet', 'cadastrar_cliente_com_pets'])->where('resultado', 'sucesso')->get();
        $this->assertCount(4, $lotes); // tutor + 2 pets + a linha geral da tool
        $this->assertCount(1, $lotes->pluck('lote_id')->unique());
        $this->assertNotNull($lotes->first()->lote_id);
    }

    public function test_erro_em_um_pet_nao_grava_nada(): void
    {
        $this->prepararAgente();
        $this->logarComo('recepcao', self::T);
        $pets = [...$this->pets(), ['nome' => 'Rex', 'especie' => 'cao', 'microchip' => '111']]; // microchip repetido

        $token = $this->propor(['nome' => 'Maria', 'pets' => $pets], 'cadastrar_cliente_com_pets');
        $this->confirmar($token);

        $this->assertSame(0, Tutor::where('nome', 'Maria')->count());
        $this->assertSame(0, Pet::count());
    }

    public function test_revisar_aponta_o_pet_e_o_campo_com_erro_na_lista(): void
    {
        $this->prepararAgente();
        $this->logarComo('recepcao', self::T);
        $proposta = $this->comando('cadastrar_cliente_com_pets', ['nome' => 'Maria'])->assertOk()->assertJsonPath('formulario.lista.campo', 'pets')->json('proposta');

        $resposta = $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => ['nome' => 'Maria', 'pets' => [['nome' => '', 'especie' => 'cao']]]]);
        $resposta->assertStatus(422)->assertJsonPath('formulario.lista.itens.0.erros.nome', fn ($v) => is_string($v) && $v !== '');
        $nomes = array_column($resposta->json('formulario.campos'), 'nome');
        $this->assertNotContains('pets', $nomes);
        $this->assertNotContains('pets.*.nome', $nomes);
    }

    public function test_o_tenant_dos_registros_e_o_do_usuario_logado(): void
    {
        $this->prepararAgente();
        $this->logarComo('recepcao', self::T);
        $token = $this->propor(['nome' => 'Ana', 'pets' => [['nome' => 'Bob', 'especie' => 'cao']]], 'cadastrar_cliente_com_pets');
        $this->confirmar($token)->assertOk();

        $this->assertSame(self::T, (int) Tutor::where('nome', 'Ana')->value('tenant_id'));
        $this->assertSame(self::T, (int) Pet::where('nome', 'Bob')->value('tenant_id'));
    }
}
