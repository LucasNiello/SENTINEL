{{-- Texto puro: {!! !!} de propósito (e-mail de texto não interpreta HTML; {{ }} deixaria &#039; no nome). Os valores já vêm limpos. --}}
Olá, {!! $tutor !!}.

@if ($evento === 'cancelado')
O agendamento abaixo foi cancelado.
@elseif ($evento === 'confirmado')
Seu agendamento está confirmado.
@else
Recebemos o agendamento abaixo.
@endif


Pet: {!! $pet !!}
Serviço: {!! $servico !!}
Quando: {!! $quando !!}

@if ($evento === 'cancelado')
Se quiser marcar outro horário, é só responder a este e-mail ou falar com a clínica.
@else
Se precisar mudar o horário, é só responder a este e-mail ou falar com a clínica.
@endif

{!! $clinica !!}
