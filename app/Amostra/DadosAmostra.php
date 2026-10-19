<?php

namespace App\Amostra;

/**
 * AMOSTRA — único lugar com dados falsos das telas de demonstração.
 *
 * Nada aqui lê ou grava no banco. Tudo é fictício e existe só para o grupo
 * enxergar a "cara" do sistema. Para remover as amostras do projeto, apague
 * este arquivo, AmostraController, routes/amostra.php, resources/views/amostra
 * e public/amostra, e tire o `require` de routes/web.php.
 */
final class DadosAmostra
{
    public const PAPEIS = ['leitura', 'operador', 'admin'];

    public static function papelValido(?string $papel): string
    {
        return in_array($papel, self::PAPEIS, true) ? $papel : 'admin';
    }

    /** Permissões fictícias por papel (hipótese de trabalho — ver MAPA_TELA_BACKEND.md). */
    public static function pode(string $papel, string $acao): bool
    {
        $matriz = [
            'consultar' => ['leitura', 'operador', 'admin'],
            'escrever' => ['operador', 'admin'],
            'criar_funcionario' => ['admin'],
            'excluir' => ['operador', 'admin'],
            'ver_lixeira' => ['operador', 'admin'],
            'restaurar' => ['operador', 'admin'], // operador chama o supervisor (reautenticação do admin)
            'auditoria' => ['admin'],
            'arquivados' => ['admin'],
            'exclusao_fisica' => ['admin'],
            'retencao' => ['admin'],
        ];

        return in_array($papel, $matriz[$acao] ?? [], true);
    }

    public static function usuario(string $papel): array
    {
        $nomes = [
            'leitura' => ['Lívia Prado', 'leitura@sentinel.local'],
            'operador' => ['Otávio Reis', 'operador@sentinel.local'],
            'admin' => ['Adriana Campos', 'admin@sentinel.local'],
        ];

        return ['nome' => $nomes[$papel][0], 'email' => $nomes[$papel][1], 'papel' => $papel, 'tenant' => 1];
    }

    /**
     * Monta o endereço de uma amostra mantendo o papel escolhido.
     * O caminho pode trazer "?consulta" e "#ancora" (ex.: "lixeira?modal=exclusao", "cadastros#governanca").
     */
    public static function url(string $caminho, string $papel, array $consulta = []): string
    {
        $ancora = '';
        if (str_contains($caminho, '#')) {
            [$caminho, $ancora] = explode('#', $caminho, 2);
            $ancora = '#' . $ancora;
        }
        $consultaCaminho = [];
        if (str_contains($caminho, '?')) {
            [$caminho, $texto] = explode('?', $caminho, 2);
            parse_str($texto, $consultaCaminho);
        }
        $base = '/amostra' . ($caminho === '' ? '' : '/' . ltrim($caminho, '/'));

        return $base . '?' . http_build_query(array_merge(['papel' => $papel], $consultaCaminho, $consulta)) . $ancora;
    }

    public static function menu(string $papel, string $modo = 'normal'): array
    {
        $itens = [
            ['chave' => 'agente', 'rotulo' => 'Agente', 'rota' => 'agente', 'visivel' => true, 'breve' => false],
            ['chave' => 'cadastros', 'rotulo' => 'Cadastros', 'rota' => 'cadastros', 'visivel' => true, 'breve' => false],
            ['chave' => 'auditoria', 'rotulo' => 'Auditoria', 'rota' => 'auditoria', 'visivel' => self::pode($papel, 'auditoria'), 'breve' => $modo === 'breve'],
            ['chave' => 'lixeira', 'rotulo' => 'Lixeira', 'rota' => 'lixeira', 'visivel' => self::pode($papel, 'ver_lixeira'), 'breve' => $modo === 'breve'],
            ['chave' => 'arquivados', 'rotulo' => 'Arquivados', 'rota' => 'arquivados', 'visivel' => self::pode($papel, 'arquivados'), 'breve' => $modo === 'breve'],
            ['chave' => 'fila', 'rotulo' => 'Fila de comandos', 'rota' => 'fila', 'visivel' => true, 'breve' => false, 'hipotese' => true],
        ];

        return array_values(array_filter($itens, fn ($i) => $i['visivel']));
    }

    // -----------------------------------------------------------------
    // Inventário das 44 telas (mesma fonte para o índice e para o mapa)
    // -----------------------------------------------------------------

