@extends('amostra.layout')

@section('conteudo')
<div class="pagina">
    <h1>Cadastros</h1>
    <p class="lead">Modo manual: tudo continua funcionando mesmo se a IA estiver fora do ar. Escolha o que quer ver ou cadastrar.</p>

    <ul class="lista-fila" aria-label="Cadastros">
        @foreach ($entidades as $e)
            <li class="item-fila">
                <span class="item-fila__texto"><strong>{{ $e['rotulo'] }}</strong><br><span class="lead">{{ count(\App\Amostra\DadosAmostra::linhas($e['slug'])) }} registros (fictícios)</span></span>
                <a class="btn" href="{{ $u('cadastros/' . $e['slug']) }}">Abrir lista</a>
            </li>
        @endforeach
    </ul>

    <h2 id="governanca">Mesma governança do agente</h2>
    <p class="lead">Nada muda por abrir o formulário direto. Cada regra abaixo continua valendo no modo manual.</p>
    <dl class="resumo">
        <dt>Permissão</dt><dd>seu papel é <span class="papel-tag">{{ $papel }}</span>: {{ $papel === 'leitura' ? 'só consulta.' : ($papel === 'operador' ? 'consulta e grava (menos funcionários).' : 'consulta e grava tudo.') }}</dd>
        <dt>Empresa</dt><dd>você só vê os dados da empresa {{ $usuario['tenant'] }}.</dd>
        <dt>Validação</dt><dd>as mesmas regras de cada cadastro.</dd>
        <dt>Confirmação</dt><dd>toda gravação pede segurar o botão por 2 segundos.</dd>
        <dt>Auditoria</dt><dd>quem fez, o que e quando fica registrado.</dd>
    </dl>
</div>
@endsection
