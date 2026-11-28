<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AiAgentService;
use App\Services\ConsultaAuditoria;
use App\Services\ContextoUsuario;
use App\Support\Front;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/**
 * B3 (Bloco 7): as telas da bancada fora do agente. Aqui só se monta a página; os dados
 * vêm das rotas JSON de sempre (/agente/consultar, /agente/formulario, /auditoria/registros),
 * que conferem RBAC, tenant e máscara no service. O papel daqui só esconde o que o
 * usuário não pode usar.
 */
class TelasController extends Controller
{
    /** Entidades da tela Cadastros no domínio antigo (contabilidade): a consulta, o cadastro e os filtros (do esquema da tool). */
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

    /** Clínica vet: o mesmo molde, só configuração (as tools vêm de CatalogoDaClinica). */
    private const CADASTROS_CLINICA = [
        'tutores' => ['rotulo' => 'Tutores', 'consultar' => 'consultar_tutores', 'criar' => 'criar_tutor',
            'filtros' => [['campo' => 'nome', 'rotulo' => 'Nome contém', 'tipo' => 'texto']]],
        'pets' => ['rotulo' => 'Pets', 'consultar' => 'consultar_pets', 'criar' => 'criar_pet',
            'filtros' => [['campo' => 'nome', 'rotulo' => 'Nome contém', 'tipo' => 'texto']]],
        'catalogo' => ['rotulo' => 'Catálogo', 'consultar' => 'consultar_catalogo', 'criar' => 'criar_item_catalogo',
            'filtros' => [
                ['campo' => 'setor', 'rotulo' => 'Setor', 'tipo' => 'lista', 'opcoes' => ['clinica' => 'Clínica', 'petshop' => 'Petshop']],
                ['campo' => 'nome', 'rotulo' => 'Nome contém', 'tipo' => 'texto'],
            ]],
    ];

    private const TIPOS_LIXEIRA_CLINICA = ['tutor' => 'Tutores', 'pet' => 'Pets', 'item_catalogo' => 'Itens do catálogo'];

    private const TIPOS_LIXEIRA = [
        'lancamento' => 'Lançamentos', 'cliente' => 'Clientes', 'fornecedor' => 'Fornecedores',
        'funcionario' => 'Funcionários', 'nota_fiscal' => 'Notas fiscais', 'categoria_lancamento' => 'Categorias de lançamento',
    ];

    public function __construct(private AiAgentService $agente, private ContextoUsuario $contexto)
    {
    }

    public function cadastros(Request $request)
    {
        // T1: o Administrador da clínica só gerencia PINs.
        if ($this->contexto->ehAdminDaClinica()) {
            return redirect('/supervisao');
        }

        // "Novo" só aparece para quem pode cadastrar aquela entidade (o /agente/formulario confere de novo).
        $cadastros = $this->contexto->dominio() === 'clinica' ? self::CADASTROS_CLINICA : self::CADASTROS;
        $entidades = array_map(fn (array $e) => [...$e, 'criar' => $this->agente->podeExecutar($e['criar']) ? $e['criar'] : null], $cadastros);

        // ?cadastro=catalogo abre direto naquele cadastro, mas só entre os que este papel consulta (a mesma regra do RBAC da consulta).
        // Valor ausente, inválido ou não permitido: a tela abre no primeiro (Tutores).
        $pedido = $request->query('cadastro');
        $visiveis = array_keys(array_filter($cadastros, fn (array $e) => $this->agente->podeExecutar($e['consultar'])));
        $inicial = is_string($pedido) && in_array($pedido, $visiveis, true) ? $pedido : null;

        $dados = [
            'cadastroInicial' => $inicial,
            'titulo' => 'Cadastros',
            'intro' => 'Liste, busque, cadastre e edite sem usar o agente. Toda gravação passa pela mesma revisão e confirmação.',
            'entidades' => $entidades,
        ];
        if (! Front::react()) {
            return view('telas.consulta', $dados);
        }

        // React (26/11): a linha ganha Excluir (para a lixeira) e, nos pets, Arquivar/Reativar. O botão só aparece se o papel pode; o servidor confere de novo.
        $excluir = ['tutores' => 'excluir_tutor', 'pets' => 'excluir_pet', 'catalogo' => 'excluir_item_catalogo'];
        $dados['entidades'] = array_map(fn (array $e, string $chave) => [...$e, 'excluir' => isset($excluir[$chave]) && $this->agente->podeExecutar($excluir[$chave]) ? $excluir[$chave] : null], $entidades, array_keys($entidades));
        $dados['entidades'] = array_combine(array_keys($entidades), $dados['entidades']);
        $dados['podeArquivarPet'] = $this->agente->podeExecutar('arquivar_pet');
        $dados['podeDarEntradaEstoque'] = $this->agente->podeExecutar('dar_entrada_estoque');
        $dados['podeReativarPet'] = $this->agente->podeExecutar('reativar_pet');

        return Front::tela('telas.consulta', 'Consulta', $dados);
    }