    /**
     * origem: N = está nos checklists do Notion; I = inferida (confirmar com o grupo).
     * situacao: amostra | existe | hipotese.
     */
    public static function inventario(): array
    {
        $i = fn ($n, $grupo, $tela, $origem, $situacao, $rota, $falta) => compact('n', 'grupo', 'tela', 'origem', 'situacao', 'rota', 'falta');

        return [
            $i(1, 'A. Públicas', 'Vitrine "/" (farol 3D ligado à rolagem, única ação "Entrar")', 'N', 'existe', null, 'Já feita pelo Matheus na branch malaman / main (PR #10). Falta juntar com a Manveru e servir o Three.js localmente (o laboratório não acessa servidor externo). Sem backend.'),
            $i(2, 'A. Públicas', 'Login (vazio, erro, carregando, limite de tentativas)', 'N', 'amostra', 'login', 'Login e throttle já existem (RF10). Falta só herdar a casca visual; sem backend novo.'),
            $i(3, 'A. Públicas', 'Loader 2D (farol girando)', 'N', 'existe', null, 'Já existe na vitrine da main (landing.css). Falta decidir se a bancada usa algum carregamento inicial. Sem backend.'),
            $i(4, 'B. Casca', 'Layout base: header, navegação e footer', 'N', 'amostra', 'casca', 'Papel e nome do usuário vêm do ContextoUsuario (existe). O "estado do agente" do header ainda não tem fonte de dados definida.'),
            $i(5, 'B. Casca', 'Menu com itens "em breve"', 'N', 'amostra', 'casca?menu=breve', 'Sem backend. Os itens são ligados conforme cada rota nascer (Fase 5).'),
            $i(6, 'B. Casca', 'Versão celular e tablet (375 px)', 'N', 'amostra', 'responsivo', 'Sem backend.'),
            $i(7, 'C. Agente', 'Chat vazio / boas-vindas', 'I', 'amostra', 'agente/vazio', 'Sem backend (texto fixo).'),
            $i(8, 'C. Agente', 'Carregando ("Consultando…")', 'N', 'amostra', 'agente/carregando', 'Já existe no agente atual.'),
            $i(9, 'C. Agente', 'Resultado de leitura (tabela crua)', 'N', 'amostra', 'agente/leitura', 'Existe para as 6 consultas. Falta só o formato (números em mono) — é front.'),
            $i(10, 'C. Agente', 'Aviso ("havia 2 pedidos…")', 'N', 'amostra', 'agente/aviso', 'Já existe (campo "aviso").'),
            $i(11, 'C. Agente', 'Botão exportar CSV na tabela', 'N', 'amostra', 'agente/leitura#exportar', 'Falta o endpoint de exportação respeitando a máscara LGPD (RF02, Fase 5).'),
            $i(12, 'C. Agente', 'Linha da tabela clicável → abre atualizar', 'N', 'amostra', 'agente/linha-clicavel', 'Falta atualizar() nos 5 services, as tools atualizar_* e o formulário do RF04 (Fase 5).'),
            $i(13, 'C. Agente', 'Card de confirmação por risco (neutro, âmbar, vermelho; segurando, soltou, quase esgotado, expirado)', 'N', 'amostra', 'agente/confirmacao-escrita', 'Hold e expiração já existem. Falta /agente/cancelar auditado (R2), limpar a sessão ao cancelar/expirar (R3) e a frase "Nada foi gravado" ao soltar.'),
            $i(14, 'C. Agente', 'Formulário pré-preenchido pelo agente (RF04)', 'N', 'amostra', 'agente/formulario', 'FALTA TUDO NO BACKEND: tipo de resposta "formulario", campos vindos do FormRequest, token preso à tool, filtro de campos, auditoria proposto × confirmado. Não conta como pronto.'),
            $i(15, 'C. Agente', 'Negado por permissão', 'N', 'amostra', 'agente/negado', 'Existe; falta revisar a redação em linguagem simples (R6).'),
            $i(16, 'C. Agente', 'Erros: rede, servidor, limite (429), tool desconhecida', 'N', 'amostra', 'agente/erro-rede', 'Existem; falta "Tool desconhecida" virar frase simples e a mensagem de erro não sumir sozinha (Tarefa 2).'),
            $i(17, 'C. Agente', 'Popup de resultado (✔ / ✕)', 'N', 'amostra', 'agente/popups', 'Existe; falta tirar o fade de 1,5 s e deixar o erro fixo (Tarefa 2). É só front.'),
            $i(18, 'C. Agente', 'Histórico da conversa (memória) e limite', 'N', 'amostra', 'agente/historico', 'Falta persistir o histórico na sessão, reenviar no array messages e definir limite (Fase 5, item 5).'),
            $i(19, 'D. Formulários', 'Cliente (criar / atualizar)', 'N', 'amostra', 'formulario/cliente', 'Criar existe como tool; atualizar não existe. Falta expor os campos do ClienteRequest ao formulário.'),
            $i(20, 'D. Formulários', 'Fornecedor (criar / atualizar)', 'N', 'amostra', 'formulario/fornecedor', 'Idem Cliente.'),
            $i(21, 'D. Formulários', 'Funcionário (criar / atualizar, só admin)', 'N', 'amostra', 'formulario/funcionario', 'Idem; CPF e salário precisam de máscara/cuidado LGPD no formulário.'),
            $i(22, 'D. Formulários', 'Nota fiscal (criar / atualizar)', 'N', 'amostra', 'formulario/nota-fiscal', 'Idem; regras condicionais (cliente × fornecedor conforme o tipo) vêm do NotaFiscalRequest.'),
            $i(23, 'D. Formulários', 'Categoria de lançamento (criar / atualizar)', 'N', 'amostra', 'formulario/categoria', 'Idem Cliente.'),
            $i(24, 'D. Formulários', 'Lançamento (criar)', 'N', 'amostra', 'formulario/lancamento', 'Tool criar_lancamento existe; ainda não passa por validar() (dívida do CLAUDE.md).'),
            $i(25, 'E. Modo manual', 'Menu "Cadastros" (porta de entrada)', 'N', 'amostra', 'cadastros', 'Falta tudo: rotas e controllers do modo manual (RF05/RNF05, Fase 5).'),
            $i(26, 'E. Modo manual', 'Listagem de cada entidade com busca, sem IA', 'I', 'amostra', 'cadastros/clientes', 'Falta controller de listagem/busca/paginação reaproveitando os services.'),
            $i(27, 'E. Modo manual', 'Mesma governança no modo manual (RBAC, tenant, validação, confirmação, auditoria)', 'N', 'amostra', 'cadastros#governanca', 'Falta reaplicar toda a governança nas rotas manuais.'),
            $i(28, 'F. Lixeira', 'Lixeira (itens excluídos)', 'N', 'amostra', 'lixeira', 'Soft delete existe; falta listar por tenant e as tools de excluir/restaurar (RF08–RF10). Hoje nenhum código chama restaurarDaLixeira.'),
            $i(29, 'F. Lixeira', 'Confirmação de exclusão (risco "exclusao", borda vermelha)', 'N', 'amostra', 'lixeira?modal=exclusao', 'Falta a tool de excluir com risco "exclusao".'),
            $i(30, 'F. Lixeira', 'Reautenticação do admin para restaurar', 'N', 'amostra', 'lixeira?modal=reautenticacao', 'ReautenticacaoAdmin existe como mecanismo; falta rota/tela e gravar QUAL admin autorizou em audit_logs.'),
            $i(31, 'F. Lixeira', 'Conflito ao restaurar (o "pai" está na lixeira)', 'N', 'amostra', 'lixeira?modal=conflito', 'Falta a checagem do pai e a decisão: recusar ou restaurar junto.'),
            $i(32, 'F. Lixeira', 'Arquivados (retenção expirada)', 'N', 'amostra', 'arquivados', 'Comando sentinel:arquivar-expirados existe; falta listagem e a tarefa schedule:run no Agendador do Windows (RF11).'),
            $i(33, 'F. Lixeira', 'Exclusão física definitiva (admin)', 'N', 'amostra', 'arquivados?modal=exclusao-fisica', 'Falta a ação do admin com reautenticação, risco "exclusao" e auditoria (RF12). Nunca automática.'),
            $i(34, 'F. Lixeira', 'Configuração do prazo de retenção', 'I', 'amostra', 'lixeira/retencao', 'Falta definir onde o prazo é guardado (config ou tabela). Não está em nenhum checklist.'),
            $i(35, 'G. Auditoria', 'Tela de auditoria com filtros', 'N', 'amostra', 'auditoria', 'Tabela audit_logs existe; falta rota, consulta com filtros e paginação (RF07).'),
            $i(36, 'G. Auditoria', 'Detalhe do registro: proposto × confirmado', 'N', 'amostra', 'auditoria?detalhe=3', 'Depende do RF04: hoje a auditoria não guarda o que a IA propôs separado do que o humano confirmou.'),
            $i(37, 'G. Auditoria', 'Coluna de tempo de resposta da IA (RNF04)', 'N', 'amostra', 'auditoria', 'Falta medir e gravar o tempo de cada chamada (coluna nova em audit_logs).'),
            $i(38, 'G. Auditoria', 'Acesso conforme o papel (tela negada)', 'N', 'amostra', 'auditoria?papel=leitura', 'Falta definir quais papéis acessam a auditoria (aqui: só admin, hipótese).'),
            $i(39, 'H. Sistema', 'Erro 419 (sessão expirada)', 'N', 'amostra', 'erro/419', 'Tarefa B7: página em português usando o layout.'),
            $i(40, 'H. Sistema', 'Erro 429 (muitos pedidos)', 'N', 'amostra', 'erro/429', 'Idem (B7). O limite de 10 pedidos/min já existe.'),
            $i(41, 'H. Sistema', 'Erro 403 (sem permissão)', 'I', 'amostra', 'erro/403', 'Falta a view de erro 403 no layout.'),
            $i(42, 'H. Sistema', 'Erro 404 (não encontrado)', 'I', 'amostra', 'erro/404', 'Falta a view de erro 404 no layout.'),
            $i(43, 'H. Sistema', 'Erro 500 (erro inesperado)', 'I', 'amostra', 'erro/500', 'Falta a view de erro 500 no layout (sem vazar detalhes técnicos).'),
            $i(44, 'I. Indefinida', 'Fila de comandos', 'N', 'hipotese', 'fila', 'SEM DEFINIÇÃO (Tarefa 5). A tela é uma hipótese: pedidos feitos com a IA fora do ar ficam na fila e só são executados com confirmação humana.'),
        ];
    }

