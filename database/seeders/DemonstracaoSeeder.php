<?php

namespace Database\Seeders;

use App\Models\CategoriaLancamento;
use App\Models\Cliente;
use App\Models\Fornecedor;
use App\Models\Funcionario;
use App\Models\Lancamento;
use App\Models\NotaFiscal;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Dados da apresentação final: escritório de contabilidade atendendo micro e pequenas empresas.
 * Só roda sob comando explícito (php artisan db:seed --class=DemonstracaoSeeder); fora do DatabaseSeeder.
 *
 * Tenants próprios (101 = empresa demo, 102 = poucos dados, só para provar isolamento), para não
 * misturar com os tenants 1 e 2 dos seeders de desenvolvimento. Idempotente: cada registro é
 * procurado pela chave natural (também na lixeira) antes de ser criado. Não grava em audit_logs.
 * CPFs e CNPJs têm dígitos válidos sobre bases inventadas; nomes são genéricos.
 */
class DemonstracaoSeeder extends Seeder
{
    public const TENANT_DEMO = 101;

    public const TENANT_ISOLAMENTO = 102;

    public function run(): void
    {
        $senha = (string) env('DEMO_SENHA', '');

        if ($senha === '') {
            throw new \RuntimeException('Defina a variável de ambiente DEMO_SENHA (senha dos usuários de demonstração) antes de rodar o DemonstracaoSeeder. Nada foi gravado.');
        }

        DB::transaction(function () use ($senha) {
            $this->usuarios($senha);
            $this->tenantDemo();
            $this->tenantIsolamento();
        });

        $this->command?->info('Demonstração pronta nos tenants '.self::TENANT_DEMO.' e '.self::TENANT_ISOLAMENTO.'. Senha: a de DEMO_SENHA.');
    }

    private function usuarios(string $senha): void
    {
        $usuarios = [
            ['name' => 'Leitura (demo)', 'email' => 'leitura@demo.sentinel.local', 'papel' => 'leitura', 'tenant_id' => self::TENANT_DEMO],
            ['name' => 'Operador (demo)', 'email' => 'operador@demo.sentinel.local', 'papel' => 'operador', 'tenant_id' => self::TENANT_DEMO],
            ['name' => 'Admin (demo)', 'email' => 'admin@demo.sentinel.local', 'papel' => 'admin', 'tenant_id' => self::TENANT_DEMO],
            ['name' => 'Admin (demo, tenant 102)', 'email' => 'admin.t2@demo.sentinel.local', 'papel' => 'admin', 'tenant_id' => self::TENANT_ISOLAMENTO],
        ];

        // Usuário que já existe fica como está (a senha não é trocada).
        foreach ($usuarios as $usuario) {
            User::query()->firstOrCreate(['email' => $usuario['email']], [...$usuario, 'password' => $senha]);
        }
    }

