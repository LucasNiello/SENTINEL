<?php

namespace Tests\Feature;

use App\Services\ConfirmacoesPendentes;
use App\Services\ConversaAgente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** 26/11 (UX): recarregar a página não apaga o trabalho; a conversa na tela vale 2 h, e sai com logout ou "Nova conversa". */
class ConversaQueSobreviveTest extends TestCase
{
    use RefreshDatabase;

    private function consulta(int $linhas = 3): array
    {
        return ['tipo' => 'resultado_leitura', 'tool' => 'consultar_agenda', 'frase' => 'Encontrei '.$linhas.' agendamentos.', 'colunas' => ['id', 'pet'],
            'resultado' => array_map(fn ($i) => ['id' => $i, 'pet' => 'Bolt'.$i, 'status' => 'agendado'], range(1, $linhas)), 'exportar' => 'uuid-x',
            'lista' => ['frase' => 'Mostrei ao usuário uma tabela com '.$linhas.' linhas (consultar_agenda).', 'entidade' => 'agendamento', 'linhas' => range(1, $linhas)]];
    }

    private function abrir(array $sessao)
    {
        return $this->withSession($sessao)->get('/agente');
    }

    public function test_a_tabela_volta_com_as_linhas_e_a_frase_dentro_do_prazo(): void
    {
        config(['sentinel.front' => 'react']);
        $this->logarComo('operador');
        $conversa = app(ConversaAgente::class);
        $sessao = app('session.store');

        $resposta = $this->consulta(3);
        $conversa->guardar($sessao, 'agendamentos do mês', $resposta);
        unset($resposta['lista']);
        $conversa->guardarTela($sessao, $resposta);

        $this->abrir($sessao->all())->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('historico.0.content', 'agendamentos do mês')
            ->where('historico.1.consulta.frase', 'Encontrei 3 agendamentos.')
            ->where('historico.1.consulta.resultado.2.pet', 'Bolt3')
            ->count('historico.1.consulta.resultado', 3));

