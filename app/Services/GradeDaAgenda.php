<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Regras de horário da agenda, em PHP puro (sem banco): quem chama entrega os agendamentos que ocupam o profissional
 * e o "agora". Usada pelo AgendaService na gravação (dentro da transação, com a leitura travada) e pelo calendário da
 * ficha (que só mostra; nunca é a trava). Configuração: config('sentinel.agenda') (decisão do Lucas, 15/11).
 *
 * - Clínica (adendo final do Lucas, 15/11): blocos de 1 h. Consulta ocupa 1 das 4 vagas do bloco do veterinário (hora
 *   marcada em lote, atendidos na ordem). Os demais serviços ocupam o bloco inteiro, sozinhos (só entram em bloco vazio
 *   e o fecham); cirurgia, 2 blocos inteiros. Internação não ocupa a agenda do veterinário. Categoria sem regra =
 *   recusa (falha fechada). O fim é o fim do(s) bloco(s). Bloco em andamento continua agendável; encerrado fecha.
 * - Petshop: um atendimento por vez por profissional, fim = início + duração do serviço, grade de 15 min contada da
 *   meia-noite, intervalo semiaberto [início, fim) (encostar não é conflito), só de agora em diante e inteiro no
 *   expediente. A ideia é a mesma do horarios-livres do wellbook, escrita aqui em PHP.
 *
 * Ocupado: ['inicio' => CarbonInterface, 'fim' => CarbonInterface, 'exclusivo' => bool] (exclusivo = ocupa o bloco inteiro).
 */
final class GradeDaAgenda
{
    private const DIAS = [1 => 'segunda', 2 => 'terça', 3 => 'quarta', 4 => 'quinta', 5 => 'sexta', 6 => 'sábado', 7 => 'domingo'];

    /** @param  array<string, mixed>  $config  a seção sentinel.agenda */
    public function __construct(private array $config, private string $fuso) {}

