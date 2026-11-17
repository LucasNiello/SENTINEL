<?php

namespace Tests\Feature\Clinica;

use App\Models\Agendamento;
use App\Models\CategoriaLancamento;
use App\Models\Cobranca;
use App\Models\CobrancaItem;
use App\Models\Estorno;
use App\Models\Lancamento;
use App\Models\Pet;
use App\Models\Venda;
use App\Models\VendaItem;
use App\Services\ResultadosClinica;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

/**
 * Front de 14/11 (F2): telas de LEITURA da clínica — /agenda, /cobrancas, /vendas, /pets/{id} e /resultados.
 * Prova quem abre cada tela (o papel da tool de leitura; Resultados só o dono), que outro tenant não aparece,
 * que a ficha do pet não mostra nada do prontuário e que as ações só aparecem para quem pode executá-las.
 */
class TelasDeLeituraDaClinicaTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    /** @return array<string, array{0: string, 1: string, 2: int}> */
    public static function quemAbre(): array
    {
        $esperado = [
            // Decisão do Lucas (15/11), tabela da FASE oito: o vendedor não abre a agenda; o veterinário vê as cobranças.
            '/agenda' => ['atendente' => 200, 'vendedor' => 403, 'recepcao' => 200, 'veterinario' => 200, 'dono' => 200],
            '/cobrancas' => ['atendente' => 200, 'vendedor' => 403, 'recepcao' => 200, 'veterinario' => 200, 'dono' => 200],
            '/vendas' => ['atendente' => 200, 'vendedor' => 200, 'recepcao' => 200, 'veterinario' => 200, 'dono' => 200],
            '/pets/{pet}' => ['atendente' => 200, 'vendedor' => 200, 'recepcao' => 200, 'veterinario' => 200, 'dono' => 200],
            '/resultados' => ['atendente' => 403, 'vendedor' => 403, 'recepcao' => 403, 'veterinario' => 403, 'dono' => 200],
        ];
        $casos = [];
        foreach ($esperado as $rota => $papeis) {
            foreach ($papeis as $papel => $status) {
                $casos["{$rota} como {$papel}"] = [$rota, $papel, $status];
            }
        }

        return $casos;
    }

    #[DataProvider('quemAbre')]
    public function test_cada_papel_abre_so_as_telas_que_pode(string $rota, string $papel, int $status): void
    {
        $pet = $this->pet();
        $this->logarComo($papel, self::T);

        $this->get(str_replace('{pet}', (string) $pet->id, $rota))->assertStatus($status);
    }

    public function test_sem_login_vai_para_o_login_admin_da_clinica_vai_para_a_supervisao_e_fora_da_clinica_nao_existe(): void
    {
        $pet = $this->pet();
        $rotas = ['/agenda', '/cobrancas', '/vendas', '/resultados', "/pets/{$pet->id}"];

        foreach ($rotas as $rota) {
            $this->get($rota)->assertRedirect('/login');
        }

        $this->logarComo('admin', self::T);
        foreach ($rotas as $rota) {
            $this->get($rota)->assertRedirect('/supervisao');
        }

        // Tenant 1 é do domínio antigo (contabilidade): as telas da clínica não existem lá, nem para o admin.
        $this->logarComo('admin', 1);
        foreach ($rotas as $rota) {
            $this->get($rota)->assertNotFound();
        }
    }

    public function test_agenda_mostra_o_dia_e_a_semana_so_do_tenant_e_filtra_por_setor(): void
    {
        $hoje = CarbonImmutable::now()->startOfDay();
        $vet = $this->usuarioClinica('veterinario');
        $this->agendamentoDireto($this->pet(null, self::T, ['nome' => 'Thor']), $vet, $this->item(),
            ['inicio' => $hoje->setTime(10, 0), 'fim' => $hoje->setTime(10, 30), 'status' => 'agendado']);
        $banho = $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho porte grande']);
        $this->agendamentoDireto($this->pet(null, self::T, ['nome' => 'Amora']), $this->usuarioClinica('atendente'), $banho,
            ['inicio' => $hoje->addDays(8)->setTime(9, 0), 'fim' => $hoje->addDays(8)->setTime(10, 0), 'status' => 'confirmado']);
        $this->agendamentoDireto($this->pet(null, self::OUTRO_TENANT, ['nome' => 'Intruso']), $this->usuarioClinica('veterinario', self::OUTRO_TENANT),
            $this->item([], self::OUTRO_TENANT), ['inicio' => $hoje->setTime(11, 0), 'fim' => $hoje->setTime(11, 30), 'status' => 'agendado']);

        $this->logarComo('recepcao', self::T);

        $this->get('/agenda')->assertOk()
            ->assertSee('Thor')->assertSee('10:00–10:30')->assertSee('Consulta clínica')->assertSee('Agendado')
            ->assertDontSee('Intruso')->assertDontSee('Amora')
            ->assertSee('data-tool="alterar_status_agendamento"', false)
            ->assertSee('data-tool="agendar_atendimento"', false);
        $this->get('/agenda?visao=semana&dia='.$hoje->addDays(8)->toDateString())->assertOk()
            ->assertSee('Amora')->assertSee('Confirmado')->assertDontSee('Thor')->assertDontSee('Intruso');
        $this->get('/agenda?setor=petshop')->assertOk()->assertDontSee('Thor')->assertSee('Nenhum horário neste dia.');
        // Filtro inválido é ignorado: a tela abre no padrão, sem erro.
        $this->get('/agenda?visao=ano&dia=amanha&setor=x')->assertOk()->assertSee('Thor');
    }

    /** Decisão do Lucas (15/11), tabela da FASE oito: o atendente só vê a agenda de banho e tosa, nem pedindo a da clínica. */
    public function test_atendente_ve_so_a_agenda_de_banho_e_tosa_na_tela_e_na_ficha(): void
    {
        $hoje = CarbonImmutable::now()->startOfDay();
        $pet = $this->pet(null, self::T, ['nome' => 'Thor']);
        $this->agendamentoDireto($pet, $this->usuarioClinica('veterinario'), $this->item(),
            ['inicio' => $hoje->setTime(10, 0), 'fim' => $hoje->setTime(10, 30), 'status' => 'agendado']);
        $banho = $this->item(['setor' => 'petshop', 'categoria' => 'banho', 'nome_comercial' => 'Banho porte grande']);
        $this->agendamentoDireto($pet, $this->usuarioClinica('atendente'), $banho,
            ['inicio' => $hoje->setTime(14, 0), 'fim' => $hoje->setTime(15, 0), 'status' => 'agendado']);

        $this->logarComo('atendente', self::T);
        foreach (['/agenda', '/agenda?setor=clinica', '/agenda?visao=semana&setor=clinica'] as $rota) {
            $this->get($rota)->assertOk()->assertSee('Banho porte grande')->assertSee('14:00–15:00')
                ->assertDontSee('Consulta clínica')->assertDontSee('10:00–10:30')
                ->assertSee('Agenda de <strong>banho e tosa</strong>', false)->assertDontSee('aria-label="Setor"', false);
        }
        $this->get("/pets/{$pet->id}")->assertOk()->assertSee('Banho porte grande')->assertDontSee('Consulta clínica');

        // A recepção vê os dois setores e escolhe.
        $this->logarComo('recepcao', self::T);
        $this->get('/agenda')->assertOk()->assertSee('Banho porte grande')->assertSee('Consulta clínica')->assertSee('aria-label="Setor"', false);
        $this->get('/agenda?setor=clinica')->assertOk()->assertSee('Consulta clínica')->assertDontSee('Banho porte grande');

        // O vendedor não abre a agenda nem vê o bloco de agendamentos na ficha.
        $this->logarComo('vendedor', self::T);
        $this->get('/agenda')->assertForbidden();
        $this->get("/pets/{$pet->id}")->assertOk()->assertDontSee('id="titulo-agenda"', false)->assertDontSee('Banho porte grande');
    }

    public function test_veterinario_ve_as_cobrancas_mas_nao_recebe_cancela_nem_estorna(): void
    {
        $this->cobranca('Thor', 'fechada', 12000);
        $this->cobranca('Luna', 'paga', 30000);

        $this->logarComo('veterinario', self::T);
        $this->get('/cobrancas')->assertOk()->assertSee('Thor')->assertSee('Luna')
            ->assertDontSee('data-tool="receber_cobranca"', false)->assertDontSee('data-tool="cancelar_cobranca"', false)
            ->assertDontSee('data-tool="estornar_cobranca"', false);
        $this->postJson('/agente/consultar', ['tool' => 'consultar_cobrancas'])->assertOk();
    }

    public function test_cobrancas_mostram_total_estornado_e_liquido_so_do_tenant_com_a_acao_de_cada_situacao(): void
    {
        $paga = $this->cobranca('Thor', 'paga', 30000);
        Estorno::create(['cobranca_id' => $paga->id, 'solicitado_por' => 1, 'autorizado_por' => 1, 'motivo' => 'Cobrado a mais', 'valor_centavos' => 5000, 'tenant_id' => self::T]);
        $this->cobranca('Luna', 'fechada', 12000);
        $this->cobranca('Intruso', 'paga', 99900, self::OUTRO_TENANT);

        $this->logarComo('recepcao', self::T);

        $this->get('/cobrancas')->assertOk()
            ->assertSee('Thor')->assertSee('R$ 300,00')->assertSee('R$ 50,00')->assertSee('R$ 250,00')
            ->assertSee('Luna')->assertSee('A receber')
            ->assertDontSee('Intruso')->assertDontSee('R$ 999,00')
            // soma do que está listado: 300 + 120 de total; líquido só do que foi pago
            ->assertSeeInOrder(['Total listado', 'R$ 420,00', 'Estornado', 'R$ 50,00', 'Líquido', 'R$ 250,00'])
            ->assertSee('data-tool="estornar_cobranca" data-id="'.$paga->id.'"', false)
            ->assertSee('data-tool="receber_cobranca"', false)
            ->assertSee('data-tool="cancelar_cobranca"', false);
        $this->get('/cobrancas?status=fechada')->assertOk()->assertSee('Luna')->assertDontSee('Thor');
        $this->get('/cobrancas?status=qualquer')->assertOk()->assertSee('Thor');
    }

    public function test_vendas_mostram_total_estornado_e_liquido_so_do_tenant_e_o_estorno_so_para_quem_pede(): void
    {
        $vendedor = $this->usuarioClinica('vendedor');
        $venda = Venda::create(['setor' => 'petshop', 'vendedor_id' => $vendedor->id, 'status' => 'paga', 'total_centavos' => 21000,
            'forma_pagamento' => 'pix', 'pago_em' => now(), 'tenant_id' => self::T]);
        Estorno::create(['venda_id' => $venda->id, 'solicitado_por' => 1, 'autorizado_por' => 1, 'motivo' => 'Devolução', 'valor_centavos' => 7000, 'tenant_id' => self::T]);
        Venda::create(['setor' => 'petshop', 'vendedor_id' => $vendedor->id, 'status' => 'paga', 'total_centavos' => 88800,
            'forma_pagamento' => 'pix', 'pago_em' => now(), 'tenant_id' => self::OUTRO_TENANT]);

        // O vendedor vê as vendas, mas não pede estorno.
        $this->actingAs($vendedor);
        $this->get('/vendas')->assertOk()
            ->assertSee('R$ 210,00')->assertSee('R$ 70,00')->assertSee('R$ 140,00')->assertSee('Pix')
            ->assertDontSee('R$ 888,00')
            ->assertDontSee('data-tool="estornar_venda"', false);

        $this->logarComo('atendente', self::T);
        $this->get('/vendas')->assertOk()->assertSee('data-tool="estornar_venda" data-id="'.$venda->id.'"', false);
        $this->get('/vendas?setor=clinica')->assertOk()->assertSee('Nenhum registro com estes filtros.');
    }

    public function test_ficha_do_pet_mostra_cadastro_alertas_agenda_e_contas_e_nada_do_prontuario(): void
    {
        $vet = $this->usuarioClinica('veterinario');
        $pet = $this->pet($this->tutor(self::T, 'Ana Souza'), self::T, ['nome' => 'Thor', 'raca' => 'Labrador', 'alertas' => 'Puxa forte na guia', 'microchip' => '985112003456781']);
        $agendamento = $this->agendamentoDireto($pet, $vet, $this->item());
        Cobranca::create(['agendamento_id' => $agendamento->id, 'setor' => 'clinica', 'status' => 'paga', 'total_centavos' => 15000, 'pago_em' => now(),
            'forma_pagamento' => 'pix', 'tenant_id' => self::T]);
        // Conteúdo clínico, que não pode aparecer em lugar nenhum da ficha.
        $this->prontuarioDireto($pet, $vet, ['agendamento_id' => $agendamento->id]);

        $this->logarComo('recepcao', self::T);

        $this->get("/pets/{$pet->id}")->assertOk()
            ->assertSee('Thor')->assertSee('Labrador')->assertSee('985112003456781')->assertSee('Ana Souza')
            ->assertSee('Puxa forte na guia')
            ->assertSee('id="titulo-agenda"', false)->assertSee('Consulta clínica')->assertSee('Realizado')
            ->assertSee('id="titulo-cobrancas"', false)->assertSee('R$ 150,00')
            ->assertSee('data-tool="atualizar_pet" data-id="'.$pet->id.'"', false)
            ->assertDontSee('Tutor relata apatia')->assertDontSee('Gastroenterite')->assertDontSee('anamnese')->assertDontSee('diagnostico');

        // O vendedor não consulta cobranças pelo agente nem vê a agenda: os dois blocos não aparecem na ficha.
        $this->logarComo('vendedor', self::T);
        $this->get("/pets/{$pet->id}")->assertOk()->assertSee('Thor')
            ->assertDontSee('id="titulo-cobrancas"', false)->assertDontSee('R$ 150,00')->assertDontSee('id="titulo-agenda"', false)
            ->assertSee('id="titulo-vendas"', false);
    }

    public function test_ficha_de_pet_de_outro_tenant_da_lixeira_ou_inexistente_nao_existe(): void
    {
        $deFora = $this->pet(null, self::OUTRO_TENANT, ['nome' => 'Intruso']);
        $naLixeira = $this->pet();
        $naLixeira->delete();

        $this->logarComo('dono', self::T);

        $this->get("/pets/{$deFora->id}")->assertNotFound();
        $this->get("/pets/{$naLixeira->id}")->assertNotFound();
        $this->get('/pets/999999')->assertNotFound();
        $this->get('/pets/abc')->assertNotFound();
    }

    public function test_resultados_somam_so_o_tenant_e_o_periodo(): void
    {
        $this->cenarioDeResultados();
        $hoje = CarbonImmutable::now();

        $r = app(ResultadosClinica::class)->resumo(self::T, $hoje->startOfDay(), $hoje->endOfDay());

        $this->assertSame([36000, 2, 5000, 31000, 18000, 10000, 21000], [$r['faturamento'], $r['pagamentos'], $r['estornado'], $r['liquido'], $r['ticket_medio'], $r['despesas'], $r['lucro']]);
        $this->assertSame([15000, 0, 15000], [$r['setores']['clinica']['faturamento'], $r['setores']['clinica']['estornado'], $r['setores']['clinica']['liquido']]);
        $this->assertSame([21000, 5000, 16000], [$r['setores']['petshop']['faturamento'], $r['setores']['petshop']['estornado'], $r['setores']['petshop']['liquido']]);
        $this->assertSame(['total' => 12000, 'cobrancas' => 1, 'vendas' => 0], $r['a_receber']);
        $this->assertSame([['descricao' => 'Ração 10 kg', 'quantidade' => 1, 'total' => 21000], ['descricao' => 'Consulta clínica', 'quantidade' => 1, 'total' => 15000]], $r['top_itens']);

        // Ontem: nada foi pago; o "a receber" é de hoje, não do período.
        $ontem = app(ResultadosClinica::class)->resumo(self::T, $hoje->subDay()->startOfDay(), $hoje->subDay()->endOfDay());
        $this->assertSame([0, 0, 0, 0, 0, 12000], [$ontem['faturamento'], $ontem['estornado'], $ontem['despesas'], $ontem['lucro'], $ontem['ticket_medio'], $ontem['a_receber']['total']]);
    }

    public function test_tela_de_resultados_do_dono_mostra_os_numeros_do_periodo(): void
    {
        $this->cenarioDeResultados();
        $this->logarComo('dono', self::T);

        $this->get('/resultados?periodo=dia')->assertOk()
            ->assertSeeInOrder(['Faturamento', 'R$ 360,00', 'Estornado', 'R$ 50,00', 'Despesas', 'R$ 100,00', 'Lucro', 'R$ 210,00', 'Ticket médio', 'R$ 180,00', 'A receber hoje', 'R$ 120,00'])
            ->assertSee('Ração 10 kg')
            ->assertDontSee('R$ 9.990,00')->assertDontSee('Item de fora');
        $this->get('/resultados')->assertOk()->assertSee('R$ 360,00');
        $this->get('/resultados?periodo=semana')->assertOk()->assertSee('R$ 360,00');
        $this->get('/resultados?periodo=dia&dia='.CarbonImmutable::now()->subDay()->toDateString())->assertOk()
            ->assertSee('Nenhum pagamento no período.')->assertDontSee('R$ 360,00');
        $this->get('/resultados?periodo=seculo&dia=x')->assertOk();
    }

    public function test_estorno_de_cobranca_entra_uma_vez_so_e_o_estorno_antigo_so_pela_coluna(): void
    {
        $hoje = CarbonImmutable::now();
        // Estorno total pela T2: a cobrança guarda o valor na coluna E existe a linha em "estornos". Conta uma vez.
        $nova = $this->cobranca('Thor', 'paga', 15000);
        $nova->forceFill(['estorno_valor_centavos' => 15000, 'estornado_em' => now()])->save();
        Estorno::create(['cobranca_id' => $nova->id, 'solicitado_por' => 1, 'autorizado_por' => 1, 'motivo' => 'Cobrado a mais', 'valor_centavos' => 15000, 'tenant_id' => self::T]);
        // Estorno de antes da T2: só a coluna, sem linha em "estornos".
        $this->cobranca('Luna', 'paga', 9000)->forceFill(['estorno_valor_centavos' => 4000, 'estornado_em' => now()])->save();
        // Outro tenant não entra, nem pela linha nem pela coluna.
        $fora = $this->cobranca('Intruso', 'paga', 50000, self::OUTRO_TENANT);
        $fora->forceFill(['estorno_valor_centavos' => 50000, 'estornado_em' => now()])->save();

        $r = app(ResultadosClinica::class)->resumo(self::T, $hoje->startOfDay(), $hoje->endOfDay());

        $this->assertSame([24000, 19000, 5000], [$r['faturamento'], $r['estornado'], $r['liquido']]);
        $this->assertSame(19000, $r['setores']['clinica']['estornado']);
        $this->assertSame(0, $r['setores']['petshop']['estornado']);
    }

    public function test_semana_vai_de_segunda_a_domingo_e_o_mes_do_dia_1_ao_ultimo(): void
    {
        $servico = app(ResultadosClinica::class);
        $quarta = CarbonImmutable::create(2026, 11, 18, 12, 0, 0, config('app.timezone'));

        [$inicio, $fim] = $servico->intervalo('semana', $quarta);
        $this->assertSame(['2026-11-16 00:00:00', '2026-11-22 23:59:59'], [$inicio->format('Y-m-d H:i:s'), $fim->format('Y-m-d H:i:s')]);
        [$inicio, $fim] = $servico->intervalo('mes', $quarta);
        $this->assertSame(['2026-11-01 00:00:00', '2026-11-30 23:59:59'], [$inicio->format('Y-m-d H:i:s'), $fim->format('Y-m-d H:i:s')]);
        [$inicio, $fim] = $servico->intervalo('dia', $quarta);
        $this->assertSame(['2026-11-18 00:00:00', '2026-11-18 23:59:59'], [$inicio->format('Y-m-d H:i:s'), $fim->format('Y-m-d H:i:s')]);

        // Pago no domingo às 23:30 entra na semana; na segunda seguinte, à 00:10, não. O último dia do mês entra no mês.
        foreach (['2026-11-22 23:30:00' => 10000, '2026-11-23 00:10:00' => 20000, '2026-11-30 22:00:00' => 40000, '2026-12-01 00:05:00' => 80000] as $pagoEm => $total) {
            $this->cobranca('Thor', 'paga', $total)->forceFill(['pago_em' => $pagoEm])->save();
        }
        $this->assertSame(10000, $servico->resumo(self::T, ...$servico->intervalo('semana', $quarta))['faturamento']);
        $this->assertSame(70000, $servico->resumo(self::T, ...$servico->intervalo('mes', $quarta))['faturamento']);
    }

    public function test_agenda_cobrancas_e_vendas_leem_pela_tool_e_ficam_na_auditoria(): void
    {
        $usuario = $this->logarComo('recepcao', self::T);

        foreach (['/agenda' => 'consultar_agenda', '/cobrancas' => 'consultar_cobrancas', '/vendas' => 'consultar_vendas'] as $rota => $tool) {
            $this->get($rota)->assertOk();
            $linha = \App\Models\AuditLog::query()->where('tool', $tool)->latest('id')->first();
            $this->assertNotNull($linha, $rota);
            $this->assertSame(['manual', 'consultar', $usuario->id, self::T], [$linha->origem, $linha->acao, $linha->user_id, $linha->tenant_id]);
        }
    }

    /** Decisão do Lucas (15/11): a ficha do pet e os Resultados gravam a leitura na auditoria, como as outras telas. */
    public function test_ficha_e_resultados_gravam_a_leitura_na_auditoria(): void
    {
        $pet = $this->pet();
        $dono = $this->logarComo('dono', self::T);

        $this->get("/pets/{$pet->id}")->assertOk();
        $this->get('/resultados?periodo=semana&dia=2026-11-18')->assertOk();

        $ficha = \App\Models\AuditLog::query()->where('tool', 'ver_ficha_pet')->sole();
        $this->assertSame(['manual', 'consultar', 'Pet', $pet->id, $dono->id, self::T, 'sucesso', 'dono'],
            [$ficha->origem, $ficha->acao, $ficha->entidade_tipo, $ficha->entidade_id, $ficha->user_id, $ficha->tenant_id, $ficha->resultado, $ficha->papel]);
        $resultados = \App\Models\AuditLog::query()->where('tool', 'ver_resultados')->sole();
        $this->assertSame(['manual', 'consultar', self::T], [$resultados->origem, $resultados->acao, $resultados->tenant_id]);
        $this->assertSame(['periodo' => 'semana', 'data_inicio' => '2026-11-16', 'data_fim' => '2026-11-22'], $resultados->parametros);

        // Pet de outro tenant (404) e papel sem acesso (403) não gravam leitura com sucesso.
        $this->get('/pets/'.$this->pet(null, self::OUTRO_TENANT)->id)->assertNotFound();
        $this->logarComo('recepcao', self::T);
        $this->get('/resultados')->assertForbidden();
        $this->assertSame(2, \App\Models\AuditLog::query()->whereIn('tool', ['ver_ficha_pet', 'ver_resultados'])->count());

        // A tela de auditoria do dono oferece as duas no filtro.
        $this->logarComo('dono', self::T);
        $this->get('/auditoria')->assertOk()->assertSee('ver_ficha_pet')->assertSee('ver_resultados');
    }

    public function test_ficha_nao_mostra_o_documento_do_tutor_e_numero_grande_demais_nao_existe(): void
    {
        $tutor = $this->tutor();
        $tutor->forceFill(['documento' => '39053344705', 'email' => 'ana@exemplo.com'])->save();
        $pet = $this->pet($tutor);

        $this->logarComo('recepcao', self::T);

        $this->get("/pets/{$pet->id}")->assertOk()->assertSee('ana@exemplo.com')
            ->assertDontSee('39053344705')->assertDontSee('390.533.447-05')->assertDontSee('Documento');
        $this->get('/pets/99999999999999999999')->assertNotFound();
    }

    public function test_paginas_que_gravam_auditoria_tem_teto_de_acessos_por_usuario(): void
    {
        $this->logarComo('recepcao', self::T);

        for ($i = 0; $i < 60; $i++) {
            $this->get('/vendas')->assertOk();
        }
        // O teto é um só para as três páginas, e o excesso não grava mais nenhuma linha de auditoria.
        $this->get('/vendas')->assertStatus(429);
        $this->get('/agenda')->assertStatus(429);
        $this->get('/cobrancas')->assertStatus(429);
        $this->assertSame(60, \App\Models\AuditLog::query()->whereIn('tool', ['consultar_vendas', 'consultar_agenda', 'consultar_cobrancas'])->count());
        // Navegar nessas páginas não gasta o teto do card (Revisar, formulário) nem trava as outras telas.
        $this->get('/cadastros')->assertOk();
        $this->postJson('/agente/consultar', ['tool' => 'consultar_tutores'])->assertOk();

        // Outro usuário tem o próprio teto.
        $this->logarComo('atendente', self::T);
        $this->get('/vendas')->assertOk();
    }

    public function test_agenda_avisa_quando_chega_ao_teto_de_200_horarios(): void
    {
        $inicio = CarbonImmutable::now()->startOfDay()->setTime(6, 0);
        $pet = $this->pet();
        $vet = $this->usuarioClinica('veterinario');
        $servico = $this->item();
        $linha = ['pet_id' => $pet->id, 'profissional_id' => $vet->id, 'setor' => 'clinica', 'catalogo_item_id' => $servico->id, 'status' => 'realizado',
            'origem' => 'normal', 'tenant_id' => self::T, 'created_at' => now(), 'updated_at' => now()];
        Agendamento::insert(array_map(fn (int $i) => [...$linha, 'inicio' => $inicio->addMinutes($i * 4), 'fim' => $inicio->addMinutes($i * 4 + 3)], range(0, 198)));

        $this->logarComo('recepcao', self::T);
        $this->get('/agenda')->assertOk()->assertSee('199 horário(s) no período')->assertDontSee('200 primeiros horários');

        Agendamento::insert([[...$linha, 'inicio' => $inicio->addMinutes(199 * 4), 'fim' => $inicio->addMinutes(199 * 4 + 3)]]);
        $this->get('/agenda')->assertOk()->assertSee('200 horário(s) no período')->assertSee('A agenda mostra só os 200 primeiros horários do período.');
    }

    public function test_menu_segue_o_papel(): void
    {
        $this->logarComo('dono', self::T);
        $this->get('/agente')->assertOk()
            ->assertSee('href="/agenda"', false)->assertSee('href="/cobrancas"', false)->assertSee('href="/vendas"', false)->assertSee('href="/resultados"', false);

        $this->logarComo('vendedor', self::T);
        $this->get('/agente')->assertOk()
            ->assertSee('href="/vendas"', false)
            ->assertDontSee('href="/agenda"', false)->assertDontSee('href="/cobrancas"', false)->assertDontSee('href="/resultados"', false);

        $this->logarComo('veterinario', self::T);
        $this->get('/agente')->assertOk()->assertSee('href="/agenda"', false)->assertSee('href="/cobrancas"', false)->assertDontSee('href="/resultados"', false);

        $this->logarComo('recepcao', self::T);
        $this->get('/agente')->assertOk()->assertSee('href="/cobrancas"', false)->assertDontSee('href="/resultados"', false);

        // Fora da clínica o menu não muda.
        $this->logarComo('admin', 1);
        $this->get('/agente')->assertOk()->assertDontSee('href="/agenda"', false)->assertDontSee('href="/resultados"', false);
    }

    public function test_cadastros_de_pets_levam_a_ficha(): void
    {
        $this->logarComo('recepcao', self::T);

        $this->get('/cadastros')->assertOk()->assertSee("href: '/pets/' + linha.id", false)->assertSee('Ver ficha');
    }

    private function cobranca(string $nomeDoPet, string $status, int $total, int $tenant = self::T): Cobranca
    {
        $agendamento = $this->agendamentoDireto($this->pet(null, $tenant, ['nome' => $nomeDoPet]), $this->usuarioClinica('veterinario', $tenant), $this->item([], $tenant));

        return Cobranca::create(['agendamento_id' => $agendamento->id, 'setor' => 'clinica', 'status' => $status, 'total_centavos' => $total,
            'pago_em' => $status === 'paga' ? now() : null, 'forma_pagamento' => $status === 'paga' ? 'pix' : null, 'tenant_id' => $tenant]);
    }

    /** Hoje no tenant 3: cobrança paga de R$ 150 (clínica), venda paga de R$ 210 com estorno de R$ 50 (petshop), R$ 120 a receber e R$ 100 de despesa. */
    private function cenarioDeResultados(): void
    {
        foreach ([self::T => 1, self::OUTRO_TENANT => 0] as $tenant => $doTenant) {
            $paga = $this->cobranca('Thor', 'paga', $doTenant ? 15000 : 999000, $tenant);
            CobrancaItem::create(['cobranca_id' => $paga->id, 'catalogo_item_id' => $this->item([], $tenant)->id, 'descricao' => $doTenant ? 'Consulta clínica' : 'Item de fora',
                'preco_unitario_centavos' => $paga->total_centavos, 'quantidade' => 1, 'subtotal_centavos' => $paga->total_centavos, 'baixou_estoque' => false, 'tenant_id' => $tenant]);
            $this->cobranca('Luna', 'fechada', $doTenant ? 12000 : 777000, $tenant);

            $venda = Venda::create(['setor' => 'petshop', 'vendedor_id' => $this->usuarioClinica('vendedor', $tenant)->id, 'status' => 'paga',
                'total_centavos' => $doTenant ? 21000 : 555000, 'forma_pagamento' => 'pix', 'pago_em' => now(), 'tenant_id' => $tenant]);
            VendaItem::create(['venda_id' => $venda->id, 'catalogo_item_id' => $this->produto([], $tenant)->id, 'descricao' => $doTenant ? 'Ração 10 kg' : 'Item de fora',
                'preco_unitario_centavos' => $venda->total_centavos, 'quantidade' => 1, 'subtotal_centavos' => $venda->total_centavos, 'baixou_estoque' => true, 'tenant_id' => $tenant]);
            Estorno::create(['venda_id' => $venda->id, 'solicitado_por' => 1, 'autorizado_por' => 1, 'motivo' => 'Devolução', 'valor_centavos' => $doTenant ? 5000 : 333000, 'tenant_id' => $tenant]);

            $despesa = CategoriaLancamento::create(['nome' => 'Aluguel', 'tipo' => 'despesa', 'tenant_id' => $tenant]);
            $receita = CategoriaLancamento::create(['nome' => 'Outras receitas', 'tipo' => 'receita', 'tenant_id' => $tenant]);
            Lancamento::create(['descricao' => 'Aluguel', 'valor' => $doTenant ? '100.00' : '4444.00', 'data' => now()->toDateString(), 'status' => 'pendente',
                'categoria_lancamento_id' => $despesa->id, 'tenant_id' => $tenant]);
            // Lançamento de receita não é despesa.
            Lancamento::create(['descricao' => 'Rendimento', 'valor' => '50.00', 'data' => now()->toDateString(), 'status' => 'pendente',
                'categoria_lancamento_id' => $receita->id, 'tenant_id' => $tenant]);
        }
    }
}