    private function tenantDemo(): void
    {
        $t = self::TENANT_DEMO;

        $honorarios = $this->categoria($t, 'Honorários contábeis', 'receita');
        $abertura = $this->categoria($t, 'Serviços de abertura', 'receita');
        $folha = $this->categoria($t, 'Folha de pagamento', 'despesa');
        $administrativas = $this->categoria($t, 'Despesas administrativas', 'despesa');

        // Nomes parecidos (lista de escolha), a Oficina com vínculos (bloqueio de exclusão) e a Academia na lixeira.
        $padariaMe = $this->cliente($t, 'Padaria Trigo Bom ME', '37.519.204/0001-43', 'financeiro@padariatrigobom.demo.sentinel.local', '(19) 3251-1040');
        $padariaFilial = $this->cliente($t, 'Padaria Trigo Bom Filial', '48.261.037/0001-08', 'filial@padariatrigobom.demo.sentinel.local', '(19) 3251-1041');
        $oficina = $this->cliente($t, 'Oficina Mecânica Engrenagem', '51.903.748/0001-35', 'contato@engrenagem.demo.sentinel.local', '(19) 3243-8810');
        $this->cliente($t, 'Studio Fit Academia', '60.841.295/0001-50', 'adm@studiofit.demo.sentinel.local', '(19) 3237-5020', diasNaLixeira: 10);
        $mercearia = $this->cliente($t, 'Mercearia Ponto Certo', '74.318.526/0001-50', 'mercearia@pontocerto.demo.sentinel.local', '(19) 3229-6614');
        $arquiteta = $this->cliente($t, 'Ana Ribeiro Arquitetura', '381.560.729-95', 'ana@ribeiroarq.demo.sentinel.local', '(19) 99812-3307');

        $imobiliaria = $this->fornecedor($t, 'Imobiliária Centro Sul', '28.194.763/0001-30', 'locacao@centrosul.demo.sentinel.local', '(19) 3236-4400');
        $software = $this->fornecedor($t, 'Contábil Sistemas Software', '93.526.014/0001-24', 'suporte@contabilsistemas.demo.sentinel.local', '(11) 4003-2210');
        $papelaria = $this->fornecedor($t, 'Papelaria Escritório Mais', '46.271.835/0001-87', 'vendas@escritoriomais.demo.sentinel.local', '(19) 3254-9031');
        $internet = $this->fornecedor($t, 'Conecta Fibra Internet', '15.783.492/0001-06', 'empresas@conectafibra.demo.sentinel.local', '(19) 3500-1212');

        $this->funcionario($t, 'Beatriz Lima', '412.873.950-23', 'Contadora', '6800.00', '2023-08-01');
        $this->funcionario($t, 'Carla Mendes', '538.206.147-53', 'Analista contábil', '4200.00', '2024-03-01');
        $this->funcionario($t, 'Rafael Souza', '694.130.582-70', 'Assistente fiscal', '2800.00', '2025-02-10');
        $diego = $this->funcionario($t, 'Diego Alves', '207.945.316-52', 'Auxiliar administrativo', '2100.00', '2025-05-05', demissao: '2026-09-15');

        $c = 'conciliado';
        $p = 'pendente';

        // Agosto: tudo conciliado.
        $honAgoPadaria = $this->lancamento($t, '2026-08-05', 'Honorários agosto — Padaria Trigo Bom ME', '850.00', $c, $honorarios, cliente: $padariaMe);
        $honAgoOficina = $this->lancamento($t, '2026-08-05', 'Honorários agosto — Oficina Mecânica Engrenagem', '1200.00', $c, $honorarios, cliente: $oficina);
        $this->lancamento($t, '2026-08-05', 'Honorários agosto — Mercearia Ponto Certo', '650.00', $c, $honorarios, cliente: $mercearia);
        $this->lancamento($t, '2026-08-10', 'Aluguel do escritório — agosto', '3200.00', $c, $administrativas, fornecedor: $imobiliaria);
        $softwareAgo = $this->lancamento($t, '2026-08-12', 'Licença do software contábil — agosto', '489.90', $c, $administrativas, fornecedor: $software);
        $aberturaArquiteta = $this->lancamento($t, '2026-08-20', 'Abertura de empresa — Ana Ribeiro Arquitetura', '1500.00', $c, $abertura, cliente: $arquiteta);
        $this->lancamento($t, '2026-08-25', 'Material de escritório — agosto', '312.80', $c, $administrativas, fornecedor: $papelaria);
        $this->lancamento($t, '2026-08-28', 'Folha de pagamento — agosto', '15900.00', $c, $folha);

        // Setembro: 2 conciliados e 10 pendentes (cabem num lote do RF03).
        $this->lancamento($t, '2026-09-05', 'Honorários setembro — Padaria Trigo Bom ME', '850.00', $c, $honorarios, cliente: $padariaMe);
        $aluguelSet = $this->lancamento($t, '2026-09-10', 'Aluguel do escritório — setembro', '3200.00', $c, $administrativas, fornecedor: $imobiliaria);
        $this->lancamento($t, '2026-09-05', 'Honorários setembro — Padaria Trigo Bom Filial', '720.00', $p, $honorarios, cliente: $padariaFilial);
        $this->lancamento($t, '2026-09-05', 'Honorários setembro — Oficina Mecânica Engrenagem', '1200.00', $p, $honorarios, cliente: $oficina);
        $this->lancamento($t, '2026-09-05', 'Honorários setembro — Mercearia Ponto Certo', '650.00', $p, $honorarios, cliente: $mercearia);
        $this->lancamento($t, '2026-09-08', 'Honorários setembro — Ana Ribeiro Arquitetura', '900.00', $p, $honorarios, cliente: $arquiteta);
        $this->lancamento($t, '2026-09-12', 'Licença do software contábil — setembro', '489.90', $p, $administrativas, fornecedor: $software);
        $this->lancamento($t, '2026-09-15', 'Material de escritório — setembro', '236.40', $p, $administrativas, fornecedor: $papelaria);
        $this->lancamento($t, '2026-09-15', 'Rescisão — Diego Alves', '3480.00', $p, $folha, funcionario: $diego);
        $this->lancamento($t, '2026-09-18', 'Internet fibra — setembro', '199.90', $p, $administrativas, fornecedor: $internet);
        $aberturaFilial = $this->lancamento($t, '2026-09-22', 'Abertura de filial — Padaria Trigo Bom Filial', '1850.00', $p, $abertura, cliente: $padariaFilial);
        $this->lancamento($t, '2026-09-29', 'Folha de pagamento — setembro', '15900.00', $p, $folha);

        // Outubro: misto, e um lançamento na lixeira.
        $this->lancamento($t, '2026-10-05', 'Honorários outubro — Padaria Trigo Bom ME', '850.00', $c, $honorarios, cliente: $padariaMe);
        $this->lancamento($t, '2026-10-05', 'Honorários outubro — Oficina Mecânica Engrenagem', '1200.00', $c, $honorarios, cliente: $oficina);
        $this->lancamento($t, '2026-10-05', 'Honorários outubro — Mercearia Ponto Certo', '650.00', $p, $honorarios, cliente: $mercearia);
        $this->lancamento($t, '2026-10-05', 'Honorários outubro — Padaria Trigo Bom Filial', '720.00', $p, $honorarios, cliente: $padariaFilial);
        $this->lancamento($t, '2026-10-10', 'Aluguel do escritório — outubro', '3200.00', $c, $administrativas, fornecedor: $imobiliaria);
        $this->lancamento($t, '2026-10-12', 'Licença do software contábil — outubro', '489.90', $p, $administrativas, fornecedor: $software);
        $this->lancamento($t, '2026-10-15', 'Honorários outubro — Ana Ribeiro Arquitetura', '900.00', $c, $honorarios, cliente: $arquiteta);
        $this->lancamento($t, '2026-10-18', 'Internet fibra — outubro', '199.90', $p, $administrativas, fornecedor: $internet);
        $this->lancamento($t, '2026-10-20', 'Café e copa — outubro', '96.50', $p, $administrativas, diasNaLixeira: 5);
        $this->lancamento($t, '2026-10-27', 'Folha de pagamento — outubro', '13800.00', $p, $folha);

        // A NF 1006 nasce com 18500.00 no lugar de 1850.00: o humano corrige na demo (valores_anteriores).
        $this->notaFiscal($t, '1001', 'saida', '850.00', '2026-08-05', $honAgoPadaria, cliente: $padariaMe);
        $this->notaFiscal($t, '1002', 'saida', '1200.00', '2026-08-05', $honAgoOficina, cliente: $oficina);
        $this->notaFiscal($t, '1003', 'saida', '1500.00', '2026-08-20', $aberturaArquiteta, cliente: $arquiteta);
        $this->notaFiscal($t, '1004', 'entrada', '3200.00', '2026-09-10', $aluguelSet, fornecedor: $imobiliaria);
        $this->notaFiscal($t, '1005', 'entrada', '489.90', '2026-08-12', $softwareAgo, fornecedor: $software);
        $this->notaFiscal($t, '1006', 'saida', '18500.00', '2026-09-22', $aberturaFilial, cliente: $padariaFilial);
    }

