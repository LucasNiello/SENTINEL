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
 * A clínica veterinária + petshop (tenant 3), o único tenant que o seed cria (17/11). Tem 11 contas
 * @clinica.sentinel.br: vet1 (dono, com CRMV), vet2 e vet3 (veterinários, com CRMV), recepcao1-3,
 * estetica1-2 (banho e tosa), vendas1-2 (loja) e administracao (gera e revoga os PINs). Também cria 5 pets
 * com seus tutores, 3 clientes só da loja (sem pet), o catálogo dos dois setores (com farmácia), a agenda
 * da semana e um
 * atendimento de emergência sem agendamento, cobranças e vendas pagas e em aberto espalhadas no último
 * mês (para os Resultados terem dados), despesas gerais e 5 mensagens na caixa de entrada simulada.
 *
 * Tudo passa pelos services (as regras de domínio valem também no seed). O histórico é gravado com o
 * relógio simulado (Carbon::setTestNow) no dia de cada atendimento, para pago_em, created_at e afins
 * caírem no passado. Datas relativas a hoje, no fuso da aplicação.
 *
 * Chamado pelo DatabaseSeeder (php artisan migrate:fresh --seed). Senha de todas as contas: a DEMO_SENHA
 * do .env; sem ela (ou fraca), nada é gravado. Usuário que já existe é preservado (a senha não é trocada).
 * Se o tenant já tem tutores, os dados não são gravados de novo.
 */
class ClinicaSeeder extends Seeder
{
    public const TENANT = 3;

    /** Domínio dos e-mails de todas as contas da clínica. */
    public const DOMINIO_EMAIL = 'clinica.sentinel.br';

    private const SENHA_MINIMO = 12;

    private const SENHAS_CONHECIDAS = ['sentinel123', 'password', '12345678', '123456789', 'admin123', 'senha123'];

    private array $usuarios = [];

    private array $itens = [];

    private array $pets = [];

    private array $tutores = [];