        // A IA continua recebendo só texto, sem as linhas.
        $paraIa = json_encode($conversa->ler($sessao));
        $this->assertStringNotContainsString('Bolt1', $paraIa);
    }

    public function test_card_pendente_volta_com_botoes_so_se_a_proposta_ainda_existe(): void
    {
        config(['sentinel.front' => 'react']);
        $this->logarComo('operador');
        $conversa = app(ConversaAgente::class);
        $sessao = app('session.store');
        $id = app(ConfirmacoesPendentes::class)->guardarRascunho($sessao, 'remarcar_agendamento', ['id' => 23]);

        $card = ['tipo' => 'confirmacao_pendente', 'tool' => 'remarcar_agendamento', 'formulario' => ['titulo' => 'Remarcar o agendamento #23', 'campos' => []], 'argumentos' => ['id' => 23]];
        $conversa->guardar($sessao, 'remarca o 23', $card);
        $conversa->vincularProposta($sessao, $id);
        $conversa->guardarTela($sessao, [...$card, 'proposta' => $id]);

        $this->abrir($sessao->all())->assertInertia(fn (Assert $p) => $p->where('historico.1.proposta.proposta', $id)->where('historico.1.proposta.formulario.titulo', 'Remarcar o agendamento #23'));

        // Sem o rascunho (descartado), vira a tira "não finalizado": nunca um card sem proposta por trás.
        $dados = $sessao->all();
        unset($dados['confirmacoes']);
        $this->flushSession();
        $this->abrir($dados)->assertInertia(fn (Assert $p) => $p->where('historico.1.tira.desfecho', 'pendente')->missing('historico.1.proposta'));
    }

    public function test_lote_encerra_a_selecao_e_marca_as_linhas_canceladas(): void
    {
        config(['sentinel.front' => 'react']);
        $this->logarComo('operador');
        $conversa = app(ConversaAgente::class);
        $sessao = app('session.store');

        $resposta = $this->consulta(3);
        $resposta['selecao'] = ['url' => '/agente/agenda/cancelar-lote', 'rotulo' => 'Cancelar'];
        $conversa->guardar($sessao, 'cancela', $resposta);
        unset($resposta['lista']);
        $conversa->guardarTela($sessao, $resposta);
        $conversa->encerrarSelecao($sessao, [1, 2]);

        $this->abrir($sessao->all())->assertInertia(fn (Assert $p) => $p
            ->missing('historico.1.consulta.selecao')->where('historico.1.consulta.resultado.0.status', 'cancelado')
            ->where('historico.1.consulta.resultado.2.status', 'agendado'));
    }

    public function test_passadas_duas_horas_a_proxima_requisicao_comeca_uma_conversa_nova(): void
    {
        config(['sentinel.front' => 'react']);
        $u = $this->logarComo('operador');
        $dados = ['identidade' => $u->id.':1', 'em' => now()->subMinutes(121)->getTimestamp(), 'mensagens' => [['role' => 'user', 'content' => 'oi'], ['role' => 'assistant', 'content' => 'olá']]];

        $this->abrir(['agente_conversa' => $dados])->assertInertia(fn (Assert $p) => $p->count('historico', 0));

        $dados['em'] = now()->subMinutes(119)->getTimestamp();
        $this->abrir(['agente_conversa' => $dados])->assertInertia(fn (Assert $p) => $p->count('historico', 2));
    }

    public function test_nova_conversa_apaga_o_historico(): void
    {
        config(['sentinel.front' => 'react']);
        $u = $this->logarComo('operador');
        $dados = ['identidade' => $u->id.':1', 'em' => now()->getTimestamp(), 'mensagens' => [['role' => 'user', 'content' => 'oi'], ['role' => 'assistant', 'content' => 'olá']]];

        $this->withSession(['agente_conversa' => $dados])->postJson('/agente/nova')->assertOk()->assertJson(['tipo' => 'ok']);
        $this->get('/agente')->assertInertia(fn (Assert $p) => $p->count('historico', 0));
    }

    public function test_marcar_proposta_de_conversa_vencida_nao_renova_o_prazo(): void
    {
        $u = $this->logarComo('operador');
        $conversa = app(ConversaAgente::class);
        $sessao = app('session.store');
        $velho = now()->subMinutes(130)->getTimestamp();
        $sessao->put('agente_conversa', ['identidade' => $u->id.':1', 'em' => $velho, 'mensagens' => [
            ['role' => 'user', 'content' => 'x'], ['role' => 'assistant', 'content' => '[Proposta: A — ainda não confirmada]', 'resumo' => 'A', 'proposta' => 'p1', 'titulo' => 'A'],
        ]]);

        $this->assertFalse($conversa->marcarProposta($sessao, 'p1', 'substituida'));
        $this->assertSame([], $conversa->lerParaTela($sessao), 'conversa vencida começa do zero');
        $this->assertNull($sessao->get('agente_conversa'), 'e não é regravada com prazo novo');
    }

    public function test_conversa_de_outro_usuario_ou_tenant_nunca_aparece_e_sem_em_vale_como_recente(): void
    {
        config(['sentinel.front' => 'react']);
        $u = $this->logarComo('operador');
        $mensagens = [['role' => 'user', 'content' => 'segredo do outro'], ['role' => 'assistant', 'content' => 'ok', 'tela' => ['tipo' => 'consulta', 'dados' => ['resultado' => [['pet' => 'Alheio']]]]]];

        // Outro usuário/tenant: apagada, nada vaza.
        $r = $this->abrir(['agente_conversa' => ['identidade' => ($u->id + 99).':1', 'em' => now()->getTimestamp(), 'mensagens' => $mensagens]])->assertOk();
        $r->assertInertia(fn (Assert $p) => $p->count('historico', 0));
        $this->assertStringNotContainsString('Alheio', $r->getContent());

        // Sessão antiga, sem "em": não quebra e conta como recente.
        $this->flushSession();
        $this->abrir(['agente_conversa' => ['identidade' => $u->id.':1', 'mensagens' => [['role' => 'user', 'content' => 'oi']]]])
            ->assertInertia(fn (Assert $p) => $p->count('historico', 1));
    }

    public function test_o_teto_de_tamanho_derruba_a_tela_das_mais_antigas_e_deixa_o_texto(): void
    {
        $this->logarComo('operador');
        $conversa = app(ConversaAgente::class);
        $sessao = app('session.store');
        $grande = $this->consulta(200);
        foreach ($grande['resultado'] as $i => $l) {
            $grande['resultado'][$i]['pet'] = str_repeat('x', 1200);
        }
        foreach (range(1, 4) as $n) {
            $conversa->guardar($sessao, "consulta $n", $grande);
            $semLista = $grande;
            unset($semLista['lista']);
            $conversa->guardarTela($sessao, $semLista);
        }

        $tudo = $conversa->lerParaTela($sessao);
        $this->assertArrayNotHasKey('tela', $tudo[1], 'a mais antiga perde a tela');
        $this->assertArrayHasKey('tela', end($tudo), 'a mais nova mantém');
        $this->assertSame('consulta 1', $tudo[0]['content'], 'o texto fica');
    }
}