    /** Inventário agrupado ("A. Públicas" => [...]), na ordem em que aparece. */
    public static function inventarioAgrupado(): array
    {
        $grupos = [];
        foreach (self::inventario() as $item) {
            $grupos[$item['grupo']][] = $item;
        }

        return $grupos;
    }

    // -----------------------------------------------------------------
    // Entidades e listagens
    // -----------------------------------------------------------------

    public static function entidades(): array
    {
        return [
            'clientes' => ['slug' => 'clientes', 'formulario' => 'cliente', 'rotulo' => 'Clientes', 'singular' => 'cliente', 'colunas' => ['id', 'nome', 'documento', 'email', 'telefone']],
            'fornecedores' => ['slug' => 'fornecedores', 'formulario' => 'fornecedor', 'rotulo' => 'Fornecedores', 'singular' => 'fornecedor', 'colunas' => ['id', 'nome', 'documento', 'email', 'telefone']],
            'funcionarios' => ['slug' => 'funcionarios', 'formulario' => 'funcionario', 'rotulo' => 'Funcionários', 'singular' => 'funcionário', 'colunas' => ['id', 'nome', 'cpf', 'cargo', 'salario', 'data_admissao']],
            'notas-fiscais' => ['slug' => 'notas-fiscais', 'formulario' => 'nota-fiscal', 'rotulo' => 'Notas fiscais', 'singular' => 'nota fiscal', 'colunas' => ['id', 'numero', 'tipo', 'valor', 'data_emissao']],
            'categorias' => ['slug' => 'categorias', 'formulario' => 'categoria', 'rotulo' => 'Categorias de lançamento', 'singular' => 'categoria', 'colunas' => ['id', 'nome', 'tipo']],
            'lancamentos' => ['slug' => 'lancamentos', 'formulario' => 'lancamento', 'rotulo' => 'Lançamentos', 'singular' => 'lançamento', 'colunas' => ['id', 'descricao', 'valor', 'status', 'data']],
        ];
    }

    /** Colunas que mostram número (fonte mono, alinhadas à direita). */
    public static function colunasNumericas(): array
    {
        return ['id', 'valor', 'salario'];
    }

