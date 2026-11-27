<?php

namespace Database\Seeders;

use App\Models\Agendamento;
use App\Models\CatalogoItem;
use App\Models\Pet;
use App\Models\Tutor;
use App\Models\User;
use App\Services\AgendaService;
use App\Services\CatalogoService;
use App\Services\CobrancaService;
use App\Services\ContextoUsuario;
use App\Services\PetService;
use App\Services\ProntuarioService;
use App\Services\TutorService;
use App\Services\VendaService;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Movimento da demonstração (26/11): enche a agenda, os prontuários, as cobranças e as vendas do mês atual e do
 * seguinte, para conversar com o agente. ADITIVO: não apaga nada e não mexe nos cadastros e na agenda do
 * ClinicaSeeder; roda uma vez (se "Larissa Campos" já existe, não grava de novo). Uso:
 *   php artisan db:seed --class=MovimentoDemoSeeder
 * (depois do ClinicaSeeder; também entra no migrate:fresh --seed, pelo DatabaseSeeder).
 *
 * Passado (antes de agora, na data em que o seed roda): atendimentos realizados com prontuário assinado e cobrança
 * paga, a receber ou aberta, mais cancelados e faltas, e vendas de balcão. Futuro (até o fim do mês seguinte): só
 * agendado ou confirmado: nada realizado, pago ou vendido no futuro (aparecia na receita dos Resultados).
 * Despesas: nenhuma nova (só receita, o lucro nunca fica negativo em dia nenhum).
 *
 * Para não mexer nas âncoras da bateria do agente (o bloco lotado do vet2, a atendente estetica1, o Rex), tudo
 * usa pets e tutores NOVOS; nos 7 primeiros dias à frente só o vet3 e a estetica2 recebem agenda nova. O que a grade
 * recusar (domingo, almoço, vaga, conflito) é pulado. Sorteio determinístico: rodar duas vezes dá o mesmo resultado.
 */
class MovimentoDemoSeeder extends Seeder
{
    private const T = ClinicaSeeder::TENANT;

    /** Dias sem expediente na demo (feriados). */
    private const FERIADOS = ['11-02', '11-15', '11-20', '12-25', '01-01'];

    private int $semente = 2611;

    private Carbon $agora;

    private Carbon $hoje;

    /** @var array<string, User> */
    private array $u = [];

    /** @var array<string, CatalogoItem> */
    private array $itens = [];

    /** @var array<int, Pet> */
    private array $pets = [];

    /** @var array<int, Tutor> */
    private array $tutores = [];

