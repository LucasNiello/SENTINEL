<?php

namespace App\Services;

use App\Exceptions\RegraDeNegocioException;
use App\Models\ConfiguracaoAgenda;

/**
 * Painel do dono (23/11): expediente, intervalo e vagas por bloco por tenant. O padrão é config('sentinel.agenda'); a
 * linha do tenant (se houver) sobrescreve só esses cinco valores. Os dias de funcionamento, a duração do bloco e a
 * regra por categoria seguem na config. Quem chama passa o tenant do ContextoUsuario e confere o papel (dono).
 */
class ConfiguracaoDaAgenda
{
    public const MAX_VAGAS = 20;

    /** A seção sentinel.agenda com os valores do tenant por cima; é o que a GradeDaAgenda lê. */
    public function agendaDoTenant(int $tenantId): array
    {
        $agenda = (array) config('sentinel.agenda', []);
        $linha = ConfiguracaoAgenda::query()->where('tenant_id', $tenantId)->first();
        if ($linha === null) {
            return $agenda;
        }

        $agenda['expediente'] = [...(array) ($agenda['expediente'] ?? []), 'inicio' => $linha->expediente_inicio, 'fim' => $linha->expediente_fim,
            'intervalo' => $linha->intervalo_inicio !== null && $linha->intervalo_fim !== null
                ? ['inicio' => $linha->intervalo_inicio, 'fim' => $linha->intervalo_fim] : null];
        $agenda['clinica'] = [...(array) ($agenda['clinica'] ?? []), 'vagas' => $linha->vagas_por_bloco];

        return $agenda;
    }

    /** @return array{abre: string, fecha: string, intervalo_inicio: ?string, intervalo_fim: ?string, vagas: int, personalizada: bool} */
    public function valores(int $tenantId): array
    {
        $agenda = $this->agendaDoTenant($tenantId);
        $exp = (array) ($agenda['expediente'] ?? []);
        $intervalo = (array) ($exp['intervalo'] ?? []);

        return [
            'abre' => (string) ($exp['inicio'] ?? ''), 'fecha' => (string) ($exp['fim'] ?? ''),
            'intervalo_inicio' => isset($intervalo['inicio']) ? (string) $intervalo['inicio'] : null,
            'intervalo_fim' => isset($intervalo['fim']) ? (string) $intervalo['fim'] : null,
            'vagas' => (int) ($agenda['clinica']['vagas'] ?? 0),
            'personalizada' => ConfiguracaoAgenda::query()->where('tenant_id', $tenantId)->exists(),
        ];
    }

    /**
     * Grava (cria ou troca) a configuração do tenant. Aceita só o que cabe: HH:MM, abre antes de fechar, intervalo
     * completo (os dois ou nenhum) dentro do expediente e vagas de 1 a MAX_VAGAS. Agendamentos que já existem não são
     * mexidos: só passam a contar na regra nova.
     *
     * @param  array{abre: string, fecha: string, intervalo_inicio: ?string, intervalo_fim: ?string, vagas: int}  $dados
     */
    public function salvar(array $dados, int $tenantId): ConfiguracaoAgenda
    {
        $abre = $this->minutos($dados['abre'] ?? null, 'Abre às');
        $fecha = $this->minutos($dados['fecha'] ?? null, 'Fecha às');
        if ($fecha <= $abre) {
            throw new RegraDeNegocioException('O expediente precisa fechar depois de abrir.');
        }

        $iIni = $dados['intervalo_inicio'] ?? null;
        $iFim = $dados['intervalo_fim'] ?? null;
        if (($iIni === null || $iIni === '') !== ($iFim === null || $iFim === '')) {
            throw new RegraDeNegocioException('Informe o início e o fim do intervalo, ou deixe os dois em branco.');
        }
        if ($iIni === null || $iIni === '') {
            $iIni = $iFim = null;
        } else {
            $a = $this->minutos($iIni, 'Início do intervalo');
            $b = $this->minutos($iFim, 'Fim do intervalo');
            if ($b <= $a || $a < $abre || $b > $fecha) {
                throw new RegraDeNegocioException('O intervalo precisa começar antes de terminar e ficar dentro do expediente.');
            }
        }

        $vagas = $dados['vagas'] ?? null;
        if (! is_int($vagas) || $vagas < 1 || $vagas > self::MAX_VAGAS) {
            throw new RegraDeNegocioException('As vagas por bloco vão de 1 a '.self::MAX_VAGAS.'.');
        }

        return ConfiguracaoAgenda::query()->updateOrCreate(['tenant_id' => $tenantId], [
            'expediente_inicio' => $dados['abre'], 'expediente_fim' => $dados['fecha'],
            'intervalo_inicio' => $iIni, 'intervalo_fim' => $iFim, 'vagas_por_bloco' => $vagas,
        ]);
    }

    private function minutos(mixed $hora, string $campo): int
    {
        if (! is_string($hora) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hora, $m) !== 1) {
            throw new RegraDeNegocioException($campo.': informe a hora no formato HH:MM.');
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }
}
