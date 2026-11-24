<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * E-mail ao tutor sobre o agendamento (criado, confirmado, cancelado). Texto puro, só strings já prontas (nunca um model):
 * pet, serviço e horário. Sem preço, sem prontuário, sem dado de outro tutor.
 */
class AgendamentoMail extends Mailable
{
    public const ASSUNTOS = [
        'criado' => 'Agendamento recebido',
        'confirmado' => 'Agendamento confirmado',
        'cancelado' => 'Agendamento cancelado',
    ];

    public function __construct(
        public readonly string $evento,
        public readonly string $tutor,
        public readonly string $pet,
        public readonly string $servico,
        public readonly string $quando,
        public readonly string $clinica,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: (self::ASSUNTOS[$this->evento] ?? 'Agendamento').' — '.$this->pet);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.agendamento');
    }
}