    public static function linhas(string $slug): array
    {
        $dados = [
            'clientes' => [
                [1, 'Mercado Aurora Ltda', '12.345.678/0001-90', 'compras@aurora.example', '(19) 3555-0101'],
                [2, 'Padaria Trigo & Mel', '23.456.789/0001-01', 'contato@trigomel.example', '(19) 3555-0102'],
                [3, 'Oficina Rota 50', '34.567.890/0001-12', 'rota50@oficina.example', '(19) 3555-0103'],
                [4, 'Clínica Vida Plena', '45.678.901/0001-23', 'adm@vidaplena.example', '(19) 3555-0104'],
                [5, 'João da Silva', '***.456.789-**', 'joao.silva@exemplo.example', '(19) 99555-0105'],
                [6, 'Escola Pequeno Mundo', '56.789.012/0001-34', 'secretaria@pmundo.example', '(19) 3555-0106'],
                [7, 'Studio Pixel Design', '67.890.123/0001-45', 'oi@studiopixel.example', '(19) 3555-0107'],
                [8, 'Maria Aparecida Souza', '***.321.654-**', 'maria.souza@exemplo.example', '(19) 99555-0108'],
            ],
            'fornecedores' => [
                [1, 'Papelaria Central S/A', '78.901.234/0001-56', 'vendas@papelariac.example', '(19) 3666-0201'],
                [2, 'Energia Limeira', '89.012.345/0001-67', 'atendimento@energialim.example', '(19) 3666-0202'],
                [3, 'Tech Suprimentos ME', '90.123.456/0001-78', 'comercial@techsup.example', '(19) 3666-0203'],
                [4, 'Limpeza Brilho Total', '01.234.567/0001-89', 'brilho@limpeza.example', '(19) 3666-0204'],
                [5, 'Contabilidade Parceira', '11.222.333/0001-44', 'contato@parceira.example', '(19) 3666-0205'],
                [6, 'Transportes Rápido Sul', '22.333.444/0001-55', 'fretes@rapidosul.example', '(19) 3666-0206'],
            ],
            'funcionarios' => [
                [1, 'Beatriz Almeida', '***.111.222-**', 'Analista fiscal', 'R$ 4.800,00', '2022-03-14'],
                [2, 'Carlos Eduardo Lima', '***.333.444-**', 'Auxiliar contábil', 'R$ 3.100,00', '2023-08-01'],
                [3, 'Fernanda Rocha', '***.555.666-**', 'Gerente administrativa', 'R$ 7.900,00', '2020-01-20'],
                [4, 'Rafael Mendes', '***.777.888-**', 'Estagiário', 'R$ 1.600,00', '2025-02-03'],
                [5, 'Patrícia Nunes', '***.999.000-**', 'Assistente de RH', 'R$ 3.600,00', '2024-05-12'],
            ],
            'notas-fiscais' => [
                [1, 'NF-000482', 'saída', 'R$ 3.450,00', '2026-09-02'],
                [2, 'NF-000483', 'saída', 'R$ 1.280,00', '2026-09-05'],
                [3, 'NF-001207', 'entrada', 'R$ 9.870,50', '2026-09-08'],
                [4, 'NF-000484', 'saída', 'R$ 560,00', '2026-09-15'],
                [5, 'NF-001208', 'entrada', 'R$ 2.140,00', '2026-09-21'],
                [6, 'NF-000485', 'saída', 'R$ 4.990,00', '2026-09-30'],
            ],
            'categorias' => [
                [1, 'Honorários contábeis', 'receita'],
                [2, 'Abertura de empresa', 'receita'],
                [3, 'Aluguel do escritório', 'despesa'],
                [4, 'Energia e internet', 'despesa'],
                [5, 'Material de escritório', 'despesa'],
                [6, 'Consultoria tributária', 'receita'],
            ],
            'lancamentos' => [
                [1, 'Honorários — Mercado Aurora', 'R$ 1.250,00', 'conciliado', '2026-09-01'],
                [2, 'Aluguel do escritório', 'R$ 2.800,00', 'conciliado', '2026-09-05'],
                [3, 'Energia — setembro', 'R$ 412,35', 'pendente', '2026-09-10'],
                [4, 'Honorários — Padaria Trigo & Mel', 'R$ 980,00', 'pendente', '2026-09-12'],
                [5, 'Material de escritório', 'R$ 236,90', 'conciliado', '2026-09-14'],
                [6, 'Consultoria tributária — Oficina Rota 50', 'R$ 1.800,00', 'pendente', '2026-09-18'],
                [7, 'Internet — setembro', 'R$ 189,90', 'conciliado', '2026-09-20'],
                [8, 'Abertura de empresa — Studio Pixel', 'R$ 1.500,00', 'pendente', '2026-09-25'],
            ],
        ];

        $entidade = self::entidades()[$slug] ?? null;
        if (!$entidade) {
            return [];
        }

        return array_map(fn ($linha) => array_combine($entidade['colunas'], $linha), $dados[$slug]);
    }

    public static function buscar(string $slug, ?string $termo): array
    {
        $linhas = self::linhas($slug);
        $termo = trim((string) $termo);
        if ($termo === '') {
            return $linhas;
        }

        return array_values(array_filter($linhas, function ($linha) use ($termo) {
            foreach ($linha as $valor) {
                if (mb_stripos((string) $valor, $termo) !== false) {
                    return true;
                }
            }

            return false;
        }));
    }

    // -----------------------------------------------------------------
    // Formulários por entidade (campos do FormRequest, sem tenant_id)
    // -----------------------------------------------------------------

