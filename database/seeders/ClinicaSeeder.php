<?php

namespace Database\Seeders;

use App\Models\CatalogoItem;
use App\Models\CategoriaLancamento;
use App\Models\Fornecedor;
use App\Models\Lancamento;
use App\Models\Mensagem;
use App\Models\Pet;
use App\Models\Tutor;
use App\Models\User;
use App\Services\AgendaService;
use App\Services\CatalogoService;
use App\Services\CobrancaService;
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
 * Clínica veterinária + petshop de demonstração (tenant 3): um usuário por papel (dono com CRMV, mais
 * um veterinário), tutores, pets, catálogo dos dois setores (com farmácia), agenda da semana, um
 * atendimento de emergência sem agendamento, cobranças e vendas pagas e em aberto espalhadas no último
 * mês (para os Resultados terem dados), despesas gerais e 5 mensagens na caixa de entrada simulada.
 *
 * Tudo passa pelos services (as regras de domínio valem também no seed). O histórico é gravado com o
 * relógio simulado (Carbon::setTestNow) no dia de cada atendimento, para pago_em, created_at e afins
 * caírem no passado. Datas relativas a hoje, no fuso da aplicação.
 *
 * Só roda sob comando explícito (php artisan db:seed --class=ClinicaSeeder, que o instalar.bat mostra junto
 * do DemonstracaoSeeder); fora do DatabaseSeeder. Senha de todas as contas: a DEMO_SENHA, com a mesma
 * validação do DemonstracaoSeeder; sem ela, nada é gravado. Usuário que já existe é preservado (a senha
 * não é trocada). Se o tenant já tem tutores, os dados não são gravados de novo.
 */
class ClinicaSeeder extends Seeder
{
    public const TENANT = 3;

    private array $usuarios = [];

    private array $itens = [];

    private array $pets = [];

    private array $tutores = [];

    public function run(): void
    {
        $this->criarUsuarios(DemonstracaoSeeder::lerDemoSenha('ClinicaSeeder'));

        if (Tutor::where('tenant_id', self::TENANT)->exists()) {
            $this->command?->info('Clínica de demonstração (tenant '.self::TENANT.') já tem dados: nada foi gravado de novo.');

            return;
        }

        $relogio = Carbon::getTestNow();

        try {
            DB::transaction(function () {
                $this->catalogo();
                $this->tutoresEPets();
                $this->historicoDaClinica();
                $this->historicoDoPetshop();
                $this->vendas();
                $this->emergenciaSemAgendamento();
                $this->agendaDaSemana();
                $this->despesas();
                $this->mensagens();
            });
        } finally {
            Carbon::setTestNow($relogio);
        }

        $this->command?->info('Clínica de demonstração pronta no tenant '.self::TENANT.'.');
    }

    private function criarUsuarios(string $senha): void
    {
        $contas = [
            'dono' => ['name' => 'Dra. Helena Prado (dono)', 'email' => 'dono@clinica.sentinel.local', 'papel' => 'dono', 'crmv' => 'CRMV-SP 21458'],
            'veterinario' => ['name' => 'Dr. Rafael Lima', 'email' => 'veterinario@clinica.sentinel.local', 'papel' => 'veterinario', 'crmv' => 'CRMV-SP 33871'],
            'recepcao' => ['name' => 'Juliana Reis (recepção)', 'email' => 'recepcao@clinica.sentinel.local', 'papel' => 'recepcao', 'crmv' => null],
            'atendente' => ['name' => 'Carlos Mendes (banho e tosa)', 'email' => 'atendente@clinica.sentinel.local', 'papel' => 'atendente', 'crmv' => null],
            'vendedor' => ['name' => 'Patrícia Gomes (loja)', 'email' => 'vendedor@clinica.sentinel.local', 'papel' => 'vendedor', 'crmv' => null],
        ];

        foreach ($contas as $chave => $atributos) {
            // Cast "hashed" do User: só o hash é gravado. A senha nunca vai para o console nem para o log.
            $usuario = User::query()->firstOrCreate(['email' => $atributos['email']], [...$atributos, 'tenant_id' => self::TENANT, 'password' => $senha]);

            $this->command?->line($usuario->wasRecentlyCreated
                ? "E-mail: {$usuario->email} | Perfil: {$usuario->papel} | Senha: a de DEMO_SENHA"
                : "Usuário existente preservado (senha não trocada): {$usuario->email}.");

            $this->usuarios[$chave] = $usuario;
        }
    }