    /** @var array<string, int> */
    private array $contagem = ['agendado' => 0, 'confirmado' => 0, 'realizado' => 0, 'cancelado' => 0, 'faltou' => 0, 'vendas' => 0, 'puladas' => 0];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('MovimentoDemoSeeder só pode ser executado em ambiente local.');
        }
        if (! ContextoUsuario::tenantDaClinica(self::T)) {
            throw new \RuntimeException('O tenant '.self::T.' não está em SENTINEL_TENANTS_CLINICA. Nada foi gravado.');
        }
        if (Tutor::where('tenant_id', self::T)->where('nome', 'Larissa Campos')->exists()) {
            $this->command?->info('O movimento da demonstração já foi plantado: nada foi gravado de novo.');

            return;
        }

        config(['sentinel.notificar_agendamento' => false]); // sem e-mail para cada um dos agendamentos
        $this->agora = Carbon::now();
        $this->hoje = $this->agora->copy()->startOfDay();
        $this->carregarBase();
        $relogio = Carbon::getTestNow();

        try {
            DB::transaction(function () {
                $this->catalogoNovo();
                $this->tutoresEPetsNovos();
                $this->agendaEAtendimentos();
                $this->vendas();
            });
        } finally {
            Carbon::setTestNow($relogio);
        }

        $c = $this->contagem;
        $this->command?->info("Movimento plantado: {$c['realizado']} realizados, {$c['cancelado']} cancelados, {$c['faltou']} faltas, "
            ."{$c['agendado']} agendados, {$c['confirmado']} confirmados, {$c['vendas']} vendas ({$c['puladas']} horários recusados pela grade foram pulados).");
    }

    private function carregarBase(): void
    {
        foreach (['vet1' => 'dono', 'vet2' => 'vet2', 'vet3' => 'vet3', 'estetica1' => 'estetica1', 'estetica2' => 'estetica2',
            'vendas2' => 'vendas2', 'recepcao2' => 'recepcao2', 'recepcao3' => 'recepcao3'] as $login => $chave) {
            $this->u[$chave] = User::where('tenant_id', self::T)->where('email', $login.'@'.ClinicaSeeder::DOMINIO_EMAIL)->first()
                ?? throw new \RuntimeException("Conta {$login} não existe: rode o ClinicaSeeder antes. Nada foi gravado.");
        }
        foreach (['Consulta clínica', 'Retorno', 'Vacina V10', 'Vacina antirrábica', 'Hemograma completo', 'Banho porte pequeno', 'Banho porte grande', 'Tosa higiênica', 'Tosa completa'] as $nome) {
            $this->itens[$nome] = CatalogoItem::where('tenant_id', self::T)->where('nome_comercial', $nome)->first()
                ?? throw new \RuntimeException("Item \"{$nome}\" não existe no catálogo: rode o ClinicaSeeder antes. Nada foi gravado.");
        }
    }

    /** Mais serviços e produtos nos dois setores, com estoque de sobra para as vendas do mês. */
    private function catalogoNovo(): void
    {
        $catalogo = app(CatalogoService::class);
        $criar = function (string $tipo, string $setor, string $categoria, string $nome, int $preco, int $estoque = 0) use ($catalogo) {
            $existente = CatalogoItem::where('tenant_id', self::T)->where('nome_comercial', $nome)->first();
            $this->itens[$nome] = $existente ?? $catalogo->criar(['tipo' => $tipo, 'setor' => $setor, 'categoria' => $categoria, 'nome_comercial' => $nome,
                'preco_centavos' => $preco, 'cobranca_por' => 'unidade', 'controla_estoque' => $tipo === 'produto', 'estoque' => $estoque, 'exige_receita' => false], self::T);
        };

        foreach ([['consulta', 'Consulta dermatológica', 18000], ['consulta', 'Retorno pós-cirúrgico', 6000], ['vacina', 'Vacina gripe canina', 9500],
            ['vacina', 'Vacina tríplice felina', 8500], ['exame', 'Ultrassonografia abdominal', 18000], ['exame', 'Raio-X', 14000]] as [$cat, $nome, $preco]) {
            $criar('servico', 'clinica', $cat, $nome, $preco);
        }
        foreach ([['banho', 'Banho porte médio', 6500], ['banho', 'Banho medicamentoso', 9500], ['tosa', 'Tosa na tesoura', 9000]] as [$cat, $nome, $preco]) {
            $criar('servico', 'petshop', $cat, $nome, $preco);
        }
        foreach ([['Anti-inflamatório (uso veterinário)', 3800], ['Suplemento vitamínico', 4900], ['Otológico (gotas)', 3600], ['Vermífugo para gatos', 3300]] as [$nome, $preco]) {
            $criar('produto', 'clinica', 'farmacia', $nome, $preco, 60);
        }
        foreach ([['racao', 'Ração para filhotes 3 kg', 8900], ['racao', 'Ração gatos castrados 10 kg', 19000], ['petisco', 'Petisco dental', 2200],
            ['acessorio', 'Arranhador', 8900], ['acessorio', 'Peitoral ajustável', 5200], ['brinquedo', 'Corda de brinquedo', 1800],
            ['higiene', 'Condicionador', 3100], ['higiene', 'Tapete higiênico (pacote)', 4500]] as [$cat, $nome, $preco]) {
            $criar('produto', 'petshop', $cat, $nome, $preco, 80);
        }
    }

    private function tutoresEPetsNovos(): void
    {
        $tutores = app(TutorService::class);
        $pets = app(PetService::class);

        foreach ([
            ['Larissa Campos', '(19) 99701-4410', 'Luna', 'cao', 'Golden Retriever', 'femea', '2020-08-14', 27000, null],
            ['Rafael Mendes', '(19) 99622-1875', 'Mel', 'gato', 'Sem raça definida', 'femea', '2022-02-09', 3600, null],
            ['Patrícia Lima', '(19) 98833-5521', 'Simba', 'gato', 'Maine Coon', 'macho', '2019-12-01', 6800, 'Arisco com estranhos'],
            ['Eduardo Barros', '(19) 99415-7706', 'Pipoca', 'cao', 'Spitz Alemão', 'femea', '2021-09-22', 3300, 'Medo de trovão'],
            ['Juliana Pires', '(19) 99288-3102', 'Zeus', 'cao', 'Rottweiler', 'macho', '2018-04-30', 41000, 'Morde ao ser contido'],
            ['Marcelo Teixeira', '(19) 98744-9063', 'Maya', 'cao', 'Border Collie', 'femea', '2020-11-11', 19000, null],
            ['Camila Azevedo', '(19) 99356-2284', 'Fred', 'cao', 'Bulldog Francês', 'macho', '2021-03-18', 12500, 'Alérgico a frango'],
            ['Thiago Ramos', '(19) 99170-6648', 'Lola', 'gato', 'Persa', 'femea', '2017-07-07', 4300, null],
            ['Renata Siqueira', '(19) 98965-4417', 'Bento', 'cao', 'Beagle', 'macho', '2022-06-25', 11200, null],
            ['Vinícius Moraes', '(19) 99512-8830', 'Tobias', 'gato', 'Siamês', 'macho', '2023-01-15', 4100, null],
        ] as [$tutor, $telefone, $nome, $especie, $raca, $sexo, $nascimento, $peso, $alertas]) {
            $t = $tutores->criar(['nome' => $tutor, 'telefone' => $telefone, 'endereco' => 'Campinas, SP',
                'email' => Str::slug(Str::before($tutor, ' ')).'@exemplo.com'], self::T);
            $this->tutores[] = $t;
            $this->pets[] = $pets->criar(['tutor_id' => $t->id, 'nome' => $nome, 'especie' => $especie, 'raca' => $raca, 'sexo' => $sexo,
                'data_nascimento' => $nascimento, 'peso_gramas' => $peso, 'alertas' => $alertas], self::T);
        }
    }

    // --- agenda e atendimentos ---------------------------------------------------------------------------------

    private function agendaEAtendimentos(): void
    {
        $fim = $this->agora->copy()->addMonthNoOverflow()->endOfMonth()->startOfDay();

        for ($dia = $this->agora->copy()->startOfMonth()->startOfDay(); $dia->lte($fim); $dia = $dia->copy()->addDay()) {
            if ($dia->dayOfWeekIso === 7 || in_array($dia->format('m-d'), self::FERIADOS, true)) {
                continue;
            }
            $adiante = (int) $this->hoje->diffInDays($dia, false); // negativo: passado
            $protegido = $adiante >= 0 && $adiante <= 6; // a semana das âncoras da demo (bloco do vet2, estetica1)
            $vets = $protegido ? ['vet3'] : ['vet3', 'dono', 'vet2'];
            $estetica = $protegido ? ['estetica2'] : ['estetica2', 'estetica1'];

            foreach ($vets as $v) {
                for ($i = 0, $n = 3 + $this->r(3); $i < $n; $i++) {
                    $this->agendar($dia->copy()->setTime(8 + $this->r(10), 0), $this->u[$v], true);
                }
            }
            foreach ($estetica as $e) {
                for ($i = 0, $n = 3 + $this->r(3); $i < $n; $i++) {
                    $this->agendar($dia->copy()->setTime(8 + $this->r(9), $this->r(2) * 30), $this->u[$e], false);
                }
            }
        }
    }

    private function agendar(Carbon $inicio, User $profissional, bool $clinica): void
    {
        $agenda = app(AgendaService::class);
        $servico = $clinica ? $this->servicoDaClinica() : $this->servicoDoPetshop();
        $pet = $this->pets[$this->r(count($this->pets))];
        $fim = $inicio->copy()->addMinutes($clinica ? 30 : 60);
        $passado = $fim->lt($this->agora);
        $dados = ['pet_id' => $pet->id, 'profissional_id' => $profissional->id, 'catalogo_item_id' => $servico->id, 'inicio' => $inicio, 'fim' => $fim];

        // Agendar no passado só com o relógio parado antes do atendimento; o futuro, com o relógio na meia-noite de hoje.
        $ag = $this->em($passado ? $inicio->copy()->subDays(2) : $this->hoje, function () use ($agenda, $dados) {
            return $agenda->motivoParaNaoAgendar($dados, self::T) === null ? $agenda->agendar($dados, self::T) : null;
        });
        if ($ag === null) {
            $this->contagem['puladas']++;

            return;
        }

        if (! $passado) {
            $confirmar = $this->r(100) < 40;
            if ($confirmar) {
                $this->em($this->hoje, fn () => $agenda->alterarStatus($ag->id, 'confirmado', self::T));
            }
            $this->contagem[$confirmar ? 'confirmado' : 'agendado']++;

            return;
        }

        $sorteio = $this->r(100);
        foreach ([[7, 'cancelado', $inicio->copy()->subHour()], [12, 'faltou', $fim->copy()->addHour()]] as [$ate, $status, $momento]) {
            if ($sorteio < $ate) {
                try {
                    $this->em($momento, fn () => $agenda->alterarStatus($ag->id, $status, self::T));
                    $this->contagem[$status]++;

                    return;
                } catch (\Throwable) {
                    break; // a regra não deixou: vira atendimento realizado (nunca sobra agendamento no passado)
                }
            }
        }

        $clinica ? $this->atenderNaClinica($ag, $servico, $inicio, $profissional) : $this->atenderNoPetshop($ag, $inicio);
        $this->contagem['realizado']++;
    }

    private function atenderNaClinica(Agendamento $ag, CatalogoItem $servico, Carbon $inicio, User $vet): void
    {
        $tipo = match ($servico->categoria) {
            'vacina' => 'vacinacao', 'exame' => 'exame', default => str_starts_with($servico->nome_comercial, 'Retorno') ? 'retorno' : 'consulta',
        };
        $texto = $this->textoClinico($tipo);
        $extra = $tipo === 'consulta' && $this->r(100) < 35 ? $this->itens[['Anti-inflamatório (uso veterinário)', 'Suplemento vitamínico', 'Otológico (gotas)', 'Vermífugo para gatos'][$this->r(4)]] : null;

        $this->em($inicio->copy()->addMinutes(30), function () use ($ag, $tipo, $texto, $extra, $vet) {
            app(AgendaService::class)->alterarStatus($ag->id, 'realizado', self::T);
            $prontuarios = app(ProntuarioService::class);
            $p = $prontuarios->abrir(['pet_id' => $ag->pet_id, 'tipo' => $tipo, 'agendamento_id' => $ag->id, ...$texto], $vet->id, self::T);
            if ($extra !== null) {
                $prontuarios->adicionarItem($p->id, $extra->id, 1, $vet->id, self::T);
            }
            $prontuarios->assinar($p->id, $vet->id, self::T);
        });

        $this->em($inicio->copy()->addMinutes(45), function () use ($ag, $extra, $inicio) {
            $cobrancas = app(CobrancaService::class);
            $c = $cobrancas->abrir($ag->id, self::T);
            if ($extra !== null) {
                $cobrancas->importarDoProntuario($c->id, self::T);
            }
            $this->fecharEPagar($c->id, $inicio);
        });
    }

    private function atenderNoPetshop(Agendamento $ag, Carbon $inicio): void
    {
        $this->em($inicio->copy()->addHour(), function () use ($ag, $inicio) {
            app(AgendaService::class)->alterarStatus($ag->id, 'realizado', self::T);
            $cobrancas = app(CobrancaService::class);
            $c = $cobrancas->abrir($ag->id, self::T);
            if ($this->r(100) < 40) {
                $extra = $this->itens[['Tosa higiênica', 'Tosa na tesoura', 'Condicionador', 'Petisco dental'][$this->r(4)]];
                $cobrancas->adicionarItem($c->id, $extra->id, 1, self::T);
            }
            $this->fecharEPagar($c->id, $inicio);
        });
    }

    /** Paga na forma sorteada; nos últimos 2 dias alguns ficam abertos, e ~12% do resto fica a receber. */
    private function fecharEPagar(int $cobrancaId, Carbon $inicio): void
    {
        $cobrancas = app(CobrancaService::class);
        $recente = $inicio->gt($this->hoje->copy()->subDays(2));
        $sorteio = $this->r(100);
        if ($recente && $sorteio < 25) {
            return; // cobrança aberta
        }
        $cobrancas->fechar($cobrancaId, self::T);
        if (! $recente && $sorteio < 12) {
            return; // a receber
        }
        $cobrancas->registrarPagamento($cobrancaId, ['pix', 'cartao', 'dinheiro'][$this->r(3)], self::T);
    }

    private function servicoDaClinica(): CatalogoItem
    {
        $n = $this->r(100);
        $nomes = match (true) {
            $n < 55 => ['Consulta clínica', 'Consulta clínica', 'Consulta clínica', 'Consulta dermatológica'],
            $n < 75 => ['Retorno', 'Retorno pós-cirúrgico'],
            $n < 88 => ['Vacina V10', 'Vacina antirrábica', 'Vacina gripe canina', 'Vacina tríplice felina'],
            default => ['Hemograma completo', 'Ultrassonografia abdominal', 'Raio-X'],
        };

        return $this->itens[$nomes[$this->r(count($nomes))]];
    }

    private function servicoDoPetshop(): CatalogoItem
    {
        $nomes = ['Banho porte pequeno', 'Banho porte médio', 'Banho porte grande', 'Banho medicamentoso', 'Tosa higiênica', 'Tosa completa', 'Tosa na tesoura'];

        return $this->itens[$nomes[$this->r(count($nomes))]];
    }

    /** @return array{anamnese: string, exame_fisico: string, diagnostico: string, conduta: string} dado fictício de demonstração */
    private function textoClinico(string $tipo): array
    {
        $por = [
            'consulta' => [
                ['Tutor relata apatia e falta de apetite há dois dias.', 'Mucosas normocoradas, leve desidratação.', 'Gastroenterite leve.', 'Dieta leve, hidratação e retorno em 5 dias.'],
                ['Coceira intensa e perda de pelos nas patas.', 'Lesões eritematosas interdigitais.', 'Dermatite alérgica.', 'Banho medicamentoso e anti-inflamatório por 7 dias.'],
                ['Vômitos esporádicos após as refeições.', 'Abdômen sem dor à palpação.', 'Indiscrição alimentar.', 'Jejum curto, dieta pastosa e observação.'],
                ['Tosse seca há uma semana.', 'Ausculta com discreto ruído de vias altas.', 'Traqueobronquite leve.', 'Repouso e medicação por 7 dias.'],
                ['Check-up de rotina, sem queixas.', 'Dentro da normalidade.', 'Animal hígido.', 'Manter vacinas e vermifugação em dia.'],
            ],
            'retorno' => [
                ['Retorno para reavaliação do quadro anterior.', 'Boa evolução, apetite normal.', 'Melhora clínica.', 'Alta com orientações ao tutor.'],
                ['Retorno após o fim da medicação.', 'Sem alterações ao exame.', 'Quadro resolvido.', 'Sem novas condutas.'],
            ],
            'vacinacao' => [
                ['Vacinação de rotina conforme o calendário.', 'Animal ativo, sem sinais de doença.', 'Apto à vacinação.', 'Dose aplicada; reforço conforme o protocolo.'],
            ],
            'exame' => [
                ['Exame solicitado para acompanhamento.', 'Coleta sem intercorrências.', 'Resultados dentro do esperado para o quadro.', 'Laudo entregue ao tutor; retorno se necessário.'],
                ['Investigação de dor abdominal intermitente.', 'Abdômen levemente tenso.', 'Sem achados que exijam intervenção.', 'Acompanhar e repetir em 30 dias se persistir.'],
            ],
        ];
        $m = $por[$tipo][$this->r(count($por[$tipo]))];

        return ['anamnese' => $m[0].' (dado fictício de demonstração)', 'exame_fisico' => $m[1], 'diagnostico' => $m[2], 'conduta' => $m[3]];
    }

    // --- vendas de balcão (só no passado) ----------------------------------------------------------------------

    private function vendas(): void
    {
        $loja = ['Ração para filhotes 3 kg', 'Ração gatos castrados 10 kg', 'Petisco dental', 'Arranhador', 'Peitoral ajustável', 'Corda de brinquedo', 'Condicionador', 'Tapete higiênico (pacote)'];
        $farmacia = ['Anti-inflamatório (uso veterinário)', 'Suplemento vitamínico', 'Otológico (gotas)', 'Vermífugo para gatos'];
        $vendas = app(VendaService::class);

        for ($dia = $this->agora->copy()->startOfMonth()->startOfDay(); $dia->lt($this->hoje); $dia = $dia->copy()->addDay()) {
            if ($dia->dayOfWeekIso === 7 || in_array($dia->format('m-d'), self::FERIADOS, true)) {
                continue;
            }
            for ($i = 0, $n = 1 + $this->r(3); $i < $n; $i++) {
                $daLoja = $this->r(100) < 70;
                $vendedor = $daLoja ? $this->u['vendas2'] : $this->u[['recepcao2', 'recepcao3'][$this->r(2)]];
                $nomes = $daLoja ? $loja : $farmacia;
                $linhas = [];
                for ($k = 0, $m = 1 + $this->r(3); $k < $m; $k++) {
                    $linhas[$nomes[$this->r(count($nomes))]] = ['catalogo_item_id' => 0, 'quantidade' => 1 + $this->r(3)]; // item sorteado de novo: vale a última quantidade
                }
                foreach ($linhas as $nome => $l) {
                    $linhas[$nome]['catalogo_item_id'] = $this->itens[$nome]->id;
                }
                $momento = $dia->copy()->setTime(9 + $this->r(9), $this->r(60));
                $recente = $dia->gt($this->hoje->copy()->subDays(3));
                $forma = $recente && $this->r(100) < 15 ? null : ['pix', 'cartao', 'dinheiro'][$this->r(3)];
                $tutor = $this->r(100) < 70 ? $this->tutores[$this->r(count($this->tutores))]->id : null;
                $this->em($momento, fn () => $vendas->registrar(array_values($linhas), $vendedor->id, $tutor, $forma, self::T));
                $this->contagem['vendas']++;
            }
        }
    }

    // --- ferramentas -------------------------------------------------------------------------------------------

    /** Sorteio determinístico (gerador congruencial): não mexe no mt_rand do resto da aplicação. 0 ≤ r < $max. */
    private function r(int $max): int
    {
        $this->semente = ($this->semente * 1103515245 + 12345) & 0x7FFFFFFF;

        return ($this->semente >> 8) % max(1, $max);
    }

    /** Roda $acao com o relógio parado em $momento (created_at, pago_em, assinado_em... na data certa). */
    private function em(CarbonInterface $momento, callable $acao): mixed
    {
        $antes = Carbon::getTestNow();
        Carbon::setTestNow($momento);

        try {
            return $acao();
        } finally {
            Carbon::setTestNow($antes);
        }
    }
}
