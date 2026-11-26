<?php

namespace App\Http\Controllers;

use App\Exceptions\RegraDeNegocioException;
use App\Models\Agendamento;
use App\Models\CatalogoItem;
use App\Models\Pet;
use App\Models\Tutor;
use App\Models\User;
use App\Services\AuditoriaService;
use App\Support\Front;
use App\Services\ConfiguracaoDaAgenda;
use App\Services\ContextoUsuario;
use App\Services\DetalhesAuditoria;
use App\Services\GradeDaAgenda;
use App\Services\ResultadosClinica;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Painel do dono (23/11): dashboard com 4 atalhos e quatro abas. Só a aba Clínica grava (expediente, intervalo e vagas por
 * bloco, em ConfiguracaoDaAgenda); Funcionários, Clientes e Petshop só listam o que já existe, com link para as telas
 * atuais. Só o dono (papel e tenant do ContextoUsuario, nunca do corpo): o Administrador da clínica vai para a
 * /supervisao na leitura e leva 403 na gravação; os demais papéis, 403 (a negação fica na auditoria). Fora da clínica não existe.
 */
class PainelDonoController extends Controller
{
    public const TOOL_PAINEL = 'ver_painel_dono';

    public const TOOL_SALVAR = 'salvar_configuracao_agenda';

    public const ABAS = ['dashboard' => 'Dashboard', 'clinica' => 'Clínica', 'funcionarios' => 'Funcionários', 'clientes' => 'Clientes', 'petshop' => 'Petshop'];

    /** Teto de linhas por lista (é um painel de leitura; o cadastro completo fica em Cadastros). */
    private const LIMITE = 50;

    public function __construct(
        private ContextoUsuario $contexto,
        private AuditoriaService $auditoria,
        private DetalhesAuditoria $detalhes,
        private ConfiguracaoDaAgenda $configuracao,
    ) {
    }

    public function mostrar(Request $request, ResultadosClinica $resultados)
    {
        abort_unless($this->contexto->dominio() === 'clinica', 404);
        if ($this->contexto->ehAdminDaClinica()) {
            return redirect('/supervisao');
        }
        if (! $this->contexto->ehDono()) {
            $this->auditar(self::TOOL_PAINEL, 'consultar', [], 'negado', 'Só o dono abre o painel.', false);
            abort(403);
        }

        $aba = $request->query('aba');
        $aba = is_string($aba) && isset(self::ABAS[$aba]) ? $aba : 'dashboard';
        $tenant = $this->contexto->tenantId();
        $dados = match ($aba) {
            'dashboard' => ['indicadores' => $this->indicadores($tenant, $resultados)],
            'clinica' => ['valores' => $this->configuracao->valores($tenant), 'maxVagas' => ConfiguracaoDaAgenda::MAX_VAGAS],
            'funcionarios' => ['funcionarios' => $this->funcionarios($tenant)],
            'clientes' => $this->clientes($tenant),
            'petshop' => ['servicos' => $this->petshop($tenant)],
        };
        $this->auditar(self::TOOL_PAINEL, 'consultar', ['aba' => $aba], 'sucesso', 'Painel do dono aberto (aba '.$aba.').', true);

        return Front::tela('clinica.painel', 'Painel', ['abas' => self::ABAS, 'aba' => $aba, ...$dados]);
    }

    public function salvarClinica(Request $request)
    {
        abort_unless($this->contexto->dominio() === 'clinica', 404);
        if (! $this->contexto->ehDono()) {
            $this->auditar(self::TOOL_SALVAR, 'atualizar', [], 'negado', 'Só o dono altera a configuração da agenda.', false);
            abort(403);
        }
        $tenant = $this->contexto->tenantId();

        // Vazio vira null (intervalo em branco = sem intervalo); vagas só aceita número inteiro.
        $vagas = $request->input('vagas');
        $dados = [
            'abre' => $this->texto($request->input('abre')), 'fecha' => $this->texto($request->input('fecha')),
            'intervalo_inicio' => $this->texto($request->input('intervalo_inicio')), 'intervalo_fim' => $this->texto($request->input('intervalo_fim')),
            'vagas' => is_string($vagas) && preg_match('/^\d{1,3}$/', $vagas) === 1 ? (int) $vagas : null,
        ];

        $anterior = $this->configuracao->valores($tenant);
        try {
            $this->configuracao->salvar($dados, $tenant);
        } catch (RegraDeNegocioException $e) {
            $this->auditar(self::TOOL_SALVAR, 'atualizar', $dados, 'recusado', $e->getMessage(), true);

            return redirect('/painel?aba=clinica')->withInput()->withErrors(['clinica' => $e->getMessage()]);
        }
        $this->detalhes->com(['valores_anteriores' => array_diff_key($anterior, ['personalizada' => 1])],
            fn () => $this->auditar(self::TOOL_SALVAR, 'atualizar', $dados, 'sucesso', 'Configuração da agenda atualizada.', true));

        return redirect('/painel?aba=clinica')->with('ok', 'Configuração salva. Vale para os próximos agendamentos; os que já existem não mudam.');
    }

