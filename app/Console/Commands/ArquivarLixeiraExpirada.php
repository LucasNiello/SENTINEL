<?php

namespace App\Console\Commands;

use App\Models\CategoriaLancamento;
use App\Models\Cliente;
use App\Models\Fornecedor;
use App\Models\Funcionario;
use App\Models\Lancamento;
use App\Models\NotaFiscal;
use Illuminate\Console\Command;

/**
 * RF11: passado o prazo de retenção da lixeira sem restauração, o registro
 * é movido para o estado arquivado (não excluído fisicamente).
 */
class ArquivarLixeiraExpirada extends Command
{
    protected $signature = 'sentinel:arquivar-expirados';

    protected $description = 'Move para o estado arquivado os registros na lixeira com prazo de retenção expirado (RF11)';

    private const MODELOS = [
        Lancamento::class,
        CategoriaLancamento::class,
        Cliente::class,
        Fornecedor::class,
        Funcionario::class,
        NotaFiscal::class,
    ];

    public function handle(): int
    {
        $dias = (int) config('sentinel.retencao_lixeira_dias');
        $limite = now()->subDays($dias);
        $total = 0;

        foreach (self::MODELOS as $modelo) {
            $registros = $modelo::onlyTrashed()
                ->whereNull('arquivado_em')
                ->where('deleted_at', '<=', $limite)
                ->get();

            foreach ($registros as $registro) {
                $registro->arquivar();
                $total++;
            }
        }

        $this->info("Arquivados: {$total} registro(s) com mais de {$dias} dia(s) na lixeira.");

        return self::SUCCESS;
    }
}
