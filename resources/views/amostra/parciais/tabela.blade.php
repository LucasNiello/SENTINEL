@php
    use App\Amostra\DadosAmostra;
    $numericas = DadosAmostra::colunasNumericas();
    $entidade = DadosAmostra::entidades()[$bloco['entidade']] ?? null;
    $clicavel = !empty($bloco['clicavel']);
@endphp
<div class="barra-tabela">
    <strong>{{ $entidade['rotulo'] ?? 'Resultado' }}</strong>
    <span class="espaco"></span>
    <button type="button" class="btn btn--pequeno" id="exportar" data-amostra-acao="Exportaria o CSV desta tabela, com CPF e salário mascarados.">Exportar CSV</button>
</div>
<div class="tabela-rolagem" tabindex="0" role="region" aria-label="Tabela de {{ $entidade['rotulo'] ?? 'resultado' }}">
    <table>
        @if (!empty($bloco['legenda'])) <caption>{{ $bloco['legenda'] }}</caption> @endif
        <thead>
            <tr>
                @foreach ($bloco['colunas'] as $col)
                    <th scope="col" @if (in_array($col, $numericas, true)) class="num" @endif>{{ $col }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($bloco['linhas'] as $linha)
                <tr @if ($clicavel) class="clicavel" tabindex="0" data-href="{{ $u('formulario/' . ($entidade['formulario'] ?? 'cliente'), ['modo' => 'atualizar']) }}" aria-label="Atualizar {{ $linha['nome'] ?? ('item ' . $linha['id']) }}" @endif>
                    @foreach ($bloco['colunas'] as $col)
                        <td @if (in_array($col, $numericas, true)) class="num" @endif>
                            @if ($col === 'status')
                                <span class="status {{ ($linha[$col] ?? '') === 'conciliado' ? 'status--sucesso' : 'status--aviso' }}">{{ ($linha[$col] ?? '') === 'conciliado' ? '✔' : '⌛' }} {{ $linha[$col] ?? '' }}</span>
                            @else
                                {{ $linha[$col] ?? '' }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