    /** Sem tenant, só a config (padrão); com tenant, a configuração do painel do dono por cima (ConfiguracaoDaAgenda). */
    public static function daConfiguracao(?int $tenantId = null): self
    {
        $config = $tenantId === null ? (array) config('sentinel.agenda', []) : app(ConfiguracaoDaAgenda::class)->agendaDoTenant($tenantId);

        return new self($config, (string) config('app.timezone'));
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null o intervalo (almoço) do dia, ou null se não há */
    public function intervalo(CarbonInterface $dia): ?array
    {
        $i = (array) (((array) ($this->config['expediente'] ?? []))['intervalo'] ?? []);
        $local = CarbonImmutable::instance($dia)->setTimezone($this->fuso);
        $inicio = $this->naHora($local, (string) ($i['inicio'] ?? ''));
        $fim = $this->naHora($local, (string) ($i['fim'] ?? ''));

        return $inicio === null || $fim === null || $fim->lte($inicio) ? null : [$inicio, $fim];
    }

    /** O trecho [de, até) encosta no intervalo do dia? */
    private function noIntervalo(CarbonImmutable $de, CarbonImmutable $ate): bool
    {
        $intervalo = $this->intervalo($de);

        return $intervalo !== null && $de->lt($intervalo[1]) && $ate->gt($intervalo[0]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null o começo e o fim do expediente do dia, ou null se não abre */
    public function expediente(CarbonInterface $dia): ?array
    {
        $exp = (array) ($this->config['expediente'] ?? []);
        $local = CarbonImmutable::instance($dia)->setTimezone($this->fuso);

        if (! in_array($local->dayOfWeekIso, array_map('intval', (array) ($exp['dias'] ?? [])), true)) {
            return null;
        }
        $inicio = $this->naHora($local, (string) ($exp['inicio'] ?? ''));
        $fim = $this->naHora($local, (string) ($exp['fim'] ?? ''));

        return $inicio === null || $fim === null || $fim->lte($inicio) ? null : [$inicio, $fim];
    }

    public function minutosDoBloco(): int
    {
        return max(1, (int) ($this->config['clinica']['bloco_minutos'] ?? 60));
    }

    public function vagasPorBloco(): int
    {
        return max(0, (int) ($this->config['clinica']['vagas'] ?? 0));
    }

    /** Como a categoria ocupa o bloco: 'vaga', 'fora' ou o número de blocos inteiros; null = sem regra (falha fechada). */
    public function ocupacao(?string $categoria): string|int|null
    {
        $regra = ((array) ($this->config['clinica']['por_categoria'] ?? []))[(string) $categoria] ?? null;

        return match (true) {
            $regra === 'vaga', $regra === 'fora' => $regra,
            is_int($regra) && $regra >= 1 => $regra,
            default => null,
        };
    }

    /**
     * Quantos blocos inteiros a categoria ocupa sozinha (cirurgia = 2, procedimento = 1); 0 = consulta (1 vaga) ou fora
     * da agenda. Categoria sem regra conta como 1 bloco inteiro: quem já está na agenda com ela nunca deixa a vaga "livre".
     */
    public function blocosExclusivos(?string $categoria): int
    {
        $ocupacao = $this->ocupacao($categoria);

        return is_int($ocupacao) ? $ocupacao : ($ocupacao === null ? 1 : 0);
    }

    /** Internação: registrada com a data de entrada, sem ocupar a agenda do veterinário. */
    public function foraDaAgenda(?string $categoria): bool
    {
        return $this->ocupacao($categoria) === 'fora';
    }

    /** O calendário mostra "N vagas" só para o que divide o bloco (consulta); os demais, só livre/ocupado. */
    public function mostraVagas(?string $categoria): bool
    {
        return $this->ocupacao($categoria) === 'vaga';
    }

    /** Duração (min) de um serviço do petshop, pela categoria; "_" vale para as outras. */
    public function duracaoPetshop(?string $categoria): int
    {
        $duracoes = (array) ($this->config['petshop']['duracoes'] ?? []);

        return max(1, (int) ($duracoes[(string) $categoria] ?? $duracoes['_'] ?? 60));
    }

    public function minutosDaGrade(): int
    {
        return max(1, (int) ($this->config['petshop']['grade_minutos'] ?? 15));
    }

    /** @return CarbonImmutable[] o início de cada bloco da clínica no dia (08:00 … 17:00), vazio se o dia não abre */
    public function blocosDoDia(CarbonInterface $dia): array
    {
        $exp = $this->expediente($dia);
        if ($exp === null) {
            return [];
        }
        $blocos = [];
        for ($b = $exp[0]; $b->addMinutes($this->minutosDoBloco())->lte($exp[1]); $b = $b->addMinutes($this->minutosDoBloco())) {
            if (! $this->noIntervalo($b, $b->addMinutes($this->minutosDoBloco()))) {
                $blocos[] = $b; // o bloco que encosta no intervalo não existe
            }
        }

        return $blocos;
    }

    /** O bloco da clínica que contém o instante (ou que começa nele), ou null fora do expediente. */
    public function blocoQueContem(CarbonInterface $instante): ?CarbonImmutable
    {
        $instante = CarbonImmutable::instance($instante);
        foreach ($this->blocosDoDia($instante) as $bloco) {
            if ($bloco->lte($instante) && $bloco->addMinutes($this->minutosDoBloco())->gt($instante)) {
                return $bloco;
            }
        }

        return null;
    }

    /**
     * O fim do agendamento. Clínica: fim do bloco que contém o início (de N blocos, na cirurgia); fora do expediente
     * (só o encaixe de emergência chega aqui), início + N blocos. Petshop: início + duração do serviço.
     */
    public function fim(string $setor, ?string $categoria, CarbonInterface $inicio): CarbonImmutable
    {
        $inicio = CarbonImmutable::instance($inicio);
        if ($setor !== 'clinica') {
            return $inicio->addMinutes($this->duracaoPetshop($categoria));
        }
        if ($this->foraDaAgenda($categoria)) {
            return $inicio->addMinutes($this->minutosDoBloco());
        }
        $blocos = max(1, $this->blocosExclusivos($categoria));

        return ($this->blocoQueContem($inicio) ?? $inicio)->addMinutes($blocos * $this->minutosDoBloco());
    }

    /** O horário já passou? Clínica: o bloco terminou. Petshop: o início ficou antes do minuto atual. */
    public function jaPassou(string $setor, CarbonInterface $inicio, CarbonInterface $agora): bool
    {
        if ($setor !== 'clinica') {
            return CarbonImmutable::instance($inicio)->lt(CarbonImmutable::instance($agora)->startOfMinute());
        }
        $bloco = $this->blocoQueContem($inicio) ?? CarbonImmutable::instance($inicio);

        return $bloco->addMinutes($this->minutosDoBloco())->lte($agora);
    }

    /** Vagas livres de UM veterinário no bloco: 0 se uma cirurgia cobre o bloco; senão as vagas menos quem já está nele. */
    public function vagasNoBloco(CarbonInterface $bloco, array $ocupados): int
    {
        $bloco = CarbonImmutable::instance($bloco);
        $fim = $bloco->addMinutes($this->minutosDoBloco());
        $noBloco = array_filter($ocupados, fn (array $o) => $o['inicio']->lt($fim) && $o['fim']->gt($bloco));

        foreach ($noBloco as $o) {
            if ($o['exclusivo']) {
                return 0;
            }
        }

        return max(0, $this->vagasPorBloco() - count($noBloco));
    }

    /**
     * A frase de recusa do horário (dia, grade, expediente, vaga ou conflito), ou null se cabe. Não confere o passado
     * (jaPassou(), com a frase de quem chama). $ocupados: os agendamentos ATIVOS do mesmo profissional.
     */
    public function motivo(string $setor, ?string $categoria, CarbonInterface $inicio, array $ocupados): ?string
    {
        $inicio = CarbonImmutable::instance($inicio)->setTimezone($this->fuso);
        $exp = $this->expediente($inicio);
        if ($exp === null) {
            return 'Fora do expediente: a agenda funciona '.$this->expedientePorExtenso().'.';
        }

        return $setor === 'clinica'
            ? $this->motivoNaClinica($categoria, $inicio, $ocupados)
            : $this->motivoNoPetshop($categoria, $inicio, $exp, $ocupados);
    }

    /**
     * Os horários de um profissional num dia, para o calendário. Clínica: cada bloco ainda não encerrado, com as vagas
     * dele (0 = lotado; na cirurgia, 1 se todos os blocos dela estiverem vazios). Petshop: só os
     * inícios livres da grade (vagas = 1).
     *
     * @return array<int, array{inicio: CarbonImmutable, vagas: int}>
     */
    public function horariosDoDia(string $setor, ?string $categoria, CarbonInterface $dia, array $ocupados, CarbonInterface $agora): array
    {
        $exp = $this->expediente($dia);
        if ($exp === null) {
            return [];
        }
        $horarios = [];

        if ($setor === 'clinica') {
            foreach ($this->blocosDoDia($dia) as $bloco) {
                if ($this->jaPassou('clinica', $bloco, $agora)) {
                    continue;
                }
                $livre = $this->motivoNaClinica($categoria, $bloco, $ocupados) === null;
                // Só a consulta divide o bloco: os demais ocupam o veterinário inteiro e livre conta 1 (na união, "qualquer
                // veterinário", soma veterinários livres).
                $vagas = $this->mostraVagas($categoria) ? $this->vagasNoBloco($bloco, $ocupados) : 1;
                $horarios[] = ['inicio' => $bloco, 'vagas' => $livre ? max(1, $vagas) : 0];
            }

            return $horarios;
        }

        $passo = $this->minutosDaGrade();
        $meiaNoite = $exp[0]->startOfDay();
        $primeiro = (int) (ceil($meiaNoite->diffInMinutes($exp[0]) / $passo) * $passo);
        for ($inicio = $meiaNoite->addMinutes($primeiro); $inicio->lt($exp[1]); $inicio = $inicio->addMinutes($passo)) {
            if (! $this->jaPassou('petshop', $inicio, $agora) && $this->motivoNoPetshop($categoria, $inicio, $exp, $ocupados) === null) {
                $horarios[] = ['inicio' => $inicio, 'vagas' => 1];
            }
        }

        return $horarios;
    }

    private function motivoNaClinica(?string $categoria, CarbonImmutable $inicio, array $ocupados): ?string
    {
        $ocupacao = $this->ocupacao($categoria);
        if ($ocupacao === null) {
            return 'Este serviço (categoria "'.$categoria.'") não tem regra de ocupação na agenda da clínica: peça ao dono para configurar.';
        }
        if ($ocupacao === 'fora') {
            return null; // internação: não ocupa a agenda do veterinário
        }
        $blocos = $this->blocosDoDia($inicio);
        $posicao = array_search(true, array_map(fn (CarbonImmutable $b) => $b->equalTo($inicio), $blocos), true);

        if ($posicao === false) {
            return 'Na clínica o horário é por bloco de '.$this->minutosDoBloco().' min ('.$this->listaDeBlocos($blocos).'): escolha um bloco.';
        }

        $exclusivos = $this->blocosExclusivos($categoria);
        if ($exclusivos === 0) {
            return $this->vagasNoBloco($inicio, $ocupados) >= 1 ? null
                : 'O veterinário não tem vaga no bloco das '.$inicio->format('H:i').' (as '.$this->vagasPorBloco().' vagas estão usadas ou o bloco está ocupado por um procedimento). Escolha outro horário ou outro veterinário.';
        }

        $cobertos = array_slice($blocos, (int) $posicao, $exclusivos);
        if (count($cobertos) < $exclusivos) {
            return 'Este atendimento ocupa '.$exclusivos.' blocos e não cabe antes do fim do expediente. Escolha um bloco mais cedo.';
        }
        foreach ($cobertos as $i => $bloco) {
            if ($i > 0 && ! $bloco->equalTo($cobertos[$i - 1]->addMinutes($this->minutosDoBloco()))) {
                return 'Este atendimento ocupa '.$exclusivos.' blocos seguidos e o intervalo do expediente está no meio. Escolha um horário antes ou depois do intervalo.';
            }
        }
        foreach ($cobertos as $bloco) {
            if ($this->vagasNoBloco($bloco, $ocupados) < $this->vagasPorBloco()) {
                return 'Este atendimento ocupa o bloco inteiro do veterinário ('.(count($cobertos) === 1 ? 'o das ' : 'os das ')
                    .implode(' e ', array_map(fn (CarbonImmutable $b) => $b->format('H:i'), $cobertos))
                    .'), e já há atendimento nele. Escolha outro horário ou outro veterinário.';
            }
        }

        return null;
    }

    /** @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $exp */
    private function motivoNoPetshop(?string $categoria, CarbonImmutable $inicio, array $exp, array $ocupados): ?string
    {
        $minutoDoDia = $inicio->startOfDay()->diffInMinutes($inicio);
        if ($inicio->second !== 0 || ((int) $minutoDoDia) % $this->minutosDaGrade() !== 0) {
            return 'No banho e tosa os horários são de '.$this->minutosDaGrade().' em '.$this->minutosDaGrade().' min (ex.: 09:00, 09:15): escolha um deles.';
        }
        $fim = $inicio->addMinutes($this->duracaoPetshop($categoria));
        if ($inicio->lt($exp[0]) || $fim->gt($exp[1])) {
            return 'O atendimento precisa caber inteiro no expediente ('.$exp[0]->format('H:i').' às '.$exp[1]->format('H:i').').';
        }
        if ($this->noIntervalo($inicio, $fim)) {
            $i = $this->intervalo($inicio);

            return 'O atendimento não pode passar pelo intervalo do expediente ('.$i[0]->format('H:i').' às '.$i[1]->format('H:i').').';
        }
        foreach ($ocupados as $o) {
            if ($o['inicio']->lt($fim) && $o['fim']->gt($inicio)) {
                return 'O profissional já tem um agendamento nesse horário.';
            }
        }

        return null;
    }

    private function naHora(CarbonImmutable $dia, string $hora): ?CarbonImmutable
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hora, $m) !== 1) {
            return null;
        }

        return $dia->startOfDay()->setTime((int) $m[1], (int) $m[2]);
    }

