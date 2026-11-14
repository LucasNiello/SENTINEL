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
    </style>
@endpush

@section('conteudo')
    <header class="page-header">
        <p class="eyebrow">Gestão da equipe</p>
        <h1>Supervisão</h1>
        <p class="intro">
            @if ($ehDono)
                Marque quem supervisiona cada setor e zere o PIN quando preciso. O supervisor do setor autoriza estornos com o PIN dele; o dono autoriza com a senha de login.
            @else
                Você supervisiona o setor {{ $meuSetor === 'clinica' ? 'clínica' : 'petshop' }}. Defina aqui o PIN que você digita para autorizar estornos desse setor.
            @endif
        </p>
    </header>

    <div id="aviso-supervisao" class="supervisao-aviso msg" role="status" aria-live="polite"></div>

    @if ($ehDono)
        <section class="supervisao-secao" aria-labelledby="titulo-supervisores">
            <h2 id="titulo-supervisores">Supervisores de setor</h2>
            <p>Desmarcar ou trocar o setor zera o PIN da pessoa. O dono não é marcado: ele usa a senha de login.</p>
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
    @endif

    @if ($meuSetor !== null)
        <section class="supervisao-secao" aria-labelledby="titulo-meu-pin">
            <h2 id="titulo-meu-pin">Meu PIN</h2>
            <p>Pelo menos 6 caracteres, diferente da sua senha de login. Para trocar, confirme com a senha de login.</p>
            <form id="form-meu-pin" class="painel" novalidate>
                <div class="campo">
                    <label for="pin">PIN novo</label>
                    <input type="password" id="pin" autocomplete="one-time-code" maxlength="255" required>
                </div>
                <div class="campo">
                    <label for="pin-confirmacao">Repita o PIN</label>
                    <input type="password" id="pin-confirmacao" autocomplete="one-time-code" maxlength="255" required>
                </div>
                <div class="campo">
                    <label for="senha-atual">Sua senha de login</label>
                    <input type="password" id="senha-atual" autocomplete="current-password" maxlength="255" required>
                </div>
                <div class="painel__acoes">
                    <button type="submit" id="botao-pin">Definir PIN</button>
                </div>
            </form>
        </section>
    @endif

    <script>
        // Tudo é conferido de novo nas rotas JSON (dono, tenant, setor, senha); aqui só se monta a tela.
        // Textos vindos do servidor entram com textContent, nunca como HTML.
        (() => {
            const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
            const aviso = document.getElementById('aviso-supervisao');
            const ROTULOS_PAPEIS = @json($rotulosPapeis);
            const SETORES = [['', 'Nenhum'], ['clinica', 'Clínica'], ['petshop', 'Petshop']];

            function mostrar(texto, tipo) {
                aviso.className = 'supervisao-aviso msg msg--' + tipo;
                aviso.textContent = texto;
            }

            async function enviar(url, metodo, corpo) {
                const resp = await fetch(url, {
                    method: metodo,
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    ...(corpo === undefined ? {} : { body: JSON.stringify(corpo) }),
                }).catch(() => null);
                const dados = resp ? await resp.json().catch(() => ({})) : {};
                return { ok: !!resp && resp.ok, dados };
            }

            const linhas = document.getElementById('linhas-supervisores');

            async function carregar() {
                if (!linhas) return;
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

            function linha(u) {
                const tr = document.createElement('tr');
                tr.append(celula(u.nome), celula(ROTULOS_PAPEIS[u.papel] || u.papel));

                const tdSetor = document.createElement('td');
                const tdAcoes = document.createElement('td');
                const acoes = document.createElement('div');
                acoes.className = 'supervisao-acoes';
                tdAcoes.append(acoes);

                if (u.papel === 'dono') {
                    tdSetor.textContent = 'Autoriza com a senha';
                } else {
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

                    const salvar = document.createElement('button');
                    salvar.type = 'button';
                    salvar.textContent = 'Salvar setor';
                    salvar.setAttribute('aria-label', 'Salvar o setor de ' + u.nome);
                    salvar.addEventListener('click', async () => {
                        salvar.disabled = true;
                        const { ok, dados } = await enviar('/supervisao/supervisores/' + u.id, 'POST', { setor: select.value === '' ? null : select.value });
                        salvar.disabled = false;
                        // A frase vem do servidor (ex.: "Sem mudança: o usuário já estava nesse setor.").
                        mostrar(ok ? u.nome + ': ' + (dados.mensagem || 'Supervisor atualizado.')
                            : (dados.mensagem || 'Não consegui salvar. Nada foi gravado.'), ok ? 'sucesso' : 'erro');
                        if (ok) carregar();
                    });
                    acoes.append(salvar);
                }

                tr.append(tdSetor, celula(u.tem_pin ? 'Definido' : 'Não definido'));

                if (u.tem_pin) {
                    const zerar = document.createElement('button');
                    zerar.type = 'button';
                    zerar.className = 'btn-danger';
                    zerar.textContent = 'Zerar PIN';
                    zerar.setAttribute('aria-label', 'Zerar o PIN de ' + u.nome);
                    zerar.addEventListener('click', async () => {
                        zerar.disabled = true;
                        const { ok, dados } = await enviar('/supervisao/supervisores/' + u.id + '/zerar-pin', 'POST', {});
                        zerar.disabled = false;
                        mostrar(ok ? 'PIN de ' + u.nome + ' zerado: a pessoa precisa definir um novo.' : (dados.mensagem || 'Não consegui zerar o PIN. Nada foi gravado.'), ok ? 'sucesso' : 'erro');
                        if (ok) carregar();
                    });
                    acoes.append(zerar);
                }

                tr.append(tdAcoes);
                return tr;
            }

            const formPin = document.getElementById('form-meu-pin');
            if (formPin) {
                formPin.addEventListener('submit', async (evento) => {
                    evento.preventDefault();
                    const pin = document.getElementById('pin');
                    const confirmacao = document.getElementById('pin-confirmacao');
                    const senha = document.getElementById('senha-atual');
                    const botao = document.getElementById('botao-pin');
                    botao.disabled = true;
                    const { ok, dados } = await enviar('/supervisao/meu-pin', 'POST', { pin: pin.value, pin_confirmacao: confirmacao.value, senha_atual: senha.value });
                    botao.disabled = false;
                    // O PIN e a senha não ficam na tela depois do envio, com acerto ou erro.
                    pin.value = '';
                    confirmacao.value = '';
                    senha.value = '';
                    mostrar(ok ? 'PIN definido. Use-o no card de estorno, junto com o seu e-mail.' : (dados.mensagem || 'Não consegui definir o PIN. Nada foi gravado.'), ok ? 'sucesso' : 'erro');
                    pin.focus();
                });
            }

            carregar();
        })();
    </script>
@endsection