    private function catalogo(): void
    {
        $catalogo = app(CatalogoService::class);
        $servico = fn (string $setor, string $categoria, string $nome, int $preco, string $por = 'unidade') => $catalogo->criar(
            ['tipo' => 'servico', 'setor' => $setor, 'categoria' => $categoria, 'nome_comercial' => $nome, 'preco_centavos' => $preco, 'cobranca_por' => $por], self::TENANT);
        $produto = fn (string $setor, string $categoria, string $nome, int $preco, int $estoque, bool $receita = false) => $catalogo->criar(
            ['tipo' => 'produto', 'setor' => $setor, 'categoria' => $categoria, 'nome_comercial' => $nome, 'preco_centavos' => $preco,
                'controla_estoque' => true, 'estoque' => $estoque, 'exige_receita' => $receita], self::TENANT);

        $this->itens = [
            'consulta' => $servico('clinica', 'consulta', 'Consulta clínica', 15000),
            'retorno' => $servico('clinica', 'consulta', 'Retorno', 8000),
            'emergencia' => $servico('clinica', 'consulta', 'Consulta de emergência', 25000),
            'v10' => $servico('clinica', 'vacina', 'Vacina V10', 9000),
            'antirrabica' => $servico('clinica', 'vacina', 'Vacina antirrábica', 7000),
            'hemograma' => $servico('clinica', 'exame', 'Hemograma completo', 6500),
            'microchip' => $servico('clinica', 'microchipagem', 'Microchipagem', 12000),
            'castracao' => $servico('clinica', 'cirurgia', 'Castração', 45000),
            'internacao' => $servico('clinica', 'internacao', 'Internação (diária)', 18000, 'diaria'),
            'vermifugo' => $produto('clinica', 'farmacia', 'Vermífugo (comprimido)', 3500, 40),
            'antipulgas' => $produto('clinica', 'farmacia', 'Antipulgas (pipeta)', 5500, 30),
            'antibiotico' => $produto('clinica', 'farmacia', 'Antibiótico (uso veterinário)', 4200, 20, receita: true),
            'banho_p' => $servico('petshop', 'banho', 'Banho porte pequeno', 5000),
            'banho_g' => $servico('petshop', 'banho', 'Banho porte grande', 8000),
            'tosa_h' => $servico('petshop', 'tosa', 'Tosa higiênica', 4000),
            'tosa_c' => $servico('petshop', 'tosa', 'Tosa completa', 7000),
            'racao' => $produto('petshop', 'racao', 'Ração premium 10 kg', 21000, 15),
            'petisco' => $produto('petshop', 'petisco', 'Petisco natural', 1500, 60),
            'coleira' => $produto('petshop', 'acessorio', 'Coleira ajustável', 3500, 20),
            'bolinha' => $produto('petshop', 'brinquedo', 'Bolinha de borracha', 1200, 30),
            'cama' => $produto('petshop', 'cama', 'Caminha tamanho M', 12000, 5),
            'shampoo' => $produto('petshop', 'higiene', 'Shampoo neutro', 2800, 25),
        ];
    }