    private function tenantIsolamento(): void
    {
        $t = self::TENANT_ISOLAMENTO;

        $honorarios = $this->categoria($t, 'Honorários contábeis', 'receita');
        $distribuidora = $this->cliente($t, 'Distribuidora Horizonte', '82.640.157/0001-46', 'financeiro@horizonte.demo.sentinel.local', '(19) 3266-7070');

        $this->lancamento($t, '2026-08-05', 'Honorários agosto — Distribuidora Horizonte', '980.00', 'conciliado', $honorarios, cliente: $distribuidora);
        $this->lancamento($t, '2026-09-05', 'Honorários setembro — Distribuidora Horizonte', '980.00', 'pendente', $honorarios, cliente: $distribuidora);
        $this->lancamento($t, '2026-09-20', 'Aluguel — setembro', '2500.00', 'pendente');
    }

    private function categoria(int $tenantId, string $nome, string $tipo): CategoriaLancamento
    {
        return CategoriaLancamento::withTrashed()->firstOrCreate(['tenant_id' => $tenantId, 'nome' => $nome], ['tipo' => $tipo]);
    }

    private function cliente(int $tenantId, string $nome, string $documento, string $email, string $telefone, ?int $diasNaLixeira = null): Cliente
    {
        $cliente = Cliente::withTrashed()->firstOrCreate(
            ['tenant_id' => $tenantId, 'documento' => $documento],
            ['nome' => $nome, 'email' => $email, 'telefone' => $telefone],
        );

        return $this->naLixeira($cliente, $diasNaLixeira);
    }

