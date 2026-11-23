<?php

namespace Tests\Feature\Clinica;

use App\Models\AuditLog;
use App\Services\AuditoriaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CenarioClinica;
use Tests\Concerns\SimulaAgente;
use Tests\TestCase;

/**
 * Máscara de CPF e telefone (23/11): saem mascarados na auditoria, nas listas, nos cards e nas respostas do agente; a ficha
 * do tutor é o único lugar com o valor completo (a recepção precisa ligar). O banco continua guardando o valor inteiro.
 */
class MascaraNaClinicaTest extends TestCase
{
    use CenarioClinica, RefreshDatabase, SimulaAgente;

    private const FONE = '(19) 98765-4321';

    private const FONE_MASCARADO = '(19) 9****-*321';

    private const CPF = '123.456.789-00';

    private const CPF_MASCARADO = '123.***.**9-00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAgente();
    }

    private function tutorComDados(): \App\Models\Tutor
    {
        $tutor = $this->tutor(self::T, 'Marina Alves');
        $tutor->update(['telefone' => self::FONE, 'documento' => self::CPF]);

        return $tutor->fresh();
    }

    public function test_a_auditoria_grava_cpf_e_telefone_mascarados(): void
    {
        $this->logarComo('recepcao', self::T);

        $log = app(AuditoriaService::class)->registrar('criar_tutor', 'criar', 'Tutor', null,
            ['nome' => 'Marina', 'telefone' => self::FONE, 'documento' => self::CPF, 'cpf' => self::CPF], 'sucesso', null, 'recepcao', true)->fresh();

        $this->assertSame(self::FONE_MASCARADO, $log->parametros['telefone']);
        $this->assertSame(self::CPF_MASCARADO, $log->parametros['documento']);
        $this->assertSame(self::CPF_MASCARADO, $log->parametros['cpf']);
        $bruto = AuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString('98765', $bruto);
        $this->assertStringNotContainsString('456', $bruto);
    }

    public function test_a_lista_do_agente_sai_mascarada_e_o_banco_guarda_o_valor_inteiro(): void
    {
        $tutor = $this->tutorComDados();
        $this->logarComo('recepcao', self::T);

        $resposta = $this->postJson('/agente/consultar', ['tool' => 'consultar_tutores', 'filtros' => []])->assertOk();

        $linha = collect($resposta->json('resultado'))->firstWhere('id', $tutor->id);
        $this->assertSame(self::FONE_MASCARADO, $linha['telefone']);
        $this->assertSame(self::CPF_MASCARADO, $linha['documento']);
        $this->assertStringNotContainsString('98765', $resposta->getContent());
        $this->assertSame(self::FONE, $tutor->telefone, 'o banco guarda o telefone completo');
        $this->assertSame(self::CPF, $tutor->documento);
    }

    public function test_o_card_de_alterar_tutor_mostra_so_a_mascara(): void
    {
        $tutor = $this->tutorComDados();
        $this->logarComo('recepcao', self::T);

        $resposta = $this->postJson('/agente/formulario', ['tool' => 'atualizar_tutor', 'id' => $tutor->id])->assertOk();

        $campos = collect($resposta->json('formulario.campos') ?? $resposta->json('campos') ?? [])->keyBy('nome');
        $this->assertSame(self::FONE_MASCARADO, $campos['telefone']['valor']);
        $this->assertSame(self::CPF_MASCARADO, $campos['documento']['valor']);
        $this->assertStringNotContainsString('98765', $resposta->getContent());
    }

    public function test_o_card_de_agendar_mostra_o_telefone_do_tutor_mascarado(): void
    {
        $tutor = $this->tutorComDados();
        $pet = $this->pet($tutor);
        $this->logarComo('recepcao', self::T);

        $resposta = $this->postJson('/agente/formulario', ['tool' => 'atualizar_pet', 'id' => $pet->id])->assertOk();

        $this->assertStringContainsString(self::FONE_MASCARADO, $resposta->getContent());
        $this->assertStringNotContainsString('98765', $resposta->getContent());
    }

    public function test_a_ficha_do_tutor_mostra_o_telefone_completo(): void
    {
        $tutor = $this->tutorComDados();
        $this->logarComo('recepcao', self::T);

        $this->get('/tutores/'.$tutor->id)->assertOk()->assertSee(self::FONE)->assertDontSee(self::FONE_MASCARADO);
    }

    public function test_a_ficha_do_pet_mostra_o_telefone_do_tutor_completo_como_a_ficha_do_tutor(): void
    {
        $pet = $this->pet($this->tutorComDados());
        $this->logarComo('recepcao', self::T);

        $this->get('/pets/'.$pet->id)->assertOk()->assertSee(self::FONE)->assertDontSee(self::FONE_MASCARADO);
    }

    // ---- Máscara nunca é gravada: vale "manter o atual" ----

    public function test_mascara_devolvida_pelo_formulario_nao_e_gravada(): void
    {
        $tutor = $this->tutorComDados();
        $this->logarComo('recepcao', self::T);
        $proposta = (string) $this->comando('atualizar_tutor', ['id' => $tutor->id, 'nome' => 'Marina Souza'])->assertOk()->json('proposta');

        // O humano devolve o que o card mostrou (a máscara) e muda só o nome.
        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => [
            'nome' => 'Marina Souza', 'telefone' => self::FONE_MASCARADO, 'documento' => self::CPF_MASCARADO,
        ]])->assertOk()->json('token');
        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();

        $tutor = $tutor->fresh();
        $this->assertSame('Marina Souza', $tutor->nome, 'o campo comum foi gravado');
        $this->assertSame(self::FONE, $tutor->telefone, 'a máscara não substitui o telefone');
        $this->assertSame(self::CPF, $tutor->documento, 'a máscara não substitui o documento');
    }

    public function test_mascara_na_tool_do_agente_nao_e_gravada_ao_atualizar_nem_ao_criar(): void
    {
        $tutor = $this->tutorComDados();
        $this->logarComo('recepcao', self::T);

        // A IA só lê valores mascarados e pode devolvê-los numa alteração: o campo mascarado é ignorado.
        $proposta = (string) $this->comando('atualizar_tutor', ['id' => $tutor->id, 'nome' => 'Marina Souza',
            'telefone' => self::FONE_MASCARADO, 'documento' => self::CPF_MASCARADO])->assertOk()->json('proposta');
        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->assertOk()->json('token');
        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();

        $tutor = $tutor->fresh();
        $this->assertSame('Marina Souza', $tutor->nome);
        $this->assertSame(self::FONE, $tutor->telefone);
        $this->assertSame(self::CPF, $tutor->documento);

        // Cadastro novo com máscara: nasce sem telefone e sem documento, nunca com a máscara.
        $proposta = (string) $this->comando('criar_tutor', ['nome' => 'Carlos Lima', 'telefone' => self::FONE_MASCARADO, 'documento' => self::CPF_MASCARADO])
            ->assertOk()->json('proposta');
        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->assertOk()->json('token');
        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();

        $novo = \App\Models\Tutor::query()->where('nome', 'Carlos Lima')->sole();
        $this->assertNull($novo->telefone);
        $this->assertNull($novo->documento);
    }

    // ---- Ponta a ponta: o servidor grava o valor REAL guardado na proposta, nunca o que o front devolve ----

    private const CPF_VALIDO = '529.982.247-25';

    private function aIaPropoeCadastrar(): string
    {
        $this->logarComo('recepcao', self::T);
        $resposta = $this->comando('criar_tutor', ['nome' => 'Carlos Lima', 'telefone' => self::FONE, 'documento' => self::CPF_VALIDO])
            ->assertOk()->assertJsonPath('tipo', 'confirmacao_pendente');

        // A proposta vai ao front mascarada: nem o telefone nem o CPF completos saem do servidor.
        $resposta->assertJsonPath('argumentos.telefone', self::FONE_MASCARADO)->assertJsonPath('argumentos.documento', '529.***.**7-25');
        $this->assertStringNotContainsString('98765', $resposta->getContent());
        $this->assertStringNotContainsString('982', $resposta->getContent());

        return (string) $resposta->json('proposta');
    }

    private function tutorCriado(): \App\Models\Tutor
    {
        return \App\Models\Tutor::query()->where('nome', 'Carlos Lima')->sole();
    }

    public function test_confirmar_direto_grava_telefone_e_cpf_completos_da_proposta(): void
    {
        $proposta = $this->aIaPropoeCadastrar();

        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->assertOk()->json('token');
        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();

        $this->assertSame(self::FONE, $this->tutorCriado()->telefone);
        $this->assertSame(self::CPF_VALIDO, $this->tutorCriado()->documento);
    }

    public function test_o_confirmar_ignora_valores_que_o_navegador_mande_junto(): void
    {
        $proposta = $this->aIaPropoeCadastrar();
        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->assertOk()->json('token');

        $this->postJson('/agente/confirmar', ['token' => $token, 'valores' => ['telefone' => '(11) 90000-0000', 'documento' => self::CPF_MASCARADO]])->assertOk();

        $this->assertSame(self::FONE, $this->tutorCriado()->telefone, 'vale o da sessão, não o do navegador');
        $this->assertSame(self::CPF_VALIDO, $this->tutorCriado()->documento);
    }

    public function test_confirmar_pelo_card_de_edicao_sem_tocar_no_telefone_grava_o_completo(): void
    {
        $proposta = $this->aIaPropoeCadastrar();

        // Revisar, "Editar" (o card volta ao formulário) e Revisar de novo: o humano muda só o nome. O card
        // mostra a máscara dos sensíveis; o front devolve vazio (o padrão) ou a própria máscara. Nos dois, "manter".
        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => []])->assertOk()->json('token');
        $this->postJson('/agente/editar', ['token' => $token])->assertOk()->assertJsonPath('proposta', $proposta);

        foreach ([['telefone' => '', 'documento' => ''], ['telefone' => self::FONE_MASCARADO, 'documento' => '529.***.**7-25']] as $i => $sensiveis) {
            $token = (string) $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => ['nome' => 'Carlos Lima'.($i ? '' : ''), ...$sensiveis]])
                ->assertOk()->json('token');
            if ($i === 0) {
                $this->postJson('/agente/editar', ['token' => $token])->assertOk(); // volta ao card e devolve a máscara
            }
        }
        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();

        $this->assertSame(self::FONE, $this->tutorCriado()->telefone);
        $this->assertSame(self::CPF_VALIDO, $this->tutorCriado()->documento);
    }

    public function test_confirmar_pelo_card_com_o_nome_editado_mantem_telefone_e_cpf_completos(): void
    {
        $proposta = $this->aIaPropoeCadastrar();

        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => ['nome' => 'Carlos Lima', 'email' => 'carlos@exemplo.com']])
            ->assertOk()->json('token');
        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();

        $tutor = $this->tutorCriado();
        $this->assertSame('carlos@exemplo.com', $tutor->email);
        $this->assertSame(self::FONE, $tutor->telefone);
        $this->assertSame(self::CPF_VALIDO, $tutor->documento);
    }

    public function test_o_telefone_novo_digitado_no_card_vale(): void
    {
        $tutor = $this->tutorComDados();
        $this->logarComo('recepcao', self::T);
        $proposta = (string) $this->comando('atualizar_tutor', ['id' => $tutor->id, 'nome' => 'Marina Alves'])->assertOk()->json('proposta');

        $token = (string) $this->postJson('/agente/revisar', ['proposta' => $proposta, 'valores' => ['telefone' => '(19) 91234-5678']])->assertOk()->json('token');
        $this->postJson('/agente/confirmar', ['token' => $token])->assertOk();

        $this->assertSame('(19) 91234-5678', $tutor->fresh()->telefone);
    }

    public function test_sem_valores_mascarados_remove_so_o_campo_sensivel_mascarado_em_qualquer_nivel(): void
    {
        $limpo = AuditoriaService::semValoresMascarados([
            'nome' => 'Ana', 'telefone' => self::FONE_MASCARADO, 'documento' => '123.456.789-00', 'cpf' => self::CPF_MASCARADO, 'salario' => '[redigido]',
            'obs' => 'texto com * asterisco', 'itens' => [['cpf' => self::CPF_MASCARADO, 'quantidade' => 2]],
        ]);

        $this->assertSame(['nome' => 'Ana', 'documento' => '123.456.789-00', 'obs' => 'texto com * asterisco', 'itens' => [['quantidade' => 2]]], $limpo);
    }
}