    private function tutoresEPets(): void
    {
        $tutores = app(TutorService::class);
        $pets = app(PetService::class);

        foreach ([
            'ana' => ['Ana Beatriz Souza', '(19) 99812-3344', 'Rua das Acácias, 120'],
            'bruno' => ['Bruno Carvalho', '(19) 99745-1020', 'Av. Brasil, 845'],
            'carla' => ['Carla Nogueira', '(19) 98111-2233', 'Rua XV de Novembro, 32'],
            'diego' => ['Diego Martins', '(19) 99630-7788', 'Rua Santa Cruz, 410'],
            'elisa' => ['Elisa Ferraz', '(19) 99258-4411', 'Rua Boa Morte, 77'],
            'fabio' => ['Fábio Rocha', '(19) 98877-6655', 'Av. Campinas, 1500'],
            'gabriela' => ['Gabriela Antunes', '(19) 99102-9988', 'Rua Tiradentes, 210'],
            'henrique' => ['Henrique Duarte', '(19) 99551-3300', 'Rua do Rosário, 18'],
        ] as $chave => [$nome, $telefone, $endereco]) {
            $this->tutores[$chave] = $tutores->criar(['nome' => $nome, 'telefone' => $telefone, 'endereco' => $endereco,
                'email' => Str::slug(Str::before($nome, ' ')).'@exemplo.com'], self::TENANT);
        }

        foreach ([
            'thor' => ['ana', 'Thor', 'cao', 'Labrador', 'macho', '2019-03-10', 32000, '985112003456781', 'Puxa forte na guia'],
            'mel' => ['ana', 'Mel', 'gato', 'SRD', 'femea', '2021-07-22', 4100, null, 'Medo de secador'],
            'bidu' => ['bruno', 'Bidu', 'cao', 'Shih-tzu', 'macho', '2020-01-05', 6200, '985112003456782', null],
            'luna' => ['carla', 'Luna', 'cao', 'Border collie', 'femea', '2022-02-14', 18500, null, null],
            'nina' => ['carla', 'Nina', 'gato', 'Siamês', 'femea', '2018-11-30', 3800, '985112003456783', 'Morde ao ser contida'],
            'rex' => ['diego', 'Rex', 'cao', 'Pastor alemão', 'macho', '2017-06-18', 38000, '985112003456784', 'Morde'],
            'pipoca' => ['elisa', 'Pipoca', 'roedor', 'Hamster sírio', 'femea', '2025-04-01', 150, null, null],
            'fred' => ['elisa', 'Fred', 'ave', 'Calopsita', 'macho', '2023-09-09', 90, null, 'Assusta com barulho'],
            'zeca' => ['fabio', 'Zeca', 'cao', 'Vira-lata', 'macho', '2016-12-25', 14000, null, null],
            'amora' => ['gabriela', 'Amora', 'cao', 'Poodle', 'femea', '2021-05-03', 5200, '985112003456785', 'Medo de secador'],
            'tom' => ['henrique', 'Tom', 'gato', 'Persa', 'macho', '2020-08-08', 4600, null, null],
            'bolt' => ['henrique', 'Bolt', 'cao', 'Beagle', 'macho', '2024-01-20', 11000, null, 'Foge pela porta'],
        ] as $chave => [$tutor, $nome, $especie, $raca, $sexo, $nascimento, $peso, $microchip, $alertas]) {
            $this->pets[$chave] = $pets->criar(['tutor_id' => $this->tutores[$tutor]->id, 'nome' => $nome, 'especie' => $especie, 'raca' => $raca,
                'sexo' => $sexo, 'data_nascimento' => $nascimento, 'peso_gramas' => $peso, 'microchip' => $microchip, 'alertas' => $alertas], self::TENANT);
        }
    }

    /** Consultas do último mês: agendamento realizado, prontuário assinado, cobrança paga, a receber ou aberta. */
    private function historicoDaClinica(): void
    {
        // [dias atrás, hora, pet, veterinário, tipo, itens do prontuário, pagamento: forma | 'a_receber' | 'aberta']
        $atendimentos = [
            [28, '09:00', 'thor', 'dono', 'vacinacao', [['v10', 1]], 'pix'],
            [26, '10:30', 'bidu', 'veterinario', 'consulta', [['hemograma', 1]], 'cartao'],
            [24, '14:00', 'nina', 'veterinario', 'consulta', [], 'dinheiro'],
            [21, '09:30', 'rex', 'dono', 'exame', [['hemograma', 1]], 'pix'],
            [19, '11:00', 'luna', 'veterinario', 'cirurgia', [['castracao', 1]], 'cartao'],
            [16, '15:30', 'zeca', 'dono', 'vacinacao', [['antirrabica', 1], ['v10', 1]], 'pix'],
            [14, '10:00', 'amora', 'veterinario', 'consulta', [['microchip', 1]], 'a_receber'],
            [9, '16:00', 'tom', 'veterinario', 'retorno', [], 'cartao'],
            [6, '09:00', 'mel', 'dono', 'consulta', [['vermifugo', 1]], 'a_receber'],
            [3, '13:30', 'bolt', 'veterinario', 'vacinacao', [['v10', 1]], 'pix'],
            [2, '10:30', 'thor', 'veterinario', 'consulta', [['hemograma', 1]], 'aberta'],
        ];

        foreach ($atendimentos as [$dias, $hora, $pet, $vet, $tipo, $itens, $pagamento]) {
            $inicio = $this->dia($dias, $hora);
            $this->atenderNaClinica($inicio, $pet, $vet, $tipo === 'retorno' ? 'retorno' : 'consulta', $tipo, $itens, $pagamento, [
                'anamnese' => 'Tutor relata o motivo da visita; sem queixas adicionais.',
                'exame_fisico' => 'Mucosas normocoradas, hidratado, temperatura dentro do normal.',
                'diagnostico' => 'Sem alterações relevantes para o quadro (dado fictício de demonstração).',
                'conduta' => 'Orientações ao tutor e retorno se necessário.',
            ]);
        }

        // Internação de 3 diárias (entrada há 12 dias à noite, alta há 9 dias de manhã).
        $this->atenderNaClinica($this->dia(12, '18:00'), 'zeca', 'dono', 'consulta', 'internacao', [['internacao', 1]], 'pix', [
            'anamnese' => 'Vômitos recorrentes (dado fictício de demonstração).',
            'conduta' => 'Internação para fluidoterapia e observação.',
            'entrada_em' => $this->dia(12, '18:30'),
            'alta_em' => $this->dia(9, '10:00'),
        ], $this->dia(9, '10:30'));
    }

