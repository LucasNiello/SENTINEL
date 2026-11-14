@extends('layouts.app')

@section('titulo', 'Supervisão')

@push('estilos')
    <style>
        /* Só tokens da bancada (sentinel-visual §2); sem animação. */
        .supervisao-secao {margin:0 0 32px}
        .supervisao-secao h2 {font-size:20px;margin:0 0 8px}
        .supervisao-secao > p {color:var(--sentinel-ink-muted);max-width:78ch;margin:0 0 16px}
        .supervisao-acoes {display:flex;flex-wrap:wrap;gap:8px}
        .supervisao-aviso {margin:0 0 24px}
        .supervisao-aviso:empty {display:none}
        .msg--sucesso {background:var(--color-sucesso-faixa);color:var(--color-sucesso)}
        .supervisao-secao td .campo {min-width:140px}
        .gerar-pin-para {flex-basis:100%;margin:0}
        .pin-gerado {margin:0 0 24px;padding:16px;border:2px solid var(--color-aviso);border-radius:var(--radius);background:var(--color-aviso-faixa);color:var(--color-tinta)}
        .pin-gerado p {margin:0 0 8px}
        .pin-gerado__codigo {font-family:"IBM Plex Mono",ui-monospace,monospace;font-variant-numeric:tabular-nums;font-size:32px;letter-spacing:.2em;margin:8px 0 12px}
    </style>
@endpush

