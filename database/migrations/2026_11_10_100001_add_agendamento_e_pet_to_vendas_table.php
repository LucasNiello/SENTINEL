<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Venda avulsa (decisão de 09/11): pode ficar ligada a um atendimento (agendamento) e a um pet, os dois
     * opcionais. O VendaService confere que são do mesmo tenant e que o pet é o do agendamento e do tutor.
     */
    public function up(): void
    {
        Schema::table('vendas', function (Blueprint $table) {
            $table->foreignId('agendamento_id')->nullable()->after('tutor_id')->constrained('agendamentos')->restrictOnDelete();
            $table->foreignId('pet_id')->nullable()->after('agendamento_id')->constrained('pets')->restrictOnDelete();
            $table->index(['tenant_id', 'pet_id']);
        });
    }

    public function down(): void
    {
        Schema::table('vendas', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'pet_id']);
            $table->dropConstrainedForeignId('pet_id');
            $table->dropConstrainedForeignId('agendamento_id');
        });
    }
};