    private function atenderNaClinica(CarbonInterface $inicio, string $pet, string $vet, string $servico, string $tipo, array $itens, string $pagamento,
        array $clinico, ?CarbonInterface $fechamento = null): void
    {
        $veterinario = $this->usuarios[$vet];

        $agendamento = $this->em($inicio->copy()->subDays(3), fn () => app(AgendaService::class)->agendar([
            'pet_id' => $this->pets[$pet]->id, 'profissional_id' => $veterinario->id, 'catalogo_item_id' => $this->itens[$servico]->id,
            'inicio' => $inicio, 'fim' => $inicio->copy()->addMinutes(30),
        ], self::TENANT));

        // Internação: o prontuário é assinado na alta (o fechamento da conta vem logo depois).
        $assinatura = $fechamento?->copy()->subMinutes(10) ?? $inicio->copy()->addMinutes(30);

        $this->em($assinatura, function () use ($agendamento, $pet, $veterinario, $tipo, $itens, $clinico) {
            app(AgendaService::class)->alterarStatus($agendamento->id, 'realizado', self::TENANT);
            $prontuarios = app(ProntuarioService::class);
            $prontuario = $prontuarios->abrir(['pet_id' => $this->pets[$pet]->id, 'tipo' => $tipo, 'agendamento_id' => $agendamento->id, ...$clinico],
                $veterinario->id, self::TENANT);
            foreach ($itens as [$item, $quantidade]) {
                $prontuarios->adicionarItem($prontuario->id, $this->itens[$item]->id, $quantidade, $veterinario->id, self::TENANT);
            }
            $prontuarios->assinar($prontuario->id, $veterinario->id, self::TENANT);
        });

        $this->em($fechamento ?? $inicio->copy()->addMinutes(40), function () use ($agendamento, $itens, $pagamento) {
            $cobrancas = app(CobrancaService::class);
            $cobranca = $cobrancas->abrir($agendamento->id, self::TENANT);
            if ($itens !== []) {
                $cobrancas->importarDoProntuario($cobranca->id, self::TENANT);
            }
            if ($pagamento === 'aberta') {
                return;
            }
            $cobrancas->fechar($cobranca->id, self::TENANT);
            if ($pagamento !== 'a_receber') {
                $cobrancas->registrarPagamento($cobranca->id, $pagamento, self::TENANT);
            }
        });
    }

    /** Banho e tosa do último mês: realizado, cobrança paga (uma a receber). */
    private function historicoDoPetshop(): void
    {
        foreach ([
            [27, '09:00', 'bidu', 'banho_p', 'tosa_h', 'pix'],
            [22, '10:00', 'amora', 'banho_p', 'tosa_c', 'cartao'],
            [20, '14:00', 'thor', 'banho_g', null, 'dinheiro'],
            [15, '09:30', 'luna', 'banho_g', 'tosa_h', 'pix'],
            [13, '11:00', 'bolt', 'banho_p', null, 'cartao'],
            [8, '15:00', 'bidu', 'banho_p', 'tosa_c', 'pix'],
            [5, '10:00', 'zeca', 'banho_g', null, 'a_receber'],
            [1, '16:00', 'amora', 'banho_p', null, 'dinheiro'],
        ] as [$dias, $hora, $pet, $banho, $tosa, $pagamento]) {
            $inicio = $this->dia($dias, $hora);
            $agendamento = $this->em($inicio->copy()->subDays(2), fn () => app(AgendaService::class)->agendar([
                'pet_id' => $this->pets[$pet]->id, 'profissional_id' => $this->usuarios['atendente']->id,
                'catalogo_item_id' => $this->itens[$banho]->id, 'inicio' => $inicio, 'fim' => $inicio->copy()->addHour(),
            ], self::TENANT));

            $this->em($inicio->copy()->addHour(), function () use ($agendamento, $tosa, $pagamento) {
                app(AgendaService::class)->alterarStatus($agendamento->id, 'realizado', self::TENANT);
                $cobrancas = app(CobrancaService::class);
                $cobranca = $cobrancas->abrir($agendamento->id, self::TENANT);
                if ($tosa !== null) {
                    $cobrancas->adicionarItem($cobranca->id, $this->itens[$tosa]->id, 1, self::TENANT);
                }
                $cobrancas->fechar($cobranca->id, self::TENANT);
                if ($pagamento !== 'a_receber') {
                    $cobrancas->registrarPagamento($cobranca->id, $pagamento, self::TENANT);
                }
            });
        }
    }

