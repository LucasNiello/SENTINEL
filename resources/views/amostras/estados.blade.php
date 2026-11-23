@php
    // Fonte única dos textos e dimensões das amostras; não representa dados do banco.
    $amostras = [
        'agenda' => ['Agenda', 'Nenhum agendamento neste período.', 8],
        'financeiro' => [$titulo ?? 'Financeiro', 'Nenhum registro encontrado.', 7],
        'resultados' => ['Resultados', 'Nenhum resultado neste período.', 6],
        'pet' => ['Ficha do pet', 'Nenhum agendamento ou cobrança para este pet.', 5],
        'tutor' => ['Pets do tutor', 'Nenhum pet vinculado a este tutor.', 3],
        'consulta' => [$titulo ?? 'Cadastros', 'Nenhum registro encontrado.', 5],
        'auditoria' => ['Auditoria', 'Nenhum registro de auditoria encontrado.', 6],
        'supervisao' => ['Supervisão', 'Nenhum funcionário encontrado.', 4],
    ];
    [$nomeAmostra, $mensagemVazia, $colunasAmostra] = $amostras[$tipoAmostra];
    $rotaReal = request()->fullUrlWithoutQuery('amostra');
@endphp
<style>
    .amostra-estados {min-width:0}
    .amostra-estados section {margin:24px 0;padding:24px;border:1px solid var(--sentinel-border);border-radius:var(--radius);background:var(--sentinel-paper);min-width:0}
    .amostra-estados h2 {font-size:20px;margin:0 0 16px}
    .amostra-estados a {display:inline-flex;align-items:center;min-height:44px;overflow-wrap:anywhere}
    .amostra-estados a:focus-visible {outline:2px solid var(--sentinel-blue);outline-offset:4px}
    .amostra-selo {display:inline-block;padding:4px 12px;border:1px solid currentColor;font-size:12px;letter-spacing:.1em}
    .amostra-linha {display:grid;grid-template-columns:repeat(var(--colunas),minmax(0,1fr));gap:12px;margin:16px 0}
    .amostra-barra {height:16px;background:var(--sentinel-border);border-radius:4px}
    .amostra-estados .amostra-erro {border-color:var(--color-erro);color:var(--color-erro)}
    @media(max-width:600px){.amostra-linha{grid-template-columns:1fr 1fr}.amostra-estados section{padding:16px}}
    @media(prefers-reduced-motion:reduce){.amostra-estados .animate-pulse{animation:none}}
</style>
<div class="amostra-estados">
    <header class="page-header">
        <span class="amostra-selo">AMOSTRA</span>
        <h1>{{ $nomeAmostra }} — estados</h1>
        <p>Exemplos visuais. Nenhum erro está sendo simulado no sistema e nenhum dado será gravado.</p>
        <a href="{{ $rotaReal }}">Voltar à tela atual</a>
    </header>
    <section aria-labelledby="amostra-vazio">
        <h2 id="amostra-vazio">Estado vazio</h2>
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path d="M4 5h16v15H4zM8 10h8M8 14h5"/></svg>
        <p>{{ $mensagemVazia }}</p>
        {{-- Criação exige o fluxo JS de revisão existente; não oferecer botão fictício. --}}
    </section>
    <section aria-labelledby="amostra-carregando">
        <h2 id="amostra-carregando">Estado carregando</h2>
        <p class="sr-only">Amostra visual de carregamento.</p>
        <div class="animate-pulse" aria-hidden="true" style="--colunas:{{ $colunasAmostra }}">
            @for ($linhaAmostra = 0; $linhaAmostra < 4; $linhaAmostra++)
                <div class="amostra-linha">
                    @for ($colunaAmostra = 0; $colunaAmostra < $colunasAmostra; $colunaAmostra++)
                        <span class="amostra-barra"></span>
                    @endfor
                </div>
            @endfor
        </div>
    </section>
    <section class="amostra-erro" aria-labelledby="amostra-erro">
        <h2 id="amostra-erro">Estado erro</h2>
        <p>Não foi possível carregar. Tente de novo.</p>
        <a href="{{ $rotaReal }}">Tentar de novo</a>
        <p>Este link abre novamente a tela real.</p>
    </section>
</div>