@section('conteudo')
    <header class="page-header">
        <p class="eyebrow">Gestão da equipe</p>
        <h1>Supervisão</h1>
        <p class="intro">
            @if ($ehDono)
                Marque quem supervisiona cada setor. O supervisor do setor e o dono autorizam estornos com o PIN que o Administrador gera.
            @else
                Gere ou revogue o PIN de autorização do dono e dos supervisores de setor. O código aparece uma vez só, aqui, para você entregar à pessoa.
            @endif
        </p>
    </header>

    <div id="aviso-supervisao" class="supervisao-aviso msg" role="status" aria-live="polite"></div>

    @if ($ehAdmin)
        {{-- O código gerado só existe neste bloco até "Já anotei"; não vai para outra resposta, sessão ou armazenamento do navegador. --}}
        <div id="pin-gerado" class="pin-gerado" role="alert" hidden>
            <p id="pin-gerado-para"></p>
            <p class="pin-gerado__codigo" id="pin-gerado-codigo"></p>
            <p>Anote e entregue à pessoa agora: este código não aparece de novo. Um código novo invalida o anterior.</p>
            <button type="button" id="pin-gerado-ok">Já anotei</button>
        </div>

        <form id="form-gerar-pin" class="painel" novalidate hidden>
            <p id="gerar-pin-para" class="campo-info gerar-pin-para"></p>
            <div class="campo">
                <label for="senha-admin">Sua senha de login</label>
                <input type="password" id="senha-admin" autocomplete="current-password" maxlength="255" required>
            </div>
            <div class="painel__acoes">
                <button type="submit" id="botao-gerar">Gerar novo PIN</button>
                <button type="button" id="botao-gerar-cancelar">Cancelar</button>
            </div>
        </form>
    @endif

    <section class="supervisao-secao" aria-labelledby="titulo-supervisores">
        <h2 id="titulo-supervisores">{{ $ehDono ? 'Supervisores de setor' : 'PIN de autorização' }}</h2>
        <p>
            @if ($ehDono)
                Desmarcar ou trocar o setor zera o PIN da pessoa. O dono não é marcado: ele já autoriza em qualquer setor.
            @else
                Gerar pede a sua senha de login. Revogar zera o PIN: a pessoa deixa de autorizar até ganhar outro.
            @endif
        </p>
        <div id="carregando" class="carregando">
            <span>Consultando…</span>
            <div class="carregando__barra"></div>
        </div>
        <div class="tabela-rolagem" id="tabela-supervisores" hidden>
            <table>
                <thead>
                    <tr><th scope="col">Nome</th><th scope="col">Papel</th><th scope="col">Setor que supervisiona</th><th scope="col">PIN</th><th scope="col">Ações</th></tr>
                </thead>
                <tbody id="linhas-supervisores"></tbody>
            </table>
        </div>
    </section>

    <script>
        // Tudo é conferido de novo nas rotas JSON (dono, Administrador, tenant, senha); aqui só se monta a tela.
        // Textos vindos do servidor entram com textContent, nunca como HTML.
        (() => {
            const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
            const aviso = document.getElementById('aviso-supervisao');
            const ROTULOS_PAPEIS = @json($rotulosPapeis);
            const EH_DONO = @json($ehDono);
            const EH_ADMIN = @json($ehAdmin);
            const SETORES = [['', 'Nenhum'], ['clinica', 'Clínica'], ['petshop', 'Petshop']];

            function mostrar(texto, tipo) {
                aviso.className = 'supervisao-aviso msg msg--' + tipo;
                aviso.textContent = texto;
            }

            async function enviar(url, metodo, corpo) {
                const resp = await fetch(url, {
                    method: metodo,
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    cache: 'no-store',
                    ...(corpo === undefined ? {} : { body: JSON.stringify(corpo) }),
                }).catch(() => null);
                const dados = resp ? await resp.json().catch(() => ({})) : {};
                return { ok: !!resp && resp.ok, dados };
            }

            const linhas = document.getElementById('linhas-supervisores');

            async function carregar() {
                const carregando = document.getElementById('carregando');
                const tabela = document.getElementById('tabela-supervisores');
                carregando.hidden = false;
                const { ok, dados } = await enviar('/supervisao/supervisores', 'GET');
                carregando.hidden = true;
                if (!ok) {
                    mostrar(dados.mensagem || 'Não consegui carregar a equipe. Tente novamente.', 'erro');
                    return;
                }
                linhas.replaceChildren(...(dados.usuarios || []).map(linha));
                tabela.hidden = false;
            }

            function celula(texto) {
                const td = document.createElement('td');
                td.textContent = texto;
                return td;
            }

            function botao(texto, rotulo, classe, acao) {
                const b = document.createElement('button');
                b.type = 'button';
                b.textContent = texto;
                b.setAttribute('aria-label', rotulo);
                if (classe) b.className = classe;
                b.addEventListener('click', () => acao(b));
                return b;
            }

            function linha(u) {
                const tr = document.createElement('tr');
                tr.append(celula(u.nome), celula(ROTULOS_PAPEIS[u.papel] || u.papel));

                const tdSetor = document.createElement('td');
                const tdAcoes = document.createElement('td');
                const acoes = document.createElement('div');
                acoes.className = 'supervisao-acoes';
                tdAcoes.append(acoes);

                if (u.papel === 'dono') {
                    tdSetor.textContent = 'Todos (dono)';
                } else if (u.papel === 'admin') {
                    tdSetor.textContent = 'Não autoriza (Administrador)';
                } else if (EH_DONO) {
                    const campo = document.createElement('div');
                    campo.className = 'campo';
                    const rotulo = document.createElement('label');
                    rotulo.className = 'sr-only';
                    rotulo.htmlFor = 'setor-' + u.id;
                    rotulo.textContent = 'Setor que ' + u.nome + ' supervisiona';
                    const select = document.createElement('select');
                    select.id = 'setor-' + u.id;
                    SETORES.forEach(([valor, texto]) => select.append(new Option(texto, valor, false, (u.supervisor_setor || '') === valor)));
                    campo.append(rotulo, select);
                    tdSetor.append(campo);

                    acoes.append(botao('Salvar setor', 'Salvar o setor de ' + u.nome, '', async (salvar) => {
                        salvar.disabled = true;
                        const { ok, dados } = await enviar('/supervisao/supervisores/' + u.id, 'POST', { setor: select.value === '' ? null : select.value });
                        salvar.disabled = false;
                        // A frase vem do servidor (ex.: "Sem mudança: o usuário já estava nesse setor.").
                        mostrar(ok ? u.nome + ': ' + (dados.mensagem || 'Supervisor atualizado.')
                            : (dados.mensagem || 'Não consegui salvar. Nada foi gravado.'), ok ? 'sucesso' : 'erro');
                        if (ok) carregar();
                    }));
                } else {
                    tdSetor.textContent = u.supervisor_setor === 'clinica' ? 'Clínica' : u.supervisor_setor === 'petshop' ? 'Petshop' : 'Nenhum';
                }

                tr.append(tdSetor, celula(u.tem_pin ? 'Gerado' : 'Sem PIN'));

                if (EH_ADMIN) {
                    acoes.append(botao('Gerar novo PIN', 'Gerar novo PIN para ' + u.nome, '', () => abrirGerar(u)));
                    if (u.tem_pin) {
                        acoes.append(botao('Revogar PIN', 'Revogar o PIN de ' + u.nome, 'btn-danger', async (revogar) => {
                            revogar.disabled = true;
                            const { ok, dados } = await enviar('/supervisao/pins/' + u.id + '/revogar', 'POST', {});
                            revogar.disabled = false;
                            mostrar(ok ? (dados.mensagem || 'PIN revogado.') + ' A pessoa não autoriza até ganhar outro.'
                                : (dados.mensagem || 'Não consegui revogar o PIN. Nada foi gravado.'), ok ? 'sucesso' : 'erro');
                            if (ok) carregar();
                        }));
                    }
                }

                tr.append(tdAcoes);
                return tr;
            }

            // Administrador: gerar pede a senha de login; o código volta UMA vez e só fica no bloco até "Já anotei".
            const formGerar = document.getElementById('form-gerar-pin');
            const caixaPin = document.getElementById('pin-gerado');
            let alvo = null;

            function esconderPin() {
                if (!caixaPin) return;
                document.getElementById('pin-gerado-codigo').textContent = '';
                document.getElementById('pin-gerado-para').textContent = '';
                caixaPin.hidden = true;
            }

            function abrirGerar(u) {
                esconderPin();
                alvo = u;
                document.getElementById('gerar-pin-para').textContent = 'Gerar um PIN novo para ' + u.nome + '. O PIN anterior deixa de valer.';
                formGerar.hidden = false;
                document.getElementById('senha-admin').focus();
            }

            if (formGerar) {
                document.getElementById('botao-gerar-cancelar').addEventListener('click', () => {
                    document.getElementById('senha-admin').value = '';
                    formGerar.hidden = true;
                    alvo = null;
                });
                document.getElementById('pin-gerado-ok').addEventListener('click', () => {
                    esconderPin();
                    linhas.querySelector('button')?.focus();
                });
                formGerar.addEventListener('submit', async (evento) => {
                    evento.preventDefault();
                    if (!alvo) return;
                    const senha = document.getElementById('senha-admin');
                    const botaoGerar = document.getElementById('botao-gerar');
                    botaoGerar.disabled = true;
                    const { ok, dados } = await enviar('/supervisao/pins/' + alvo.id + '/gerar', 'POST', { senha_atual: senha.value });
                    botaoGerar.disabled = false;
                    // A senha não fica na tela depois do envio, com acerto ou erro.
                    senha.value = '';
                    if (!ok || typeof dados.pin !== 'string') {
                        mostrar(dados.mensagem || 'Não consegui gerar o PIN. Nada foi gravado.', 'erro');
                        senha.focus();
                        return;
                    }
                    formGerar.hidden = true;
                    mostrar(dados.mensagem || 'PIN gerado.', 'sucesso');
                    document.getElementById('pin-gerado-para').textContent = 'PIN de ' + alvo.nome + ':';
                    document.getElementById('pin-gerado-codigo').textContent = dados.pin;
                    caixaPin.hidden = false;
                    document.getElementById('pin-gerado-ok').focus();
                    alvo = null;
                    carregar();
                });
            }

            carregar();
        })();
    </script>
@endsection