    public static function formulario(string $entidade): ?array
    {
        $texto = fn ($nome, $rotulo, $obrigatorio, $extra = []) => ['nome' => $nome, 'rotulo' => $rotulo, 'tipo' => 'text', 'obrigatorio' => $obrigatorio] + $extra;

        $mapa = [
            'cliente' => [
                'titulo' => 'Cliente', 'rotulo_entidade' => 'cliente', 'papel_minimo' => 'operador', 'lista' => 'clientes',
                'campos' => [
                    $texto('nome', 'Nome', true, ['ia' => 'João da Silva', 'atual' => 'João da Silva', 'maximo' => 255]),
                    $texto('documento', 'CPF ou CNPJ', false, ['ia' => '123.456.789-09', 'atual' => '***.456.789-**', 'dica' => 'Opcional. Só números ou com pontos e traço.', 'maximo' => 32]),
                    $texto('email', 'E-mail', false, ['tipo' => 'email', 'ia' => null, 'atual' => 'joao.silva@exemplo.example']),
                    $texto('telefone', 'Telefone', false, ['tipo' => 'tel', 'ia' => '(19) 99555-0105', 'atual' => '(19) 99555-0105', 'maximo' => 20]),
                ],
                'erro' => ['campo' => 'email', 'mensagem' => 'Informe um e-mail válido, como nome@empresa.com.br.', 'valor' => 'joao.silva@exemplo'],
                'faltante' => 'documento',
            ],
            'fornecedor' => [
                'titulo' => 'Fornecedor', 'rotulo_entidade' => 'fornecedor', 'papel_minimo' => 'operador', 'lista' => 'fornecedores',
                'campos' => [
                    $texto('nome', 'Nome', true, ['ia' => 'Papelaria Central S/A', 'atual' => 'Papelaria Central S/A', 'maximo' => 255]),
                    $texto('documento', 'CNPJ ou CPF', false, ['ia' => '78.901.234/0001-56', 'atual' => '78.901.234/0001-56', 'maximo' => 32]),
                    $texto('email', 'E-mail', false, ['tipo' => 'email', 'ia' => null, 'atual' => 'vendas@papelariac.example']),
                    $texto('telefone', 'Telefone', false, ['tipo' => 'tel', 'ia' => null, 'atual' => '(19) 3666-0201', 'maximo' => 20]),
                ],
                'erro' => ['campo' => 'email', 'mensagem' => 'Informe um e-mail válido, como nome@empresa.com.br.', 'valor' => 'vendas@papelariac'],
                'faltante' => 'nome',
            ],
            'funcionario' => [
                'titulo' => 'Funcionário', 'rotulo_entidade' => 'funcionário', 'papel_minimo' => 'admin', 'lista' => 'funcionarios',
                'campos' => [
                    $texto('nome', 'Nome', true, ['ia' => 'Beatriz Almeida', 'atual' => 'Beatriz Almeida']),
                    $texto('cpf', 'CPF', true, ['ia' => '111.222.333-44', 'atual' => '***.111.222-**', 'dica' => 'Dado sensível: aparece mascarado depois de salvo.', 'maximo' => 14]),
                    $texto('cargo', 'Cargo', false, ['ia' => 'Analista fiscal', 'atual' => 'Analista fiscal']),
                    $texto('salario', 'Salário (R$)', true, ['tipo' => 'number', 'ia' => null, 'atual' => '4800.00', 'numerico' => true, 'dica' => 'Dado sensível. Máximo 99.999.999,99.']),
                    $texto('data_admissao', 'Data de admissão', true, ['tipo' => 'date', 'ia' => '2022-03-14', 'atual' => '2022-03-14']),
                    $texto('data_demissao', 'Data de demissão', false, ['tipo' => 'date', 'ia' => null, 'atual' => '', 'dica' => 'Só preencha se a pessoa saiu. Não pode ser antes da admissão.']),
                ],
                'erro' => ['campo' => 'data_demissao', 'mensagem' => 'A data de demissão não pode ser anterior à data de admissão.', 'valor' => '2021-12-01'],
                'faltante' => 'salario',
            ],
            'nota-fiscal' => [
                'titulo' => 'Nota fiscal', 'rotulo_entidade' => 'nota fiscal', 'papel_minimo' => 'operador', 'lista' => 'notas-fiscais',
                'campos' => [
                    $texto('numero', 'Número', true, ['ia' => 'NF-000486', 'atual' => 'NF-000486', 'maximo' => 50]),
                    $texto('tipo', 'Tipo', true, ['tipo' => 'select', 'opcoes' => ['saida' => 'Saída (venda)', 'entrada' => 'Entrada (compra)'], 'ia' => 'saida', 'atual' => 'saida']),
                    $texto('valor', 'Valor (R$)', true, ['tipo' => 'number', 'ia' => '3450.00', 'atual' => '3450.00', 'numerico' => true]),
                    $texto('data_emissao', 'Data de emissão', true, ['tipo' => 'date', 'ia' => '2026-10-06', 'atual' => '2026-10-06']),
                    $texto('cliente_id', 'Cliente', false, ['tipo' => 'select', 'opcoes' => ['' => '— escolha —', '1' => 'Mercado Aurora Ltda', '2' => 'Padaria Trigo & Mel', '3' => 'Oficina Rota 50'], 'ia' => '1', 'atual' => '1', 'dica' => 'Obrigatório quando o tipo é saída. Proibido quando é entrada.']),
                    $texto('fornecedor_id', 'Fornecedor', false, ['tipo' => 'select', 'opcoes' => ['' => '— escolha —', '1' => 'Papelaria Central S/A', '2' => 'Energia Limeira'], 'ia' => null, 'atual' => '', 'dica' => 'Obrigatório quando o tipo é entrada. Proibido quando é saída.']),
                    $texto('lancamento_id', 'Lançamento relacionado', false, ['tipo' => 'select', 'opcoes' => ['' => '— nenhum —', '1' => 'Honorários — Mercado Aurora', '4' => 'Honorários — Padaria Trigo & Mel'], 'ia' => null, 'atual' => '']),
                ],
                'erro' => ['campo' => 'fornecedor_id', 'mensagem' => 'Nota de saída não pode ter fornecedor. Escolha só o cliente.', 'valor' => '1'],
                'faltante' => 'numero',
            ],
            'categoria' => [
                'titulo' => 'Categoria de lançamento', 'rotulo_entidade' => 'categoria', 'papel_minimo' => 'operador', 'lista' => 'categorias',
                'campos' => [
                    $texto('nome', 'Nome', true, ['ia' => 'Consultoria tributária', 'atual' => 'Consultoria tributária']),
                    $texto('tipo', 'Tipo', true, ['tipo' => 'select', 'opcoes' => ['receita' => 'Receita', 'despesa' => 'Despesa'], 'ia' => 'receita', 'atual' => 'receita']),
                ],
                'erro' => ['campo' => 'nome', 'mensagem' => 'O nome é obrigatório.', 'valor' => ''],
                'faltante' => 'nome',
            ],
            'lancamento' => [
                'titulo' => 'Lançamento', 'rotulo_entidade' => 'lançamento', 'papel_minimo' => 'operador', 'lista' => 'lancamentos',
                'campos' => [
                    $texto('descricao', 'Descrição', true, ['ia' => 'teste roteiro', 'atual' => 'Energia — setembro', 'maximo' => 255]),
                    $texto('valor', 'Valor (R$)', true, ['tipo' => 'number', 'ia' => '50.00', 'atual' => '412.35', 'numerico' => true, 'dica' => 'Maior que zero.']),
                    $texto('data', 'Data', true, ['tipo' => 'date', 'ia' => '2026-10-06', 'atual' => '2026-09-10']),
                ],
                'erro' => ['campo' => 'valor', 'mensagem' => 'O valor precisa ser maior que zero.', 'valor' => '0'],
                'faltante' => 'valor',
            ],
        ];

        return $mapa[$entidade] ?? null;
    }

    // -----------------------------------------------------------------
    // Estados do agente (blocos do chat)
    // -----------------------------------------------------------------