    private function fornecedor(int $tenantId, string $nome, string $documento, string $email, string $telefone): Fornecedor
    {
        return Fornecedor::withTrashed()->firstOrCreate(
            ['tenant_id' => $tenantId, 'documento' => $documento],
            ['nome' => $nome, 'email' => $email, 'telefone' => $telefone],
        );
    }

    private function funcionario(int $tenantId, string $nome, string $cpf, string $cargo, string $salario, string $admissao, ?string $demissao = null): Funcionario
    {
        return Funcionario::withTrashed()->firstOrCreate(
            ['tenant_id' => $tenantId, 'cpf' => $cpf],
            ['nome' => $nome, 'cargo' => $cargo, 'salario' => $salario, 'data_admissao' => $admissao, 'data_demissao' => $demissao],
        );
    }

    /** Chave natural: tenant + descrição + data (whereDate: a coluna pode guardar a hora junto). */
    private function lancamento(
        int $tenantId, string $data, string $descricao, string $valor, string $status,
        ?CategoriaLancamento $categoria = null, ?Cliente $cliente = null, ?Fornecedor $fornecedor = null,
        ?Funcionario $funcionario = null, ?int $diasNaLixeira = null,
    ): Lancamento {
        $lancamento = Lancamento::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('descricao', $descricao)
            ->whereDate('data', $data)
            ->first()
            ?? Lancamento::create([
                'tenant_id' => $tenantId,
                'descricao' => $descricao,
                'data' => $data,
                'valor' => $valor,
                'status' => $status,
                'categoria_lancamento_id' => $categoria?->id,
                'cliente_id' => $cliente?->id,
                'fornecedor_id' => $fornecedor?->id,
                'funcionario_id' => $funcionario?->id,
            ]);

        return $this->naLixeira($lancamento, $diasNaLixeira);
    }

    private function notaFiscal(
        int $tenantId, string $numero, string $tipo, string $valor, string $emissao, Lancamento $lancamento,
        ?Cliente $cliente = null, ?Fornecedor $fornecedor = null,
    ): NotaFiscal {
        return NotaFiscal::withTrashed()->firstOrCreate(
            ['tenant_id' => $tenantId, 'numero' => $numero],
            [
                'tipo' => $tipo,
                'valor' => $valor,
                'data_emissao' => $emissao,
                'lancamento_id' => $lancamento->id,
                'cliente_id' => $cliente?->id,
                'fornecedor_id' => $fornecedor?->id,
            ],
        );
    }

    /** Só põe na lixeira quando acabou de criar: na segunda execução a data original fica. */
    private function naLixeira(Model $registro, ?int $dias): Model
    {
        if ($dias !== null && $registro->wasRecentlyCreated) {
            $registro->forceFill(['deleted_at' => now()->subDays($dias)])->save();
        }

        return $registro;
    }
}
