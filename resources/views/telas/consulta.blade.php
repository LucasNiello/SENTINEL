@extends('layouts.app')

@section('titulo', $titulo)

@push('estilos')
    <style>
        /* B3: telas Cadastros e Lixeira (modo manual, sem IA). Tokens vêm da casca. */
        .intro { color: var(--color-tinta-2); margin-top: 0; }
        .painel { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 0.75rem; margin-bottom: 1rem; }
        .filtros { display: contents; }
        .campo { display: flex; flex-direction: column; gap: 0.25rem; min-width: 0; }
        .campo label { font-size: 15px; color: var(--color-tinta-2); }
        .campo input, .campo select { min-height: 44px; padding: 0.4rem 0.6rem; font: inherit; background: var(--color-creme); color: var(--color-tinta); border: 1px solid var(--color-borda-campo); border-radius: 6px; max-width: 100%; }
        .painel__acoes { display: flex; gap: 0.5rem; }
        .painel__acoes button { min-height: 44px; padding: 0.4rem 1rem; }
    </style>
@endpush

@section('conteudo')
    <h1>{{ $titulo }}</h1>
    <p class="intro">{{ $intro }}</p>

    <form id="form-consulta" class="painel" aria-label="Filtros">
        @if (count($entidades) > 1)
            <div class="campo">
                <label for="entidade">Cadastro</label>
                <select id="entidade">
                    @foreach ($entidades as $chave => $entidade)
                        <option value="{{ $chave }}">{{ $entidade['rotulo'] }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div id="filtros" class="filtros"></div>
        <div class="painel__acoes">
            <button type="submit" id="botao-buscar" data-foco>Buscar</button>
            <button type="button" id="botao-novo" hidden>Novo</button>
        </div>
    </form>

    <div id="carregando" class="carregando" hidden>
        <span>Consultando…</span>
        <div class="carregando__barra"></div>
    </div>

    <div id="historico" aria-live="polite"></div>

    @include('partials.bancada')

    <script>
        // Configuração da tela (montada no servidor); RBAC, tenant e filtros são conferidos de novo no /agente/consultar.
        const ENTIDADES = @json($entidades);
        const formConsulta = document.getElementById('form-consulta');
        const seletorEntidade = document.getElementById('entidade');
        const areaFiltros = document.getElementById('filtros');
        const botaoBuscar = document.getElementById('botao-buscar');
        const botaoNovo = document.getElementById('botao-novo');
        const indicadorCarregando = document.getElementById('carregando');

        function entidadeAtual() {
            return ENTIDADES[seletorEntidade ? seletorEntidade.value : Object.keys(ENTIDADES)[0]];
        }

        // Os filtros saem do esquema da consulta: texto, lista fechada ou data.
        function montarFiltros() {
            const entidade = entidadeAtual();
            areaFiltros.replaceChildren();
            entidade.filtros.forEach((filtro) => {
                const bloco = document.createElement('div');
                bloco.className = 'campo';
                const rotulo = document.createElement('label');
                rotulo.htmlFor = 'filtro-' + filtro.campo;
                rotulo.textContent = filtro.rotulo;
                let entrada;
                if (filtro.tipo === 'lista') {
                    entrada = document.createElement('select');
                    entrada.appendChild(new Option('Todos', ''));
                    Object.entries(filtro.opcoes).forEach(([valor, texto]) => entrada.appendChild(new Option(texto, valor)));
                } else {
                    entrada = document.createElement('input');
                    entrada.type = filtro.tipo === 'data' ? 'date' : 'text';
                    if (filtro.tipo === 'texto') entrada.maxLength = 100;
                }
                entrada.id = 'filtro-' + filtro.campo;
                entrada.name = filtro.campo;
                bloco.append(rotulo, entrada);
                areaFiltros.appendChild(bloco);
            });
            botaoNovo.hidden = !entidade.criar;
        }

        // Uma busca nova troca a tabela; um card de confirmação aberto continua na tela.
        function limparResultados() {
            Array.from(historico.children).forEach((filho) => {
                if (!filho.classList.contains('card-confirmacao')) filho.remove();
            });
        }

        function definirCarregando(ativo) {
            botaoBuscar.disabled = ativo;
            formConsulta.setAttribute('aria-busy', ativo ? 'true' : 'false');
            indicadorCarregando.hidden = !ativo;
            if (ativo) anunciar('consultando');
        }

        formConsulta.addEventListener('submit', async (e) => {
            e.preventDefault();
            const entidade = entidadeAtual();
            const filtros = {};
            entidade.filtros.forEach((filtro) => {
                const valor = formConsulta.elements.namedItem(filtro.campo).value.trim();
                if (valor) filtros[filtro.campo] = valor;
            });

            limparResultados();
            definirCarregando(true);
            try {
                const resp = await fetch('/agente/consultar', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ tool: entidade.consultar, filtros }),
                });
                if (resp.status === 401 || resp.status === 419) {
                    window.location.href = '/login';
                    return;
                }
                const dados = await resp.json().catch(() => ({}));
                if (resp.ok && dados.tipo === 'resultado_leitura') {
                    adicionarTabela(dados.resultado || [], dados.colunas, dados.editar, dados.exportar);
                    anunciar((dados.resultado || []).length + ' registro(s) encontrado(s)');
                } else {
                    adicionarMensagem(dados.mensagem || 'Não consegui fazer a consulta. Tente de novo.', 'msg--erro');
                }
                if (dados.aviso) adicionarAviso(dados.aviso);
            } catch (err) {
                adicionarMensagem('Falha ao contatar o servidor.', 'msg--erro');
            } finally {
                definirCarregando(false);
            }
        });

        botaoNovo.addEventListener('click', () => abrirFormulario(entidadeAtual().criar, null, botaoNovo));

        if (seletorEntidade) {
            seletorEntidade.addEventListener('change', () => {
                montarFiltros();
                formConsulta.requestSubmit();
            });
        }

        montarFiltros();
        formConsulta.requestSubmit();
    </script>
@endsection