    public static function estadosAgente(): array
    {
        $tabelaLancamentos = ['tipo' => 'tabela', 'entidade' => 'lancamentos', 'colunas' => ['id', 'descricao', 'valor', 'status', 'data'], 'linhas' => array_slice(self::linhas('lancamentos'), 0, 5), 'legenda' => '5 de 8 lançamentos — pendentes primeiro'];
        $confirmacao = fn ($risco, $situacao, $titulo, $dados, $botao = 'Confirmar') => ['tipo' => 'confirmacao', 'risco' => $risco, 'situacao' => $situacao, 'titulo' => $titulo, 'dados' => $dados, 'botao' => $botao];
        $dadosLancamento = [['Ação', 'Criar lançamento'], ['Descrição', 'teste roteiro'], ['Valor', 'R$ 50,00'], ['Data', '06/10/2026']];

        return [
            'conversa' => ['titulo' => 'Conversa completa (visão geral)', 'blocos' => [
                ['tipo' => 'agente', 'texto' => 'Olá, Adriana. Posso consultar lançamentos, clientes, fornecedores, funcionários, notas fiscais e categorias. Para gravar qualquer coisa, eu preparo e você confirma.'],
                ['tipo' => 'usuario', 'texto' => 'liste os lançamentos pendentes'],
                $tabelaLancamentos,
                ['tipo' => 'usuario', 'texto' => 'crie um lançamento de 50 reais "teste roteiro" com data de hoje'],
                $confirmacao('escrita', 'ativa', 'Criar lançamento', $dadosLancamento),
                ['tipo' => 'usuario', 'texto' => 'cadastre o cliente João da Silva, CPF 123.456.789-09'],
                ['tipo' => 'formulario', 'entidade' => 'cliente', 'modo' => 'criar', 'estado' => 'normal'],
            ]],
            'vazio' => ['titulo' => 'Chat vazio / boas-vindas', 'blocos' => [
                ['tipo' => 'agente', 'texto' => 'Olá, Adriana. Escreva o que você precisa, por exemplo: "liste os lançamentos pendentes" ou "cadastre o cliente João da Silva".'],
                ['tipo' => 'sugestoes', 'itens' => ['liste os lançamentos pendentes', 'mostre os clientes', 'cadastre um fornecedor', 'quais notas fiscais de entrada em setembro?']],
            ]],
            'carregando' => ['titulo' => 'Carregando ("Consultando…")', 'blocos' => [
                ['tipo' => 'usuario', 'texto' => 'liste os lançamentos'],
                ['tipo' => 'carregando', 'texto' => 'Consultando…'],
            ]],
            'leitura' => ['titulo' => 'Resultado de leitura (tabela crua)', 'blocos' => [
                ['tipo' => 'usuario', 'texto' => 'liste os lançamentos'],
                $tabelaLancamentos,
            ]],
            'aviso' => ['titulo' => 'Aviso junto da resposta', 'blocos' => [
                ['tipo' => 'usuario', 'texto' => 'liste os clientes e os fornecedores'],
                ['tipo' => 'tabela', 'entidade' => 'clientes', 'colunas' => ['id', 'nome', 'documento', 'email', 'telefone'], 'linhas' => array_slice(self::linhas('clientes'), 0, 4), 'legenda' => '4 de 8 clientes'],
                ['tipo' => 'aviso', 'texto' => 'Havia 2 pedidos na mensagem e só o primeiro foi feito. Peça os fornecedores em uma nova mensagem.'],
            ]],
            'linha-clicavel' => ['titulo' => 'Linha clicável abre "atualizar"', 'blocos' => [
                ['tipo' => 'usuario', 'texto' => 'mostre os clientes'],
                ['tipo' => 'tabela', 'entidade' => 'clientes', 'colunas' => ['id', 'nome', 'documento', 'email', 'telefone'], 'linhas' => array_slice(self::linhas('clientes'), 0, 4), 'legenda' => 'Clique em uma linha (ou aperte Enter nela) para atualizar o cadastro.', 'clicavel' => true],
                ['tipo' => 'agente', 'texto' => 'Abri o cadastro de "Padaria Trigo & Mel" com os dados atuais. Altere o que precisar e segure Confirmar.'],
                ['tipo' => 'formulario', 'entidade' => 'cliente', 'modo' => 'atualizar', 'estado' => 'atualizar'],
            ]],
            'confirmacao-escrita' => ['titulo' => 'Confirmação — escrita (borda âmbar)', 'blocos' => [
                ['tipo' => 'usuario', 'texto' => 'crie um lançamento de 50 reais "teste roteiro" com data de hoje'],
                $confirmacao('escrita', 'ativa', 'Criar lançamento', $dadosLancamento),
                ['tipo' => 'nota', 'texto' => 'Segure "Confirmar" por 2 segundos. Se soltar antes, nada é gravado.'],
            ]],
            'confirmacao-exclusao' => ['titulo' => 'Confirmação — exclusão (borda vermelha)', 'blocos' => [
                ['tipo' => 'usuario', 'texto' => 'exclua o cliente Oficina Rota 50'],
                $confirmacao('exclusao', 'ativa', 'Mover cliente para a lixeira', [['Ação', 'Mover para a lixeira'], ['Cliente', 'Oficina Rota 50'], ['Documento', '34.567.890/0001-12'], ['Depois', 'Dá para restaurar pela Lixeira (precisa de um administrador).']], 'Excluir'),
            ]],
            'confirmacao-leitura' => ['titulo' => 'Card de leitura (borda neutra)', 'blocos' => [
                ['tipo' => 'usuario', 'texto' => 'exporte os lançamentos de setembro'],
                $confirmacao('leitura', 'ativa', 'Exportar lançamentos para CSV', [['Ação', 'Exportar tabela'], ['Período', '01/09/2026 a 30/09/2026'], ['Linhas', '8'], ['Dados sensíveis', 'Mascarados']], 'Exportar'),
            ]],
            'soltou' => ['titulo' => 'Soltou antes do fim ("Nada foi gravado")', 'blocos' => [
                $confirmacao('escrita', 'soltou', 'Criar lançamento', $dadosLancamento),
            ]],
            'quase' => ['titulo' => 'Tempo quase esgotado', 'blocos' => [
                $confirmacao('escrita', 'quase', 'Criar lançamento', $dadosLancamento),
            ]],
            'expirada' => ['titulo' => 'Confirmação expirada', 'blocos' => [
                $confirmacao('escrita', 'expirada', 'Criar lançamento', $dadosLancamento),
            ]],
            'formulario' => ['titulo' => 'Formulário pré-preenchido (RF04)', 'blocos' => [
                ['tipo' => 'usuario', 'texto' => 'cadastre o cliente João da Silva, CPF 123.456.789-09, telefone (19) 99555-0105'],
                ['tipo' => 'agente', 'texto' => 'Preparei o cadastro com o que entendi. Confira, corrija se precisar e segure Confirmar.'],
                ['tipo' => 'formulario', 'entidade' => 'cliente', 'modo' => 'criar', 'estado' => 'normal'],
            ]],
            'formulario-faltantes' => ['titulo' => 'Formulário com campos faltando', 'blocos' => [
                ['tipo' => 'usuario', 'texto' => 'cadastre um funcionário chamado Beatriz Almeida, analista fiscal, admitida em 14/03/2022'],
                ['tipo' => 'agente', 'texto' => 'Falta o salário. Preencha o campo marcado e depois confirme.'],
                ['tipo' => 'formulario', 'entidade' => 'funcionario', 'modo' => 'criar', 'estado' => 'faltantes'],
            ]],
            'formulario-erro' => ['titulo' => 'Formulário com erro de validação', 'blocos' => [
                ['tipo' => 'formulario', 'entidade' => 'cliente', 'modo' => 'criar', 'estado' => 'erro'],
            ]],
            'negado' => ['titulo' => 'Negado por permissão', 'blocos' => [
                ['tipo' => 'usuario', 'texto' => 'cadastre o funcionário João, CPF 123.456.789-09'],
                ['tipo' => 'negado', 'texto' => 'Você não tem permissão para cadastrar funcionários. Peça a um administrador.'],
            ]],
            'erro-rede' => ['titulo' => 'Erro: não deu para falar com a IA', 'blocos' => [
                ['tipo' => 'usuario', 'texto' => 'liste os lançamentos'],
                ['tipo' => 'erro', 'texto' => 'Não foi possível contatar o agente de IA agora.', 'acao' => 'Tente de novo em alguns instantes. Você ainda pode usar o menu Cadastros.'],
            ]],
            'erro-servidor' => ['titulo' => 'Erro: servidor indisponível', 'blocos' => [
                ['tipo' => 'usuario', 'texto' => 'liste os clientes'],
                ['tipo' => 'erro', 'texto' => 'Falha ao contatar o servidor.', 'acao' => 'Confira sua conexão e tente de novo. Se continuar, avise o suporte.'],
            ]],
            'erro-429' => ['titulo' => 'Erro: muitos pedidos (429)', 'blocos' => [
                ['tipo' => 'usuario', 'texto' => 'oi'],
                ['tipo' => 'erro', 'texto' => 'Muitos pedidos em pouco tempo.', 'acao' => 'Aguarde um minuto e envie de novo.'],
            ]],
            'erro-tool' => ['titulo' => 'Erro: pedido que o agente não sabe fazer', 'blocos' => [
                ['tipo' => 'usuario', 'texto' => 'apague todos os lançamentos de 2025'],
                ['tipo' => 'erro', 'texto' => 'Não sei fazer isso ainda.', 'acao' => 'Você pode pedir consultas e cadastros. Para excluir, peça um item por vez.'],
            ]],
            'popups' => ['titulo' => 'Popups de resultado', 'blocos' => [
                ['tipo' => 'popup', 'estado' => 'sucesso', 'texto' => '✔ Confirmado'],
                ['tipo' => 'popup', 'estado' => 'neutro', 'texto' => '✕ Cancelado'],
                ['tipo' => 'popup', 'estado' => 'erro', 'texto' => '✕ Não foi possível gravar. Tente de novo.'],
                ['tipo' => 'nota', 'texto' => 'Sucesso e cancelamento somem em 2,5 s. O erro fica na tela até você fechar.'],
            ]],
            'historico' => ['titulo' => 'Memória da conversa e limite', 'blocos' => [
                ['tipo' => 'memoria', 'texto' => 'O agente lembra das últimas 10 mensagens desta conversa.'],
                ['tipo' => 'usuario', 'texto' => 'liste os clientes'],
                ['tipo' => 'tabela', 'entidade' => 'clientes', 'colunas' => ['id', 'nome', 'documento', 'email', 'telefone'], 'linhas' => array_slice(self::linhas('clientes'), 0, 3), 'legenda' => '3 de 8 clientes'],
                ['tipo' => 'usuario', 'texto' => 'exclua o segundo'],
                ['tipo' => 'agente', 'texto' => 'O segundo da tabela é "Padaria Trigo & Mel". Vou preparar a exclusão para você confirmar.'],
                ['tipo' => 'memoria', 'texto' => 'Conversa longa: as mensagens mais antigas saem da memória do agente, mas continuam visíveis aqui.'],
            ]],
        ];
    }

