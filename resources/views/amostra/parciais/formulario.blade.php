@php
    use App\Amostra\DadosAmostra;

    $def = DadosAmostra::formulario($bloco['entidade']);
    $modo = $bloco['modo'];
    $estado = $bloco['estado'];
    $noChat = $noChat ?? false;
    $acaoPermissao = $def['papel_minimo'] === 'admin' ? 'criar_funcionario' : 'escrever';
    $permitido = DadosAmostra::pode($papel, $acaoPermissao);

    $campos = [];
    foreach ($def['campos'] as $c) {
        $valor = '';
        $etiqueta = null;
        $erro = null;
        $faltante = false;
        if ($noChat && $modo === 'criar') {
            $valor = $c['ia'] ?? '';
            if (($c['ia'] ?? null) !== null) { $etiqueta = 'ia'; }
            if ($estado === 'faltantes' && $c['nome'] === $def['faltante']) { $valor = ''; $etiqueta = null; }
            if ($c['obrigatorio'] && $valor === '') { $faltante = true; $etiqueta = 'faltante'; }
        } elseif ($modo === 'atualizar' || $estado === 'atualizar') {
            $valor = $c['atual'] ?? '';
            $etiqueta = 'atual';
        }
        if ($estado === 'faltantes' && !$noChat && $c['nome'] === $def['faltante']) { $faltante = true; $etiqueta = 'faltante'; }
        if ($estado === 'erro' && $c['nome'] === $def['erro']['campo']) { $valor = $def['erro']['valor']; $erro = $def['erro']['mensagem']; }
        $campos[] = $c + ['valor_atual' => $valor, 'etiqueta' => $etiqueta, 'erro_msg' => $erro, 'faltante_flag' => $faltante];
    }
    $verbo = $modo === 'atualizar' ? 'Atualizar' : 'Cadastrar';
    $idForm = 'form-' . $bloco['entidade'] . '-' . ($noChat ? 'chat' : 'pagina');
@endphp

@if (!$permitido)
    <div class="msg msg--erro" role="alert">
        <strong>Você não tem permissão para {{ strtolower($verbo) }} {{ $def['rotulo_entidade'] }}.</strong>
        <span class="msg__acao">Seu papel agora é <span class="papel-tag">{{ $papel }}</span>. {{ $def['papel_minimo'] === 'admin' ? 'Só administradores mexem em dados de funcionários.' : 'É preciso ser operador ou administrador.' }}</span>
    </div>
@else
<form class="card card--risco-escrita" id="{{ $idForm }}" data-amostra="Para gravar, segure o botão Confirmar." novalidate aria-label="{{ $verbo }} {{ $def['rotulo_entidade'] }}">
    <div class="card__titulo">
        <span>{{ $verbo }} {{ $def['rotulo_entidade'] }}</span>
        <span class="card__risco">risco: escrita</span>
        @if ($noChat && $modo === 'criar') <span class="tag tag--ia">Preenchido pela IA — confira</span> @endif
    </div>

    @if ($estado === 'erro')
        <div class="msg msg--erro" role="alert"><strong>Corrija o campo marcado antes de confirmar.</strong></div>
    @elseif ($estado === 'faltantes' || in_array(true, array_column($campos, 'faltante_flag'), true))
        <div class="msg msg--aviso" role="status"><strong>Falta preencher um campo obrigatório.</strong></div>
    @endif

    <div class="grade-campos">
        @foreach ($campos as $c)
            @php $id = $idForm . '-' . $c['nome']; $ajuda = $id . '-ajuda'; @endphp
            <div class="campo @if ($c['erro_msg']) campo--erro @elseif ($c['faltante_flag']) campo--faltante @endif">
                <label for="{{ $id }}">
                    {{ $c['rotulo'] }}
                    @if ($c['obrigatorio']) <span class="obrigatorio">(obrigatório)</span> @endif
                    @if ($c['etiqueta'] === 'ia') <span class="tag tag--ia">IA</span>
                    @elseif ($c['etiqueta'] === 'faltante') <span class="tag tag--faltante">Falta preencher</span>
                    @elseif ($c['etiqueta'] === 'atual') <span class="tag tag--neutro">Valor atual</span> @endif
                </label>
                @if (($c['tipo'] ?? 'text') === 'select')
                    <select id="{{ $id }}" name="{{ $c['nome'] }}" aria-describedby="{{ $ajuda }}" @if ($c['erro_msg']) aria-invalid="true" @endif>
                        @foreach ($c['opcoes'] as $chave => $texto)
                            <option value="{{ $chave }}" @if ((string) $chave === (string) $c['valor_atual']) selected @endif>{{ $texto }}</option>
                        @endforeach
                    </select>
                @else
                    <input id="{{ $id }}" name="{{ $c['nome'] }}" type="{{ $c['tipo'] ?? 'text' }}" value="{{ $c['valor_atual'] }}"
                           @if (!empty($c['numerico'])) step="0.01" inputmode="decimal" @endif
                           @if (!empty($c['maximo'])) maxlength="{{ $c['maximo'] }}" @endif
                           aria-describedby="{{ $ajuda }}" @if ($c['erro_msg']) aria-invalid="true" @endif>
                @endif
                <span class="dica" id="{{ $ajuda }}">@if ($c['erro_msg'])<span class="msg-erro">✕ {{ $c['erro_msg'] }}</span>@else{{ $c['dica'] ?? '' }}@endif</span>
            </div>
        @endforeach
    </div>

    <p class="dica-segurar">A empresa e o usuário são definidos pelo servidor e nunca aparecem neste formulário.</p>
    <div class="acoes">
        <button type="button" class="btn-confirmar" data-hold="Nada foi gravado: isto é uma amostra.">
            <span class="btn-confirmar__fill"></span>
            <span class="btn-confirmar__label">Confirmar</span>
        </button>
        <button type="button" class="btn-cancelar" data-cancelar>Cancelar</button>
    </div>
    <p class="dica-segurar">Segure "Confirmar" por 2 segundos. Se soltar antes, nada é gravado.</p>
    <div class="msg msg--aviso" data-aviso-soltou hidden role="status"></div>
</form>
@endif
