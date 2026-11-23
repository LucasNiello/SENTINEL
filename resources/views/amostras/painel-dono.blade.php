@php
    $abas = ['dashboard' => 'Dashboard', 'clinica' => 'Clínica', 'funcionarios' => 'Funcionários', 'clientes' => 'Clientes', 'petshop' => 'Petshop'];
    $aba = request()->query('aba');
    $aba = is_string($aba) && isset($abas[$aba]) ? $aba : 'dashboard';
    $indicadores = [
        ['Agenda de hoje', '12', 'M3 5h18v16H3z M7 3v4m10-4v4M3 10h18'],
        ['Funcionários', '8', 'M16 21v-2a6 6 0 0 0-12 0v2 M14 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0 M17 12a6 6 0 0 1 4 6'],
        ['Estoque', '46 itens', 'M3 7l9-5 9 5v10l-9 5-9-5z M3 7l9 5 9-5m-9 5v10'],
        ['Financeiro', 'R$ 1.280', 'M3 5h18v14H3z M3 9h18m-5 6h2'],
    ];
    $campos = ['Abre às' => '08:00', 'Fecha às' => '18:00', 'Início do intervalo' => '12:00', 'Fim do intervalo' => '13:00', 'Vagas por bloco' => '3'];
    $registros = [
        'funcionarios' => ['Lívia Nunes' => 'Veterinária', 'Caio Reis' => 'Recepção'],
        'clientes' => ['Marina Alves' => 'Mel · Cão', 'Rafael Lima' => 'Lua · Gato'],
        'petshop' => ['Banho' => '45 minutos', 'Tosa' => '60 minutos'],
    ];
@endphp

@push('estilos')
<style>
    .painel-dono nav {display:flex;overflow-x:auto;gap:4px;margin-bottom:24px;padding:4px}
    .painel-dono nav a {flex:none;display:inline-flex;align-items:center;min-height:44px;padding:8px 16px;border-radius:6px;color:var(--sentinel-ink)}
    .painel-dono nav a[aria-current] {background:var(--sentinel-blue);color:var(--sentinel-paper);font-weight:600}
    .painel-dono nav a:focus-visible {outline:2px solid var(--sentinel-blue);outline-offset:2px}
    .painel-cards {display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
    .painel-card {min-width:0;padding:20px;border:1px solid var(--sentinel-border);border-radius:var(--radius);background:var(--sentinel-paper)}
    .painel-card header {display:flex;align-items:center;justify-content:space-between;color:var(--sentinel-blue)}
    .painel-selo {display:inline-block;font-size:12px;letter-spacing:.08em;border:1px solid currentColor;border-radius:4px;padding:2px 8px}
    .painel-card h3 {margin:12px 0 4px;font-size:1rem}
    .painel-card p {margin:0;font:1.5rem var(--fonte-mono)}
    .painel-campos {display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
    .painel-campos input {width:100%;min-width:0}
    @media (max-width:767px) {.painel-cards,.painel-campos {grid-template-columns:minmax(0,1fr)}}
</style>
@endpush

<div class="painel-dono">
    <header class="page-header">
        <span class="painel-selo">AMOSTRA</span>
        <h1>Painel do Dono</h1>
    </header>
    <nav aria-label="Seções do Painel do Dono">
        @foreach ($abas as $chave => $nome)
            <a href="{{ request()->url() }}?amostra=painel-dono&amp;aba={{ $chave }}" @if ($aba === $chave) aria-current="page" @endif>{{ $nome }}</a>
        @endforeach
    </nav>
    <section aria-labelledby="painel-titulo">
        <h2 id="painel-titulo">{{ $abas[$aba] }}</h2>
        @if ($aba === 'dashboard')
            <div class="painel-cards">
                @foreach ($indicadores as [$tituloCard, $numero, $icone])
                    <article class="painel-card">
                        <header>
                            <svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="{{ $icone }}"/></svg>
                            <span class="painel-selo">AMOSTRA</span>
                        </header>
                        <h3>{{ $tituloCard }}</h3>
                        <p>{{ $numero }}</p>
                    </article>
                @endforeach
            </div>
        @elseif ($aba === 'clinica')
            <div class="painel-campos">
                @foreach ($campos as $rotulo => $valor)
                    <div class="campo">
                        <label for="clinica-{{ $loop->index }}">{{ $rotulo }}</label>
                        <input id="clinica-{{ $loop->index }}" value="{{ $valor }}" readonly>
                    </div>
                @endforeach
            </div>
        @else
            @if ($aba === 'clientes')
                <p>Tutores e seus pets</p>
            @endif
            <dl class="ficha">
                @foreach ($registros[$aba] as $nome => $detalhe)
                    <dt>{{ $nome }}</dt><dd>{{ $detalhe }}</dd>
                @endforeach
            </dl>
        @endif
    </section>
</div>
