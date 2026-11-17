{{-- Front de 14/11 (F2): as telas de leitura da clínica não gravam nada. O botão com data-tool abre o card de sempre
     (/agente/formulario → Revisar → Confirmar; segurar só nas destrutivas), com RBAC e tenant conferidos no servidor. --}}
<div id="historico" class="tela-acoes" aria-live="polite"></div>

@include('partials.bancada')

<script>
    document.querySelectorAll('[data-tool]').forEach((botao) => {
        botao.addEventListener('click', async () => {
            await abrirFormulario(botao.dataset.tool, botao.dataset.id ? Number(botao.dataset.id) : null, botao);
            const card = historico.lastElementChild;
            if (card) card.scrollIntoView({ block: 'nearest' });
        });
    });

    // Depois de gravar, a lista de cima ficou velha: o aviso fica na tela até o usuário atualizar (nada some sozinho).
    document.addEventListener('sentinel:gravado', () => {
        if (document.getElementById('aviso-atualizar')) return;
        const aviso = document.createElement('div');
        aviso.id = 'aviso-atualizar';
        aviso.className = 'msg msg--aviso faixa-atualizar';
        const texto = document.createElement('span');
        texto.textContent = 'Gravado. A lista desta tela ainda mostra os dados de antes.';
        const botao = document.createElement('button');
        botao.type = 'button';
        botao.textContent = 'Atualizar a tela';
        botao.addEventListener('click', () => window.location.reload());
        aviso.append(texto, botao);
        historico.appendChild(aviso);
    });
</script>
