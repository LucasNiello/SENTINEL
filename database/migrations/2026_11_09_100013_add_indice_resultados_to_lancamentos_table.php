<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clínica vet: lançamentos viram "despesas gerais"; os Resultados somam por período (data).
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->index(['tenant_id', 'data']);
        });
    }

    public function down(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'data']);
        });
    }
};
