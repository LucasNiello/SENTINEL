<?php

namespace App\Http\Controllers;

use App\Amostra\DadosAmostra;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;

/**
 * AMOSTRA: só monta as telas de demonstração a partir de DadosAmostra.
 * Não usa banco, não usa IA, não grava nada. Ver app/Amostra/DadosAmostra.php.
 */
class AmostraController extends Controller
{
    private const MODAIS = ['reautenticacao', 'conflito', 'exclusao', 'exclusao-fisica'];

    public function indice(Request $r): View
    {
        return $this->ver($r, 'indice', '', 'Índice das telas', '', [
            'grupos' => DadosAmostra::inventarioAgrupado(),
            'total' => count(DadosAmostra::inventario()),
        ]);
    }

    public function casca(Request $r): View
    {
        return $this->ver($r, 'casca', 'casca', 'Casca da aplicação', 'agente');
    }

    public function responsivo(Request $r): View
    {
        return $this->ver($r, 'responsivo', 'responsivo', 'Celular e tablet', 'agente');
    }

    public function login(Request $r): View
    {
        $estado = in_array($r->query('estado'), ['vazio', 'erro', 'carregando', 'limite'], true) ? $r->query('estado') : 'vazio';

        return $this->ver($r, 'login', 'login', 'Entrar', '', ['estado' => $estado]);
    }

    public function agente(Request $r, string $estado = 'conversa'): View
    {
        $estados = DadosAmostra::estadosAgente();
        abort_unless(isset($estados[$estado]), 404);

        return $this->ver($r, 'agente', 'agente/' . $estado, 'Agente', 'agente', ['estados' => $estados, 'estadoAtual' => $estado]);
    }

    public function formulario(Request $r, string $entidade): View
    {
        $def = DadosAmostra::formulario($entidade);
        abort_unless($def !== null, 404);
        $modo = $r->query('modo') === 'atualizar' ? 'atualizar' : 'criar';
        $estado = in_array($r->query('estado'), ['faltantes', 'erro'], true) ? $r->query('estado') : 'normal';

        return $this->ver($r, 'formulario', 'formulario/' . $entidade, ($modo === 'atualizar' ? 'Atualizar ' : 'Cadastrar ') . $def['rotulo_entidade'], 'cadastros', [
            'entidade' => $entidade,
            'def' => $def,
            'entidadeLista' => DadosAmostra::entidades()[$def['lista']],
            'modo' => $modo,
            'estado' => $estado,
        ]);
    }

    public function cadastros(Request $r): View
    {
        return $this->ver($r, 'cadastros', 'cadastros', 'Cadastros', 'cadastros', ['entidades' => DadosAmostra::entidades()]);
    }

    public function cadastroLista(Request $r, string $slug): View
    {
        $entidade = DadosAmostra::entidades()[$slug] ?? null;
        abort_unless($entidade !== null, 404);
        $termo = trim((string) $r->query('q', ''));

        return $this->ver($r, 'cadastro-lista', 'cadastros/' . $slug, $entidade['rotulo'], 'cadastros', [
            'slug' => $slug,
            'entidade' => $entidade,
            'termo' => $termo,
            'linhas' => DadosAmostra::buscar($slug, $termo),
        ]);
    }

    public function lixeira(Request $r): View
    {
        return $this->ver($r, 'lixeira', 'lixeira', 'Lixeira', 'lixeira', ['itens' => DadosAmostra::lixeira()]);
    }

    public function arquivados(Request $r): View
    {
        return $this->ver($r, 'arquivados', 'arquivados', 'Arquivados', 'arquivados', ['itens' => DadosAmostra::arquivados()]);
    }

    public function retencao(Request $r): View
    {
        return $this->ver($r, 'retencao', 'lixeira/retencao', 'Prazo da lixeira', 'lixeira', ['opcoes' => DadosAmostra::retencoes()]);
    }

    public function auditoria(Request $r): View
    {
        $filtros = array_filter([
            'usuario' => $r->query('usuario'),
            'tool' => $r->query('tool'),
            'resultado' => $r->query('resultado'),
        ], fn ($v) => is_string($v) && $v !== '');
        $todas = DadosAmostra::auditoria();
        $linhas = DadosAmostra::filtrarAuditoria($filtros);
        $tempos = array_column(array_filter($todas, fn ($l) => $l['resultado'] === 'sucesso'), 'ms');
        $detalhe = null;
        foreach ($todas as $l) {
            if ((string) $l['id'] === (string) $r->query('detalhe')) {
                $detalhe = $l;
            }
        }

        return $this->ver($r, 'auditoria', 'auditoria', 'Auditoria', 'auditoria', [
            'linhas' => $linhas,
            'filtros' => $filtros,
            'usuarios' => array_values(array_unique(array_column($todas, 'usuario'))),
            'tools' => array_values(array_unique(array_column($todas, 'tool'))),
            'resultados' => DadosAmostra::resultadosAuditoria(),
            'mediaMs' => $tempos ? array_sum($tempos) / count($tempos) : 0,
            'detalhe' => $detalhe,
        ]);
    }

    public function erro(Request $r, string $codigo): View
    {
        $todos = DadosAmostra::erros();
        abort_unless(isset($todos[$codigo]), 404);

        return $this->ver($r, 'erro', 'erro/' . $codigo, 'Erro ' . $codigo, '', ['erro' => $todos[$codigo], 'todos' => $todos]);
    }

    public function fila(Request $r): View
    {
        return $this->ver($r, 'fila', 'fila', 'Fila de comandos (hipótese)', 'fila', [
            'itens' => DadosAmostra::fila(),
            'status' => DadosAmostra::statusFila(),
            'agenteOffline' => true,
        ]);
    }

    /** Dados que toda amostra recebe: papel escolhido, usuário fictício, menu e construtor de endereços. */
    private function ver(Request $r, string $view, string $caminho, string $titulo, string $menuAtual, array $dados = []): View
    {
        $papel = DadosAmostra::papelValido($r->query('papel'));
        $menuModo = $r->query('menu') === 'breve' ? 'breve' : 'normal';
        $modal = in_array($r->query('modal'), self::MODAIS, true) ? $r->query('modal') : null;

        return view('amostra.' . $view, $dados + [
            'papel' => $papel,
            'usuario' => DadosAmostra::usuario($papel),
            'menuItens' => DadosAmostra::menu($papel, $menuModo),
            'menuModo' => $menuModo,
            'menuAtual' => $menuAtual,
            'titulo' => $titulo,
            'caminhoAtual' => $caminho,
            'consultaAtual' => array_diff_key($r->query(), ['papel' => 1]),
            'modalInicial' => $modal,
            'u' => fn (string $c, array $q = []) => DadosAmostra::url($c, $papel, $q),
        ]);
    }
}