    /** @return list<array{titulo: string, numero: string, link: string, icone: string}> */
    private function indicadores(int $tenant, ResultadosClinica $resultados): array
    {
        $fuso = (string) config('app.timezone');
        $hoje = CarbonImmutable::now($fuso)->startOfDay();
        $agendaHoje = Agendamento::query()->where('tenant_id', $tenant)->whereNotIn('status', Agendamento::STATUS_LIVRES)
            ->where('inicio', '>=', $hoje)->where('inicio', '<', $hoje->addDay())->count();
        $estoque = CatalogoItem::query()->where('tenant_id', $tenant)->where('controla_estoque', true)->count();
        $mes = $resultados->resumo($tenant, $hoje->startOfMonth(), $hoje->endOfMonth());

        return [
            ['titulo' => 'Agenda de hoje', 'numero' => (string) $agendaHoje, 'link' => '/agenda', 'icone' => 'M3 5h18v16H3z M7 3v4m10-4v4M3 10h18'],
            ['titulo' => 'Funcionários', 'numero' => (string) User::query()->where('tenant_id', $tenant)->where('ativo', true)->count(), 'link' => '/painel?aba=funcionarios', 'icone' => 'M16 21v-2a6 6 0 0 0-12 0v2 M14 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0 M17 12a6 6 0 0 1 4 6'],
            ['titulo' => 'Estoque', 'numero' => $estoque.($estoque === 1 ? ' item' : ' itens'), 'nota' => 'com controle de estoque. Abre o Catálogo, que também lista serviços.', 'link' => '/cadastros?cadastro=catalogo', 'icone' => 'M3 7l9-5 9 5v10l-9 5-9-5z M3 7l9 5 9-5m-9 5v10'],
            ['titulo' => 'Financeiro', 'numero' => ResultadosClinica::reais($mes['liquido']), 'link' => '/resultados', 'icone' => 'M3 5h18v14H3z M3 9h18m-5 6h2'],
        ];
    }

    /** @return list<array{nome: string, papel: string, supervisor: ?string}> */
    private function funcionarios(int $tenant): array
    {
        $rotulos = (array) config('sentinel.rotulos_papeis', []);

        return User::query()->where('tenant_id', $tenant)->where('ativo', true)->orderBy('name')->limit(self::LIMITE)->get(['name', 'papel', 'supervisor_setor'])
            ->map(fn (User $u) => ['nome' => $u->name, 'papel' => (string) ($rotulos[$u->papel] ?? $u->papel), 'supervisor' => $u->supervisor_setor])->all();
    }

    /** @return array{tutores: list<array{id: int, nome: string, pets: list<array{id: int, nome: string, especie: ?string}>}>, total: int} */
    private function clientes(int $tenant): array
    {
        $tutores = Tutor::query()->where('tenant_id', $tenant)->orderBy('nome')->limit(self::LIMITE)->get(['id', 'nome']);
        $pets = Pet::query()->where('tenant_id', $tenant)->whereIn('tutor_id', $tutores->pluck('id'))->orderBy('nome')->get(['id', 'tutor_id', 'nome', 'especie'])->groupBy('tutor_id');

        return [
            'total' => Tutor::query()->where('tenant_id', $tenant)->count(),
            'tutores' => $tutores->map(fn (Tutor $t) => ['id' => $t->id, 'nome' => $t->nome,
                'pets' => ($pets[$t->id] ?? collect())->map(fn (Pet $p) => ['id' => $p->id, 'nome' => $p->nome, 'especie' => $p->especie])->values()->all()])->all(),
        ];
    }

    /** @return list<array{nome: string, categoria: string, preco: string, duracao: ?int}> os itens do setor petshop */
    private function petshop(int $tenant): array
    {
        $grade = GradeDaAgenda::daConfiguracao($tenant);

        return CatalogoItem::query()->where('tenant_id', $tenant)->where('setor', 'petshop')->orderBy('nome_comercial')->limit(self::LIMITE)->get()
            ->map(fn (CatalogoItem $i) => ['nome' => $i->nome_comercial, 'categoria' => (string) $i->categoria,
                'preco' => ResultadosClinica::reais((int) $i->preco_centavos),
                'duracao' => $i->tipo === 'servico' ? $grade->duracaoPetshop($i->categoria) : null])->all();
    }

    private function texto(mixed $valor): ?string
    {
        return is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
    }

    private function auditar(string $tool, string $acao, array $parametros, string $resultado, string $mensagem, bool $permitido): void
    {
        $this->detalhes->com(['origem' => 'manual'], fn () => $this->auditoria->registrar(
            tool: $tool, acao: $acao, entidadeTipo: $acao === 'atualizar' ? 'ConfiguracaoAgenda' : null, entidadeId: null, parametros: $parametros,
            resultado: $resultado, mensagem: $mensagem, papel: $this->contexto->papel(), permitido: $permitido,
        ));
    }
}
