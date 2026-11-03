@extends('layouts.app')

@section('titulo', 'Auditoria')



@section('conteudo')
    <header class="page-header">
    <p class="eyebrow">Rastreabilidade</p>
    <h1>Auditoria</h1>
    <p class="intro">Veja quem pediu, o que foi executado e o resultado. Dados pessoais aparecem mascarados.</p>

    </header>

    <form id="form-auditoria" class="painel" aria-label="Filtros da auditoria">
        <div class="campo">
            <label for="filtro-de">De</label>
            <input type="date" id="filtro-de" name="de">
        </div>
        <div class="campo">
            <label for="filtro-ate">Até</label>
            <input type="date" id="filtro-ate" name="ate">
        </div>
        <div class="campo">
            <label for="filtro-usuario">Usuário</label>
            <select id="filtro-usuario" name="usuario">
                <option value="">Todos</option>
                @foreach ($usuarios as $id => $nome)
                    <option value="{{ $id }}">{{ $nome }}</option>
                @endforeach
            </select>
        </div>
        <div class="campo">
            <label for="filtro-tool">Ação</label>
            <select id="filtro-tool" name="tool">
                <option value="">Todas</option>
                @foreach ($tools as $tool)
                    <option value="{{ $tool }}">{{ \Illuminate\Support\Str::ucfirst(str_replace('_', ' ', $tool)) }}</option>
                @endforeach
            </select>
        </div>
        <div class="campo">
            <label for="filtro-resultado">Resultado</label>
            <select id="filtro-resultado" name="resultado">
                <option value="">Todos</option>
                @foreach ($resultados as $resultado)
                    <option value="{{ $resultado }}">{{ \Illuminate\Support\Str::ucfirst(str_replace('_', ' ', $resultado)) }}</option>
                @endforeach
            </select>
        </div>
        <div class="campo">
            <label for="filtro-grupo">Mostrar</label>
            <select id="filtro-grupo" name="grupo">
                <option value="">Tudo</option>
                <option value="desfechos">Só desfechos</option>
                <option value="revisoes">Só revisões com erro</option>
            </select>
        </div>
        <div class="painel__acoes">
        <button type="submit" id="botao-filtrar" data-foco>Filtrar</button>
        <button type="button" id="botao-exportar">Exportar CSV</button>
        </div>
    </form>

    <div id="carregando" class="carregando" hidden>
        <span>Consultando…</span>
        <div class="carregando__barra"></div>
    </div>

    <div id="lista" aria-live="polite"></div>
    <div id="paginacao" class="paginacao" hidden>
        <button type="button" id="pagina-anterior">Anterior</button>
        <span id="pagina-texto"></span>
        <button type="button" id="pagina-proxima">Próxima</button>
    </div>
    <section id="detalhe" class="detalhe" hidden tabindex="-1" aria-labelledby="detalhe-titulo"></section>

    <div id="status-anuncio" class="sr-only" aria-live="assertive"></div>

    <script>
        // Os dados vêm do /auditoria/registros (só admin, só o tenant do usuário, mascarados no servidor).
        // Tudo por createElement/textContent: nada do registro vira HTML.
        const formAuditoria = document.getElementById('form-auditoria');
        const lista = document.getElementById('lista');
        const paginacao = document.getElementById('paginacao');
        const paginaTexto = document.getElementById('pagina-texto');
        const botaoAnterior = document.getElementById('pagina-anterior');
        const botaoProxima = document.getElementById('pagina-proxima');
        const detalhe = document.getElementById('detalhe');
        const indicadorCarregando = document.getElementById('carregando');
        const statusAnuncio = document.getElementById('status-anuncio');
        const RESULTADOS = { sucesso: 'Sucesso', erro: 'Erro', recusado: 'Recusado', negado: 'Negado', cancelado: 'Cancelado', expirado: 'Expirado', revisao_com_erro: 'Revisão com erro' };
        const ORIGENS = { agente: 'Agente', formulario: 'Formulário', manual: 'Manual' };
        const ENTIDADES = { Lancamento: 'Lançamento', Cliente: 'Cliente', Fornecedor: 'Fornecedor', Funcionario: 'Funcionário', NotaFiscal: 'Nota fiscal', CategoriaLancamento: 'Categoria', AuditLog: 'Auditoria', Lixeira: 'Lixeira' };
        const registro = (r) => r.entidade_id ? (ENTIDADES[r.entidade_tipo] || legivel(r.entidade_tipo)) + ' #' + r.entidade_id : '';
        let paginaAtual = 1;

        const legivel = (texto) => texto ? String(texto).charAt(0).toUpperCase() + String(texto).slice(1).replaceAll('_', ' ') : '';

        function mensagem(texto) {
            const div = document.createElement('div');
            div.className = 'msg msg--erro';
            div.textContent = texto;
            lista.replaceChildren(div);
        }

        async function buscarJson(url) {
            const resp = await fetch(url, { headers: { 'Accept': 'application/json' } });
            if (resp.status === 401 || resp.status === 419) {
                window.location.href = '/login';
                return null;
            }
            const dados = await resp.json().catch(() => ({}));
            if (!resp.ok) throw new Error(dados.mensagem || 'Não consegui consultar a auditoria agora. Tente de novo.');
            return dados;
        }

        function celula(tr, texto) {
            const td = document.createElement('td');
            td.textContent = texto ?? '';
            tr.appendChild(td);
        }

        function montarLista(dados) {
            if (!dados.registros.length) {
                const p = document.createElement('p');
                p.textContent = 'Nenhum registro encontrado.';
                p.className = 'empty-state';
                lista.replaceChildren(p);
                paginacao.hidden = true;
                return;
            }
            const table = document.createElement('table');
            const cabecalho = table.createTHead().insertRow();
            ['Data', 'Usuário', 'Papel', 'Ação', 'Registro', 'Resultado', 'Origem', 'Detalhe'].forEach((titulo) => {
                const th = document.createElement('th');
                th.textContent = titulo;
                th.scope = 'col';
                cabecalho.appendChild(th);
            });
            const tbody = table.createTBody();
            dados.registros.forEach((r) => {
                const tr = tbody.insertRow();
                celula(tr, r.data);
                celula(tr, r.usuario);
                celula(tr, legivel(r.papel));
                celula(tr, legivel(r.tool));
                celula(tr, registro(r));
                const status = document.createElement('span');
                status.className = 'status-badge status--' + (Object.hasOwn(RESULTADOS, r.resultado) ? r.resultado : 'neutro');
                status.textContent = RESULTADOS[r.resultado] || legivel(r.resultado);
                const statusCell = tr.insertCell();
                statusCell.appendChild(status);
                celula(tr, ORIGENS[r.origem] || legivel(r.origem));
                const td = document.createElement('td');
                const botao = document.createElement('button');
                botao.type = 'button';
                botao.className = 'btn-ver';
                botao.textContent = 'Ver';
                botao.setAttribute('aria-label', 'Ver o registro ' + r.id);
                botao.addEventListener('click', () => abrirDetalhe(r.id, botao));
                td.appendChild(botao);
                tr.appendChild(td);
            });
            const rolagem = document.createElement('div');
            rolagem.className = 'tabela-rolagem';
            rolagem.tabIndex = 0;
            rolagem.setAttribute('role', 'region');
            rolagem.setAttribute('aria-label', 'Registros de auditoria');
            rolagem.appendChild(table);
            lista.replaceChildren(rolagem);

            paginacao.hidden = dados.paginas <= 1;
            paginaTexto.textContent = 'Página ' + dados.pagina + ' de ' + dados.paginas + ' (' + dados.total + ' registros)';
            botaoAnterior.disabled = dados.pagina <= 1;
            botaoProxima.disabled = dados.pagina >= dados.paginas;
        }

        async function carregar(pagina) {
            const parametros = new URLSearchParams();
            new FormData(formAuditoria).forEach((valor, campo) => { if (valor) parametros.set(campo, valor); });
            parametros.set('pagina', pagina);
            indicadorCarregando.hidden = false;
            formAuditoria.setAttribute('aria-busy', 'true');
            try {
                const dados = await buscarJson('/auditoria/registros?' + parametros.toString());
                if (!dados) return;
                paginaAtual = dados.pagina;
                montarLista(dados);
                statusAnuncio.textContent = dados.total + ' registro(s)';
            } catch (erro) {
                paginacao.hidden = true;
                mensagem(erro.message === 'Failed to fetch' ? 'Falha ao contatar o servidor.' : erro.message);
            } finally {
                indicadorCarregando.hidden = true;
                formAuditoria.setAttribute('aria-busy', 'false');
            }
        }

        // Proposto (o que a IA ou o formulário pediu) × confirmado (o que foi executado) × anterior (o "antes").
        function tabelaDeValores(titulo, valores) {
            const bloco = document.createElement('div');
            const h3 = document.createElement('h3');
            h3.textContent = titulo;
            bloco.appendChild(h3);
            if (valores === null || valores === undefined || (typeof valores === 'object' && !Object.keys(valores).length)) {
                const p = document.createElement('p');
                p.textContent = 'Nada registrado.';
                bloco.appendChild(p);
                return bloco;
            }
            if (typeof valores !== 'object') {
                const p = document.createElement('p');
                p.textContent = String(valores);
                bloco.appendChild(p);
                return bloco;
            }
            const table = document.createElement('table');
            const tbody = table.createTBody();
            Object.entries(valores).forEach(([campo, valor]) => {
                const tr = tbody.insertRow();
                celula(tr, legivel(campo));
                celula(tr, valor !== null && typeof valor === 'object' ? JSON.stringify(valor) : valor);
            });
            const rolagem = document.createElement('div');
            rolagem.className = 'tabela-rolagem';
            rolagem.appendChild(table);
            bloco.appendChild(rolagem);
            return bloco;
        }

        async function abrirDetalhe(id, botao) {
            botao.disabled = true;
            try {
                const r = await buscarJson('/auditoria/registros/' + encodeURIComponent(id));
                if (!r) return;
                const h2 = document.createElement('h2');
                h2.id = 'detalhe-titulo';
                h2.textContent = 'Registro ' + r.id;
                const dl = document.createElement('dl');
                [
                    ['Data', r.data], ['Usuário', r.usuario], ['Papel', legivel(r.papel)], ['Ação', legivel(r.tool)],
                    ['Registro', registro(r) || '—'],
                    ['Resultado', RESULTADOS[r.resultado] || legivel(r.resultado)], ['Origem', ORIGENS[r.origem] || legivel(r.origem) || '—'],
                    ['Mensagem', r.mensagem || '—'], ['Autorizado por', r.autorizado_por || '—'],
                    ['Tempo da IA', r.tempo_ia_ms !== null && r.tempo_ia_ms !== undefined ? r.tempo_ia_ms + ' ms' : '—'],
                ].forEach(([rotulo, valor]) => {
                    const dt = document.createElement('dt');
                    dt.textContent = rotulo;
                    const dd = document.createElement('dd');
                    dd.textContent = valor ?? '—';
                    dl.append(dt, dd);
                });
                detalhe.replaceChildren(h2, dl,
                    tabelaDeValores('Proposto', r.proposto),
                    tabelaDeValores('Confirmado', r.confirmado),
                    tabelaDeValores('Anterior', r.anterior));
                detalhe.hidden = false;
                detalhe.focus();
            } catch (erro) {
                // Erro no detalhe aparece no próprio quadro; a lista continua na tela.
                const p = document.createElement('p');
                p.className = 'msg msg--erro';
                p.textContent = erro.message === 'Failed to fetch' ? 'Falha ao contatar o servidor.' : erro.message;
                detalhe.replaceChildren(p);
                detalhe.hidden = false;
            } finally {
                botao.disabled = false;
            }
        }

        formAuditoria.addEventListener('submit', (e) => {
            e.preventDefault();
            detalhe.hidden = true;
            carregar(1);
        });
        botaoAnterior.addEventListener('click', () => carregar(paginaAtual - 1));
        botaoProxima.addEventListener('click', () => carregar(paginaAtual + 1));

        document.getElementById('botao-exportar').addEventListener('click', () => {
            const parametros = new URLSearchParams();
            new FormData(formAuditoria).forEach((valor, campo) => { if (valor) parametros.set(campo, valor); });
            window.location.href = '/auditoria/exportar?' + parametros.toString();
        });

        carregar(1);
    </script>
@endsection