    public function lixeira()
    {
        abort_unless($this->agente->podeExecutar('consultar_lixeira'), 403);

        return Front::tela('telas.consulta', 'Consulta', [
            'titulo' => 'Lixeira',
            'intro' => 'Registros excluídos podem ser restaurados dentro do prazo; depois ficam arquivados. Restaurar e excluir de vez pedem a senha de um administrador.',
            'entidades' => ['lixeira' => ['rotulo' => 'Lixeira', 'consultar' => 'consultar_lixeira', 'criar' => null, 'filtros' => [
                ['campo' => 'tipo', 'rotulo' => 'Tipo de registro', 'tipo' => 'lista', 'opcoes' => $this->contexto->dominio() === 'clinica' ? self::TIPOS_LIXEIRA_CLINICA : self::TIPOS_LIXEIRA],
                ['campo' => 'situacao', 'rotulo' => 'Situação', 'tipo' => 'lista', 'opcoes' => ['lixeira' => 'Na lixeira', 'arquivado' => 'Arquivado']],
            ]]],
        ]);
    }

    /**
     * Item I (clínica vet), T1 (14/11): o dono marca e desmarca os supervisores de setor; o Administrador e o dono geram e revogam
     * o PIN do dono e dos supervisores. Só monta a página: as rotas JSON /supervisao/* conferem dono, Administrador,
     * tenant e a senha de login de novo. Fora da clínica não existe (404, como as rotas JSON); os outros papéis não
     * têm o que fazer aqui (403), nem o supervisor (o PIN dele vem do dono ou do Administrador).
     */
    public function supervisao()
    {
        abort_unless($this->contexto->dominio() === 'clinica', 404);

        $ehDono = $this->contexto->ehDono();
        $ehAdmin = $this->contexto->ehAdminDaClinica();
        abort_unless($ehDono || $ehAdmin, 403);

        return Front::tela('telas.supervisao', 'Supervisao', [
            'ehDono' => $ehDono,
            'ehAdmin' => $ehAdmin,
            'rotulosPapeis' => (array) config('sentinel.rotulos_papeis', []),
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

        $dados = [
            'usuarios' => User::query()->where('tenant_id', $this->contexto->tenantId())->orderBy('name')->pluck('name', 'id')->all(),
            'resultados' => ConsultaAuditoria::resultados(),
            'tools' => [...array_column(array_column($this->agente->tools(), 'function'), 'name'), ConsultaAuditoria::TOOL,
                ...($this->contexto->dominio() === 'clinica' ? [ClinicaTelasController::TOOL_FICHA, ClinicaTelasController::TOOL_PRONTUARIO, ProntuarioController::TOOL_VER, ProntuarioController::TOOL_ABRIR, ProntuarioController::TOOL_ATUALIZAR, ProntuarioController::TOOL_ASSINAR, ProntuarioController::TOOL_ADENDAR, ClinicaTelasController::TOOL_FICHA_TUTOR, ClinicaTelasController::TOOL_RESULTADOS, PainelDonoController::TOOL_PAINEL, PainelDonoController::TOOL_SALVAR] : [])],
        ];
        if (! Front::react()) {
            return view('telas.auditoria', $dados);
        }

        // React: lista de {id, nome} (a ordem do nome é mantida; chave numérica de objeto JSON seria reordenada pelo navegador).
        $dados['usuarios'] = array_map(fn ($id, $nome) => ['id' => $id, 'nome' => $nome], array_keys($dados['usuarios']), array_values($dados['usuarios']));

        return Front::tela('telas.auditoria', 'Auditoria', $dados);
    }
}
