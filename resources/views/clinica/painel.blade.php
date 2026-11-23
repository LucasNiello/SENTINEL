@extends('layouts.app')

@section('titulo', 'Painel do Dono')

{{-- Tela real do painel do dono (23/11), feita a partir de amostras/painel-dono.blade.php (Matheus), sem o selo AMOSTRA. --}}
@push('estilos')
<style>
    .painel-dono nav {display:flex;overflow-x:auto;gap:4px;margin-bottom:24px;padding:4px}
    .painel-dono nav a {flex:none;display:inline-flex;align-items:center;min-height:44px;padding:8px 16px;border-radius:6px;color:var(--sentinel-ink)}
    .painel-dono nav a[aria-current] {background:var(--sentinel-blue);color:var(--sentinel-paper);font-weight:600}
    .painel-dono nav a:focus-visible,.painel-card:focus-visible {outline:2px solid var(--sentinel-blue);outline-offset:2px}
    .painel-cards {display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
    .painel-card {display:block;min-width:0;padding:20px;border:1px solid var(--sentinel-border);border-radius:var(--radius);background:var(--sentinel-paper);color:inherit;text-decoration:none}
    .painel-card header {display:flex;align-items:center;justify-content:space-between;color:var(--sentinel-blue)}
    .painel-card h3 {margin:12px 0 4px;font-size:1rem}
    .painel-card p {margin:0;font:1.5rem var(--fonte-mono)}
    .painel-campos {display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:16px}
    .painel-campos input {width:100%;min-width:0;font-size:1rem}
    .painel-lista {list-style:none;margin:0;padding:0;display:grid;gap:8px}
    .painel-lista li {padding:12px 16px;border:1px solid var(--sentinel-border);border-radius:var(--radius);background:var(--sentinel-paper)}
    .painel-lista a {display:inline-flex;align-items:center;min-height:44px}
    .painel-dono .msg {margin:0 0 16px}
    @media (max-width:767px) {.painel-cards,.painel-campos {grid-template-columns:minmax(0,1fr)}}
</style>
@endpush

@section('conteudo')
<div class="painel-dono">
    <header class="page-header">
        <p class="eyebrow">Gestão da clínica</p>
        <h1>Painel do Dono</h1>
    </header>
    <nav aria-label="Seções do Painel do Dono">
        @foreach ($abas as $chave => $nome)
            <a href="/painel?aba={{ $chave }}" @if ($aba === $chave) aria-current="page" @endif>{{ $nome }}</a>
        @endforeach
    </nav>
    <section aria-labelledby="painel-titulo">
        <h2 id="painel-titulo">{{ $abas[$aba] }}</h2>
        @if ($aba === 'dashboard')
            <div class="painel-cards">
                @foreach ($indicadores as $card)
                    <a class="painel-card" href="{{ $card['link'] }}">
                        <header>
                            <svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="{{ $card['icone'] }}"/></svg>
                        </header>
                        <h3>{{ $card['titulo'] }}</h3>
                        <p>{{ $card['numero'] }}</p>
                    </a>
                @endforeach
            </div>
        @elseif ($aba === 'clinica')
            @if (session('ok'))
                <p class="msg msg--sucesso" role="status">{{ session('ok') }}</p>
            @endif
            @if ($errors->has('clinica'))
                <p class="msg msg--erro" role="alert">{{ $errors->first('clinica') }}</p>
            @endif
            <p>A agenda da clínica e do banho e tosa usa estes horários. {{ $valores['personalizada'] ? 'Valores definidos pelo dono.' : 'Ainda não alterado: valem os valores padrão do sistema.' }}</p>
            <form method="POST" action="/painel/clinica">
                @csrf
                <div class="painel-campos">
                    <div class="campo">
                        <label for="abre">Abre às</label>
                        <input id="abre" name="abre" type="time" required value="{{ old('abre', $valores['abre']) }}">
                    </div>
                    <div class="campo">
                        <label for="fecha">Fecha às</label>
                        <input id="fecha" name="fecha" type="time" required value="{{ old('fecha', $valores['fecha']) }}">
                    </div>
                    <div class="campo">
                        <label for="intervalo_inicio">Início do intervalo</label>
                        <input id="intervalo_inicio" name="intervalo_inicio" type="time" value="{{ old('intervalo_inicio', $valores['intervalo_inicio']) }}">
                    </div>
                    <div class="campo">
                        <label for="intervalo_fim">Fim do intervalo</label>
                        <input id="intervalo_fim" name="intervalo_fim" type="time" value="{{ old('intervalo_fim', $valores['intervalo_fim']) }}">
                    </div>
                    <div class="campo">
                        <label for="vagas">Vagas por bloco (por veterinário)</label>
                        <input id="vagas" name="vagas" type="number" min="1" max="{{ $maxVagas }}" step="1" required value="{{ old('vagas', $valores['vagas']) }}">
                    </div>
                </div>
                <p>Deixe o intervalo em branco se a clínica não para no meio do dia. Os agendamentos que já existem não mudam.</p>
                <button type="submit" class="botao-principal">Salvar configuração</button>
            </form>
        @elseif ($aba === 'funcionarios')
            <p><a class="botao" href="/supervisao">Supervisores e PINs</a></p>
            <ul class="painel-lista">
                @forelse ($funcionarios as $f)
                    <li><strong>{{ $f['nome'] }}</strong> · {{ $f['papel'] }}@if ($f['supervisor']) · supervisor de {{ $f['supervisor'] === 'clinica' ? 'clínica' : 'petshop' }}@endif</li>
                @empty
                    <li>Nenhum funcionário cadastrado.</li>
                @endforelse
            </ul>
        @elseif ($aba === 'clientes')
            <p>Tutores e seus pets ({{ $total }} no total). <a href="/cadastros">Abrir cadastros</a></p>
            <ul class="painel-lista">
                @forelse ($tutores as $t)
                    <li>
                        <a href="/tutores/{{ $t['id'] }}"><strong>{{ $t['nome'] }}</strong></a>
                        @if ($t['pets'])
                            <br>@foreach ($t['pets'] as $p)<a href="/pets/{{ $p['id'] }}">{{ $p['nome'] }}</a>@if ($p['especie']) ({{ $p['especie'] }})@endif @if (! $loop->last) · @endif @endforeach
                        @endif
                    </li>
                @empty
                    <li>Nenhum tutor cadastrado.</li>
                @endforelse
            </ul>
        @else
            <p><a class="botao" href="/cadastros">Abrir o catálogo</a> <a class="botao" href="/agenda">Agenda do banho e tosa</a></p>
            <ul class="painel-lista">
                @forelse ($servicos as $s)
                    <li><strong>{{ $s['nome'] }}</strong> · {{ $s['preco'] }}@if ($s['duracao']) · {{ $s['duracao'] }} minutos @endif</li>
                @empty
                    <li>Nenhum item do petshop no catálogo.</li>
                @endforelse
            </ul>
        @endif
    </section>
</div>
@endsection