    /** Balcão: loja (vendedor) e farmácia (recepção), pagas na hora; duas ficam em aberto. */
    private function vendas(): void
    {
        foreach ([
            [29, '11:00', 'vendedor', 'ana', [['racao', 1], ['petisco', 2]], 'pix'],
            [25, '17:00', 'vendedor', null, [['bolinha', 2]], 'dinheiro'],
            [23, '10:15', 'recepcao', 'carla', [['antipulgas', 2]], 'cartao'],
            [18, '16:30', 'vendedor', 'diego', [['racao', 2]], 'cartao'],
            [17, '09:45', 'vendedor', null, [['coleira', 1], ['petisco', 3]], 'pix'],
            [11, '14:20', 'recepcao', 'fabio', [['vermifugo', 2]], 'dinheiro'],
            [10, '18:00', 'vendedor', 'gabriela', [['cama', 1]], 'cartao'],
            [7, '12:10', 'vendedor', null, [['shampoo', 1], ['petisco', 1]], 'pix'],
            [4, '15:40', 'vendedor', 'henrique', [['racao', 1]], null],
            [1, '11:30', 'recepcao', 'elisa', [['antipulgas', 1]], null],
        ] as [$dias, $hora, $quem, $tutor, $itens, $forma]) {
            $this->em($this->dia($dias, $hora), fn () => app(VendaService::class)->registrar(
                array_map(fn (array $i) => ['catalogo_item_id' => $this->itens[$i[0]]->id, 'quantidade' => $i[1]], $itens),
                $this->usuarios[$quem]->id, $tutor === null ? null : $this->tutores[$tutor]->id, $forma, self::TENANT));
        }
    }

    /** Ontem à noite, sem agendamento: o prontuário fica assinado e sem vínculo (a recepção vincula depois). */
    private function emergenciaSemAgendamento(): void
    {
        $this->em($this->dia(1, '22:40'), function () {
            $prontuarios = app(ProntuarioService::class);
            $vet = $this->usuarios['veterinario'];
            $prontuario = $prontuarios->abrir(['pet_id' => $this->pets['rex']->id, 'tipo' => 'emergencia',
                'anamnese' => 'Chegou após ingerir objeto estranho (dado fictício de demonstração).',
                'conduta' => 'Indução de êmese e observação.'], $vet->id, self::TENANT);
            $prontuarios->adicionarItem($prontuario->id, $this->itens['emergencia']->id, 1, $vet->id, self::TENANT);
            $prontuarios->assinar($prontuario->id, $vet->id, self::TENANT);
        });
    }

