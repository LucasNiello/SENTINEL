@php
    $agendamento = ['Tutor' => 'Marina Alves', 'Pet' => 'Mel', 'Serviço' => 'Consulta de retorno'];
    $mes = 'Novembro de 2026';
    // O mês começa no domingo. Dias sem marcação estão livres.
    $dias = array_replace(array_fill(1, 30, 'livre'), [12 => 'lotado', 18 => 'selecionado', 23 => 'lotado']);
    $estados = ['livre' => 'Livre', 'lotado' => 'Lotado', 'selecionado' => 'Selecionado'];
    $profissionais = [
        ['nome' => 'Lívia Nunes', 'crmv' => 'CRMV-SP 00001', 'selecionado' => true],
        ['nome' => 'Caio Reis', 'crmv' => 'CRMV-SP 00002', 'selecionado' => false],
    ];
@endphp

@push('estilos')
<style>
    .remarcacao-selo {display:inline-block;padding:4px 12px;border:1px solid currentColor;font-size:12px;letter-spacing:.1em}
    .remarcacao-grade {display:grid;gap:24px;align-items:start}
    .remarcacao-grade > section {min-width:0}
    .remarcacao-calendario {width:100%;min-width:0;table-layout:fixed;border-collapse:separate;border-spacing:2px}
    .remarcacao-calendario caption {text-align:left;font-weight:600;margin-bottom:8px}
    .remarcacao-calendario :is(th,td) {text-align:center;padding:6px 0;font:inherit;border:0}
    .remarcacao-calendario td:not(:empty) {border:1px solid var(--sentinel-border);border-radius:4px;background:var(--sentinel-paper)}
    .remarcacao-calendario td.lotado {background:#e4e5e6;color:var(--sentinel-ink);cursor:not-allowed;text-decoration:line-through}
    .remarcacao-calendario td.selecionado {background:var(--sentinel-blue);color:var(--sentinel-paper);font-weight:700}
    .remarcacao-legenda {display:flex;flex-wrap:wrap;gap:6px 16px;margin-top:8px}
    .remarcacao-legenda span::before {content:'';display:inline-block;width:.85em;height:.85em;border:1px solid var(--sentinel-ink-muted);border-radius:3px;margin-right:5px;background:var(--sentinel-paper)}
    .remarcacao-legenda .lotado::before {background:#e4e5e6}
    .remarcacao-legenda .selecionado::before {background:var(--sentinel-blue)}
    .remarcacao-equipe {list-style:none;display:grid;gap:12px;padding:0;margin:0}
    .remarcacao-equipe li {display:grid;padding:12px 16px;border:1px solid var(--sentinel-border);border-radius:var(--radius);background:var(--sentinel-paper)}
    .remarcacao-equipe .selecionado {border:2px solid var(--sentinel-blue)}
    @media (min-width:768px) {.remarcacao-grade {grid-template-columns:minmax(0,3fr) minmax(0,2fr)}}
</style>
@endpush

<header class="page-header">
    <span class="remarcacao-selo">AMOSTRA</span>
    <h1>Remarcação de agendamento</h1>
</header>
<section class="bloco" aria-labelledby="titulo-agendamento">
    <h2 id="titulo-agendamento">Ficha do agendamento</h2>
    <dl class="ficha">
        @foreach ($agendamento as $rotulo => $valor)
            <dt>{{ $rotulo }}</dt><dd>{{ $valor }}</dd>
        @endforeach
    </dl>
</section>
<div class="remarcacao-grade">
    <section class="bloco" aria-labelledby="titulo-calendario">
        <h2 id="titulo-calendario">Calendário</h2>
        <table class="remarcacao-calendario">
            <caption>{{ $mes }}</caption>
            <thead><tr>
                @foreach (['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'] as $semana)
                    <th scope="col">{{ $semana }}</th>
                @endforeach
            </tr></thead>
            <tbody>
                @foreach (array_chunk($dias, 7, true) as $linha)
                    <tr>
                        @foreach ($linha as $dia => $estado)
                            <td class="{{ $estado }}">{{ $dia }}<span class="sr-only"> — {{ $estados[$estado] }}</span></td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="remarcacao-legenda">
            @foreach ($estados as $estado => $rotulo)
                <span class="{{ $estado }}">{{ $rotulo }}</span>
            @endforeach
        </div>
    </section>
    <section class="bloco" aria-labelledby="titulo-profissionais">
        <h2 id="titulo-profissionais">Profissionais disponíveis</h2>
        <ul class="remarcacao-equipe">
            @foreach ($profissionais as $profissional)
                <li @class(['selecionado' => $profissional['selecionado']])>
                    <strong>{{ $profissional['nome'] }}</strong>
                    <span>{{ $profissional['crmv'] }}</span>
                    @if ($profissional['selecionado'])
                        <span>Selecionada</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
</div>
