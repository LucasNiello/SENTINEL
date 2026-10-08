<section class="pagina pagina--estreita">
    <div class="msg msg--erro" role="alert">
        <strong>Você não tem acesso a esta tela.</strong>
        <span class="msg__acao">{{ $motivo }} Seu papel agora: <span class="papel-tag">{{ $papel }}</span>. Use "Ver como" no topo para trocar o papel da amostra.</span>
    </div>
    <a class="btn" href="{{ $u('agente') }}">Voltar ao agente</a>
</section>