    // -----------------------------------------------------------------
    // Lixeira, arquivados, retenção
    // -----------------------------------------------------------------

    public static function lixeira(): array
    {
        return [
            ['id' => 3, 'entidade' => 'Cliente', 'nome' => 'Oficina Rota 50', 'excluido_por' => 'Otávio Reis', 'excluido_em' => '02/10/2026', 'expira_em' => '31/12/2026', 'conflito' => false],
            ['id' => 9, 'entidade' => 'Lançamento', 'nome' => 'Consultoria tributária — Oficina Rota 50', 'excluido_por' => 'Otávio Reis', 'excluido_em' => '02/10/2026', 'expira_em' => '31/12/2026', 'conflito' => true, 'pai' => 'Cliente "Oficina Rota 50"'],
            ['id' => 2, 'entidade' => 'Fornecedor', 'nome' => 'Limpeza Brilho Total', 'excluido_por' => 'Adriana Campos', 'excluido_em' => '25/09/2026', 'expira_em' => '24/12/2026', 'conflito' => false],
            ['id' => 5, 'entidade' => 'Nota fiscal', 'nome' => 'NF-000479', 'excluido_por' => 'Adriana Campos', 'excluido_em' => '18/09/2026', 'expira_em' => '17/12/2026', 'conflito' => false],
        ];
    }

    public static function arquivados(): array
    {
        return [
            ['id' => 14, 'entidade' => 'Cliente', 'nome' => 'Bar do Zé', 'arquivado_em' => '30/06/2026', 'motivo' => 'Prazo de 90 dias na lixeira terminou', 'relacoes' => '3 lançamentos, 1 nota fiscal'],
            ['id' => 21, 'entidade' => 'Funcionário', 'nome' => 'Marcos Tavares', 'arquivado_em' => '15/07/2026', 'motivo' => 'Prazo de 90 dias na lixeira terminou', 'relacoes' => 'Retenção trabalhista'],
            ['id' => 8, 'entidade' => 'Fornecedor', 'nome' => 'Gráfica Expressa', 'arquivado_em' => '02/08/2026', 'motivo' => 'Prazo de 90 dias na lixeira terminou', 'relacoes' => '2 notas fiscais'],
        ];
    }

    public static function retencoes(): array
    {
        return ['30' => '30 dias', '60' => '60 dias', '90' => '90 dias (padrão)', '180' => '180 dias', '365' => '1 ano'];
    }

    // -----------------------------------------------------------------
    // Auditoria
    // -----------------------------------------------------------------