    public function run(): void
    {
        // Falha fechado: fora de sentinel.tenants_clinica, a conta admin viraria admin da contabilidade (com lixeira).
        if (! ContextoUsuario::tenantDaClinica(self::TENANT)) {
            throw new \RuntimeException('O tenant '.self::TENANT.' não está em SENTINEL_TENANTS_CLINICA. Nada foi gravado.');
        }

        $this->criarUsuarios(self::lerDemoSenha());

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

    /** A DEMO_SENHA do .env, validada. As mensagens nunca repetem a senha recebida. Espaços nas pontas não contam. */
    public static function lerDemoSenha(): string
    {
        $senha = (string) env('DEMO_SENHA', '');

        if ($senha === '') {
            throw new \RuntimeException('Defina a variável de ambiente DEMO_SENHA (senha das contas da clínica) antes de rodar o seed. Nada foi gravado.');
        }
        if (in_array(mb_strtolower(trim($senha)), self::SENHAS_CONHECIDAS, true)) {
            throw new \RuntimeException('DEMO_SENHA está na lista de senhas conhecidas; escolha outra. Nada foi gravado.');
        }
        if (mb_strlen(trim($senha)) < self::SENHA_MINIMO) {
            throw new \RuntimeException('DEMO_SENHA é curta demais: use pelo menos '.self::SENHA_MINIMO.' caracteres. Nada foi gravado.');
        }

        return $senha;
    }

    private function criarUsuarios(string $senha): void
    {
        // [chave usada no histórico, login, nome, papel, CRMV]. vet1 é o dono (veterinário com CRMV).
        $contas = [
            ['dono', 'vet1', 'vet1', 'dono', 'CRMV-SP 21458'],
            ['veterinario', 'vet2', 'vet2', 'veterinario', 'CRMV-SP 33871'],
            ['vet3', 'vet3', 'vet3', 'veterinario', 'CRMV-SP 40215'],
            ['recepcao', 'recepcao1', 'recepcao1', 'recepcao', null],
            ['recepcao2', 'recepcao2', 'recepcao2', 'recepcao', null],
            ['recepcao3', 'recepcao3', 'recepcao3', 'recepcao', null],
            ['atendente', 'estetica1', 'estetica1', 'atendente', null],
            ['estetica2', 'estetica2', 'estetica2', 'atendente', null],
            ['vendedor', 'vendas1', 'vendas1', 'vendedor', null],
            ['vendas2', 'vendas2', 'vendas2', 'vendedor', null],
            // Administração (17/11): cuida dos funcionários; por enquanto só gera e revoga os PINs (tela Supervisão).
            ['admin', 'administracao', 'Administração', 'admin', null],
        ];

        foreach ($contas as [$chave, $login, $nome, $papel, $crmv]) {
            $atributos = ['name' => $nome, 'email' => $login.'@'.self::DOMINIO_EMAIL, 'papel' => $papel, 'crmv' => $crmv];
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
            ['tipo' => 'servico', 'setor' => $setor, 'categoria' => $categoria, 'nome_comercial' => $nome, 'preco_centavos' => $preco, 'cobranca_por' => $por,
                'controla_estoque' => false, 'exige_receita' => false], self::TENANT);
        $produto = fn (string $setor, string $categoria, string $nome, int $preco, int $estoque, bool $receita = false) => $catalogo->criar(
            ['tipo' => 'produto', 'setor' => $setor, 'categoria' => $categoria, 'nome_comercial' => $nome, 'preco_centavos' => $preco, 'cobranca_por' => 'unidade',
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

        // 5 pets, um por tutor. Elisa, Fábio e Henrique são só clientes da loja (sem pet).
        foreach ([
            'thor' => ['ana', 'Thor', 'cao', 'Labrador', 'macho', '2019-03-10', 32000, '985112003456781', 'Puxa forte na guia'],
            'bidu' => ['bruno', 'Bidu', 'cao', 'Shih-tzu', 'macho', '2020-01-05', 6200, '985112003456782', null],
            'nina' => ['carla', 'Nina', 'gato', 'Siamês', 'femea', '2018-11-30', 3800, '985112003456783', 'Morde ao ser contida'],
            'rex' => ['diego', 'Rex', 'cao', 'Pastor alemão', 'macho', '2017-06-18', 38000, '985112003456784', 'Morde'],
            'amora' => ['gabriela', 'Amora', 'cao', 'Poodle', 'femea', '2021-05-03', 5200, null, 'Medo de secador'],
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
            [26, '10:00', 'bidu', 'veterinario', 'consulta', [['hemograma', 1]], 'cartao'],
            [24, '14:00', 'nina', 'veterinario', 'consulta', [], 'dinheiro'],
            [21, '09:00', 'rex', 'dono', 'exame', [['hemograma', 1]], 'pix'],
            [19, '11:00', 'amora', 'veterinario', 'cirurgia', [['castracao', 1]], 'cartao'],
            [16, '15:00', 'rex', 'dono', 'vacinacao', [['antirrabica', 1], ['v10', 1]], 'pix'],
            [14, '10:00', 'amora', 'veterinario', 'consulta', [['microchip', 1]], 'a_receber'],
            [9, '16:00', 'nina', 'veterinario', 'retorno', [], 'cartao'],
            [6, '09:00', 'nina', 'dono', 'consulta', [['vermifugo', 1]], 'a_receber'],
            [3, '13:00', 'bidu', 'veterinario', 'vacinacao', [['v10', 1]], 'pix'],
            [2, '10:00', 'thor', 'veterinario', 'consulta', [['hemograma', 1]], 'aberta'],
        ];

        foreach ($atendimentos as [$dias, $hora, $pet, $vet, $tipo, $itens, $pagamento]) {
            $inicio = $this->diaDaAgenda($dias, $hora);
            $this->atenderNaClinica($inicio, $pet, $vet, $tipo === 'retorno' ? 'retorno' : 'consulta', $tipo, $itens, $pagamento, [
                'anamnese' => 'Tutor relata o motivo da visita; sem queixas adicionais.',
                'exame_fisico' => 'Mucosas normocoradas, hidratado, temperatura dentro do normal.',
                'diagnostico' => 'Sem alterações relevantes para o quadro (dado fictício de demonstração).',
                'conduta' => 'Orientações ao tutor e retorno se necessário.',
            ]);
        }

        // Internação de 3 diárias (entrada há 12 dias no fim da tarde, alta há 9 dias de manhã).
        $this->atenderNaClinica($this->diaDaAgenda(12, '17:00'), 'rex', 'dono', 'consulta', 'internacao', [['internacao', 1]], 'pix', [
            'anamnese' => 'Vômitos recorrentes (dado fictício de demonstração).',
            'conduta' => 'Internação para fluidoterapia e observação.',
            'entrada_em' => $this->diaDaAgenda(12, '18:30'),
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
            [15, '09:30', 'thor', 'banho_g', 'tosa_h', 'pix'],
            [13, '11:00', 'bidu', 'banho_p', null, 'cartao'],
            [8, '15:00', 'bidu', 'banho_p', 'tosa_c', 'pix'],
            [5, '10:00', 'rex', 'banho_g', null, 'a_receber'],
            [1, '16:00', 'amora', 'banho_p', null, 'dinheiro'],
        ] as [$dias, $hora, $pet, $banho, $tosa, $pagamento]) {
            $inicio = $this->diaDaAgenda($dias, $hora);
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
            [25, '17:00', 'vendedor', 'elisa', [['bolinha', 2]], 'dinheiro'],
            [23, '10:15', 'recepcao', 'carla', [['antipulgas', 2]], 'cartao'],
            [18, '16:30', 'vendedor', 'diego', [['racao', 2]], 'cartao'],
            [17, '09:45', 'vendedor', 'fabio', [['coleira', 1], ['petisco', 3]], 'pix'],
            [11, '14:20', 'recepcao', 'bruno', [['vermifugo', 2]], 'dinheiro'],
            [10, '18:00', 'vendedor', 'gabriela', [['cama', 1]], 'cartao'],
            [7, '12:10', 'vendedor', null, [['shampoo', 1], ['petisco', 1]], 'pix'],
            [4, '15:40', 'vendedor', 'henrique', [['racao', 1]], null],
            [1, '11:30', 'recepcao', 'ana', [['antipulgas', 1]], null],
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
            [0, '17:00', 'amora', 'veterinario', 'retorno', 'confirmado'],
            [0, '17:00', 'bidu', 'atendente', 'banho_p', 'confirmado'],
            [1, '09:00', 'nina', 'dono', 'consulta', 'confirmado'],
            [1, '09:00', 'thor', 'atendente', 'banho_g', 'agendado'],
            [1, '10:00', 'bidu', 'veterinario', 'consulta', 'agendado'],
            [2, '14:00', 'nina', 'dono', 'consulta', 'agendado'],
            [2, '15:00', 'amora', 'atendente', 'tosa_c', 'agendado'],
            [3, '09:00', 'rex', 'veterinario', 'consulta', 'agendado'],
            [3, '11:00', 'rex', 'dono', 'retorno', 'agendado'],
            [4, '10:00', 'thor', 'veterinario', 'consulta', 'agendado'],
            [4, '13:00', 'rex', 'atendente', 'banho_g', 'agendado'],
            [5, '09:00', 'bidu', 'dono', 'consulta', 'agendado'],
            [5, '10:00', 'thor', 'atendente', 'banho_g', 'agendado'],
            [6, '11:00', 'thor', 'veterinario', 'consulta', 'agendado'],
        ];

        // Relógio na meia-noite de hoje: agendar no passado é recusado, e o horário de hoje pode já ter passado.
        $this->em($this->dia(0, '00:00'), function () use ($semana, $agenda) {
            foreach ($semana as [$dias, $hora, $pet, $quem, $servico, $status]) {
                $inicio = $this->diaDaAgenda(-$dias, $hora);
                $agendamento = $agenda->agendar(['pet_id' => $this->pets[$pet]->id, 'profissional_id' => $this->usuarios[$quem]->id,
                    'catalogo_item_id' => $this->itens[$servico]->id, 'inicio' => $inicio,
                    'fim' => $inicio->copy()->addMinutes($quem === 'atendente' ? 60 : 30)], self::TENANT);
                if ($status === 'confirmado') {
                    $agenda->alterarStatus($agendamento->id, 'confirmado', self::TENANT);
                }
            }
        });
    }

    /**
     * Despesas gerais (lançamentos de categoria "despesa"), para o lucro dos Resultados.
     *
     * Decisão do Lucas (15/11): a demo não mostra prejuízo. O seed recebe ~R$ 3.014 entre 15 e 29 dias atrás e ~R$ 1.463
     * nos últimos 14; as despesas ficam em ~50% e ~70% disso, e CADA uma cai num dia em que o seed recebe mais do que
     * ela (o "dias atrás" ao lado do valor). Assim nenhum dia, semana ou mês fica negativo, qualquer que seja a data em
     * que o seed rodar (as datas são relativas a hoje). ClinicaSeederTest prova isso. Os valores são simulados, na
     * escala da receita do seed, não de uma clínica real.
     */
    private function despesas(): void
    {
        $categoria = fn (string $nome) => CategoriaLancamento::create(['nome' => $nome, 'tipo' => 'despesa', 'tenant_id' => self::TENANT]);
        $fornecedor = Fornecedor::create(['nome' => 'Distribuidora PetMais Ltda', 'documento' => '11.222.333/0001-81', 'tenant_id' => self::TENANT]);
        $c = ['aluguel' => $categoria('Aluguel'), 'energia' => $categoria('Energia elétrica'), 'agua' => $categoria('Água'),
            'compras' => $categoria('Compras de estoque'), 'manutencao' => $categoria('Manutenção e higiene')];

        foreach ([
            // 15 a 29 dias atrás (receita ~R$ 3.014): R$ 1.551,60
            ['Aluguel (parcela quinzenal)', '450.00', 19, 'aluguel', 'conciliado', null],              // dia: R$ 600,00
            ['Compra de ração e petiscos', '320.00', 18, 'compras', 'conciliado', $fornecedor->id],      // dia: R$ 420,00
            ['Conta de energia', '210.80', 16, 'energia', 'conciliado', null],                          // dia: R$ 310,00
            ['Compra de medicamentos da farmácia', '175.50', 28, 'compras', 'conciliado', $fornecedor->id], // dia: R$ 240,00
            ['Manutenção do secador do banho e tosa', '160.00', 29, 'manutencao', 'conciliado', null], // dia: R$ 240,00
            ['Material de higiene do banho e tosa', '140.00', 21, 'manutencao', 'conciliado', null],    // dia: R$ 215,00
            ['Conta de água', '95.30', 26, 'agua', 'conciliado', null],                                 // dia: R$ 215,00
            // últimos 14 dias (receita ~R$ 1.463): R$ 1.021,45
            ['Aluguel (parcela quinzenal)', '450.00', 9, 'aluguel', 'pendente', null],                  // dia: R$ 770,00
            ['Compra de medicamentos da farmácia', '180.90', 9, 'compras', 'pendente', $fornecedor->id],
            ['Conta de energia', '165.40', 3, 'energia', 'pendente', null],                             // dia: R$ 240,00
            ['Compra de ração e petiscos', '98.00', 8, 'compras', 'pendente', $fornecedor->id],         // dia: R$ 120,00
            ['Material de higiene do banho e tosa', '85.00', 10, 'manutencao', 'pendente', null],       // dia: R$ 120,00
            ['Conta de água', '42.15', 1, 'agua', 'pendente', null],                                    // dia: R$ 50,00
        ] as [$descricao, $valor, $dias, $cat, $status, $fornecedorId]) {
            // 15/11: a despesa cai no mesmo dia da receita que a cobre. A receita da agenda pula o domingo (diaDaAgenda); a das
            // vendas avulsas (dias 18, 29, 10 e 1) e a do fechamento da internação (dia 9), não.
            $data = in_array($dias, [18, 29, 10, 1, 9], true) ? $this->dia($dias, '08:00') : $this->diaDaAgenda($dias, '08:00');
            Lancamento::create(['descricao' => $descricao, 'valor' => $valor, 'data' => $data->toDateString(), 'status' => $status,
                'categoria_lancamento_id' => $c[$cat]->id, 'fornecedor_id' => $fornecedorId, 'tenant_id' => self::TENANT]);
        }
    }

    private function mensagens(): void
    {
        foreach ([
            ['ana', null, 'Oi! Queria remarcar o banho do Thor para sábado, pode ser?', 'nova'],
            ['carla', null, 'A Nina está sem comer desde ontem, consigo uma consulta hoje?', 'nova'],
            [null, 'Marcos (19) 99333-1212', 'Vocês vendem ração para filhote de gato?', 'nova'],
            ['henrique', null, 'Obrigado pela ajuda na escolha da ração!', 'tratada'],
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

    /**
     * Como dia(), para a agenda (15/11): a grade só abre no expediente (config sentinel.agenda: segunda a sábado). Um
     * horário que cai no domingo vai para o dia útil vizinho: o sábado, se já passou; a segunda, se é futuro. As horas
     * da clínica são hora cheia (blocos de 1 h).
     */
    private function diaDaAgenda(int $diasAtras, string $hora): Carbon
    {
        $dia = $this->dia($diasAtras, $hora);
        $abre = array_map('intval', (array) config('sentinel.agenda.expediente.dias', []));
        for ($i = 0; $i < 7 && ! in_array($dia->dayOfWeekIso, $abre, true); $i++) {
            $dia = $dia->lt(Carbon::now()) ? $dia->subDay() : $dia->addDay();
        }

        return $dia;
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
