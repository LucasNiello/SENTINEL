<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RF03: conciliar_lancamentos_em_lote grava uma linha por item; o lote_id (UUID gerado
     * no Confirmar) liga as linhas do mesmo lote. Nulo em tudo o que não é lote.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->uuid('lote_id')->nullable()->after('entidade_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['lote_id']);
            $table->dropColumn('lote_id');
        });
    }
};
