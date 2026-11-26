<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use App\Services\ConversaAgente;
use Tests\TestCase;

/** 26/11 (UX): ao recarregar o chat, card finalizado e tabela viram uma "tira" (título, desfecho, hora), sem dados e sem a nota interna. */
class HistoricoComTiraTest extends TestCase
{
    use RefreshDatabase;

    public function test_recarregar_o_chat_mostra_tiras_no_lugar_de_cards_e_tabelas(): void
    {
        config(['sentinel.front' => 'react']);
        $u = $this->logarComo('operador');

        $mensagens = [
            ['role' => 'user', 'content' => 'remarca o 23'],
            ['role' => 'assistant', 'content' => '[Proposta: Remarcar o agendamento #23 (id: 23) — confirmada e executada]', 'resumo' => 'Remarcar o agendamento #23 (id: 23)',
                'titulo' => 'Remarcar o agendamento #23', 'hora' => '14:32', 'desfecho' => 'confirmada', 'proposta' => 'uuid-secreto'],
            ['role' => 'user', 'content' => 'Confirmei a proposta no card.'],
            ['role' => 'assistant', 'content' => 'Agendamento remarcado. Número do registro gravado: 23.'],
            ['role' => 'user', 'content' => 'me mostre os agendamentos'],
            ['role' => 'assistant', 'content' => 'Mostrei ao usuário uma tabela com 11 linhas (consultar_agenda). Ela fica guardada no servidor', 'tabela' => 11],
            ['role' => 'user', 'content' => 'cancela o 25'],
            ['role' => 'assistant', 'content' => '[Proposta: Cancelar — ainda não confirmada]', 'resumo' => 'Cancelar', 'titulo' => 'Cancelar o agendamento #25', 'hora' => '14:40'],
        ];

        $r = $this->withSession(['agente_conversa' => ['identidade' => $u->id.':1', 'mensagens' => $mensagens]])->get('/agente')->assertOk();

        $r->assertInertia(fn (Assert $p) => $p->component('Agente', false)
            ->where('historico.1.tira', ['tipo' => 'proposta', 'titulo' => 'Remarcar o agendamento #23', 'desfecho' => 'confirmada', 'hora' => '14:32'])
            ->where('historico.2.content', 'Agendamento remarcado. Número do registro gravado: 23.')
            ->where('historico.4.tira', ['tipo' => 'consulta', 'linhas' => 11])
            ->where('historico.6.tira.desfecho', 'pendente')
            ->count('historico', 7));

        // A fala sintética, o resumo da proposta e o id dela não saem do servidor.
        $this->assertStringNotContainsString('uuid-secreto', $r->getContent());
        $this->assertStringNotContainsString('Confirmei a proposta no card', $r->getContent());
        $this->assertStringNotContainsString('Ela fica guardada no servidor', $r->getContent());
    }

    public function test_mensagens_antigas_sem_os_campos_novos_tambem_viram_tira(): void
    {
        config(['sentinel.front' => 'react']);
        $u = $this->logarComo('operador');
        $mensagens = [
            ['role' => 'assistant', 'content' => 'Mostrei ao usuário uma tabela com 1 linha (consultar_agenda). Ela fica guardada no servidor: para alterar...'],
            ['role' => 'assistant', 'content' => '[Proposta: Remarcar o agendamento #29 (profissional_id: 9; id: 29) — substituída por novo pedido. Nada foi gravado]'],
        ];

        $r = $this->withSession(['agente_conversa' => ['identidade' => $u->id.':1', 'mensagens' => $mensagens]])->get('/agente')->assertOk();
        $r->assertInertia(fn (Assert $p) => $p->where('historico.0.tira', ['tipo' => 'consulta', 'linhas' => 1])
            ->where('historico.1.tira', ['tipo' => 'proposta', 'titulo' => 'Remarcar o agendamento #29', 'desfecho' => 'substituida', 'hora' => null]));
        $this->assertStringNotContainsString('profissional_id', $r->getContent());
    }

    public function test_fluxo_real_guarda_titulo_e_desfecho_e_resultado_de_card_fora_do_chat_fica_oculto(): void
    {
        config(['sentinel.front' => 'react']);
        $this->logarComo('operador');
        $conversa = app(ConversaAgente::class);
        $sessao = app('session.store');

        // Card do chat: proposta guardada, ligada e depois confirmada.
        $conversa->guardar($sessao, 'remarca o 23', ['tipo' => 'confirmacao_pendente', 'tool' => 'remarcar_agendamento',
            'formulario' => ['titulo' => 'Remarcar o agendamento #23'], 'argumentos' => ['id' => 23]]);
        $conversa->vincularProposta($sessao, 'prop-1');
        $this->assertTrue($conversa->marcarProposta($sessao, 'prop-1', 'confirmada'));
        $conversa->registrarResultado($sessao, 'Agendamento remarcado.', 23);

        // Card aberto em outra tela: nenhuma fala ligada; o resultado vai para a IA mas não aparece na tela.
        $this->assertFalse($conversa->marcarProposta($sessao, 'prop-de-outra-tela', 'confirmada'));
        $conversa->registrarResultado($sessao, 'Cliente cadastrado.', 7, null, true);

        // Lote: sem card de proposta, deixa a tira pelo título.
        $conversa->registrarResultado($sessao, 'Pronto, cancelei os 11 agendamentos que você marcou.', null, 'Cancelar 11 agendamentos');

        $r = $this->withSession($sessao->all())->get('/agente')->assertOk();
        $r->assertInertia(fn (Assert $p) => $p
            ->where('historico.1.tira.titulo', 'Remarcar o agendamento #23')->where('historico.1.tira.desfecho', 'confirmada')->has('historico.1.tira.hora')
            ->where('historico.2.content', 'Agendamento remarcado.')
            ->where('historico.3.tira.titulo', 'Cancelar 11 agendamentos')->where('historico.3.tira.desfecho', 'confirmada')
            ->count('historico', 4));
        $this->assertStringNotContainsString('Cliente cadastrado', $r->getContent());
        $this->assertStringNotContainsString('(id: ', $r->getContent());
        $this->assertStringNotContainsString('[Proposta:', $r->getContent());
    }
}
