<?php

namespace App\Services;

use App\Mail\AgendamentoMail;
use App\Models\Agendamento;
use App\Models\Pet;
use App\Models\Tutor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Aviso por e-mail ao tutor quando o agendamento é CRIADO, CONFIRMADO ou CANCELADO (remarcar fica para depois).
 * Chamado pelo AgendaService depois do commit (DB::afterCommit). Regras:
 * - tutor sem e-mail válido (ou de outro tenant, ou na lixeira): não envia, sem aviso;
 * - encaixe de emergência (já aconteceu): não envia;
 * - qualquer falha (SMTP fora do ar, timeout, endereço rejeitado) NUNCA afeta o agendamento: só um log com metadados
 *   (evento e classe da exceção), nunca o endereço, o texto do e-mail nem a mensagem da exceção;
 * - tudo é lido do tenant do agendamento; o envio é síncrono (sem fila) e o SMTP tem timeout curto (config/mail.php).
 */
class NotificacaoDeAgendamento
{
    public function avisar(int $agendamentoId, int $tenantId, string $evento): void
    {
        if (! config('sentinel.notificar_agendamento', true) || ! isset(AgendamentoMail::ASSUNTOS[$evento])) {
            return;
        }

        try {
            $agendamento = Agendamento::query()->where('tenant_id', $tenantId)->with('servico')->find($agendamentoId);
            if ($agendamento === null || $agendamento->origem === 'encaixe_emergencia') {
                return;
            }

            $pet = Pet::query()->where('tenant_id', $tenantId)->find($agendamento->pet_id);
            $tutor = $pet === null ? null : Tutor::query()->where('tenant_id', $tenantId)->find($pet->tutor_id);
            $email = $tutor === null ? '' : trim((string) $tutor->email);
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return;
            }

            Mail::to($email)->send(new AgendamentoMail(
                evento: $evento,
                tutor: $this->limpo($tutor->nome),
                pet: $this->limpo($pet->nome),
                servico: $this->limpo((string) $agendamento->servico?->nome_comercial),
                quando: $agendamento->inicio->format('d/m/Y \à\s H:i'),
                clinica: $this->limpo((string) config('mail.from.name')),
            ));
        } catch (Throwable $e) {
            Log::warning('NotificacaoDeAgendamento: falha ao enviar o e-mail', [
                'categoria' => 'notificacao_error',
                'evento' => $evento,
                'excecao' => $e::class,
                'local' => basename($e->getFile()).':'.$e->getLine(),
            ]);
        }
    }

    /** Uma linha só, sem controle: nome com quebra de linha não forja linha nem cabeçalho do e-mail. */
    private function limpo(string $valor): string
    {
        return Str::limit(trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $valor)), 120, '');
    }
}
