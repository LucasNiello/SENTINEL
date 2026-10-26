<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AiAgentService;
use App\Services\ConsultaAuditoria;
use App\Services\ContextoUsuario;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * B3 (Bloco 7): as telas da bancada fora do agente. Aqui só se monta a página; os dados
 * vêm das rotas JSON de sempre (/agente/consultar, /agente/formulario, /auditoria/registros),
 * que conferem RBAC, tenant e máscara no service. O papel daqui só esconde o que o
 * usuário não pode usar.
 */
class TelasController extends Controller
{
    /** Entidades da tela Cadastros: a consulta, o cadastro e os filtros (do esquema da tool). */
    private const CADASTROS = [
        'clientes' => ['rotulo' => 'Clientes', 'consultar' => 'consultar_clientes', 'criar' => 'criar_cliente',
            'filtros' => [['campo' => 'nome', 'rotulo' => 'Nome contém', 'tipo' => 'texto']]],
        'fornecedores' => ['rotulo' => 'Fornecedores', 'consultar' => 'consultar_fornecedores', 'criar' => 'criar_fornecedor',
            'filtros' => [['campo' => 'nome', 'rotulo' => 'Nome contém', 'tipo' => 'texto']]],
        'funcionarios' => ['rotulo' => 'Funcionários', 'consultar' => 'consultar_funcionarios', 'criar' => 'criar_funcionario',
            'filtros' => [['campo' => 'nome', 'rotulo' => 'Nome contém', 'tipo' => 'texto']]],
        'lancamentos' => ['rotulo' => 'Lançamentos', 'consultar' => 'consultar_lancamentos', 'criar' => 'criar_lancamento',
            'filtros' => [
                ['campo' => 'status', 'rotulo' => 'Status', 'tipo' => 'lista', 'opcoes' => ['pendente' => 'Pendente', 'conciliado' => 'Conciliado']],
                ['campo' => 'data_inicio', 'rotulo' => 'De', 'tipo' => 'data'],
                ['campo' => 'data_fim', 'rotulo' => 'Até', 'tipo' => 'data'],
            ]],
        'notas_fiscais' => ['rotulo' => 'Notas fiscais', 'consultar' => 'consultar_notas_fiscais', 'criar' => 'criar_nota_fiscal',
            'filtros' => [['campo' => 'tipo', 'rotulo' => 'Tipo', 'tipo' => 'lista', 'opcoes' => ['entrada' => 'Entrada', 'saida' => 'Saída']]]],
        'categorias' => ['rotulo' => 'Categorias de lançamento', 'consultar' => 'consultar_categorias_lancamento', 'criar' => 'criar_categoria_lancamento',
            'filtros' => [['campo' => 'tipo', 'rotulo' => 'Tipo', 'tipo' => 'lista', 'opcoes' => ['receita' => 'Receita', 'despesa' => 'Despesa']]]],
    ];

    private const TIPOS_LIXEIRA = [
        'lancamento' => 'Lançamentos', 'cliente' => 'Clientes', 'fornecedor' => 'Fornecedores',
        'funcionario' => 'Funcionários', 'nota_fiscal' => 'Notas fiscais', 'categoria_lancamento' => 'Categorias de lançamento',
    ];

    public function __construct(private AiAgentService $agente, private ContextoUsuario $contexto)
    {
    }

    public function cadastros()
    {
        // "Novo" só aparece para quem pode cadastrar aquela entidade (o /agente/formulario confere de novo).
        $entidades = array_map(fn (array $e) => [...$e, 'criar' => $this->agente->podeExecutar($e['criar']) ? $e['criar'] : null], self::CADASTROS);

        return view('telas.consulta', [
            'titulo' => 'Cadastros',
            'intro' => 'Liste, busque, cadastre e edite sem usar o agente. Toda gravação passa pela mesma revisão e confirmação.',
            'entidades' => $entidades,
        ]);
    }

    public function lixeira()
    {
        abort_unless($this->agente->podeExecutar('consultar_lixeira'), 403);

        return view('telas.consulta', [
            'titulo' => 'Lixeira',
            'intro' => 'Registros excluídos podem ser restaurados dentro do prazo; depois ficam arquivados. Restaurar e excluir de vez pedem a senha de um administrador.',
            'entidades' => ['lixeira' => ['rotulo' => 'Lixeira', 'consultar' => 'consultar_lixeira', 'criar' => null, 'filtros' => [
                ['campo' => 'tipo', 'rotulo' => 'Tipo de registro', 'tipo' => 'lista', 'opcoes' => self::TIPOS_LIXEIRA],
                ['campo' => 'situacao', 'rotulo' => 'Situação', 'tipo' => 'lista', 'opcoes' => ['lixeira' => 'Na lixeira', 'arquivado' => 'Arquivado']],
            ]]],
        ]);
    }

    public function auditoria(ConsultaAuditoria $consulta)
    {
        try {
            // Só admin; a tentativa negada fica na auditoria, como na rota JSON.
            $consulta->autorizar('consultar');
        } catch (AuthorizationException) {
            abort(403);
        }

        return view('telas.auditoria', [
            'usuarios' => User::query()->where('tenant_id', $this->contexto->tenantId())->orderBy('name')->pluck('name', 'id')->all(),
            'resultados' => ConsultaAuditoria::resultados(),
            'tools' => [...array_column(array_column($this->agente->tools(), 'function'), 'name'), ConsultaAuditoria::TOOL],
        ]);
    }
}