    /** 26/11: o funcionamento por extenso, para o agente explicar um dia sem expediente ("de segunda a sábado, das 08:00 às 18:00"). */
    public function funcionamento(): string
    {
        return $this->expedientePorExtenso();
    }

    /** "de segunda a sábado, das 08:00 às 18:00" */
    private function expedientePorExtenso(): string
    {
        $exp = (array) ($this->config['expediente'] ?? []);
        $dias = array_values(array_filter(array_map('intval', (array) ($exp['dias'] ?? [])), fn (int $d) => isset(self::DIAS[$d])));
        sort($dias);
        if ($dias === []) {
            return 'em nenhum dia (expediente não configurado)';
        }
        $contiguos = $dias === range($dias[0], end($dias));
        $quando = count($dias) === 1 ? 'só '.self::DIAS[$dias[0]]
            : ($contiguos ? 'de '.self::DIAS[$dias[0]].' a '.self::DIAS[end($dias)] : implode(', ', array_map(fn (int $d) => self::DIAS[$d], $dias)));

        $intervalo = (array) ($exp['intervalo'] ?? []);
        $pausa = isset($intervalo['inicio'], $intervalo['fim']) ? ' (intervalo das '.$intervalo['inicio'].' às '.$intervalo['fim'].')' : '';

        return $quando.', das '.($exp['inicio'] ?? '?').' às '.($exp['fim'] ?? '?').$pausa;
    }

    /** @param  CarbonImmutable[]  $blocos */
    private function listaDeBlocos(array $blocos): string
    {
        if ($blocos === []) {
            return 'nenhum bloco neste dia';
        }

        return count($blocos) <= 2
            ? implode(', ', array_map(fn ($b) => $b->format('H:i'), $blocos))
            : $blocos[0]->format('H:i').', '.$blocos[1]->format('H:i').' … '.end($blocos)->format('H:i');
    }
}