    public static function auditoria(): array
    {
        $r = fn ($id, $quando, $usuario, $papel, $tool, $entidade, $resultado, $ms, $proposto = null, $confirmado = null, $mensagem = '') => compact('id', 'quando', 'usuario', 'papel', 'tool', 'entidade', 'resultado', 'ms', 'proposto', 'confirmado', 'mensagem');

        return [
            $r(12, '06/10/2026 13:58', 'Otávio Reis', 'operador', 'criar_lancamento', 'Lançamento', 'sucesso', 1840, ['descricao' => 'teste roteiro', 'valor' => '50.00', 'data' => '2026-10-06'], ['descricao' => 'teste roteiro', 'valor' => '50.00', 'data' => '2026-10-06']),
            $r(11, '06/10/2026 13:55', 'Lívia Prado', 'leitura', 'criar_cliente', 'Cliente', 'negado', 1210, ['nome' => 'Teste'], null, 'Papel sem permissão para escrever.'),
            $r(10, '06/10/2026 13:51', 'Otávio Reis', 'operador', 'consultar_lancamentos', 'Lançamento', 'sucesso', 1520),
            $r(9, '06/10/2026 11:20', 'Adriana Campos', 'admin', 'criar_cliente', 'Cliente', 'sucesso', 2310, ['nome' => 'João da Silva', 'documento' => '123.456.789-09', 'telefone' => '(19) 99555-0105'], ['nome' => 'João da Silva', 'documento' => '***.456.789-**', 'telefone' => '(19) 99555-0105'], 'Registro gravado.'),
            $r(8, '06/10/2026 11:12', 'Adriana Campos', 'admin', 'criar_funcionario', 'Funcionário', 'sucesso', 2890, ['nome' => 'Beatriz Almeida', 'cargo' => 'Analista fiscal', 'salario' => '4800.00'], ['nome' => 'Beatriz Almeida', 'cargo' => 'Analista fiscal', 'salario' => '4850.00'], 'O humano corrigiu o salário antes de confirmar.'),
            $r(7, '05/10/2026 17:40', 'Otávio Reis', 'operador', 'criar_lancamento', 'Lançamento', 'cancelado', 1675, ['descricao' => 'Energia — outubro', 'valor' => '430.00'], null, 'Cancelado pelo usuário.'),
            $r(6, '05/10/2026 17:02', 'Otávio Reis', 'operador', 'atualizar_status_lancamento', 'Lançamento', 'expirado', 1390, ['id' => '3', 'novo_status' => 'conciliado'], null, 'Confirmação expirou. Nada foi gravado.'),
            $r(5, '05/10/2026 16:44', 'Otávio Reis', 'operador', 'consultar_clientes', 'Cliente', 'erro', 30000, null, null, 'A IA não respondeu em 30 s.'),
            $r(4, '05/10/2026 10:15', 'Adriana Campos', 'admin', 'consultar_notas_fiscais', 'Nota fiscal', 'sucesso', 1760),
            $r(3, '04/10/2026 15:30', 'Otávio Reis', 'operador', 'excluir_cliente', 'Cliente', 'sucesso', 2105, ['id' => '3', 'nome' => 'Oficina Rota 50'], ['id' => '3', 'nome' => 'Oficina Rota 50'], 'Movido para a lixeira.'),
            $r(2, '04/10/2026 09:48', 'Adriana Campos', 'admin', 'restaurar_fornecedor', 'Fornecedor', 'sucesso', 980, ['id' => '2'], ['id' => '2'], 'Admin que autorizou: Adriana Campos.'),
            $r(1, '03/10/2026 18:05', 'Lívia Prado', 'leitura', 'consultar_funcionarios', 'Funcionário', 'sucesso', 1430),
        ];
    }

    public static function resultadosAuditoria(): array
    {
        return ['sucesso' => ['✔', 'Sucesso'], 'negado' => ['✕', 'Negado'], 'erro' => ['!', 'Erro'], 'cancelado' => ['–', 'Cancelado'], 'expirado' => ['⌛', 'Expirado']];
    }

    public static function filtrarAuditoria(array $filtros): array
    {
        return array_values(array_filter(self::auditoria(), function ($linha) use ($filtros) {
            if (!empty($filtros['usuario']) && $linha['usuario'] !== $filtros['usuario']) {
                return false;
            }
            if (!empty($filtros['tool']) && $linha['tool'] !== $filtros['tool']) {
                return false;
            }
            if (!empty($filtros['resultado']) && $linha['resultado'] !== $filtros['resultado']) {
                return false;
            }

            return true;
        }));
    }

    // -----------------------------------------------------------------
    // Fila de comandos (HIPÓTESE — definição pendente)
    // -----------------------------------------------------------------

    public static function fila(): array
    {
        return [
            ['id' => 1, 'texto' => 'liste os lançamentos pendentes', 'tipo' => 'leitura', 'enviado' => '14:02', 'status' => 'fila', 'detalhe' => 'Esperando o agente voltar.'],
            ['id' => 2, 'texto' => 'cadastre o cliente Café do Largo', 'tipo' => 'escrita', 'enviado' => '14:03', 'status' => 'fila', 'detalhe' => 'Quando o agente voltar, o formulário abre para você confirmar.'],
            ['id' => 3, 'texto' => 'mostre as notas fiscais de setembro', 'tipo' => 'leitura', 'enviado' => '13:48', 'status' => 'concluido', 'detalhe' => 'Resposta já está no chat.'],
            ['id' => 4, 'texto' => 'exclua o fornecedor Gráfica Expressa', 'tipo' => 'exclusao', 'enviado' => '13:40', 'status' => 'confirmar', 'detalhe' => 'Pronto para você confirmar. Nada foi excluído.'],
            ['id' => 5, 'texto' => 'oi', 'tipo' => 'leitura', 'enviado' => '13:31', 'status' => 'falhou', 'detalhe' => 'Não foi possível enviar. Tente de novo.'],
        ];
    }

    public static function statusFila(): array
    {
        return ['fila' => ['⏳', 'Na fila'], 'confirmar' => ['✋', 'Aguardando sua confirmação'], 'concluido' => ['✔', 'Concluído'], 'falhou' => ['!', 'Falhou']];
    }

    // -----------------------------------------------------------------
    // Páginas de erro
    // -----------------------------------------------------------------

    public static function erros(): array
    {
        return [
            '403' => ['codigo' => '403', 'titulo' => 'Você não tem acesso a esta página', 'texto' => 'O seu papel não permite ver esta tela. Se precisar dela, peça a um administrador.', 'acoes' => [['Voltar ao agente', 'agente']]],
            '404' => ['codigo' => '404', 'titulo' => 'Não encontramos esta página', 'texto' => 'O endereço pode estar errado ou a página foi removida.', 'acoes' => [['Voltar ao agente', 'agente'], ['Ir para Cadastros', 'cadastros']]],
            '419' => ['codigo' => '419', 'titulo' => 'Sua sessão expirou', 'texto' => 'Por segurança, você precisa entrar de novo. O que você estava fazendo não foi gravado.', 'acoes' => [['Entrar de novo', 'login']]],
            '429' => ['codigo' => '429', 'titulo' => 'Muitos pedidos em pouco tempo', 'texto' => 'Aguarde um minuto e tente de novo. Isso protege o sistema e o seu crédito de IA.', 'acoes' => [['Voltar ao agente', 'agente']]],
            '500' => ['codigo' => '500', 'titulo' => 'Algo deu errado do nosso lado', 'texto' => 'Não foi culpa sua. Tente de novo em instantes. Se continuar, avise o suporte informando a hora do erro.', 'acoes' => [['Voltar ao agente', 'agente']]],
        ];
    }
}
