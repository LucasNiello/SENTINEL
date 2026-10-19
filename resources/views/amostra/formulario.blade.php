@extends('amostra.layout')

@section('conteudo')
@php $verbo = $modo === 'atualizar' ? 'Atualizar' : 'Cadastrar'; @endphp
<div class="pagina pagina--estreita">
    <p><a href="{{ $u('cadastros/' . $def['lista']) }}">← Voltar para {{ $entidadeLista['rotulo'] }}</a></p>

    <div class="painel-amostra">
        <h2>Só da amostra: variações</h2>
        <div class="chips">
            <a class="chip" href="{{ $u('formulario/' . $entidade, ['modo' => 'criar']) }}" @if ($modo === 'criar' && $estado === 'normal') aria-current="true" @endif>Cadastrar</a>
            <a class="chip" href="{{ $u('formulario/' . $entidade, ['modo' => 'atualizar']) }}" @if ($modo === 'atualizar') aria-current="true" @endif>Atualizar</a>
            <a class="chip" href="{{ $u('formulario/' . $entidade, ['modo' => 'criar', 'estado' => 'faltantes']) }}" @if ($estado === 'faltantes') aria-current="true" @endif>Campo faltando</a>
            <a class="chip" href="{{ $u('formulario/' . $entidade, ['modo' => 'criar', 'estado' => 'erro']) }}" @if ($estado === 'erro') aria-current="true" @endif>Erro de validação</a>
        </div>
    </div>

    <h1>{{ $verbo }} {{ $def['rotulo_entidade'] }}</h1>
    <p class="lead">O mesmo formulário que o agente abre no chat, aberto direto pelo menu Cadastros (modo manual, sem IA). A governança é a mesma: permissão, empresa, validação, confirmação e auditoria.</p>

    @include('amostra.parciais.formulario', ['bloco' => ['entidade' => $entidade, 'modo' => $modo, 'estado' => $estado], 'noChat' => false])
</div>
@endsection