    /** Hoje e os próximos 6 dias: consultas e banhos agendados (alguns confirmados). */
    private function agendaDaSemana(): void
    {
        $agenda = app(AgendaService::class);
        $semana = [
            [0, '17:00', 'luna', 'veterinario', 'retorno', 'confirmado'],
            [0, '17:30', 'bidu', 'atendente', 'banho_p', 'confirmado'],
            [1, '09:00', 'mel', 'dono', 'consulta', 'confirmado'],
            [1, '09:00', 'thor', 'atendente', 'banho_g', 'agendado'],
            [1, '10:00', 'pipoca', 'veterinario', 'consulta', 'agendado'],
            [2, '14:00', 'nina', 'dono', 'consulta', 'agendado'],
            [2, '15:00', 'amora', 'atendente', 'tosa_c', 'agendado'],
            [3, '09:30', 'fred', 'veterinario', 'consulta', 'agendado'],
            [3, '11:00', 'rex', 'dono', 'retorno', 'agendado'],
            [4, '10:00', 'tom', 'veterinario', 'consulta', 'agendado'],
            [4, '13:00', 'zeca', 'atendente', 'banho_g', 'agendado'],
            [5, '09:00', 'bolt', 'dono', 'consulta', 'agendado'],
            [5, '10:00', 'luna', 'atendente', 'banho_g', 'agendado'],
            [6, '11:00', 'thor', 'veterinario', 'consulta', 'agendado'],
        ];

        // Relógio na meia-noite de hoje: agendar no passado é recusado, e o horário de hoje pode já ter passado.
        $this->em($this->dia(0, '00:00'), function () use ($semana, $agenda) {
            foreach ($semana as [$dias, $hora, $pet, $quem, $servico, $status]) {
                $inicio = $this->dia(-$dias, $hora);
                $agendamento = $agenda->agendar(['pet_id' => $this->pets[$pet]->id, 'profissional_id' => $this->usuarios[$quem]->id,
                    'catalogo_item_id' => $this->itens[$servico]->id, 'inicio' => $inicio,
                    'fim' => $inicio->copy()->addMinutes($quem === 'atendente' ? 60 : 30)], self::TENANT);
                if ($status === 'confirmado') {
                    $agenda->alterarStatus($agendamento->id, 'confirmado', self::TENANT);
                }
            }
        });
    }

    /** Despesas gerais do mês (lançamentos de categoria "despesa"), para o lucro dos Resultados. */
    private function despesas(): void
    {
        $categoria = fn (string $nome) => CategoriaLancamento::create(['nome' => $nome, 'tipo' => 'despesa', 'tenant_id' => self::TENANT]);
        $fornecedor = Fornecedor::create(['nome' => 'Distribuidora PetMais Ltda', 'documento' => '11.222.333/0001-81', 'tenant_id' => self::TENANT]);
        $c = ['aluguel' => $categoria('Aluguel'), 'energia' => $categoria('Energia elétrica'), 'agua' => $categoria('Água'),
            'compras' => $categoria('Compras de estoque'), 'salarios' => $categoria('Salários')];

        foreach ([
            ['Aluguel do imóvel', '4500.00', 25, 'aluguel', 'conciliado', null],
            ['Conta de energia', '850.40', 18, 'energia', 'conciliado', null],
            ['Conta de água', '220.15', 18, 'agua', 'pendente', null],
            ['Compra de ração e petiscos', '3200.00', 20, 'compras', 'conciliado', $fornecedor->id],
            ['Compra de medicamentos da farmácia', '1480.90', 12, 'compras', 'pendente', $fornecedor->id],
            ['Folha de pagamento', '6800.00', 5, 'salarios', 'pendente', null],
        ] as [$descricao, $valor, $dias, $cat, $status, $fornecedorId]) {
            Lancamento::create(['descricao' => $descricao, 'valor' => $valor, 'data' => $this->dia($dias, '08:00')->toDateString(), 'status' => $status,
                'categoria_lancamento_id' => $c[$cat]->id, 'fornecedor_id' => $fornecedorId, 'tenant_id' => self::TENANT]);
        }
    }

    private function mensagens(): void
    {
        foreach ([
            ['ana', null, 'Oi! Queria remarcar o banho do Thor para sábado, pode ser?', 'nova'],
            ['carla', null, 'A Nina está sem comer desde ontem, consigo uma consulta hoje?', 'nova'],
            [null, 'Marcos (19) 99333-1212', 'Vocês vendem ração para filhote de gato?', 'nova'],
            ['henrique', null, 'Obrigado pelo atendimento do Tom!', 'tratada'],
            [null, 'Luciana', 'Qual o horário de funcionamento no feriado?', 'nova'],
        ] as $i => [$tutor, $remetente, $texto, $status]) {
            $this->em($this->dia(2 - min($i, 2), (8 + $i).':15'), fn () => Mensagem::create([
                'tutor_id' => $tutor === null ? null : $this->tutores[$tutor]->id, 'remetente' => $remetente, 'texto' => $texto,
                'status' => $status, 'tenant_id' => self::TENANT]));
        }
    }

    /** Dia relativo a hoje (positivo = passado; negativo = futuro), na hora dada, no fuso da aplicação. */
    private function dia(int $diasAtras, string $hora): Carbon
    {
        [$h, $m] = array_map('intval', explode(':', $hora));

        return Carbon::now()->startOfDay()->subDays($diasAtras)->setTime($h, $m);
    }

    /** Roda $acao com o relógio parado em $momento (created_at, pago_em, assinado_em... no passado). */
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
