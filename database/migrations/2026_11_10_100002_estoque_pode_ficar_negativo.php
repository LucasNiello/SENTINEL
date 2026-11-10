<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Decisão do Lucas (10/11/2026): o estoque PODE ficar negativo. O saldo do sistema pode estar errado em relação
     * à prateleira, e isso não pode travar venda nem uso no atendimento. A coluna deixa de ser sem sinal; a baixa
     * continua atômica (CatalogoService::baixarEstoque) e o card avisa antes do segurar quando o saldo vai ficar negativo.
     */
    public function up(): void
    {
        Schema::table('catalogo_itens', function (Blueprint $table) {
            $table->integer('estoque')->default(0)->change();
        });
    }

    /** Só volta se nenhum item estiver negativo (a coluna sem sinal recusaria o valor). */
    public function down(): void
    {
        Schema::table('catalogo_itens', function (Blueprint $table) {
            $table->unsignedInteger('estoque')->default(0)->change();
        });
    }
};
